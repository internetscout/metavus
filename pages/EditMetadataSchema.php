<?PHP
#
#   FILE: EditMetadataSchema.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\FormUI;
use Metavus\MetadataSchema;
use Metavus\RecordFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

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
        if ($Value->id() != MetadataSchema::SCHEMAID_DEFAULT) {
            $Suffix = "&SC=" . (string)$Value->id();
        }
    # use the value directly if not using the default schema
    } elseif (!is_null($Value) && $Value != MetadataSchema::SCHEMAID_DEFAULT) {
        $Suffix = "&SC=" . urlencode($Value);
    }

    return "DBEditor" . $Suffix;
}

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Edit Metadata Schema");

# check authorization
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

# construct the schema object
$SchemaId = StdLib::getArrayValue($_GET, "SC", MetadataSchema::SCHEMAID_DEFAULT);
$H_Schema = new MetadataSchema($SchemaId);

$ResourceName = $H_Schema->resourceName();

# the action to be performed, if any
$Action = StdLib::getFormValue("F_Submit");

# variable for holding privilege errors
$H_PrivilegesError = null;

$AF = ApplicationFramework::getInstance();

$DefaultSortField = $H_Schema->defaultSortField();

# build the form
$FormFields = [
    "Name" => [
        "Type" => FormUI::FTYPE_CUSTOMCONTENT,
        "Label" => "Name",
        "Content" => defaulthtmlentities($H_Schema->name())
    ],
    "Resource Name" => [
        "Type" => FormUI::FTYPE_CUSTOMCONTENT,
        "Label" => "Resource Name",
        "Content" => defaulthtmlentities($H_Schema->resourceName())
    ],
    "View Page" => [
        "Type" => FormUI::FTYPE_CUSTOMCONTENT,
        "Label" => "View Page",
        "Content" => defaulthtmlentities($H_Schema->getViewPage())
    ],
    "Title" => [
        "Type" => FormUI::FTYPE_OPTION,
        "OptionType" => FormUI::OTYPE_LIST,
        "OptionThreshold" => 0,
        "Label" => $ResourceName." Title Field",
        "Options" => $H_Schema->getFieldNames(MetadataSchema::MDFTYPE_TEXT),
        "Value" => $H_Schema->stdNameToFieldMapping("Title")
    ],
    "Description" => [
        "Type" => FormUI::FTYPE_OPTION,
        "OptionType" => FormUI::OTYPE_LIST,
        "OptionThreshold" => 0,
        "Label" => $ResourceName." Description Field",
        "Options" => $H_Schema->getFieldNames(MetadataSchema::MDFTYPE_PARAGRAPH),
        "Value" => $H_Schema->stdNameToFieldMapping("Description")
    ],
    "Url" => [
        "Type" => FormUI::FTYPE_OPTION,
        "OptionType" => FormUI::OTYPE_LIST,
        "OptionThreshold" => 0,
        "Label" => $ResourceName." Url Field",
        "Options" => $H_Schema->getFieldNames(MetadataSchema::MDFTYPE_URL),
        "Value" => $H_Schema->stdNameToFieldMapping("Url")
    ],
    "File" => [
        "Type" => FormUI::FTYPE_OPTION,
        "OptionType" => FormUI::OTYPE_LIST,
        "OptionThreshold" => 0,
        "Label" => $ResourceName." File Field",
        "Options" => $H_Schema->getFieldNames(MetadataSchema::MDFTYPE_FILE),
        "Value" => $H_Schema->stdNameToFieldMapping("File")
    ],
    "Screenshot" => [
        "Type" => FormUI::FTYPE_OPTION,
        "OptionType" => FormUI::OTYPE_LIST,
        "OptionThreshold" => 0,
        "Label" => $ResourceName." Screenshot Field",
        "Options" => $H_Schema->getFieldNames(MetadataSchema::MDFTYPE_IMAGE),
        "Value" => $H_Schema->stdNameToFieldMapping("Screenshot")
    ],
    "DefaultSortField" => [
        "Type" => FormUI::FTYPE_OPTION,
        "OptionType" => FormUI::OTYPE_LIST,
        "OptionThreshold" => 0,
        "Label" => "Default Sort Field",
        "Options" => ["R" => "(Relevance)"] + $H_Schema->getSortFields(),
        "Value" => ($DefaultSortField == false ? "R" : $DefaultSortField)
    ],
    "viewingPrivileges" => [
        "Type" => FormUI::FTYPE_PRIVILEGES,
        "Schemas" => $H_Schema->id(),
        "Label" => "Viewing Permissions",
        "Value" => $H_Schema->viewingPrivileges()
    ],
    "authoringPrivileges" => [
        "Type" => FormUI::FTYPE_PRIVILEGES,
        "Schemas" => $H_Schema->id(),
        "Label" => "Authoring Permissions",
        "Value" => $H_Schema->authoringPrivileges()
    ],
    "editingPrivileges" => [
        "Type" => FormUI::FTYPE_PRIVILEGES,
        "Schemas" => $H_Schema->id(),
        "Label" => "Editing Permissions",
        "Value" => $H_Schema->editingPrivileges()
    ],
    "AllowComments" => [
        "Type" => FormUI::FTYPE_FLAG,
        "Label" => "Allow Comments",
        "Value" => $H_Schema->commentsEnabled()
    ]
];
$MappingFormFields = ["Title", "Description", "Url", "File", "Screenshot"];
foreach ($MappingFormFields as $FieldId) {
    $Options = $FormFields[$FieldId]["Options"] ?? [];
    if (count($Options) == 0) {
        $FormFields[$FieldId] = [
            "Type" => FormUI::FTYPE_CUSTOMCONTENT,
            "Label" => $FormFields[$FieldId]["Label"],
            "Content" => "<p>(No metadata fields of an appropriate type are available.)</p>"
        ];
    } elseif ($FieldId !== "Title") {
        $FormFields[$FieldId]["Options"] = ["" => "--"] + $Options;
    }
}
$H_Form = new FormUI($FormFields);

