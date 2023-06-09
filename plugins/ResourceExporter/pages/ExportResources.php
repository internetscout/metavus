<?PHP
#
#   FILE:  ExportResources.php (ResourceExporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\FormUI;
use Metavus\MetadataSchema;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Retrieve list of available folders of resources to export.
 * @return array Array with Folder names for the values and Folder IDs for
 *       the index, plus an entry for all resources with -1 as the index.
 */
function GetAvailableSources()
{
    # start with empty list of sources
    $Sources = [];

    # if Folders plugin is available
    if ($GLOBALS["G_PluginManager"]->PluginEnabled("Folders")) {
        # retrieve folders
        $Folders = [];
        $FFactory = new FolderFactory(User::getCurrentUser()->Id());
        $ResourceFolder = $FFactory->GetResourceFolder();
        $FolderIds = $ResourceFolder->GetItemIds();
        foreach ($FolderIds as $Id) {
            $Folders[$Id] = new Folder($Id);
        }

        # assemble source list from folder names
        foreach ($Folders as $Id => $Folder) {
            $FolderItemCount = $Folder->GetItemCount();
            $Sources[$Id] = $Folder->Name()." (".$FolderItemCount." item"
                    .(($FolderItemCount == 1) ? "" : "s").")";
        }
    }

    # add option list selection for all resources
    foreach (MetadataSchema::GetAllSchemas() as $Id => $Schema) {
        $Sources[-1 - $Id] = "ALL ".strtoupper($Schema->Name());
    }

    # return sources to caller
    return $Sources;
}

/**
 * Retrieve list of schemas corresponding to each source.
 * @param array $AvailableSources List of available sources.
 * @return array Array keyed by SourceId as returned by
 * GetAvailableSources(), where values are arrays of relevant
 * SchemaIds.
 */
function GetSourceSchemas(array $AvailableSources)
{
    $Result = [];

    foreach ($AvailableSources as $Id => $SourceName) {
        if ($Id >= 0) {
            # if result was a folder, iterate over all the folder
            # Items and collect their SchemaIds
            $Folder = new Folder($Id);

            $FolderSchemas = [];
            foreach ($Folder->GetItemIds() as $ItemId) {
                $Resource = new Record($ItemId);
                $FolderSchemas[$Resource->getSchemaId()] = true;
            }

            $Result[$Id] = array_keys($FolderSchemas);
        } else {
            # otherwise result was a schema, and it just contains itself
            $Result[$Id] = [ 0 - ($Id + 1) ];
        }
    }
    return $Result;
}

/**
 * Retrieve list of resource folders that should not be used for export.
 * @param array $AvailableSources Available folders of resources to export.
 * @return array Array with Folder names for the values and Folder IDs
 *       for the index.
 */
function GetDisabledSources($AvailableSources)
{
    # start out assuming no sources will be disabled
    $DisabledSources = [];

    # for each available source
    foreach ($AvailableSources as $Id => $SourceName) {
        # if source is a folder
        if ($Id >= 0) {
            # if folder has no resources
            $Folder = new Folder($Id);
            if ($Folder->GetItemCount() == 0) {
                # add source to disabled list
                $DisabledSources[$Id] = $SourceName;
            }
        }
    }

    # return list of disabled sources to caller
    return $DisabledSources;
}


/**
 * Get the list of FieldIds currently selected.
 * @return array|null Array of FieldIds or NULL when no fields are checked.
 */
function GetSelectedFields()
{
    $FieldIds = null;

    # iterate over all fields in all schemas, checking each to see if
    # it should be included in the export
    foreach (MetadataSchema::GetAllSchemas() as $Schema) {
        $Fields = $Schema->GetFields();
        foreach ($Fields as $Field) {
            if (array_key_exists("F_ExportField_".$Field->Id(), $_POST)) {
                $FieldIds[] = $Field->Id();
            }
        }
    }

    return $FieldIds;
}


/**
 * Get format parameters from POST data.
 * @return Array of format parameters.
 */
function GetFormatParameters()
{
    $Plugin = $GLOBALS["G_PluginManager"]->GetPluginForCurrentPage();
    $FormatParameterValues = [];

    $Formats = $Plugin->GetFormats();
    $FormatParameters = $Plugin->GetExportParameters();

    foreach ($FormatParameters as $FormatName => $FormatParams) {
        if (is_array($FormatParams) && count($FormatParams)) {
            $CfgUI = new FormUI($FormatParams, [], $FormatName);
            $FormatParameterValues[$FormatName] = $CfgUI->GetNewValuesFromForm();
        }
    }

    return $FormatParameterValues;
}

