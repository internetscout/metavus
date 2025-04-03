<?PHP
#
#   FILE: EditMetadataSchema.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\PrivilegeEditingUI;
use Metavus\RecordFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Get the page with necessary parameters when returning to the DBEditor page.
* @param mixed $Value MetadataSchema object, MetadataField object, or a schema
*      ID.
* @return string  Returns the page to return to.
*/
function GetReturnToPage($Value): string
{
    $Suffix = "";

    # get the suffix from a metadata schema if not using the default schema
    if ($Value instanceof MetadataSchema) {
        if ($Value->Id() != MetadataSchema::SCHEMAID_DEFAULT) {
            $Suffix = "&SC=" . (string)$Value->Id();
        }
    # use the value directly if not using the default schema
    } elseif (!is_null($Value) && $Value != MetadataSchema::SCHEMAID_DEFAULT) {
        $Suffix = "&SC=" . urlencode($Value);
    }

    return "DBEditor" . $Suffix;
}

/**
 * Save field mappings from editing form data into a Metadata Schema.
 * @param array $FormFields Array holding the parameters for the fields mapping.
 * @param MetadataSchema $Schema The schema whose field mappings will be updated.
 */
function saveFieldMapping(array $FormFields, MetadataSchema $Schema): void
{
    foreach ($FormFields as $FieldId => $Params) {
        $NewFieldValue = StdLib::getFormValue($FieldId);
        if (is_null($NewFieldValue) || !strlen($NewFieldValue)) {
            $NewFieldValue = false;
        }
        $Schema->stdNameToFieldMapping($Params["MFieldName"], $NewFieldValue);
    }
}

# ----- MAIN -----------------------------------------------------------------

PageTitle("Edit Metadata Schema");

# check authorization
if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

# construct the schema object
$SchemaId = StdLib::getArrayValue($_GET, "SC", MetadataSchema::SCHEMAID_DEFAULT);
$H_Schema = new MetadataSchema($SchemaId);

$ResourceName = $H_Schema->resourceName();

$H_MappingFormFields = [
    "F_TitleField".$SchemaId => [
        "MFieldName" => "Title",
        "Label" => $ResourceName." Title Field",
        "FieldTypes" => MetadataSchema::MDFTYPE_TEXT,
        "Value" => $H_Schema->stdNameToFieldMapping("Title"),
        "Required" => true
    ],
    "F_DescriptionField".$SchemaId => [
        "MFieldName" => "Description",
        "Label" => $ResourceName." Description Field",
        "FieldTypes" => MetadataSchema::MDFTYPE_PARAGRAPH,
        "Value" => $H_Schema->stdNameToFieldMapping("Description"),
        "Required" => false
    ],
    "F_UrlField".$SchemaId => [
        "MFieldName" => "Url",
        "Label" => $ResourceName." Url Field",
        "FieldTypes" => MetadataSchema::MDFTYPE_URL,
        "Value" => $H_Schema->stdNameToFieldMapping("Url"),
        "Required" => false
    ],
    "F_FileField".$SchemaId => [
        "MFieldName" => "File",
        "Label" => $ResourceName." File Field",
        "FieldTypes" => MetadataSchema::MDFTYPE_FILE,
        "Value" => $H_Schema->stdNameToFieldMapping("File"),
        "Required" => false
    ],
    "F_ScreenshotField".$SchemaId => [
        "MFieldName" => "Screenshot",
        "Label" => $ResourceName." Screenshot Field",
        "FieldTypes" => MetadataSchema::MDFTYPE_IMAGE,
        "Value" => $H_Schema->stdNameToFieldMapping("Screenshot"),
        "Required" => false
    ],
];

# the action to be performed, if any
$Action = StdLib::getFormValue("F_Submit");

# variables for holding privilege errors
$H_PrivilegesError = null;
$H_PrivsetUI = new PrivilegeEditingUI($SchemaId);
$AF = ApplicationFramework::getInstance();

# if user canceled editing
if ($Action == "Cancel") {
    # go back to the list of fields for the schema
    $AF->SetJumpToPage(GetReturnToPage($H_Schema));
    return;
# else if user requested changes be saved
} elseif ($Action == "Save Changes") {
    try {
        # update schema default sort field
        $F_DefaultSortField = StdLib::getFormValue("F_DefaultSortField", "R");
        $H_Schema->defaultSortField(
            ($F_DefaultSortField == "R") ? false : intval($F_DefaultSortField)
        );

        # update the schema mapping fields
        saveFieldMapping($H_MappingFormFields, $H_Schema);

        # attempt to extract modified privsets from form
        $NewPrivsets = $H_PrivsetUI->GetPrivilegeSetsFromForm();

        # update each type of privilege
        foreach (["View", "Author", "Edit"] as $PrivPrefix) {
            $PrivilegeType = $PrivPrefix."ingPrivileges";
            $H_Schema->{$PrivilegeType}($NewPrivsets[$PrivilegeType]);
        }

        $H_Schema->commentsEnabled(StdLib::getFormValue("F_AllowComments", false));

        # nuke the page cache in case permission changes affect what is displayed
        $AF->ClearPageCache();
        RecordFactory::ClearViewingPermsCache();
    } catch (Exception $Exception) {
        # couldn't update the privileges
        $H_PrivilegesError = $Exception->getMessage();
    }

    # if there were no errors
    if (is_null($H_PrivilegesError)) {
        # go back to the list of fields for the schema
        $AF->SetJumpToPage(GetReturnToPage($H_Schema));
        return;
    }
}