# if user canceled editing
if ($Action == "Cancel") {
    # go back to the list of fields for the schema
    $AF->setJumpToPage(GetReturnToPage($H_Schema));
    return;
# else if user requested changes be saved
} elseif ($Action == "Save Changes") {
    try {
        $FormValues = $H_Form->getNewValuesFromForm();

        # update schema default sort field
        $DefaultSortField = $FormValues["DefaultSortField"];
        $H_Schema->defaultSortField(
            ($DefaultSortField == "R") ? false : intval($DefaultSortField)
        );

        # update the schema mapping fields
        foreach ($MappingFormFields as $FieldId) {
            $NewFieldValue = array_key_exists($FieldId, $FormValues) ? $FormValues[$FieldId] : null;
            if (is_null($NewFieldValue) || !strlen($NewFieldValue)) {
                $NewFieldValue = false;
            }
            $H_Schema->stdNameToFieldMapping($FieldId, $NewFieldValue);
        }

        # update each type of privilege
        foreach (["view", "author", "edit"] as $PrivPrefix) {
            $PrivilegeType = $PrivPrefix."ingPrivileges";
            $H_Schema->{$PrivilegeType}($FormValues[$PrivilegeType]);
        }

        # update the comments enabled field
        $H_Schema->commentsEnabled($FormValues["AllowComments"]);

        # nuke the page cache in case permission changes affect what is displayed
        $AF->clearPageCache();
        RecordFactory::clearViewingPermsCache();
    } catch (Exception $Exception) {
        # couldn't update the privileges
        $H_PrivilegesError = $Exception->getMessage();
    }

    # if there were no errors
    if (is_null($H_PrivilegesError)) {
        # go back to the list of fields for the schema
        $AF->setJumpToPage(GetReturnToPage($H_Schema));
        return;
    }
}