# ----- MAIN -----------------------------------------------------------------

if (!CheckAuthorization(PRIV_COLLECTIONADMIN)) {
    return;
}

# retrieve user currently logged in
$User = User::getCurrentUser();

$GLOBALS["AF"]->RequireUIFile(
    "jquery.cookie.js",
    ApplicationFramework::ORDER_FIRST
);

$Plugin = $GLOBALS["G_PluginManager"]->GetPluginForCurrentPage();
$UserId = $User->Id();

$H_FieldSetName = StdLib::getArrayValue(
    $_COOKIE,
    "ResourceExporter_FieldSetName",
    ""
);

# determine which action we are currently taking
$H_Action = strtoupper(StdLib::getFormValue("F_Submit", ""));

if ($H_Action == "CANCEL") {
    $GLOBALS["AF"]->SetJumpToPage("SysAdmin");
    return;
} elseif (!in_array($H_Action, ["SETUP", "EXPORT", "SAVE", "DELETE"])) {
    $H_Action = "SETUP";
}

# if we're saving a new fieldset
if ($H_Action == "SAVE") {
    $H_FieldSetName = StdLib::getFormValue("F_FieldSetName", "");
    $Format = StdLib::getFormValue("F_Format");
    $FieldIds = GetSelectedFields();
    if ($FieldIds === null) {
        $H_ErrorMessages[] = "No metadata fields were selected.";
    } elseif (strlen($H_FieldSetName) == 0) {
        $H_ErrorMessages[] = "No name provided for saved settings.";
    } elseif (!$Plugin->IsRegisteredFormat($Format)) {
        $H_ErrorMessages[] = "Export format was not recognized.";
    } else {
        $ExportConfigs = $Plugin->ConfigSetting("ExportConfigs");
        $ExportConfigs[$UserId][$H_FieldSetName] = [
            "FieldIds" => $FieldIds,
            "Format" => $Format,
            "FormatParams" => GetFormatParameters(),
        ];
        $Plugin->ConfigSetting("ExportConfigs", $ExportConfigs);
    }

    $H_Action = "SETUP";
# if we're deleting a fieldset
} elseif ($H_Action == "DELETE") {
    $SelectedFieldSet = StdLib::getFormvalue("F_FieldSetName");

    $ExportConfigs = $Plugin->ConfigSetting("ExportConfigs");
    if (array_key_exists($UserId, $ExportConfigs)) {
        if (array_key_exists($SelectedFieldSet, $ExportConfigs[$UserId])) {
            unset($ExportConfigs[$UserId][$SelectedFieldSet]);
            $Plugin->ConfigSetting("ExportConfigs", $ExportConfigs);
            unset($_POST["F_FieldSetName"]);
            unset($_COOKIE["ResourceExporter_FieldSetName"]);
            $H_FieldSetName = "";
        } else {
            $H_ErrorMessages[] =
                "Cannot delete saved selections because provided name was invalid.";
        }
    }

    # clear selected format parameters
    $Formats = $Plugin->GetFormats();
    $FormatParameters = $Plugin->GetExportParameters();
    foreach ($FormatParameters as $FormatName => $FormatParams) {
        if (is_array($FormatParams)) {
            foreach ($FormatParams as $FieldName => $FieldData) {
                $Key = "F_".$FormatName."_".$FieldName;
                if (isset($_POST[$Key])) {
                    unset($_POST[$Key]);
                }
            }
        }
    }

    $H_Action = "SETUP";
# if we are at the data export stage
} elseif ($H_Action == "EXPORT") {
    # retrieve ID of folder with resources to be exported
    $SourceFolderId = isset($_POST["F_ResourceSource"]) ?
        $_POST["F_ResourceSource"] : null;

    # retrieve and check list of metadata fields to export
    $FieldIds = null;

    # iterate over all fields in all schemas, checking each to see if
    # it should be included in the export
    foreach (MetadataSchema::GetAllSchemas() as $Schema) {
        $Fields = $Schema->GetFields();
        foreach ($Fields as $Field) {
            if (array_key_exists("F_ExportField_".$Field->Id(), $_POST)) {
                $FieldIds[] = $Field->Id();
            }
        }
    }

    if ($FieldIds === null) {
        $H_ErrorMessages[] = "No metadata fields were selected for export.";
    }

    # retrieve and check export format
    $Format = $_POST["F_Format"];
    if (!$Plugin->IsRegisteredFormat($Format)) {
        $H_ErrorMessages[] = "Export format was not recognized.";
    } else {
        $Formats = $Plugin->ConfigSetting("SelectedFormats");
        $Formats[$UserId] = $Format;
        $Plugin->ConfigSetting("SelectedFormats", $Formats);
    }

    # retrieve and save export format parameters
    $Formats = $Plugin->GetFormats();
    $FormatParameterValues = GetFormatParameters();

    # if errors were found
    if (isset($H_ErrorMessages)) {
        # switch to setup mode
        $H_Action = "SETUP";
    } else {
        # export data
        $H_ExportedResourceCount = $Plugin->ExportData(
            $Format,
            $SourceFolderId,
            $FieldIds,
            isset($FormatParameterValues[$Format])
                        ? $FormatParameterValues[$Format]
            : []
        );

        # if export succeeded
        if ($H_ExportedResourceCount !== null) {
            # set values for display in HTML
            $H_ExportedFileName = $Plugin->LastExportFileName();
            $H_ExportedFileSecret = $Plugin->LastExportFileSecret();
        } else {
            # retrieve error messages
            $Errors = $Plugin->LastExportErrorMessages();
            foreach ($Errors as $ErrMsg) {
                $H_ErrorMessages[] = $ErrMsg;
            }

            # switch to setup mode
            $H_Action = "SETUP";
        }
    }
}

