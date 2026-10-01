<?PHP
#
#   FILE:  PhotoLibrary.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2023-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use InvalidArgumentException;
use Metavus\FormUI;
use Metavus\HtmlButton;
use Metavus\Image;
use Metavus\InterfaceConfiguration;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\SecondaryNavigation;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\SearchParameterSet;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\ImageFile;
use ScoutLib\Plugin;
use ScoutLib\PluginManager;
use ZipArchive;

/**
 * Plugin that provides a separate photo library, that can be managed and
 * used alongside the main collection.
 */
class PhotoLibrary extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    public const EDIT_PAGE_LINK = 'index.php?P=EditResource&ID=$ID';
    public const IMAGE_FILE_NAME_FIELD_NAME = "Image File Name";
    public const VIEW_PAGE_LINK = 'index.php?P=P_PhotoLibrary_DisplayPhoto&ID=$ID';

    /**
     * Set the plugin attributes.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Photo Library";
        $this->Version = "1.0.7";
        $this->Description = "Provides support for a photo gallery, with"
                ." photos and photo information stored in a separate schema.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "http://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
        ];
        $this->EnabledByDefault = false;

        $this->CfgSetup["PhotoDisplayFieldList"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Photo Display Page Info",
            "ValidateFunction" => [$this, "validatePhotoDisplayFieldList"],
            "Help" => "List of metadata fields to include on the photo"
                    ." display page, one per line.  Fields can be split"
                    ." into multiple display groups by separating them"
                    ." with blank lines.",
            "Default" => "Height\n"
                    ."Width\n"
                    ."\n"
                    ."File Size in KB"
        ];

        $ImageSizeNames = Image::getAllSizeNames();
        $ImageOptions = array_combine($ImageSizeNames, $ImageSizeNames);
        $this->CfgSetup["ImageSize"] = [
            "Label" => "Image Size",
            "Type" => FormUI::FTYPE_OPTION,
            "Options" => $ImageOptions,
            "Help" => "Image size to use on DisplayPhoto.",
            "Default" => "mv-image-largesquare",
            "Required" => true,
        ];

        $this->CfgSetup["ImageDownloadingHeading"] = [
            "Label" => "Image Downloading",
            "Type" => FormUI::FTYPE_HEADING,
        ];

        $this->CfgSetup["DownloadZipFileNamePrefix"] = [
            "Label" => "Zip File Name Prefix",
            "Type" => FormUI::FTYPE_TEXT,
            "Help" => "Prefix to use for downloaded Zip file names."
                    ." The string ".self::FOLDER_NAME_TOKEN
                    ." can be included and will be"
                    ." replaced with a normalized version of the folder name.",
            "Default" => "PhotoLibrary-".self::FOLDER_NAME_TOKEN."-",
            "Required" => true,
            "ValidateFunction" => [$this, "validateDownloadFileNamePrefix"],
        ];

        $this->CfgSetup["DownloadImageFileNamePrefix"] = [
            "Label" => "Image File Name Prefix",
            "Type" => FormUI::FTYPE_TEXT,
            "Help" => "Prefix to use for image file names within downloaded"
                    ." Zip files.",
            "Default" => self::getDefaultDownloadImageFileNamePrefix(),
            "Required" => true,
            "ValidateFunction" => [$this, "validateDownloadImageFileNamePrefix"],
        ];

        $this->CfgSetup["FolderNameSegmentLength"] = [
            "Label" => "Folder Name Segment Length",
            "Type" => FormUI::FTYPE_NUMBER,
            "Help" => "Maximum length for normalized folder names when they"
                    ." are used in downloaded Zip file names via "
                    .self::FOLDER_NAME_TOKEN.".",
            "Default" => 12,
            "MinVal" => 1,
            "Units" => "characters",
        ];

        $this->CfgSetup["PhotoTitleSegmentLength"] = [
            "Label" => "Photo Title Segment Length",
            "Type" => FormUI::FTYPE_NUMBER,
            "Help" => "Maximum length for normalized photo titles when they"
                    ." are used in downloaded file names.",
            "Default" => 20,
            "MinVal" => 1,
            "Units" => "characters",
        ];

        $this->CfgSetup["DownloadZipLifetimeMinutes"] = [
            "Label" => "Zip Lifetime",
            "Type" => FormUI::FTYPE_NUMBER,
            "Help" => "Number of minutes to keep generated download Zip files.",
            "Default" => 120,
            "MinVal" => 1,
            "Units" => "minutes",
        ];
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return null|string NULL if installation succeeded, otherwise a string
     *      containing an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        # set up metadata schema
        $Result = $this->setUpSchema();
        if ($Result !== null) {
            return $Result;
        }

        return null;
    }

    /**
     * Perform any work needed when the plugin is uninstalled.
     * @return null|string NULL if uninstall succeeded, otherwise a string
     *      containing an error message indicating why uninstall failed.
     */
    public function uninstall(): ?string
    {
        # delete all records
        $RFactory = new RecordFactory($this->getConfigSetting("MetadataSchemaId"));
        $Ids = $RFactory->getItemIds();
        foreach ($Ids as $Id) {
            $Record = Record::getRecord($Id);
            $Record->destroy();
        }

        # delete our metadata schema
        $Schema = new MetadataSchema($this->getConfigSetting("MetadataSchemaId"));
        $Schema->delete();

        return null;
    }

    /**
     * Initialize the plugin.  This is called (if the plugin is enabled) after
     * all plugins have been loaded but before any methods for this plugin
     * (other than register()) have been called.
     * @return null|string NULL if initialization was successful, otherwise a
     *      string or array of strings containing error message(s) indicating
     *      why initialization failed.
     */
    public function initialize(): ?string
    {
        $PluginMgr = PluginManager::getInstance();

        # add "Add Photo" to available secondary nav items if appropriate
        if (User::getCurrentUser()->isLoggedIn()) {
            if ($PluginMgr->pluginReady("SecondaryNavigation")) {
                $Schema = new MetadataSchema($this->getConfigSetting("MetadataSchemaId"));
                $SecondaryNavPlugin = SecondaryNavigation::getInstance();
                $RequiredPrivs = new PrivilegeSet();
                $RequiredPrivs->addSubset($Schema->editingPrivileges());
                $RequiredPrivs->addSubset($Schema->authoringPrivileges());
                $RequiredPrivs->usesAndLogic(false);
                $SecondaryNavPlugin->offerNavItem(
                    "Add Photo",
                    "index.php?P=EditResource&ID=NEW&SC=".$Schema->id(),
                    $RequiredPrivs,
                    "Add new photo to library."
                );
            }
        }

        # register filter used for PhotoLibrary gallery bulk folder actions
        if ($PluginMgr->pluginReady("Folders")) {
            Folders::getInstance()->registerSearchActionResultsFilterFunction(
                [$this, "filterFolderSearchActionResults"]
            );
        }

        # update stored image file names when the fields they depend on change
        $this->registerImageFileNameUpdateObservers();

        # report successful initialization
        return null;
    }

    /**
     * Hook event callbacks into the application framework.
     * @return array Events to be hooked.
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_HTML_INSERTION_POINT" => "insertFolderDownloadButton",
            "EVENT_PLUGIN_CONFIG_CHANGE" => "handleConfigChange",
        ];
    }

    /**
     * React to plugin configuration changes.
     * @param string $PluginName Name of plugin that had a setting changed.
     * @param string $ConfigSetting Name of configuration setting that changed.
     * @param mixed $OldValue Previous setting value.
     * @param mixed $NewValue New setting value.
     * @return void
     */
    public function handleConfigChange(
        string $PluginName,
        string $ConfigSetting,
        $OldValue,
        $NewValue
    ): void {
        # ignore changes for other plugins
        if ($PluginName !== $this->Name) {
            return;
        }

        # identify settings that can change generated image file names
        $SettingsThatAffectFileNames = [
            "DownloadImageFileNamePrefix",
            "PhotoTitleSegmentLength",
        ];
        $UsingDefaultImagePrefix =
                $this->getConfigSetting("DownloadImageFileNamePrefix") === null;
        # skip unrelated settings unless the default prefix may depend on portal name
        if (!in_array($ConfigSetting, $SettingsThatAffectFileNames, true)
                && !$UsingDefaultImagePrefix) {
            return;
        }

        # avoid queueing duplicate recalculations during one config save
        if ($this->ImageFileNameUpdateAfterConfigChangeQueued) {
            return;
        }

        # refresh after config saving so final setting values are used
        ApplicationFramework::getInstance()->addPostProcessingCall(
            [$this, "updateImageFileNamesForAllRecords"]
        );
        $this->ImageFileNameUpdateAfterConfigChangeQueued = true;
    }

    /**
     * Validate values for the download file name prefix setting.
     * @param string $SettingName Name of configuration setting being validated.
     * @param string $Value Setting value to validate.
     * @return string|null Error message or NULL if no error found.
     */
    public function validateDownloadFileNamePrefix(
        string $SettingName,
        string $Value
    ): ?string {
        # remove supported replacement token before checking literal characters
        $LiteralValue = str_replace(self::FOLDER_NAME_TOKEN, "", $Value);

        if (preg_match('/^[A-Za-z0-9 _.-]*$/', $LiteralValue) !== 1) {
            return "The prefix may only contain letters, numbers, spaces,"
                    ." underscores, hyphens, periods, and "
                    .self::FOLDER_NAME_TOKEN.".";
        }

        return null;
    }

    /**
     * Validate values for the download image file name prefix setting.
     * @param string $SettingName Name of configuration setting being validated.
     * @param string $Value Setting value to validate.
     * @return string|null Error message or NULL if no error found.
     */
    public function validateDownloadImageFileNamePrefix(
        string $SettingName,
        string $Value
    ): ?string {
        if (strpos($Value, self::FOLDER_NAME_TOKEN) !== false) {
            return "The image file name prefix may not contain "
                    .self::FOLDER_NAME_TOKEN.".";
        }

        if (preg_match('/^[A-Za-z0-9 _.-]*$/', $Value) !== 1) {
            return "The prefix may only contain letters, numbers, spaces,"
                    ." underscores, hyphens, and periods.";
        }

        return null;
    }

    /**
     * Validate values for the photo display page info field list setting.
     * @param string $SettingName Name of configuration setting being validated
     * @param string $Value Setting value to validate.
     * @return string|null Error message or NULL if no error found.
     */
    public function validatePhotoDisplayFieldList(
        string $SettingName,
        string $Value
    ): ?string {
        $ErrMsgs = [];
        $Schema = new MetadataSchema($this->getConfigSetting("MetadataSchemaId"));

        # split setting into lines
        $Lines = preg_split('/\r\n|\r|\n/', $Value);

        # report no errors if setting was empty
        if ($Lines === false) {
            return null;
        }

        # for each line in setting
        foreach ($Lines as $Line) {
            # if line appears to contain field
            $Line = trim($Line);
            if (strlen($Line) !== 0) {
                # if field does not exist in our schema
                if (!$Schema->fieldExists($Line)) {
                    # add error message for field
                    $ErrMsgs[] = "Unknown metadata field \"".htmlspecialchars($Line)."\".";
                }
            }
        }

        # report any errors to caller
        return count($ErrMsgs) !== 0 ? join("\n", $ErrMsgs) : null;
    }

    /**
     * Generate a list (2D array) of groups of metadata field names, based on
     * the current photo display page info field list setting.
     * @return array Array of arrays, with the top level being metadata field
     *      groups and the second level being metadata field names.
     */
    public function getPhotoDisplayFields(): array
    {
        # if it appears that photo display field setting has not changed
        $FieldSetting = $this->getConfigSetting("PhotoDisplayFieldList") ?? "";
        $Checksum = md5($FieldSetting);
        if ($Checksum == $this->getConfigSetting("PhotoDisplayFieldChecksum")) {
            # return cached value
            return $this->getConfigSetting("PhotoDisplayFieldGroups");
        }

        # split setting into lines
        $Lines = preg_split('/\r\n|\r|\n/', $FieldSetting);

        # return empty field group list if setting was empty
        if ($Lines === false) {
            return [];
        }

        # for each line in setting
        $FieldGroups = [];
        foreach ($Lines as $Line) {
            # if line is blank
            $Line = trim($Line);
            if (strlen($Line) === 0) {
                # if we have a current group with fields
                if (count($CurrentGroup ?? []) !== 0) {
                    # add group to field groups
                    $FieldGroups[] = $CurrentGroup;

                    # clear current group
                    $CurrentGroup = [];
                }
            # else line contains field
            } else {
                # add field to current group
                $CurrentGroup[] = $Line;
            }
        }

        # if we have a current group with fields
        if (count($CurrentGroup ?? []) !== 0) {
            # add group to field groups
            $FieldGroups[] = $CurrentGroup;
        }

        # save generated groups and checksum for current setting
        $this->setConfigSetting("PhotoDisplayFieldGroups", $FieldGroups);
        $this->setConfigSetting("PhotoDisplayFieldChecksum", $Checksum);

        # return generated groups to caller
        return $FieldGroups;
    }

    /**
     * Filter record IDs down to PhotoLibrary records with displayable screenshots.
     * @param array $RecordIds Record IDs to filter.
     * @param User $User User to use for screenshot field visibility checks.
     * @return array Record IDs for records with screenshots available to display.
     */
    public function filterOutRecordsWithoutDisplayableImages(
        array $RecordIds,
        User $User
    ): array {
        $DisplayableRecordIds = [];
        $SchemaId = (int)$this->getConfigSetting("MetadataSchemaId");

        foreach ($RecordIds as $RecordId) {
            $RecordId = (int)$RecordId;
            if (!Record::itemExists($RecordId)) {
                continue;
            }

            $Record = Record::getRecord($RecordId);
            if ($Record->getSchemaId() !== $SchemaId
                    || !$Record->userCanViewMappedField($User, "Screenshot")) {
                continue;
            }

            $Screenshots = $Record->getMapped("Screenshot", true);
            if (is_array($Screenshots) && count($Screenshots) !== 0) {
                $DisplayableRecordIds[] = $RecordId;
            }
        }

        return $DisplayableRecordIds;
    }

    /**
     * Filter Folders bulk search-action results for PhotoLibrary gallery actions.
     * @param array $SearchResults Search results keyed by schema ID, then item ID.
     * @param SearchParameterSet $SearchParams Search parameters used for the action.
     * @param string $Action Action being performed, either "add" or "remove".
     * @param User $User User performing the action.
     * @param array $RequestParameters Request parameters for the action.
     * @return array Filtered search results.
     */
    public function filterFolderSearchActionResults(
        array $SearchResults,
        SearchParameterSet $SearchParams,
        string $Action,
        User $User,
        array $RequestParameters
    ): array {
        if (($RequestParameters[self::SEARCH_ACTION_RESULTS_FILTER_PARAMETER] ?? null)
                !== self::SEARCH_ACTION_RESULTS_FILTER_DISPLAY_GALLERY) {
            return $SearchResults;
        }

        $SchemaId = (int)$this->getConfigSetting("MetadataSchemaId");
        if (!isset($SearchResults[$SchemaId])) {
            return [];
        }

        # keep only displayable PhotoLibrary records for gallery bulk actions
        $DisplayableRecordIds = $this->filterOutRecordsWithoutDisplayableImages(
            array_keys($SearchResults[$SchemaId]),
            $User
        );
        $FilteredResults = array_intersect_key(
            $SearchResults[$SchemaId],
            array_flip($DisplayableRecordIds)
        );

        return count($FilteredResults) !== 0 ? [$SchemaId => $FilteredResults] : [];
    }

    /**
     * Get HTML for DisplayGallery bulk folder action buttons.
     * @param SearchParameterSet $SearchParams Search parameters used by the gallery.
     * @param array $RecordIds All displayable record IDs matching gallery filters.
     * @param User $User User viewing the gallery.
     * @return string HTML for folder action buttons, or an empty string if no
     *      buttons should be displayed.
     * @throws Exception If button HTML cannot be generated.
     */
    public function getDisplayGalleryFolderButtonsHtml(
        SearchParameterSet $SearchParams,
        array $RecordIds,
        User $User
    ): string {
        if (!$User->isLoggedIn()
                || $SearchParams->parameterCount() === 0
                || count($RecordIds) === 0
                || !PluginManager::getInstance()->pluginReady("Folders")) {
            return "";
        }

        $Folder = Folders::getInstance()->getSelectedFolder();
        if ($Folder === null) {
            return "";
        }

        # determine which bulk actions are applicable for the selected folder
        $ItemsInFolder = false;
        $ItemsOutsideFolder = false;
        foreach ($RecordIds as $RecordId) {
            if ($Folder->containsItem((int)$RecordId)) {
                $ItemsInFolder = true;
            } else {
                $ItemsOutsideFolder = true;
            }

            if ($ItemsInFolder && $ItemsOutsideFolder) {
                break;
            }
        }

        if (!$ItemsInFolder && !$ItemsOutsideFolder) {
            return "";
        }

        # include filter token so Folders limits the action to displayable photos
        $SearchParamsForUrl = $SearchParams->urlParameterString();
        $FilterParam = http_build_query([
            self::SEARCH_ACTION_RESULTS_FILTER_PARAMETER
                => self::SEARCH_ACTION_RESULTS_FILTER_DISPLAY_GALLERY,
        ]);
        $SearchParamsForUrl .= ($SearchParamsForUrl !== "" ? "&" : "").$FilterParam;

        $AddButton = new HtmlButton("Add All to Folder");
        $AddButton->setIcon("FolderPlus.svg");
        $AddButton->setSize(HtmlButton::SIZE_SMALL);
        $AddButton->addClass("mv-folders-addallsearch mv-button-iconed");
        $AddButton->setTitle(
            "Add the results of this search to your current folder."
        );
        $AddButton->addAttributes([
            "data-buttonclasses" => "btn btn-primary btn-sm",
            "data-searchparams" => $SearchParamsForUrl,
            "data-action" => "add",
        ]);
        $AddButton->setOnclick("Folders.handleSearchResultsActionButtonClick()");
        if (!$ItemsOutsideFolder) {
            $AddButton->hide();
        }

        $RemoveButton = new HtmlButton("Remove All from Folder");
        $RemoveButton->setIcon("FolderMinus.svg");
        $RemoveButton->setSize(HtmlButton::SIZE_SMALL);
        $RemoveButton->addClass("mv-folders-removeallsearch");
        $RemoveButton->setTitle(
            "Remove the results of this search from your current folder."
        );
        $RemoveButton->addAttributes([
            "data-buttonclasses" => "btn btn-primary btn-sm mv-folders-removeallsearch",
            "data-searchparams" => $SearchParamsForUrl,
            "data-action" => "remove",
        ]);
        $RemoveButton->setOnclick("Folders.handleSearchResultsActionButtonClick()");
        if (!$ItemsInFolder) {
            $RemoveButton->hide();
        }

        return $AddButton->getHtml()." ".$RemoveButton->getHtml();
    }

    /**
     * Create a Zip file of original images from PhotoLibrary records in a folder.
     * @param int $FolderId ID of folder containing records with images.
     * @return string|null Relative path to generated Zip file or NULL if no
     *      suitable images were found.
     * @throws Exception If Folders is not available, ZipArchive is not
     *      available, or the Zip file cannot be created.
     * @throws InvalidArgumentException If the supplied folder ID is invalid.
     */
    public function prepareZipFileWithImagesFromFolder(int $FolderId): ?string
    {
        if (!PluginManager::getInstance()->pluginReady("Folders")) {
            throw new Exception("The Folders plugin is not available.");
        }
        if (!class_exists("\\ZipArchive")) {
            throw new Exception("ZipArchive is not available.");
        }
        if (!Folder::itemExists($FolderId)) {
            throw new InvalidArgumentException("Invalid folder ID (".$FolderId.").");
        }

        $Folder = new Folder($FolderId);
        $ZipFileNamePrefix = $this->getDownloadFileNamePrefix(
            $Folder,
            "DownloadZipFileNamePrefix"
        );
        $ImageFileNamePrefix = $this->getDownloadImageFileNamePrefix();
        $ZipEntries = $this->getZipEntriesForFolder($Folder, $ImageFileNamePrefix);

        if (count($ZipEntries) === 0) {
            return null;
        }

        # create Zip file and add all selected original image files
        $RelativeZipPath = $this->getUniqueZipFilePath($ZipFileNamePrefix);
        $FullZipPath = dirname(__DIR__, 2)."/".$RelativeZipPath;
        $Zip = new ZipArchive();
        $OpenResult = $Zip->open($FullZipPath, ZipArchive::CREATE | ZipArchive::EXCL);
        if ($OpenResult !== true) {
            throw new Exception("Unable to create Zip file at ".$RelativeZipPath.".");
        }

        foreach ($ZipEntries as $Entry) {
            if (!$this->addEntryToZipFile($Zip, $Entry)) {
                $Zip->close();
                if (is_file($FullZipPath)) {
                    unlink($FullZipPath);
                }
                throw new Exception(
                    "Unable to add image file to Zip: ".$Entry["SourcePath"]."."
                );
            }
        }

        if (!$Zip->close()) {
            if (is_file($FullZipPath)) {
                unlink($FullZipPath);
            }
            throw new Exception("Unable to close Zip file at ".$RelativeZipPath.".");
        }

        # queue cleanup task for generated Zip file
        $LifetimeSetting = $this->getConfigSetting("DownloadZipLifetimeMinutes");
        $Lifetime = ($LifetimeSetting !== null) ? (int)$LifetimeSetting : 120;
        $RunAt = time() + (max($Lifetime, 1) * 60);
        ApplicationFramework::getInstance()->queueTaskToRunAt(
            [self::class, "deleteGeneratedZipFile"],
            $RunAt,
            [$RelativeZipPath],
            ApplicationFramework::PRIORITY_BACKGROUND,
            "Delete generated PhotoLibrary image Zip file."
        );

        return $RelativeZipPath;
    }

    /**
     * Delete a generated Zip file from the tmp directory.
     * @param string $RelativePath Relative path to the Zip file.
     * @return void
     */
    public static function deleteGeneratedZipFile(string $RelativePath): void
    {
        $RootDir = dirname(__DIR__, 2);
        $TmpDir = realpath($RootDir."/tmp");
        $TargetPath = $RootDir."/".$RelativePath;
        $TargetDir = realpath(dirname($TargetPath));

        # only delete files that are directly within the application tmp directory
        if ($TmpDir !== false
                && $TargetDir === $TmpDir
                && is_file($TargetPath)) {
            unlink($TargetPath);
        }
    }

    /**
     * Insert a Download button into the Folders ViewFolder page.
     * @param string $PageName Name of page that signaled the insertion point.
     * @param string $Location Location on page for the insertion point.
     * @param array|null $Context Context supplied by the insertion point.
     * @return void
     * @throws Exception If button HTML cannot be generated.
     */
    public function insertFolderDownloadButton(
        string $PageName,
        string $Location,
        ?array $Context = null
    ): void {
        if ($PageName !== "P_Folders_ViewFolder"
                || $Location !== "Folder Buttons"
                || $Context === null
                || !isset($Context["FolderId"])
                || !PluginManager::getInstance()->pluginReady("Folders")
                || !class_exists("\\ZipArchive")) {
            return;
        }

        $FolderId = (int)$Context["FolderId"];
        if (!Folder::itemExists($FolderId)) {
            return;
        }

        $Folder = new Folder($FolderId);
        if (!$this->folderHasViewableImages($Folder, User::getCurrentUser())) {
            return;
        }

        # print the download button for folders with viewable PhotoLibrary images
        $Button = new HtmlButton("Download");
        $Button->setIcon("Download.svg");
        $Button->setSize(HtmlButton::SIZE_SMALL);
        $Button->setLink(
            "index.php?P=P_PhotoLibrary_DownloadFolderImages&FolderId=".$FolderId
        );
        print " ".$Button->getHtml();
    }

    /**
     * Determine if a folder contains any PhotoLibrary images viewable by a user.
     * @param Folder $Folder Folder to examine.
     * @param User $User User for visibility checks.
     * @return bool TRUE if at least one viewable image is present.
     */
    public function folderHasViewableImages(Folder $Folder, User $User): bool
    {
        $SchemaId = (int)$this->getConfigSetting("MetadataSchemaId");

        # check all valid PhotoLibrary records in the folder for visible images
        foreach ($this->getRecordIdsFromFolder($Folder) as $RecordId) {
            if (!Record::itemExists($RecordId)) {
                continue;
            }

            $Record = Record::getRecord($RecordId);
            if ($Record->getSchemaId() !== $SchemaId
                    || !$Record->userCanView($User)
                    || !$Record->userCanViewMappedField($User, "Screenshot")) {
                continue;
            }

            $Images = $Record->getMapped("Screenshot", true);
            if (!is_array($Images) || count($Images) === 0) {
                continue;
            }

            foreach ($Images as $Image) {
                if ($Image instanceof Image
                        && $this->imageSourceIsAvailable(
                            $Image->getFullPathForOriginalImage()
                        )) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the downloaded file name for a PhotoLibrary image.
     * @param Record $Record Record containing the image.
     * @param Image $Image Image being downloaded.
     * @return string File name to use for downloads.
     * @throws Exception If image format cannot be mapped to a file extension.
     */
    public function getDownloadFileNameForImage(Record $Record, Image $Image): string
    {
        return $this->getDownloadFileNameForImageWithTitleVisibility(
            $Record,
            $Image,
            false
        );
    }

    /**
     * Download an original PhotoLibrary image.
     * @param Record $Record Record containing the image.
     * @param Image $Image Image being downloaded.
     * @return bool TRUE if the download was queued successfully.
     * @throws Exception If image format cannot be mapped to a file extension.
     */
    public function downloadOriginalImage(Record $Record, Image $Image): bool
    {
        $SourcePath = $Image->getFullPathForOriginalImage();
        $DownloadFileName = $this->getDownloadFileNameForImage($Record, $Image);
        $AF = ApplicationFramework::getInstance();

        # use the standard streaming helper for local original image files
        if (!$this->pathIsUrl($SourcePath)) {
            return $AF->downloadFile($SourcePath, $DownloadFileName, null, true);
        }

        # fetch URL-backed image data before sending headers so failures can be reported
        $ImageData = @file_get_contents($SourcePath);
        if ($ImageData === false) {
            return false;
        }

        # queue the fetched image for output with the expected download file name
        header("Content-Type: ".$this->getMimeTypeForImage($Image));
        header('Content-Disposition: attachment; filename="'.$DownloadFileName.'"');
        $AF->addUnbufferedCallback(function ($Data): void {
            session_write_close();
            header("Content-Length: ".strlen($Data));
            print $Data;
            flush();
        }, [$ImageData]);
        $AF->suppressHtmlOutput();

        return true;
    }

    /**
     * Get the image used by the original-image download button.
     * @param Record $Record Record containing image metadata.
     * @return Image|null Image used by the download button, or NULL if none exists.
     */
    public function getOriginalDownloadImageForRecord(Record $Record): ?Image
    {
        $Images = $Record->getMapped("Screenshot", true);
        if (!is_array($Images) || count($Images) === 0) {
            return null;
        }

        $Image = array_pop($Images);

        return $Image instanceof Image ? $Image : null;
    }

    /**
     * Update stored image file name for a PhotoLibrary record.
     * @param Record $Record Record to update.
     * @return bool TRUE if field value was changed.
     */
    public function updateImageFileNameForRecord(Record $Record): bool
    {
        # only PhotoLibrary records can use our generated file name field
        if ($Record->getSchemaId() !== $this->getSchemaId()) {
            return false;
        }

        # skip updates before the field has been added to older installations
        $Schema = new MetadataSchema($this->getSchemaId());
        if (!$Schema->fieldExists(self::IMAGE_FILE_NAME_FIELD_NAME)) {
            return false;
        }

        # regenerate from current metadata so the field remains a cache
        $ImageFileNameField = $Schema->getField(self::IMAGE_FILE_NAME_FIELD_NAME);
        $NewValue = $this->getStoredImageFileNameForRecord($Record, $ImageFileNameField);

        return $Record->set($ImageFileNameField, $NewValue);
    }

    /**
     * Update stored image file names for all PhotoLibrary records.
     * @return void
     */
    public function updateImageFileNamesForAllRecords(): void
    {
        # skip bulk updates before the field exists
        $Schema = new MetadataSchema($this->getSchemaId());
        if (!$Schema->fieldExists(self::IMAGE_FILE_NAME_FIELD_NAME)) {
            return;
        }

        # reuse one field object while recalculating every PhotoLibrary record
        $ImageFileNameField = $Schema->getField(self::IMAGE_FILE_NAME_FIELD_NAME);
        $RFactory = new RecordFactory($this->getSchemaId());
        foreach ($RFactory->getItemIds() as $RecordId) {
            $Record = Record::getRecord((int)$RecordId);
            try {
                # continue through the batch even if one record cannot be updated
                $this->updateImageFileNameForRecord($Record);
            } catch (Exception $Exception) {
                $this->logImageFileNameUpdateFailure($Record, $Exception);
                $this->clearImageFileNameField($Record, $ImageFileNameField);
            }
        }
    }

    /**
     * Get the default prefix to use for downloaded image file names.
     * @return string Default image file name prefix.
     */
    public static function getDefaultDownloadImageFileNamePrefix(): string
    {
        $PortalName = InterfaceConfiguration::getInstance()->getString("PortalName");
        $NormalizedPortalName = self::normalizeFileNameSegment($PortalName);

        return strlen($NormalizedPortalName) !== 0
                ? $NormalizedPortalName."-" : "Photo-";
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private const FOLDER_NAME_TOKEN = "%FOLDERNAME%";
    private const SEARCH_ACTION_RESULTS_FILTER_DISPLAY_GALLERY =
            "PhotoLibraryDisplayGallery";
    private const SEARCH_ACTION_RESULTS_FILTER_PARAMETER = "SearchActionResultsFilter";

    private $ImageFileNameUpdateAfterConfigChangeQueued = false;

    /**
     * Register observers that keep stored image file names up to date.
     * @return void
     */
    private function registerImageFileNameUpdateObservers(): void
    {
        $Schema = new MetadataSchema($this->getSchemaId());
        if ($Schema->fieldExists(self::IMAGE_FILE_NAME_FIELD_NAME)) {
            Record::registerObserver(
                Record::EVENT_CREATE,
                [$this, "handleRecordAdded"]
            );
        }

        foreach (["Title", "Screenshot"] as $MappedFieldName) {
            $Field = $Schema->getFieldByMappedName($MappedFieldName);
            if (!($Field instanceof MetadataField)) {
                continue;
            }

            MetadataField::registerObserver(
                MetadataField::EVENT_SET | MetadataField::EVENT_CLEAR,
                [$this, "handleFilenameMetadataFieldChange"],
                $Field->id()
            );
        }
    }

    /**
     * React to changes in fields that affect generated image file names.
     * @param int $Event Field observer event.
     * @param Record $Record Record whose field changed.
     * @param MetadataField $Field Metadata field that changed.
     * @param array $Values Values removed or assigned.
     * @return void
     */
    public function handleFilenameMetadataFieldChange(
        int $Event,
        Record $Record,
        MetadataField $Field,
        array $Values
    ): void {
        if ($Record->getSchemaId() !== $this->getSchemaId()) {
            return;
        }

        # recompute the stored filename after title or photo changes
        try {
            $this->updateImageFileNameForRecord($Record);
        } catch (Exception $Exception) {
            $this->logImageFileNameUpdateFailure($Record, $Exception);
        }
    }

    /**
     * React to record creation and temp-to-permanent ID changes.
     * @param int $Event Record observer event.
     * @param Record $Record Record that changed.
     * @return void
     */
    public function handleRecordAdded(int $Event, Record $Record): void
    {
        try {
            $this->updateImageFileNameForRecord($Record);
        } catch (Exception $Exception) {
            $this->logImageFileNameUpdateFailure($Record, $Exception);
        }
    }

    /**
     * Get configured download file name prefix with folder name token resolved.
     * @param Folder $Folder Folder containing records to be downloaded.
     * @param string $SettingName Name of setting that contains prefix.
     * @return string Download file name prefix.
     */
    private function getDownloadFileNamePrefix(
        Folder $Folder,
        string $SettingName
    ): string {
        $Prefix = (string)($this->getConfigSetting($SettingName) ?? "PhotoLibrary-");
        $FolderName = $this->normalizeNameForDownload(
            $Folder->name(),
            (int)($this->getConfigSetting("FolderNameSegmentLength") ?? 1)
        );

        return str_replace(self::FOLDER_NAME_TOKEN, $FolderName, $Prefix);
    }

    /**
     * Get configured image file name prefix for downloads.
     * @return string Image file name prefix.
     */
    private function getDownloadImageFileNamePrefix(): string
    {
        return (string)(
            $this->getConfigSetting("DownloadImageFileNamePrefix")
            ?? self::getDefaultDownloadImageFileNamePrefix()
        );
    }

    /**
     * Get the stored file name for a PhotoLibrary record.
     * @param Record $Record Record containing image metadata.
     * @param MetadataField $Field Field that will store the generated file name.
     * @return string File name to store, or an empty string when no image exists.
     */
    private function getStoredImageFileNameForRecord(
        Record $Record,
        MetadataField $Field
    ): string {
        $Image = $this->getOriginalDownloadImageForRecord($Record);
        if ($Image === null) {
            return "";
        }

        $FileName = $this->getDownloadFileNameForImageWithTitleVisibility(
            $Record,
            $Image,
            true
        );

        return substr($FileName, 0, $Field->maxLength());
    }

    /**
     * Clear stored image file name for a record.
     * @param Record $Record Record to update.
     * @param MetadataField $ImageFileNameField Field to clear.
     * @return void
     */
    private function clearImageFileNameField(
        Record $Record,
        MetadataField $ImageFileNameField
    ): void {
        try {
            $Record->set($ImageFileNameField, "");
        } catch (Exception $Exception) {
            ApplicationFramework::getInstance()->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "Unable to clear PhotoLibrary Image File Name for record ID "
                .$Record->id().": ".$Exception->getMessage()
            );
        }
    }

    /**
     * Log that generated image file name update failed for a record.
     * @param Record $Record Record being updated.
     * @param Exception $Exception Exception that prevented update.
     * @return void
     */
    private function logImageFileNameUpdateFailure(
        Record $Record,
        Exception $Exception
    ): void {
        ApplicationFramework::getInstance()->logMessage(
            ApplicationFramework::LOGLVL_ERROR,
            "Unable to update PhotoLibrary Image File Name for record ID "
            .$Record->id().": ".$Exception->getMessage()
        );
    }

    /**
     * Generate Zip entries for all suitable images in a folder.
     * @param Folder $Folder Folder containing records with images.
     * @param string $FileNamePrefix Prefix to use for image file names.
     * @return array Zip entry info, with source paths in the "SourcePath"
     *      index and archive names in the "ArchiveName" index.
     * @throws Exception If image format cannot be mapped to a file extension.
     */
    private function getZipEntriesForFolder(Folder $Folder, string $FileNamePrefix): array
    {
        $Entries = [];
        $SchemaId = (int)$this->getConfigSetting("MetadataSchemaId");
        $User = User::getCurrentUser();

        # gather candidate image files from all PhotoLibrary records in the folder
        foreach ($this->getRecordIdsFromFolder($Folder) as $RecordId) {
            if (!Record::itemExists($RecordId)) {
                continue;
            }

            $Record = Record::getRecord($RecordId);
            if ($Record->getSchemaId() !== $SchemaId
                    || !$Record->userCanView($User)
                    || !$Record->userCanViewMappedField($User, "Screenshot")) {
                continue;
            }

            $RecordEntries = $this->getZipEntriesForRecord(
                $Record,
                $FileNamePrefix
            );
            $Entries = array_merge($Entries, $RecordEntries);
        }

        return $Entries;
    }

    /**
     * Get record IDs from a folder, regardless of folder item type.
     * @param Folder $Folder Folder to examine.
     * @return array Record IDs found in folder contents.
     */
    private function getRecordIdsFromFolder(Folder $Folder): array
    {
        $RecordIds = [];

        # normalize mixed-folder entries and regular entries to plain IDs
        foreach ($Folder->getItemIds() as $Item) {
            if (is_array($Item)) {
                if (isset($Item["ID"]) && is_numeric($Item["ID"])) {
                    $RecordIds[] = (int)$Item["ID"];
                }
            } elseif (is_numeric($Item)) {
                $RecordIds[] = (int)$Item;
            }
        }

        return $RecordIds;
    }

    /**
     * Generate Zip entries for suitable images in a record.
     * @param Record $Record Record to examine.
     * @param string $FileNamePrefix Prefix to use for image file names.
     * @return array Zip entry info, with source paths in the "SourcePath"
     *      index and archive names in the "ArchiveName" index.
     * @throws Exception If image format cannot be mapped to a file extension.
     */
    private function getZipEntriesForRecord(Record $Record, string $FileNamePrefix): array
    {
        $Entries = [];
        $Images = $Record->getMapped("Screenshot", true);
        if (!is_array($Images) || count($Images) === 0) {
            return [];
        }

        # collect images that have an available original file
        $AvailableImages = [];
        foreach ($Images as $Image) {
            if (!$Image instanceof Image) {
                continue;
            }

            $OriginalPath = $Image->getFullPathForOriginalImage();
            if ($this->imageSourceIsAvailable($OriginalPath)) {
                $AvailableImages[] = [
                    "Image" => $Image,
                    "SourcePath" => $OriginalPath,
                ];
            }
        }

        if (count($AvailableImages) === 0) {
            return [];
        }

        foreach ($AvailableImages as $ImageInfo) {
            $Image = $ImageInfo["Image"];
            $BaseName = $this->getDownloadBaseNameForImage(
                $Record,
                $Image,
                $FileNamePrefix
            );
            $Extension = ImageFile::extensionForFormat($ImageInfo["Image"]->format());
            $Entries[] = [
                "ArchiveName" => $BaseName.".".$Extension,
                "SourcePath" => $ImageInfo["SourcePath"],
            ];
        }

        return $Entries;
    }

    /**
     * Get the downloaded file base name for a PhotoLibrary image.
     * @param Record $Record Record containing the image.
     * @param Image $Image Image being downloaded.
     * @param string $FileNamePrefix Prefix to use for image file names.
     * @return string Base file name to use for downloads, without extension.
     */
    private function getDownloadBaseNameForImage(
        Record $Record,
        Image $Image,
        string $FileNamePrefix
    ): string {
        return $this->getDownloadBaseNameForImageWithTitleVisibility(
            $Record,
            $Image,
            $FileNamePrefix,
            false
        );
    }

    /**
     * Get the downloaded file name for an image, with title visibility control.
     * @param Record $Record Record containing the image.
     * @param Image $Image Image being downloaded.
     * @param bool $AssumeTitleIsViewable TRUE to include the title segment
     *      without checking current user permissions.
     * @return string File name to use for downloads.
     * @throws Exception If image format cannot be mapped to a file extension.
     */
    private function getDownloadFileNameForImageWithTitleVisibility(
        Record $Record,
        Image $Image,
        bool $AssumeTitleIsViewable
    ): string {
        $FileNamePrefix = $this->getDownloadImageFileNamePrefix();
        $ArchiveBaseName = $this->getDownloadBaseNameForImageWithTitleVisibility(
            $Record,
            $Image,
            $FileNamePrefix,
            $AssumeTitleIsViewable
        );
        $Extension = ImageFile::extensionForFormat($Image->format());

        return $ArchiveBaseName.".".$Extension;
    }

    /**
     * Get downloaded image base name, with title visibility control.
     * @param Record $Record Record containing the image.
     * @param Image $Image Image being downloaded.
     * @param string $FileNamePrefix Prefix to use for image file names.
     * @param bool $AssumeTitleIsViewable TRUE to include the title segment
     *      without checking current user permissions.
     * @return string Base file name to use for downloads, without extension.
     */
    private function getDownloadBaseNameForImageWithTitleVisibility(
        Record $Record,
        Image $Image,
        string $FileNamePrefix,
        bool $AssumeTitleIsViewable
    ): string {
        # include title segment when allowed or when storing generated metadata
        $User = User::getCurrentUser();
        $Title = $Record->getMapped("Title");
        $TitleIsAvailable = $AssumeTitleIsViewable
                || $Record->userCanViewMappedField($User, "Title");
        $TitlePart = ($TitleIsAvailable && is_scalar($Title))
                ? $this->normalizeNameForDownload(
                    (string)$Title,
                    (int)($this->getConfigSetting("PhotoTitleSegmentLength") ?? 1)
                )
                : "";

        $BaseName = $FileNamePrefix.$Record->id();
        if (strlen($TitlePart) !== 0) {
            $BaseName .= "-".$TitlePart;
        }

        return $BaseName."-".$Image->id();
    }

    /**
     * Normalize text for use as part of a downloaded file name.
     * @param string $Name Text to normalize.
     * @param int $MaxLength Maximum length of normalized text.
     * @return string Normalized text.
     */
    private function normalizeNameForDownload(string $Name, int $MaxLength): string
    {
        $NormalizedName = self::normalizeFileNameSegment($Name);

        # trim separators that may be left at the end after truncation
        return rtrim(substr($NormalizedName, 0, $MaxLength), "_");
    }

    /**
     * Normalize text for use as one segment of a downloaded file name by
     * removing non-alphanumeric characters except spaces, trimming leading
     * and trailing whitespace, replacing space runs with underscores, and
     * trimming trailing underscores.
     * @param string $Name Text to normalize.
     * @return string Normalized text.
     */
    private static function normalizeFileNameSegment(string $Name): string
    {
        $NormalizedName = preg_replace("/[^A-Za-z0-9 ]/", "", $Name);
        if ($NormalizedName === null) {
            return "";
        }

        $NormalizedName = trim($NormalizedName);
        $NormalizedName = preg_replace("/ +/", "_", $NormalizedName);
        if ($NormalizedName === null) {
            return "";
        }

        return rtrim($NormalizedName, "_");
    }

    /**
     * Add an image entry to a Zip archive.
     * @param ZipArchive $Zip Zip archive to add the entry to.
     * @param array $Entry Entry info, with source path and archive name.
     * @return bool TRUE if the entry was added successfully.
     */
    private function addEntryToZipFile(ZipArchive $Zip, array $Entry): bool
    {
        # add local files directly so large images are streamed by ZipArchive
        if (!$this->pathIsUrl($Entry["SourcePath"])) {
            return $Zip->addFile($Entry["SourcePath"], $Entry["ArchiveName"]);
        }

        # fetch fallback URL image data and add it as archive contents
        $ImageData = @file_get_contents($Entry["SourcePath"]);
        if ($ImageData === false) {
            return false;
        }

        return $Zip->addFromString($Entry["ArchiveName"], $ImageData);
    }

    /**
     * Determine if an image source can be used for Zip generation.
     * @param string $SourcePath Path or URL for the image source.
     * @return bool TRUE if the source appears available.
     */
    private function imageSourceIsAvailable(string $SourcePath): bool
    {
        if ($this->pathIsUrl($SourcePath)) {
            return true;
        }

        return is_readable($SourcePath);
    }

    /**
     * Get the MIME type to use when streaming an image.
     * @param Image $Image Image to examine.
     * @return string MIME type for the image.
     */
    private function getMimeTypeForImage(Image $Image): string
    {
        switch ($Image->format()) {
            case ImageFile::IMGTYPE_JPEG:
                return "image/jpeg";

            case ImageFile::IMGTYPE_GIF:
                return "image/gif";

            case ImageFile::IMGTYPE_BMP:
                return "image/bmp";

            case ImageFile::IMGTYPE_PNG:
                return "image/png";

            case ImageFile::IMGTYPE_SVG:
                return "image/svg+xml";

            default:
                return "application/octet-stream";
        }
    }

    /**
     * Determine if a path is an HTTP(S) URL.
     * @param string $Path Path to examine.
     * @return bool TRUE if the path is an HTTP(S) URL.
     */
    private function pathIsUrl(string $Path): bool
    {
        $Scheme = parse_url($Path, PHP_URL_SCHEME);

        return $Scheme === "http" || $Scheme === "https";
    }

    /**
     * Get a unique relative path for a new Zip file in the tmp directory.
     * @param string $FileNamePrefix Prefix to use for the Zip file name.
     * @return string Relative path for the Zip file.
     * @throws Exception If the tmp directory is not available.
     */
    private function getUniqueZipFilePath(string $FileNamePrefix): string
    {
        $RootDir = dirname(__DIR__, 2);
        $TmpDir = realpath($RootDir."/tmp");
        if ($TmpDir === false) {
            throw new Exception("Temporary directory is not available.");
        }

        # append a short numeric suffix when the generated name already exists
        $BaseName = $FileNamePrefix.date("ymd_His");
        $Suffix = "";
        $Counter = 1;
        do {
            $RelativePath = "tmp/".$BaseName.$Suffix.".zip";
            $FullPath = $RootDir."/".$RelativePath;
            $Suffix = "-".$Counter;
            $Counter++;
        } while (file_exists($FullPath));

        return $RelativePath;
    }

    /**
     * Set up our metadata schema.
     * @return null|string NULL upon success, or error string upon failure.
     */
    private function setUpSchema(): ?string
    {
        # setup the default privileges for authoring and editing
        $AuthorPrivs = new PrivilegeSet();
        $AuthorPrivs->addPrivilege(PRIV_COLLECTIONADMIN);
        $EditPrivs = new PrivilegeSet();
        $EditPrivs->addPrivilege(PRIV_COLLECTIONADMIN);

        # create a new metadata schema and save its ID
        $Schema = MetadataSchema::create("Photos", $AuthorPrivs, $EditPrivs);
        $Schema->setViewPage(self::VIEW_PAGE_LINK);
        $Schema->setEditPage(self::EDIT_PAGE_LINK);
        $Schema->setOwnerToPlugin($this);
        $this->setConfigSetting("MetadataSchemaId", $Schema->id());

        # load fields into schema
        $Result = $this->loadSchemaFieldsFromFile($Schema);

        # if field loading failed
        if ($Result !== null) {
            # clear out new schema
            $Schema->delete();
        }

        # report result to caller
        return $Result;
    }

    /**
     * Get the schema ID associated with the blog entry metadata schema.
     * @return int Returns the schema ID of the blog entry metadata schema.
     */
    public function getSchemaId(): int
    {
        return $this::getConfigSetting("MetadataSchemaId");
    }

    /**
     * Load (or update) our metadata fields from an XML file.
     * @param MetadataSchema $Schema Schema to load fields into.
     * @return null|string NULL upon success, or error string upon failure.
     */
    private function loadSchemaFieldsFromFile(MetadataSchema $Schema): ?string
    {
        $SchemaFile = __DIR__."/install/MetadataSchema--".static::getBaseName().".xml";
        if ($Schema->addFieldsFromXmlFile($SchemaFile, $this->Name) == false) {
            return "Error Loading Metadata Fields from XML: ".implode(
                ", ",
                $Schema->errorMessages("addFieldsFromXmlFile")
            );
        }
        return null;
    }
}
