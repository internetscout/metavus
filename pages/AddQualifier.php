<?PHP
#
#   FILE:  AddQualifier.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2003-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\FormUI;
use Metavus\Qualifier;
use Metavus\QualifierFactory;
use Metavus\User;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$QualifierFactory = new QualifierFactory();

if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

# form fields definition
$FormFields = [
    "Name" => [
        "Type" => FormUI::FTYPE_TEXT,
        "MaxLength" => 30,
        "Size" => 15,
        "Label" => "Name",
        "Placeholder" => "",
        "Required" => true,
        "ValidateFunction" => function ($FieldName, $FieldValue) {
                # pass true to nameIsInUse so a case-insensitive string
                # comparison is used to check if the new qualifier's name
                # is already in use
                return (
                    (new QualifierFactory())->nameIsInUse($FieldValue, true)
                )
                            ? "Qualifier name already in use." : null;
        }
    ],
    "Namespace" => [
        "Type" => FormUI::FTYPE_URL,
        "MaxLength" => 30,
        "Size" => 50,
        "Label" => "Namespace",
        "Placeholder" => "",
    ],
    "URL" => [
        "Type" => FormUI::FTYPE_URL,
        "MaxLength" => 90,
        "Size" => 50,
        "Label" => "URL",
        "Placeholder" => "",
    ],
];

# instantiate FormUI using form fields
$H_FormUI = new FormUI($FormFields);

# get array of all qualifier ids
$H_Qualifiers = $QualifierFactory->getItems();

# act on any button push
$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Add":
        # check values and bail out if any are invalid
        if ($H_FormUI->validateFieldInput()) {
            return;
        }

        # Get form values for new qualifier
        $QualifierValues = $H_FormUI->getNewValuesFromForm();

        # create new qualifier from values
        $Qualifier = Qualifier::create(trim($QualifierValues["Name"]));
        $Qualifier->nSpace($QualifierValues["Namespace"]);
        $Qualifier->url($QualifierValues["URL"]);

        # refresh page to show new qualifier
        $AF->setJumpToPage("AddQualifier");
        break;

    case "Cancel":
        $AF->setJumpToPage("SysAdmin");
        break;

    case "Save":
        $Errors = [];
        $Qualifiers = [];
        # get list of all qualifier ids using ids
        foreach ($_POST as $Var => $Value) {
            if (preg_match("/qn_[0-9]+/", $Var)) {
                $Qualifiers[] = intval(str_replace("qn_", "", $Var));
            }
        }
        # go through and save inserted data for each field, with validiation
        foreach ($Qualifiers as $QualifierId) {
            $Qualifier = new Qualifier($QualifierId);
            $NewName = trim(StdLib::getFormValue("qn_".$QualifierId));
            $NewNamespace = trim(StdLib::getFormValue("qs_".$QualifierId));
            $NewUrl = trim(StdLib::getFormValue("qu_".$QualifierId));
            $Valid = true;
            # check if namespace is a valid url
            if (!filter_var($NewNamespace, FILTER_VALIDATE_URL) && strlen($NewNamespace)) {
                FormUI::logError($NewNamespace." is not a valid URL.");
                $Valid = false;
            }
            # check if url is a valid url
            if (!filter_var($NewUrl, FILTER_VALIDATE_URL) && strlen($NewUrl)) {
                FormUI::logError($NewUrl." is not a valid URL.");
                $Valid = false;
            }
            #check if name is empty
            if (strlen(trim($NewName)) == 0) {
                FormUI::logError("Name cannot be empty.");
                $Valid = false;
            }
            # pass true to nameIsInUse so a case-insensitive string comparison
            # is used to check if the new qualifier's name is already in usee
            if ($QualifierFactory->nameIsInUse(trim($NewName), true)
                    && $NewName != $Qualifier->name()) {
                FormUI::logError($NewName." is already in use.");
                $Valid = false;
            }
            if ($Valid) {
                $Qualifier = new Qualifier($QualifierId);
                $Qualifier->name($NewName);
                $Qualifier->nSpace($NewNamespace);
                $Qualifier->url($NewUrl);
                $QualifierId = null;
            }
        }
        # get updated array of all qualifiers using qualifier ids
        $H_Qualifiers = $QualifierFactory->getItems();
        break;
    case "Delete Selected":
        $Qualifiers = [];
        foreach ($_POST as $Var => $Value) {
            if (preg_match("/qid_[0-9]+/", $Var)) {
                $QualifierIds[] = $Value;
            }
        }
        if (isset($QualifierIds)) {
            $QualifierIdString = implode("|", $QualifierIds);
            $AF->setJumpToPage("ConfirmDeleteQualifier&QI=".$QualifierIdString);
        } else {
            $AF->setJumpToPage("AddQualifier");
        }
        break;
}
