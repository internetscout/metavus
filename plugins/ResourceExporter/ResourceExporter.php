<?PHP
#
#   FILE:  ResourceExporter.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2014-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\Folder;
use Metavus\Plugin;
use Metavus\RecordFactory;
use Metavus\User;
use ScoutLib\StdLib;

/**
 * Plugin to support exporting resource metadata.
 */
class ResourceExporter extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set plugin attributes.
     */
    public function register(): void
    {
        $this->Name = "Resource Exporter";
        $this->Version = "1.1.0";
        $this->Description = "Supports exporting resource metadata in"
                ." various formats.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = true;

        $this->addAdminMenuEntry(
            "ExportResources",
            "Export Resources",
            [ PRIV_COLLECTIONADMIN ]
        );
    }

    /**
     * Hook the events into the application framework.
     * @return array Returns an array of events to be hooked into the application
     *      framework.
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_HOURLY" => "CleanOutOldExports",
        ];
    }

    /**
     * Perform one-time plugin setup tasks.
     * @return string|null NULL on success or an error string on failure.
     */
    public function install(): ?string
    {
        $this->setConfigSetting("FormatParameterValues", []);
        return null;
    }

    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Clean out old export files.
     */
    public function cleanOutOldExports(): void
    {
        # retrieve list of exported files
        $ExportedFiles = $this->getConfigSetting("ExportedFiles");

        # for each known exported file
        $NewExportedFiles = [];
        foreach ($ExportedFiles as $Secret => $Info) {
            # if file was exported more than a day ago
            if ($Info["ExportTimestamp"] < strtotime("-1 day")) {
                # delete file
                unlink($Info["LocalFileName"]);
            } else {
                # keep file in list of exported files
                $NewExportedFiles[$Secret] = $Info;
            }
        }

        # save new list of exported files
        $this->setConfigSetting("ExportedFiles", $NewExportedFiles);
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Register format as available for export.
     * @param string $FormatName Human-readable name of format.
     * @param string $FileNameExtension Extension for file name (e.g."xml"),
     *       without the leading "."..
     * @param callable $ExportFunc Function or method to call to export.
     * @param array $ExportedDataTypes Metadata field types exported.
     * @param array $Params Export parameter setup, in the format
     *       supported by the FormUI class.(OPTIONAL)
     */
    public function registerFormat(
        $FormatName,
        $FileNameExtension,
        $ExportFunc,
        $ExportedDataTypes,
        $Params = null
    ): void {
        # check to make sure format name is not a duplicate
        if (array_key_exists($FormatName, $this->ExportFuncs)) {
            throw new Exception("Duplicate format name registered: ".$FormatName);
        }

        # check to make sure export function is callable
        if (!is_callable($ExportFunc)) {
            throw new Exception("Uncallable export function for format ".$FormatName);
        }

        # save format information
        $this->ExportFuncs[$FormatName] = $ExportFunc;
        $this->FileNameExtensions[$FormatName] = $FileNameExtension;
        $this->ExportedDataTypes[$FormatName] = $ExportedDataTypes;
        $this->ExportParameters[$FormatName] = $Params;
    }

    /**
     * Check whether the specified format is registered.
     * @param string $FormatName Human-readable name of format.
     * @return bool TRUE if specified format is registered, otherwise FALSE.
     */
    public function isRegisteredFormat($FormatName): bool
    {
        return array_key_exists($FormatName, $this->ExportFuncs)
                ? true : false;
    }

    /**
     * Get list of registered formats.
     * @return array Array of names of registered formats.
     */
    public function getFormats(): array
    {
        $Formats = [];
        foreach ($this->ExportFuncs as $FormatName => $Func) {
            $Formats[] = $FormatName;
        }
        return $Formats;
    }

    /**
     * Get list of metadata field types supported by each registered format.
     * @return array Array with names of registered formats for the index and
     *       arrays of metadata field types for the values.
     */
    public function getExportedDataTypes(): array
    {
        return $this->ExportedDataTypes;
    }

    /**
     * Get set of export parameters (if any) for each registered format.
     * @return array Array with names of registered formats for the index and
     *       export parameter sets for the values.
     */
    public function getExportParameters(): array
    {
        return $this->ExportParameters;
    }

    /**
     * Export data to file(s).
     * @param string $FormatName Name of format in which to export.
     * @param int $SourceFolderId ID of folder containing resources, or
     *     (-$SchemaId - 1) to export all resources for a specified schema.
     * @param array $FieldIds Array of IDs of metadata fields to export, or
     *       NULL to export all enabled fields.
     * @param array $ParamSettings Export parameter settings.
     * @return int|null Number of resources exported, or NULL if export failed.
     */
    public function exportData(
        $FormatName,
        $SourceFolderId,
        $FieldIds,
        $ParamSettings
    ): ?int {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # retrieve ResourceIds, either all for a specified schema or
        # all contained in a folder
        if ($SourceFolderId < 0) {
            $RFactory = new RecordFactory(-($SourceFolderId + 1));
            $ResourceIds = $RFactory->getItemIds();
        } else {
            # extract all the ids from the target folder
            $Folder = new Folder($SourceFolderId);
            $ResourceIds = $Folder->getItemIds();
        }

        # generate secret string for local file name
        $this->LastExportFileSecret = sprintf(
            "%05X%01X",
            (time() % 0xFFFFF),
            rand(0, 0xF)
        );

        # construct file name
        $this->LastExportFileName = "tmp/"
                ."ResourceExport-".sprintf("%04d", $User->id())."-"
                .date("ymd-His").".".$this->FileNameExtensions[$FormatName];

        # construct local file name
        $this->LastExportLocalFileName = "tmp/".$this->LastExportFileSecret."."
                ."ResourceExport-".sprintf("%04d", $User->id())."-"
                .date("ymd-His").".".$this->FileNameExtensions[$FormatName];

        # attempt to export data
        $this->LastExportErrors = [];
        try {
            $ResourceCount = call_user_func(
                $this->ExportFuncs[$FormatName],
                $ResourceIds,
                $FieldIds,
                $this->LastExportLocalFileName,
                $ParamSettings
            );
        } catch (Exception $Exception) {
            $this->LastExportErrors[] = $Exception->getMessage();
            $ResourceCount = null;
        }

        # save export values if export succeeded
        if ($ResourceCount !== null) {
            $ExportedFiles = $this->getConfigSetting("ExportedFiles");
            if (!is_array($ExportedFiles)) {
                $ExportedFiles = [];
            }
            $ExportedFiles[$this->LastExportFileSecret] = [
                "FileName" => $this->LastExportFileName,
                "LocalFileName" => $this->LastExportLocalFileName,
                "ExportTimestamp" => time(),
                "ExporterId" => $User->id(),
                "ResourceCount" => $ResourceCount
            ];
            $this->setConfigSetting("ExportedFiles", $ExportedFiles);
        }

        # return number of resources exported to caller
        return $ResourceCount;
    }

    /**
     * Retrieve name of last exported file as stored locally (includes
     * leading path and secret hash value in name).
     * @return string Local file name or NULL if no last exported file.
     */
    public function lastExportLocalFileName(): string
    {
        return $this->LastExportLocalFileName;
    }

    /**
     * Retrieve name of last exported file as intended to be downloaded by
     * user, with no leading path.(Not the name of the file stored locally.)
     * @return string File name or NULL if no last exported file.
     */
    public function lastExportFileName(): string
    {
        return $this->LastExportFileName;
    }

    /**
     * Retrieve secret string used in local file name for last export.
     * @return string Secret string.
     */
    public function lastExportFileSecret(): string
    {
        return $this->LastExportFileSecret;
    }

    /**
     * Retrieve error messages (if any) from last export.
     * @return array Array of error messages strings.
     */
    public function lastExportErrorMessages(): array
    {
        return $this->LastExportErrors;
    }

    /**
     * Retrieve info about exported file.
     * @param string $Secret Secret string that identifies file.
     * @return array|null Associative array with "FileName", "LocalFileName",
     *       "ExportTimestamp", "ExporterId", and "ResourceCount" entries,
     *       or NULL if no exported file found with specified secret.
     */
    public function getExportedFileInfo($Secret): ?array
    {
        $ExportedFiles = $this->getConfigSetting("ExportedFiles");
        return (!is_array($ExportedFiles)
                || !array_key_exists($Secret, $ExportedFiles))
                ? null : $ExportedFiles[$Secret];
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $ExportedDataTypes = [];
    private $ExportFuncs = [];
    private $ExportParameters = [];
    private $FileNameExtensions = [];
    private $LastExportErrors = [];
    private $LastExportFileName = null;
    private $LastExportFileSecret = null;
    private $LastExportLocalFileName = null;
}
