<?PHP
#
#   FILE:  AddQualifier.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2003-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\FormUI;
use Metavus\Qualifier;
use Metavus\QualifierFactory;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------

$QualifierFactory = new QualifierFactory();

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
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
                return ((new QualifierFactory())->NameIsInUse($FieldValue))
                            ? "Field name already in use." : null;
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
$H_Qualifiers = $QualifierFactory->GetItems();

# act on any button push
$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Add":
        # check values and bail out if any are invalid
        if ($H_FormUI->ValidateFieldInput()) {
            return;
        }

        # Get form values for new qualifier
        $QualifierValues = $H_FormUI->GetNewValuesFromForm();

        # create new qualifier from values
        $Qualifier = Qualifier::Create(trim($QualifierValues["Name"]));
        $Qualifier->NSpace($QualifierValues["Namespace"]);
        $Qualifier->Url($QualifierValues["URL"]);

        # refresh page to show new qualifier
        $AF->SetJumpToPage("AddQualifier");
        break;

    case "Cancel":
        $AF->SetJumpToPage("SysAdmin");
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
                FormUI::LogError($NewNamespace." is not a valid URL.");
                $Valid = false;
            }
            # check if url is a valid url
            if (!filter_var($NewUrl, FILTER_VALIDATE_URL) && strlen($NewUrl)) {
                FormUI::LogError($NewUrl." is not a valid URL.");
                $Valid = false;
            }
            #check if name is empty
            if (strlen(trim($NewName)) == 0) {
                FormUI::LogError("Name cannot be empty.");
                $Valid = false;
            }
            if ($QualifierFactory->NameIsInUse(trim($NewName)) && $NewName != $Qualifier->Name()) {
                FormUI::LogError($NewName." is already in use.");
                $Valid = false;
            }
            if ($Valid) {
                $Qualifier = new Qualifier($QualifierId);
                $Qualifier->Name($NewName);
                $Qualifier->NSpace($NewNamespace);
                $Qualifier->Url($NewUrl);
                $QualifierId = null;
            }
        }
        # get updated array of all qualifiers using qualifier ids
        $H_Qualifiers = $QualifierFactory->GetItems();
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
            $AF->SetJumpToPage("ConfirmDeleteQualifier&QI=".$QualifierIdString);
        } else {
            $AF->SetJumpToPage("AddQualifier");
        }
        break;
}