# if we are at the export setup stage
if ($H_Action == "SETUP") {
    $H_AvailableSources = GetAvailableSources();
    $H_SourceToSchemaMap = GetSourceSchemas($H_AvailableSources);
    $H_DisabledSources = GetDisabledSources($H_AvailableSources);

    $H_SelectedSource = StdLib::getFormValue("F_ResourceSource");

    $H_AvailableFormats = $Plugin->GetFormats();

    if (is_null($H_SelectedSource)) {
        # if folders is enabled, use the current user's selected folder
        # otherwise use the first available source
        if ($GLOBALS["G_PluginManager"]->PluginEnabled("Folders")) {
            $FFactory = new FolderFactory($User->Id());
            $H_SelectedSource = $FFactory->GetSelectedFolder()->Id();
        } else {
            $PossibleValueIds = array_keys($H_AvailableSources);
            $H_SelectedSource = reset($PossibleValueIds);
        }
    }

    # construct list of all possible fields
    $H_SchemaNames = [];
    foreach (MetadataSchema::GetAllSchemas() as $SchemaId => $Schema) {
        $H_AvailableFields[$SchemaId] = $Schema->GetFields(
            null,
            MetadataSchema::MDFORDER_ALPHABETICAL
        );
        $H_SchemaNames[$SchemaId] = $Schema->Name();
    }

    $ExportConfigs = $Plugin->ConfigSetting("ExportConfigs");
    if (is_array($ExportConfigs) && array_key_exists($UserId, $ExportConfigs)) {
        $H_FieldSets = $ExportConfigs[$UserId];
        $H_AvailableFieldSets[-1] = "--";
        $H_AvailableFieldSets += array_keys($H_FieldSets);
        $H_SelectedFormat = isset($H_FieldSets[$H_FieldSetName]) ?
            $H_FieldSets[$H_FieldSetName]["Format"] :
            reset($H_AvailableFormats);
    } else {
        $H_FieldSets = [];
        $H_AvailableFieldSets = [
            -1 => "--",
        ];
        $H_SelectedFormat = reset($H_AvailableFormats);
    }

    $H_ExportedDataTypes = $Plugin->GetExportedDataTypes();

    # construct list of standard field mappings
    $H_StandardFields = [];
    foreach (MetadataSchema::GetAllSchemas() as $SchemaId => $Schema) {
        # select standard fields
        $StandardFieldNames = [
            "Title",
            "Description",
            "Url"
        ];

        $H_StandardFields[$SchemaId] = [];
        foreach ($StandardFieldNames as $Name) {
            $Id = $Schema->GetFieldIdByMappedName($Name);
            if ($Id !== null) {
                $H_StandardFields[$SchemaId][] = $Id;
            }
        }
    }

    # retrieve values for format list
    $H_FormatParameters = $Plugin->GetExportParameters();
    $H_FormatParameterValues = $Plugin->ConfigSetting("FormatParameterValues");
}
