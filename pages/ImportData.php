<?PHP
#
#   FILE:  ImportData.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\FormUI;
use Metavus\File;
use Metavus\MetadataSchema;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Checks to make sure file is of correct type
 * @param string $FieldName Name of field being validated
 * @param array $FieldValue Array with index 0 as name of file with extension to validate type
 * @return string|null NULL if field input is valid, error message otherwise
 */
function validateFileType(string $FieldName, array $FieldValue)
{
    if (count($FieldValue) == 0) {
        return "The data file must be uploaded in order to process it.";
    }

    $File = new File(intval($FieldValue[0]));
    if (preg_match("/\.(tsv)|(csv)|(txt)$/", $File->name()) && $File->getType() == 'text/plain') {
        return null;
    }

    return "Incorrect file type.";
}

/**
 * Get an html option list of the unique field.
 * @return array Array containing field names
 */
function getUniqueFieldList(): array
{
    $Values = [];

    # first entry is empty
    $Values["-1"] = "None Selected";

    # Get the schema
    $Schema = new MetadataSchema();

    # Get the fields for the schema
    $Fields = $Schema->getFields(
        MetadataSchema::MDFTYPE_TEXT |
        MetadataSchema::MDFTYPE_PARAGRAPH |
        MetadataSchema::MDFTYPE_NUMBER |
        MetadataSchema::MDFTYPE_URL
    );

    foreach ($Fields as $Field) {
        if ($Field->enabled()) {
            $Values[$Field->name()] = $Field->name();
        }
    }
    return $Values;
}

# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$FormFields = [
    "UniqueField" => [
        "Type" => FormUI::FTYPE_OPTION,
        "Label" => "Unique Field",
        "Options" => getUniqueFieldList(),
        "Help" => 'Unique Field can be used to determine a unique record. '
            .'If a Unique Field is selected, each imported record will '
            .'be compared to existing records in the database to find a match '
            .'for the Unique Field value. If found, the imported field '
            .'values are assigned to that record. If no matching record is '
            .'found, a new record with that unique value will be created. By '
            .'default, "Title" AND "Description" are used to determine unique '
            .'records during import.',
    ],
    "Delimiter" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Delimiter for Fields with Multiple Values",
        "Help" => "Fields that allow multiple values to be selected "
                ."will normally display the values on different rows. "
                ."If a delimiter was specified when exporting to put the values on the same row "
                ."separated by the given delimiter, the same delimiter should be specified here."
    ],
    "File" => [
        "Type" => FormUI::FTYPE_FILE,
        "Label" => "File Name",
        "Required" => true,
        "ValidateFunction" => "validateFileType"
    ],
    "Debug" => [
        "Type" => FormUI::FTYPE_OPTION,
        "Label" => "Debug",
        "Options" => ["Off", "On"],
        "Value" => 0,
    ],
];

$H_FormUI = new FormUI($FormFields);

if (isset($_SESSION["ErrorMessage"])) {
    FormUI::logError($_SESSION["ErrorMessage"]);
    unset($_SESSION["ErrorMessage"]);
}

# act on any button press
switch (StdLib::getFormValue($H_FormUI->getButtonName())) {
    case "Upload":
        $H_FormUI->handleUploads();
        break;

    case "Delete":
        $H_FormUI->handleDeletes();
        break;

    default:
        break;
}

$ButtonPushed = StdLib::getFormValue("Submit");
$AF = ApplicationFramework::getInstance();
switch ($ButtonPushed) {
    case "Begin Import":
        # if the input provided was valid
        if ($H_FormUI->validateFieldInput() == 0) {
            $FieldValues = $H_FormUI->getNewValuesFromForm();

            # save form values in session for ImportDataExecute to use
            $UniqueField = $FieldValues["UniqueField"];
            $Debug = $FieldValues["Debug"];
            $Delimiter = $FieldValues["Delimiter"];
            $_SESSION["UniqueField"] = $UniqueField;
            $_SESSION["Debug"] = $Debug;
            $_SESSION["Delimiter"] = $Delimiter;
            $Path = (new File($FieldValues["File"][0]))->getNameOfStoredFile();
            $_SESSION["Path"] = $Path;
            $_SESSION["FileId"] = $FieldValues["File"][0];

            # go to ImportDataExecute
            $AF->setJumpToPage("ImportDataExecute");
        }
        break;

    case "Cancel":
        $AF->setJumpToPage("SysAdmin");
        break;

    default:
        break;
}
