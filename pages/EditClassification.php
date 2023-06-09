<?PHP
#
#   FILE:  EditClassification.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Classification;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\Qualifier;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------


# ----- LOCAL FUNCTIONS ------------------------------------------------------


# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized
if (!CheckAuthorization(PRIV_CLASSADMIN)) {
    return;
}

# assume we're editing and not deleting until told otherwise
$H_ConfirmDelete = false;

# retrieve class info from DB, as well as it's associated Field ID and list of qualifiers to display
$H_ClassId = StdLib::getFormValue("ClassificationId", StdLib::getFormValue("F_ClassificationId"));
$H_Class = new Classification($H_ClassId);
$Field = new MetadataField($H_Class->fieldId());

# instantiate form fields
$FormFields = [
    "FullName" => [
        "Label" => "Full Name",
        "ReadOnly" => true,
        "Type" => FormUI::FTYPE_TEXT,
        "Value" => $H_Class->fullName(),
    ],
    "SegmentName" => [
        "Label" => "Segment Name",
        "Required" => true,
        "Type" => FormUI::FTYPE_TEXT,
        "Value" => $H_Class->segmentName(),
    ],
];

# if classification uses qualifiers, add that part of the form to the form fields
if ($Field->usesQualifiers()) {
    $QualifierField = [
        "Label" => "Qualifier",
        "Value" => "None",
    ];

    if ($Field->hasItemLevelQualifiers()) {
        $QualifierField["Type"] = FormUI::FTYPE_OPTION;
        $QualifierField["Options"] =
            [ "None" => "(none)" ] + $Field->associatedQualifierList();

        $Value = $H_Class->qualifierId();
        if ($Value !== false && !Qualifier::itemExists($Value)) {
            $Value = $Field->defaultQualifier();
        }

        if ($Value !== false && Qualifier::itemExists($Value)) {
            $QualifierField["Value"] = $Value;
        }
    } else {
        $QualifierField["ReadOnly"] = true;
        $QualifierField["Type"] = FormUI::FTYPE_TEXT;

        $Value = $Field->defaultQualifier();
        if ($Value !== false && Qualifier::itemExists($Value)) {
            $QualifierField["Value"] = (new Qualifier($Value))->name();
        }
    }

    $FormFields["Qualifier"] = $QualifierField;
}

# create form and add hidden field for classificaiton id
$H_FormUI = new FormUI($FormFields);
$H_FormUI->addHiddenField("ClassificationId", strval($H_Class->id()));

# act on any button push
$ButtonPushed = StdLib::getFormValue("Submit");

# generate link parameters with previous information using old logic
$H_JumpParams =
    ((isset($_GET["SQ"])) ? ("&SQ=" . urlencode($_GET["SQ"])) : "")
    . ((isset($_GET["SL"])) ? ("&SL=" . urlencode($_GET["SL"])) : "")
    . ((isset($_GET["ParentId"])) ? ("&ParentId=" . intval($_GET["ParentId"])) : "")
    . ((isset($_GET["FieldId"])) ? ("&FieldId=" . intval($_GET["FieldId"])) : "");

switch ($ButtonPushed) {
    case "Save Changes":
        # check values and bail out if any are invalid
        if ($H_FormUI->validateFieldInput()) {
            return;
        }

        # retrieve values from form
        $NewValues = $H_FormUI->getNewValuesFromForm();

        # set segment name - don't need to check length since its required in the form
        $H_Class->segmentName($NewValues["SegmentName"]);

        # if qualifier is set, set qualifier
        if ($Field->usesQualifiers()) {
            $QualifierId = ($NewValues["Qualifier"] == "None") ?
                false : intval($NewValues["Qualifier"]);

            $H_Class->qualifierId($QualifierId);
        }

        $H_Class->recalcDepthAndFullName();

        # go back to EditClassifications
        $GLOBALS["AF"]->SetJumpToPage("EditClassifications" . $H_JumpParams);
        return;
    case "Delete Classification":
        # display confirm delete page
        $H_ChildClasses = [];
        if ($H_Class->childCount() > 0) {
            foreach ($H_Class->childList() as $ChildId) {
                $H_ChildClasses[] = (new Classification($ChildId))->fullName();
            }
        }
        $H_ConfirmDelete = true;
        break;
    case "Confirm Delete Classification":
        # user was warned so we're deleting everything like we said
        $ChildList = $H_Class->childList();
        foreach ($ChildList as $ChildId) {
            $Child = new Classification($ChildId);
            $Child->destroy(false, true, true);
        }
        $H_Class->destroy(false, true, true);
        $GLOBALS["AF"]->SetJumpToPage("EditClassifications" . $H_JumpParams);
        return;
    case "Cancel":
        # if we're cancelling deletion, go back to EditClassification rather than EditClassifications
        if (array_key_exists("F_ClassificationId", $_POST)) {
            break;
        }
        $GLOBALS["AF"]->SetJumpToPage("EditClassifications" . $H_JumpParams);
        return;
}
