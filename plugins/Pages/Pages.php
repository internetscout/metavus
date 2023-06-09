<?PHP
#
#   FILE:  Pages.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2012-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Plugins\Pages\Page;
use Metavus\Plugins\Pages\PageFactory;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * Free-form content page plugin.
 */
class Pages extends Plugin
{
    # ---- STANDARD PLUGIN METHODS -------------------------------------------

    /**
     * Set the plugin attributes.At minimum this method MUST set $this->Name
     * and $this->Version.This is called when the plugin is initially loaded.
     */
    public function register()
    {
        $this->Name = "Pages";
        $this->Version = "2.0.15";
        $this->Description = "Allows the creation and editing of additional"
                ." web pages with free-form HTML content";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.0.0"];
        $this->InitializeAfter = ["SecondaryNavigation"];
        $this->EnabledByDefault = true;

        $this->CfgSetup["SummaryLength"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Summary Length",
            "Help" => "Target length for page summaries,"
                        ." displayed in search results.",
            "Default" => 280,
            "MinVal" => 10,
            "MaxVal" => 2000,
            "Units" => "characters",
        ];
        $this->CfgSetup["AllowedInsertionKeywords"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Allowed Insertion Keywords",
            "Help" => "Insertion keywords that are allowed to be"
                        ." expanded when used in page content.(Any"
                        ." insertion keywords not listed here will be"
                        ." displayed verbatim.)",
            "Columns" => 40,
            "Rows" => 6,
        ];
    }

    /**
     * Initialize the plugin.This is called after all plugins have been loaded
     * but before any methods for this plugin (other than Register() or Initialize())
     * have been called.
     * @return null|string NULL if initialization was successful, otherwise
     *      an error message indicating why initialization failed.
     */
    public function initialize()
    {
        $AF = ApplicationFramework::getInstance();
        $this->DB = new Database();

        # if the Records table does not yet exist because the updates that
        # create it have not yet been run then attempting to use PageFactory
        # methods will generate errors that prevent the bootstrap from
        # completing, which then prevents './install/cwis plugin command
        # Developer upgrade' from applying those upgrades.so, if Records
        # doesn't yet exist, cleanly report an error so that the rest of our
        # bootstrap can continue
        if (!$this->DB->tableExists("Records")) {
            return "Records table does not exist";
        }

        # give page factory our metadata schema ID
        PageFactory::$PageSchemaId = $this->configSetting("MetadataSchemaId");

        # see if our help pages need updating
        if (version_compare($this->configSetting("VersionOfLastUpdate"), CWIS_VERSION, "<")) {
            $Result = $this->loadHelpPages();
            if ($Result !== null) {
                return $Result;
            }
        }

        # if we have migrated about page content to load, do so
        if (!is_null($this->configSetting("MigratedAboutContent"))) {
            $this->loadAboutPage();
        }

        # set up clean URL mappings for current pages
        $CleanUrls = $this->configSetting("CleanUrlCache");
        if (is_null($CleanUrls)) {
            $PFactory = new PageFactory();
            $CleanUrls = $PFactory->getCleanUrls();
            $this->configSetting("CleanUrlCache", $CleanUrls);
        }

        foreach ($CleanUrls as $PageId => $Urls) {
            if (count($Urls) == 0) {
                continue;
            }

            # add url for the page itself
            $PageUrl = array_shift($Urls);
            $AF->addCleanUrl(
                "%^".$PageUrl."$%",
                "P_Pages_DisplayPage",
                ["ID" => $PageId],
                $PageUrl
            );

            # if page contained any tabs, add those as well
            foreach ($Urls as $TabUrl) {
                # construct a regex from the URL that pulls the tab name out
                # in a capturing subgroup
                $Pattern = preg_replace(
                    '%([^/]+)/(.+)%',
                    '%^\1/(\2)$%',
                    $TabUrl
                );
                $AF->addCleanUrl(
                    $Pattern,
                    "P_Pages_DisplayPage",
                    [
                        "ID" => $PageId,
                        "AT" => "\$1"
                    ],
                    $PageUrl."/\$AT"
                );
            }
        }

        $PluginMgr = PluginManager::getInstance();
        if ($PluginMgr->pluginEnabled("SecondaryNavigation") &&
            User::getCurrentUser()->isLoggedIn()) {
            $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));

