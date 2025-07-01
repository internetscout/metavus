<?PHP
#
#   FILE:  AddClassification.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
 * Validation function for classification name field.
 * @param string $FieldName Name of field being validated.
 * @param string $FieldValue Value to validate.
 * @param array $AllFieldValues Containing values for all fields.
 * @param int $FieldId ID of field in order to check if segment name is already in use.
 * @param int $ParentId ID of parent in order to construct full classification name.
 * @return string|null NULL if field input is valid, otherwise error message.
 */
function segmentValidateFunc(
    string $FieldName,
    string $FieldValue,
    array $AllFieldValues,
    int $FieldId,
    int $ParentId
) {
    $FieldValue = trim($FieldValue);
    # get full name for potential new classification
    if ($ParentId == Classification::NOPARENT) {
        $NewFullName = $FieldValue;
    } else {
        $ParentClass = new Classification($ParentId);
        $NewFullName = $ParentClass->name()." -- ".$FieldValue;
    }
    # if classification already exists return error
    # pass true to nameIsInUse so a case-insensitive string
    # comparison is used to check if the new classification's name
    # is already in use
    $CFactory = new ClassificationFactory($FieldId);
    if ($CFactory->nameIsInUse($NewFullName, true)) {
        return "A classification with that name is already in use.";
    }

    return null;
}

/**
 * Get array of qualifers to display in FormUI fields
 * @param int $FieldId ID of metadata field for classification.
 * @return array Items containing qualifier names indexed by ID
 *         with placeholder for no qualifier selected
 */
function getQualifiers(int $FieldId): array
{
    $Field = MetadataField::getField($FieldId);

    $Items[-1] = "--";
    if ($Field->hasItemLevelQualifiers()) {
        $Items += $Field->associatedQualifierList();
    } elseif ($Field->defaultQualifier() != false) {
        $Qualifier = new Qualifier($Field->defaultQualifier());
        $Items[$Qualifier->id()] = $Qualifier->name();
    }
    ksort($Items);
    return $Items;
}

/**
 * Set up and return array of form field defintions for FormUI to display.
 * @param int $FieldId ID of field for displaying field name.
 * @param int $ParentId ID of parent for displaying classification to be added under.
 * @return array Form field definitions.
 */
function getFormFieldDefinitions(int $FieldId, int $ParentId): array
{
    # If parent doesn't exist
    if ($ParentId == Classification::NOPARENT) {
        # set parent classification name to '(top)'
        $ParentClassName = "(top)";
    } else {
        # otherwise set parent classification name to parent classification's name
        $ParentClass = new Classification($ParentId);
        $ParentClassName = $ParentClass->fullName();
    }

    # Get field and field name using field id.
    $FieldName = (MetadataField::getField($FieldId))->getDisplayName();

    return [
        "ClassificationType" => [
            "Type" => FormUI::FTYPE_CUSTOMCONTENT,
            "ReadOnly" => true,
            "Label" => "Classification Type",
            "Content" => $FieldName,
        ],
        "ToBeAddedUnder" => [
            "Type" => FormUI::FTYPE_CUSTOMCONTENT,
            "ReadOnly" => true,
            "Label" => "To Be Added Under",
            "Content" => $ParentClassName,
        ],
        "NewSegmentName" => [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Segment Name",
            "Placeholder" => "Segment Name",
            "ValidateFunction" => "Metavus\\segmentValidateFunc",
            "Required" => true,
        ],
        "QualifierId" => [
            "Type" => FormUI::FTYPE_OPTION,
            "Label" => "Qualifier",
            "Options" => getQualifiers($FieldId),
            "Value" => -1,
        ],
    ];
}

# ----- MAIN -----------------------------------------------------------------

if (!User::requirePrivilege(PRIV_CLASSADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

# get ParentId and FieldId from form or url
$ParentId = StdLib::getFormValue(
    "F_ParentId",
    StdLib::getFormValue("ParentId", Classification::NOPARENT)
);
$FieldId = StdLib::getFormValue("F_FieldId", StdLib::getFormValue("FieldId", false));

# get form fields using FieldId and ParentId
$FormFields = getFormFieldDefinitions($FieldId, $ParentId);

# instantiate FormUI using defined fields
$H_FormUI = new FormUI($FormFields);

# add hidden values to form
$H_FormUI->addHiddenField("F_ParentId", $ParentId);
$H_FormUI->addHiddenField("F_FieldId", $FieldId);
$H_FormUI->addValidationParameters($FieldId, $ParentId);
# act on any button push
$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Add":
        # check values and bail out if any are invalid
        if ($H_FormUI->validateFieldInput()) {
            return;
        }
        $ClassValues = $H_FormUI->getNewValuesFromForm();
        $NewSegmentName = trim($ClassValues["NewSegmentName"]);
        $QualifierId = $ClassValues["QualifierId"];

        # add new classification
        $Class = Classification::create(
            $NewSegmentName,
            $FieldId,
            $ParentId
        );

        # add qualifier ID (if any)
        if (isset($QualifierId) && strlen($QualifierId)) {
            $Class->qualifierId($QualifierId);
        }

        # if errors were not found
        if (!$H_FormUI->errorsLogged()) {
            # set parent ID to classification if it is top level, otherwise its parent
            $ParentIdForUrl = ($ParentId == Classification::NOPARENT) ? $Class->id() : $ParentId;

            # go to classification selection page for parent of edited classification
            $AF->setJumpToPage("EditClassifications&FieldId=".$FieldId
                       ."&ParentId=".$ParentIdForUrl);
        }
        break;
    case "Cancel":
        $AF->setJumpToPage("EditClassifications");
        break;
}
