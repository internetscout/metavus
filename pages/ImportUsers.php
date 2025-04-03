<?PHP
#
#   FILE:  ImportUsers.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\File;
use Metavus\FormUI;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Checks to make sure file is of correct type
 * @param string $FieldName Name of field being validated
 * @param array $FieldValue Array with index 0 as name of file with extension to validate type
 * @return string|null NULL if field input is valid, error message otherwise
 */
function validateFileType(string $FieldName, array $FieldValue)
{
    $File = new File(intval($FieldValue[0]));
    if (preg_match("/\.(tsv)|(csv)|(txt)$/", $File->Name()) && $File->GetType() == "text/plain") {
        return null;
    } else {
        return "Incorrect file type.";
    }
}

# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized
if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

# set form fields to initialize FormUI
$FormFields = [
    "Encrypt" => [
        "Type" => FormUI::FTYPE_OPTION,
        "Label" => "Password Format",
        "Options" => ["Hashed", "Plain Text"],
        "Required" => true,
        "Value" => 0,
    ],
    "File" => [
        "Type" => FormUI::FTYPE_FILE,
        "Label" => "File Name",
        "Required" => true,
        "ValidateFunction" => "validateFileType"
    ]
];
$H_FormUI = new FormUI($FormFields);

if (isset($_SESSION["ErrorMessage"])) {
    FormUI::LogError($_SESSION["ErrorMessage"]);
    unset($_SESSION["ErrorMessage"]);
}

switch (StdLib::getFormValue($H_FormUI->getButtonName())) {
    case "Upload":
        $H_FormUI->HandleUploads();
        break;

    case "Delete":
        $H_FormUI->HandleDeletes();
        break;

    default:
        break;
}

$AF = ApplicationFramework::getInstance();
$ButtonPushed = StdLib::getFormValue("Submit");
switch ($ButtonPushed) {
    case "Begin Import":
        # if the input provided was valid
        if ($H_FormUI->ValidateFieldInput() == 0) {
            # get updated field values
            $FieldValues = $H_FormUI->GetNewValuesFromForm();

            # set session values for execute page
            $Path = (new File($FieldValues["File"][0]))->GetNameOfStoredFile();

            $_SESSION["FileId"] = $FieldValues["File"][0];

            # go to ImportUsersExecute
            $AF->SetJumpToPage(
                "index.php?P=ImportUsersExecute&EN=".$FieldValues["Encrypt"]."&TF=".$Path
            );
        }
        break;

    case "Cancel":
        $AF->SetJumpToPage("SysAdmin");
        break;

    default:
        break;
}