            $ListPrivs = new PrivilegeSet();
            $ListPrivs->addSubset($Schema->editingPrivileges());
            $ListPrivs->addSubset($Schema->authoringPrivileges());
            $ListPrivs->usesAndLogic(false);

            $AuthoringPrivs = $Schema->authoringPrivileges();
            $NewPageUrl = str_replace('$ID', "NEW&SC=".$Schema->id(), $Schema->editPage());

            $SecondaryNav = $PluginMgr->getPlugin("SecondaryNavigation");
            $SecondaryNav->offerNavItem(
                "Page List",
                "index.php?P=P_Pages_ListPages",
                $ListPrivs,
                "See list of editable pages."
            );
            $SecondaryNav->offerNavItem(
                "Add New Page",
                $NewPageUrl,
                $AuthoringPrivs,
                "Add new editable page."
            );
        }

        # report successful initialization
        return null;
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return null|string NULL if installation succeeded, otherwise a string
     *      containing an error message indicating why installation failed.
     */
    public function install()
    {
        # if User::getCurrentUser() isn't a CWUser (now Metavus\User), then we're
        # being run from the install during a site upgrade, and the RecordUserInts
        # table required by User objects and created in SiteUpgrade--3.9.0 may not yet
        # have been created.defer our installation to avoid exploding because of that
        if (!User::getCurrentUser() instanceof User) {
            return "Cannot install Pages during a site upgrade.";
        }

        # set up metadata schema
        $Result = $this->setUpSchema();
        if ($Result !== null) {
            return $Result;
        }

        # set up database tables
        $Result = $this->createTables($this->SqlTables);
        if ($Result !== null) {
            return $Result;
        }

        # set the AbbreviatedName for Pages schema
        $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
        $Schema->abbreviatedName("W");

        # pre-load help pages
        $Result = $this->loadHelpPages();
        if ($Result !== null) {
            return $Result;
        }

        $this->loadAboutPage();

        # report success to caller
        return null;
    }

    /**
     * Upgrade this plugin from a previous version.
     * @param string $PreviousVersion String contining previous version number.
     * @return null|string NULL if successful or error message string if not.
     */
    public function upgrade(string $PreviousVersion)
    {
        $DB = new Database();

        if (!$DB->tableExists("Records")) {
            return "Records table does not exist";
        }

        # ugprade from versions < 1.0.1 to 1.0.1
        if (version_compare($PreviousVersion, "1.0.1", "<")) {
            $PrivilegeToAuthor = $this->configSetting("PrivilegeToAuthor");
            $PrivilegeToEdit = $this->configSetting("PrivilegeToEdit");

            # if authoring privilege is an array because it hasn't been edited
            # since the plugin was installed
            if (is_array($PrivilegeToAuthor)) {
                $this->configSetting(
                    "PrivilegeToAuthor",
                    array_shift($PrivilegeToAuthor)
                );
            }

            # if editing privilege is an array because it hasn't been edited
            # since the plugin was installed
            if (is_array($PrivilegeToEdit)) {
                $this->configSetting("PrivilegeToEdit", array_shift($PrivilegeToEdit));
            }
        }

        # upgrade from versions < 1.0.2 to 1.0.2
        if (version_compare($PreviousVersion, "1.0.2", "<")) {
            # add clean URL column to database
            $Result = $DB->query("ALTER TABLE Pages_Pages"
                    ." ADD COLUMN CleanUrl TEXT");
            if ($Result === false) {
                return "Upgrade failed adding"
                    ." \"CleanUrl\" column to database.";
            }
        }

        # upgrade from versions < 1.0.5 to 1.0.5
        if (version_compare($PreviousVersion, "1.0.5", "<")) {
            # convert content column in database to larger type
            $Result = $DB->query("ALTER TABLE Pages_Pages"
                    ." MODIFY COLUMN PageContent MEDIUMTEXT");
            if ($Result === false) {
                return "Upgrade failed converting"
                    ." \"PageContent\" column to MEDIUMTEXT.";
            }
        }

        # upgrade from versions < 2.0.0 to 2.0.0
        if (version_compare($PreviousVersion, "2.0.0", "<")) {
            # switch to metadata-schema-based storage of pages
            $Result = $this->upgradeToMetadataPageStorage();
            if ($Result !== null) {
                return $Result;
            }
        }

        # upgrade from versions < 2.0.1 to 2.0.1
        if (version_compare($PreviousVersion, "2.0.1", "<")) {
            # set the AbbreviatedName for Pages schema
            $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
            $Schema->abbreviatedName("W");
        }

        # upgrade from versions < 2.0.2 to 2.0.2
        if (version_compare($PreviousVersion, "2.0.2", "<")) {
            # add Summary metadata field
            $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
            if ($Schema->addFieldsFromXmlFile(Pages::schemaDefinitionFile(), $this->Name)
                == false) {
                return "Error Loading Metadata Fields from XML: ".implode(
                    " ",
                    $Schema->errorMessages("AddFieldsFromXmlFile")
                );
            }

            # populate Summary field for all pages
            PageFactory::$PageSchemaId = $this->configSetting("MetadataSchemaId");
            $PFactory = new PageFactory();
            $Ids = $PFactory->getItemIds();
            foreach ($Ids as $Id) {
                $Page = new Page($Id);
                $Page->set("Summary", $Page->getSummary());
            }
        }

        # upgrade from versions < 2.0.3 to 2.0.3
        if (version_compare($PreviousVersion, "2.0.3", "<")) {
            # regenerate page summaries to reflect changes to summary generation
            PageFactory::$PageSchemaId = $this->configSetting("MetadataSchemaId");
            $PFactory = new PageFactory();
            $Ids = $PFactory->getItemIds();
            foreach ($Ids as $Id) {
                $Page = new Page($Id);
                $Page->set("Summary", $Page->getSummary(
                    $this->configSetting("SummaryLength")
                ));
            }
        }

        # upgrade from versions < 2.0.4 to 2.0.4
        if (version_compare($PreviousVersion, "2.0.4", "<")) {
            PageFactory::$PageSchemaId = $this->configSetting("MetadataSchemaId");
            $Result = $this->loadHelpPages();
            if ($Result !== null) {
                return $Result;
            }
        }

        if (version_compare($PreviousVersion, "2.0.5", "<")) {
            $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
            $Result = $Schema->addFieldsFromXmlFile(
                Pages::schemaDefinitionFile(),
                $this->Name
            );

            if ($Result == false) {
                return "Error Loading Metadata Fields from XML: "
                    .implode(" ", $Schema->errorMessages("AddFieldsFromXmlFile"));
            }

            PageFactory::$PageSchemaId = $Schema->id();
            $PFactory = new PageFactory();

            # set hashes for previously loaded pages
            $ContentHashes = [
                "help/collections"
                    => "7c2dbd3394a13c743c76c8d893dc95358e4f81c5b12d32a592ceb2d93aec5bb2",
                "help/collections/customizing_metadata_fields"
                    => "dd018590a21d89f95205c9012065186d365f079b113e9d760d3303761cde6667",
                "help/collections/metadata_field_editor"
                    => "70dbcc9fc8f8956c952c3a64b84a22fd23d0c395adcc19c9f0cda2bf78639d8d",
                "help/collections/permissions"
                    => "e0fca33ae31ddf37f6966df9601e52164ea7413e252c29bc8a1f4381f618bbf3",
                "help/metadata/updating_controlled_names"
                    => "82e34db618c255eb9a57878fe45cb00ce338eb3879a6733d4e28c28c167c3e4d",
                "help/metadata/updating_option_lists"
                    => "c690c83c83168d491432135b6dc750b2cbabee665e839bc787cfbe21a67b365a",
                "help/users/user_access_privilege_flags"
                    => "63c612c3a63e28909365eff85f5630b97739b26622f1f665b1aef9a565ad9cb0"
            ];

            foreach ($ContentHashes as $Url => $ContentHash) {
                $Matches = $PFactory->getIdsOfMatchingRecords(
                    ["Clean URL" => $Url]
                );

                if (count($Matches) == 0) {
                    continue;
                }

                $Page = new Record(array_shift($Matches));
                $Page->set("Initial Content Hash", $ContentHash);
            }

            # (updates will happen in initialize)
        }

        if (version_compare($PreviousVersion, "2.0.6", "<")) {
            $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));

            $ParagraphFields = $Schema->getFields(MetadataSchema::MDFTYPE_PARAGRAPH);
            foreach ($ParagraphFields as $Field) {
                $Field->allowHTML(true);
            }
        }

        # upgrade from versions < 2.0.7 to 2.0.7
        if (version_compare($PreviousVersion, "2.0.7", "<")) {
            PageFactory::$PageSchemaId = $this->configSetting("MetadataSchemaId");
            $PFactory = new PageFactory();
            $Ids = $PFactory->getItemIds();
            foreach ($Ids as $Id) {
                $Page = new Page($Id);
                $UpdatedContent = preg_replace(
                    '/<h2 class="cw-tab-start">/',
                    '<h2 class="mv-tab-start">',
                    $Page->get("Content")
                );
                $Page->set("Content", $UpdatedContent);
            }
        }

        # upgrade from versions < 2.0.8 to 2.0.8
        if (version_compare($PreviousVersion, "2.0.8", "<")) {
            $PageSchemaId = $this->configSetting("MetadataSchemaId");
            $PSchema = new MetadataSchema($PageSchemaId);
            $PSchema->getField("Added By Id")->updateMethod(
                MetadataField::UPDATEMETHOD_ONRECORDCREATE
            );
            $PSchema->getField("Last Modified By Id")->updateMethod(
                MetadataField::UPDATEMETHOD_ONRECORDCHANGE
            );
        }

        # upgrade from versions < 2.0.9 to 2.0.9
        if (version_compare($PreviousVersion, "2.0.9", "<")) {
            $Result = $this->loadSchemaFieldsFromFile();
            if ($Result !== null) {
                return $Result;
            }
        }

        if (version_compare($PreviousVersion, "2.0.10", "<")) {
            # rewrite links in Pages content to use updated URLs for preview images
            PageFactory::$PageSchemaId = $this->configSetting("MetadataSchemaId");
            $PFactory = new PageFactory();
            $Ids = $PFactory->getItemIds();
            foreach ($Ids as $Id) {
                $Page = new Page($Id);
                $UpdatedContent = preg_replace(
                    '%local/data/images/previews/Preview--([0-9]+)\.([a-z]+)%',
                    'local/data/caches/images/scaled/img_\1_300x300.\2',
                    $Page->get("Content")
                );
                $Page->set("Content", $UpdatedContent);
            }
        }

        if (version_compare($PreviousVersion, "2.0.11", "<")) {
            $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
            if ($Schema->fieldExists("Images")) {
                $FieldId = $Schema->getFieldIdByName("Images");
                $Schema->stdNameToFieldMapping("Screenshot", $FieldId);
            }
        }

        if (version_compare($PreviousVersion, "2.0.12", "<")) {
            # rewrite links in Pages content to use the IMAGEURL keyword
            # (the set() will rewrite urls)
            PageFactory::$PageSchemaId = $this->configSetting("MetadataSchemaId");
            $Ids = (new PageFactory())->getItemIds();
            foreach ($Ids as $Id) {
                $Page = new Page($Id);
                $Page->set("Content", $Page->get("Content"));
            }
        }

        if (version_compare($PreviousVersion, "2.0.13", "<")) {
            # clear caches to force the list of page URLs to be updated
            $this->clearCaches();
        }

        if (version_compare($PreviousVersion, "2.0.14", "<")) {
            # set our item class name
            $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
            $Schema->setItemClassName("Metavus\\Plugins\\Pages\\Page");
        }

        if (version_compare($PreviousVersion, "2.0.15", "<")) {
            $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
            $Schema->editPage("index.php?P=P_Pages_EditPage&ID=\$ID");
        }

        # report success to caller
        return null;
    }

    /**
     * Perform any work needed when the plugin is uninstalled.
     * @return null|string NULL if uninstall succeeded, otherwise a string
     *      containing an error message indicating why uninstall failed.
     */
    public function uninstall()
    {
        # delete all pages
        PageFactory::$PageSchemaId = $this->configSetting("MetadataSchemaId");
        $PFactory = new PageFactory();
        $Ids = $PFactory->getItemIds();
        foreach ($Ids as $Id) {
            $Page = new Page($Id);
            $Page->destroy();
        }

        # delete our metadata schema
        $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
        $Schema->delete();

        # remove tables from database
        return $this->dropTables($this->SqlTables);
    }

    /**
     * Hook the events into the application framework.
     * @return array Returns an array of events to be hooked into the application
     *      framework.
     */
    public function hookEvents(): array
    {
        $Events = [
            "EVENT_MODIFY_SECONDARY_NAV" => "AddMenuOptions",
            "EVENT_PLUGIN_CONFIG_CHANGE" => "PluginConfigChange",
        ];
        return $Events;
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Add options to administration menu.
     * @return array Array of menu items to add.
     */
    public function addAdminMenuItems(): array
    {
        return [
            "index.php?P=P_Pages_EditPage&amp;ID=NEW" => "Add New Page",
            "index.php?P=P_Pages_ListPages" => "List Pages",
        ];
    }

    /**
     * Add options to secondary navigation menu.
     * @param array $NavItems Existing array of menu items.
     * @return array Modified array of menu items.
     */
    public function addMenuOptions(array $NavItems): array
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # build up list of nav items to add
        $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
        if ($Schema->userCanAuthor($User) || $Schema->userCanEdit($User)) {
            $MyNavItems["Page List"] = "index.php?P=P_Pages_ListPages";
        }

        # if there are nav items to add
        if (isset($MyNavItems)) {
            # step through options to add new entries at most desirable spot
            $NewNavItems = [];
            $NeedToAddMyOptions = true;
            foreach ($NavItems as $Label => $Link) {
                if ($NeedToAddMyOptions && (($Label == "Administration") ||
                    ($Label == "Log Out"))) {
                    $NewNavItems = $NewNavItems + $MyNavItems;
                    $NeedToAddMyOptions = false;
                }
                $NewNavItems[$Label] = $Link;
            }
            if ($NeedToAddMyOptions) {
                $NewNavItems = $NewNavItems + $MyNavItems;
            }
        } else {
            # return nav item list unchanged
            $NewNavItems = $NavItems;
        }

        # return new list of nav options to caller
        return ["NavItems" => $NewNavItems];
    }

    /**
     * Handle plugin configuration changes.
     * @param string $PluginName Name of the plugin that has changed.
     * @param string $ConfigSetting Name of the setting that has change.
     * @param mixed $OldValue The old value of the setting.
     * @param mixed $NewValue The new value of the setting.
     */
    public function pluginConfigChange(
        string $PluginName,
        string $ConfigSetting,
        $OldValue,
        $NewValue
    ) {
        # only worried about changes to our settings
        if ($PluginName != $this->Name) {
            return;
        }

        # update image field settings if they've changed
        $SchemaId = $this->configSetting("MetadataSchemaId");
        $Schema = new MetadataSchema($SchemaId);
        $ImageField = $Schema->getField("Images");

        # regenerate all page summaries if summary length has changed
        if ($ConfigSetting == "SummaryLength") {
            PageFactory::$PageSchemaId = $this->configSetting("MetadataSchemaId");
            $PFactory = new PageFactory();
            $Ids = $PFactory->getItemIds();
            foreach ($Ids as $Id) {
                $Page = new Page($Id);
                $Page->set("Summary", $Page->getSummary($NewValue));
            }
        }
    }

    /**
     * Clear internal caches.
     */
    public function clearCaches()
    {
        $this->configSetting("CleanUrlCache", null);
    }

    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Plugin command for refreshing help page content.
     */
    public function commandUpdateHelpPages()
    {
        $this->loadHelpPages();
    }

    /**
     * Get insertion keywords allowed in page content.
     * @return array Insertion keywords (without surrounding braces).
     */
    public function getAllowedInsertionKeywords(): array
    {
        $Setting = $this->configSetting("AllowedInsertionKeywords");
        $Keywords = !is_null($Setting) ?
            preg_split('%[\s,{}]+%', $Setting, -1, PREG_SPLIT_NO_EMPTY) :
            false;
        return ($Keywords === false) ? [] : $Keywords;
    }

    # ---- PRIVATE METHODS ---------------------------------------------------

    /**
     * Set up our metadata schema.
     * @return null|string NULL upon success, or error string upon failure.
     */
    private function setUpSchema()
    {
        # setup the default privileges for authoring and editing
        $AuthorPrivs = new PrivilegeSet();
        $AuthorPrivs->addPrivilege(PRIV_SYSADMIN);
        $EditPrivs = new PrivilegeSet();
        $EditPrivs->addPrivilege(PRIV_SYSADMIN);

        # create a new metadata schema and save its ID
        $Schema = MetadataSchema::create(
            "Pages",
            $AuthorPrivs,
            $EditPrivs,
            null,
            "index.php?P=P_Pages_DisplayPage&ID=\$ID"
        );
        $Schema->setItemClassName("Metavus\\Plugins\\Pages\\Page");
        $this->configSetting("MetadataSchemaId", $Schema->id());
        PageFactory::$PageSchemaId = $Schema->id();
        $Schema->editPage("index.php?P=P_Pages_EditPage&ID=\$ID");

        # load fields into schema and return result back to caller
        return $this->loadSchemaFieldsFromFile();
    }

    /**
     * Load (or update) our metadata fields from an XML file.
     * @return null|string NULL upon success, or error string upon failure.
     */
    private function loadSchemaFieldsFromFile()
    {
        $Schema = new MetadataSchema($this->configSetting("MetadataSchemaId"));
        if ($Schema->addFieldsFromXmlFile(Pages::schemaDefinitionFile(), $this->Name) == false) {
            return "Error Loading Metadata Fields from XML: ".implode(
                " ",
                $Schema->errorMessages("AddFieldsFromXmlFile")
            );
        }
        return null;
    }

    /**
     * Load and/or update help pages.
     * @return null|string NULL upon success, or error string upon failure.
     */
    private function loadHelpPages()
    {
        $HelpPageContentFile = __DIR__."/install/HelpPages.xml";

        # if the help page content has not changed since we last loaded it, no
        # need to do anything
        $CurrentHash = md5_file($HelpPageContentFile);
        if ($this->configSetting("HelpPagesHash") == $CurrentHash) {
            return null;
        }

        $PFactory = new PageFactory();

        # delete existing help pages that have not been modified
        foreach ($PFactory->getItemIds() as $PageId) {
            $Page = new Page($PageId);
            $InitialContentHash = $Page->get("Initial Content Hash");

            # if this page was not automatically created, skip it
            if (strlen($InitialContentHash) == 0) {
                continue;
            }

            # if this page has not been changed since it was created, we can delete it
            $ContentHash = hash(self::HASH_ALGO, $Page->get("Content"));
            if ($ContentHash == $InitialContentHash) {
                $Page->destroy();
            }
        }

        # load updated help content
        $PageIds = $PFactory->updatePagesFromXmlFile($HelpPageContentFile);

        # if the page ID list is an error message, return it
        if (!is_array($PageIds)) {
            return $PageIds;
        }

        # update our stored hash for HelpPages.xml and the CWIS version when
        # we last loaded this content
        $this->configSetting("HelpPagesHash", $CurrentHash);
        $this->configSetting("VersionOfLastUpdate", CWIS_VERSION);

        # report success
        return null;
    }

    /**
     * Load the about page, potentially migrating content if necessary.
     */
    private function loadAboutPage()
    {
        $AboutPageContentFile = __DIR__."/install/AboutPage.xml";

        $PFactory = new PageFactory();
        $PageIds = $PFactory->updatePagesFromXmlFile($AboutPageContentFile);

        if (is_string($PageIds)) {
            throw new Exception(
                "Error loading AboutPage: ".$PageIds
            );
        }

        $AboutPage = new Page(array_shift($PageIds));

        if (!is_null($this->configSetting("MigratedAboutContent"))) {
            $AboutPage->set(
                "Content",
                $this->configSetting("MigratedAboutContent")
            );
            $this->configSetting("MigratedAboutContent", null);
        }
    }

    /**
     * Migrate data from database-based format to metadata-schema-based format.
     * @return null|string NULL upon success, or error string upon failure.
     */
    private function upgradeToMetadataPageStorage()
    {
        $DB = new Database();

        # bail out if conversion has already been done or is under way
        if (!$DB->tableExists("Pages_Pages") ||
            !$DB->query("RENAME TABLE Pages_Pages TO Pages_Pages_OLD")) {
            return null;
        }

        # set up metadata schema
        $Result = $this->setUpSchema();
        if ($Result !== null) {
            return $Result;
        }

        # load old privilege information
        $DB->query("SELECT * FROM Pages_Privileges");
        $OldPrivs = [];
        while ($Row = $DB->fetchRow()) {
            $OldPrivs[$Row["PageId"]][] = $Row["Privilege"];
        }

        # create new privileges table
        $DB->query("DROP TABLE Pages_Privileges");
        $DB->query(
            "CREATE TABLE IF NOT EXISTS Pages_Privileges (
                    PageId              INT NOT NULL,
                    ViewingPrivileges   BLOB,
                    INDEX       (PageId))"
        );

        # for each page in database
        $DB->query("SELECT * FROM Pages_Pages_OLD");
        while ($Row = $DB->fetchRow()) {
            # add new record for page
            $Page = Page::create();

            # transfer page values
            $Page->set("Title", $Row["PageTitle"]);
            $Page->set("Content", $Row["PageContent"]);
            $Page->set("Clean URL", $Row["CleanUrl"]);
            $Page->set("Creation Date", $Row["CreatedOn"]);
            $Page->set("Added By Id", $Row["AuthorId"]);
            $Page->set("Date Last Modified", $Row["UpdatedOn"]);
            $Page->set("Last Modified By Id", $Row["EditorId"]);

            # set viewing privileges
            $PrivSet = new PrivilegeSet();
            if (isset($OldPrivs[$Row["PageId"]])) {
                $PrivSet->addPrivilege($OldPrivs[$Row["PageId"]]);
            }
            $Page->viewingPrivileges($PrivSet);

            # make page permanent
            $Page->isTempRecord(false);
        }

        # drop content table from database
        $DB->query("DROP TABLE IF EXISTS Pages_Pages_OLD");

        # report success to caller
        return null;
    }

    private $DB;

    private $SqlTables = [
        "Privileges" => "CREATE TABLE Pages_Privileges (
            PageId              INT NOT NULL,
            ViewingPrivileges   BLOB,
            INDEX       (PageId))",
    ];

    private static function schemaDefinitionFile()
    {
        return __DIR__."/install/MetadataSchema--".static::getBaseName().".xml";
    }

    const HASH_ALGO = 'sha256';
}
