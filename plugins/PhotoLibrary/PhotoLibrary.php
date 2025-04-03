<?PHP
#
#   FILE:  PhotoLibrary.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\FormUI;
use Metavus\Image;
use Metavus\MetadataSchema;
use Metavus\Plugins\SecondaryNavigation;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\User;
use ScoutLib\Plugin;
use ScoutLib\PluginManager;

/**
 * Plugin that provides a separate photo library, that can be managed and
 * used alongside the main collection.
 */
class PhotoLibrary extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set the plugin attributes.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Photo Library";
        $this->Version = "1.0.2";
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

        # load vocabulary for "Record Status" Option field
        $VocFile = __DIR__."/install/Vocabulary--Record-Status.voc";
        $Schema = new MetadataSchema($this->getConfigSetting("MetadataSchemaId"));
        $RSField = $Schema->getField("Record Status");
        $RSField->loadVocabulary($VocFile);

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
            $Record = new Record($Id);
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
        # add "Add Photo" to available secondary nav items if appropriate
        if (User::getCurrentUser()->isLoggedIn()) {
            $PluginMgr = PluginManager::getInstance();
            if ($PluginMgr->pluginEnabled("SecondaryNavigation")) {
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

        # report successful initialization
        return null;
    }

    /**
     * Validate values for the photo display page info field list setting.
     * @param string $SettingName Name of configuration setting being validated
     * @param string $Value Setting value to validate.
     * @return string|null Error message or NULL if no error found.
     */
    public function validatePhotoDisplayFieldList(string $SettingName, string $Value): ?string
    {
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
            if (strlen($Line)) {
                # if field does not exist in our schema
                if (!$Schema->fieldExists($Line)) {
                    # add error message for field
                    $ErrMsgs[] = "Unknown metadata field \"".$Line."\".";
                }
            }
        }

        # report any errors to caller
        return count($ErrMsgs) ? join("\n", $ErrMsgs) : null;
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
            if (!strlen($Line)) {
                # if we have a current group with fields
                if (count($CurrentGroup ?? [])) {
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
        if (count($CurrentGroup ?? [])) {
            # add group to field groups
            $FieldGroups[] = $CurrentGroup;
        }

        # save generated groups and checksum for current setting
        $this->setConfigSetting("PhotoDisplayFieldGroups", $FieldGroups);
        $this->setConfigSetting("PhotoDisplayFieldChecksum", $Checksum);

        # return generated groups to caller
        return $FieldGroups;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    const EDIT_PAGE_LINK = 'index.php?P=EditResource&ID=$ID';
    const VIEW_PAGE_LINK = 'index.php?P=P_PhotoLibrary_DisplayPhoto&ID=$ID';

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
