<?PHP
#
#   FILE:  Pages.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
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
use Metavus\Plugins\SecondaryNavigation;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\PluginManager;

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
    public function register(): void
    {
        $this->Name = "Pages";
        $this->Version = "2.0.15";
        $this->Description = "Allows the creation and editing of additional"
                ." web pages with free-form HTML content";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.2.0"];
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
    public function initialize(): ?string
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
        PageFactory::$PageSchemaId = $this->getConfigSetting("MetadataSchemaId");

        # see if our help pages need updating
        if (version_compare($this->getConfigSetting("VersionOfLastUpdate"), CWIS_VERSION, "<")) {
            $Result = $this->loadHelpPages();
            if ($Result !== null) {
                return $Result;
            }
        }

        # if we have migrated about page content to load, do so
        if (!is_null($this->getConfigSetting("MigratedAboutContent"))) {
            $this->loadAboutPage();
        }

        # set up clean URL mappings for current pages
        $CleanUrls = $this->getConfigSetting("CleanUrlCache");
        if (is_null($CleanUrls)) {
            $PFactory = new PageFactory();
            $CleanUrls = $PFactory->getCleanUrls();
            $this->setConfigSetting("CleanUrlCache", $CleanUrls);
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
            $Schema = new MetadataSchema($this->getConfigSetting("MetadataSchemaId"));

            $ListPrivs = new PrivilegeSet();
            $ListPrivs->addSubset($Schema->editingPrivileges());
            $ListPrivs->addSubset($Schema->authoringPrivileges());
            $ListPrivs->usesAndLogic(false);

            $AuthoringPrivs = $Schema->authoringPrivileges();
            $NewPageUrl = str_replace('$ID', "NEW&SC=".$Schema->id(), $Schema->getEditPage());

            $SecondaryNav = SecondaryNavigation::getInstance();
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
    public function install(): ?string
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
        $Schema = new MetadataSchema($this->getConfigSetting("MetadataSchemaId"));
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
     * Perform any work needed when the plugin is uninstalled.
     * @return null|string NULL if uninstall succeeded, otherwise a string
     *      containing an error message indicating why uninstall failed.
     */
    public function uninstall(): ?string
    {
        # delete all pages
        PageFactory::$PageSchemaId = $this->getConfigSetting("MetadataSchemaId");
        $PFactory = new PageFactory();
        $Ids = $PFactory->getItemIds();
        foreach ($Ids as $Id) {
            $Page = new Page($Id);
            $Page->destroy();
        }

        # delete our metadata schema
        $Schema = new MetadataSchema($this->getConfigSetting("MetadataSchemaId"));
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
        $Schema = new MetadataSchema($this->getConfigSetting("MetadataSchemaId"));
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
    ): void {
        # only worried about changes to our settings
        if ($PluginName != $this->Name) {
            return;
        }

        # update image field settings if they've changed
        $SchemaId = $this->getConfigSetting("MetadataSchemaId");
        $Schema = new MetadataSchema($SchemaId);
        $ImageField = $Schema->getField("Images");

        # regenerate all page summaries if summary length has changed
        if ($ConfigSetting == "SummaryLength") {
            PageFactory::$PageSchemaId = $this->getConfigSetting("MetadataSchemaId");
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
    public function clearCaches(): void
    {
        $this->setConfigSetting("CleanUrlCache", null);
    }

    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Plugin command for refreshing help page content.
     */
    public function commandUpdateHelpPages(): void
    {
        $this->loadHelpPages();
    }

    /**
     * Get insertion keywords allowed in page content.
     * @return array Insertion keywords (without surrounding braces).
     */
    public function getAllowedInsertionKeywords(): array
    {
        $Setting = $this->getConfigSetting("AllowedInsertionKeywords");
        $Keywords = !is_null($Setting) ?
            preg_split('%[\s,{}]+%', $Setting, -1, PREG_SPLIT_NO_EMPTY) :
            false;
        return ($Keywords === false) ? [] : $Keywords;
    }

    /**
     * Set up our metadata schema.
     * @return null|string NULL upon success, or error string upon failure.
     */
    public function setUpSchema(): ?string
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
        $this->setConfigSetting("MetadataSchemaId", $Schema->id());
        PageFactory::$PageSchemaId = $Schema->id();
        $Schema->setEditPage("index.php?P=P_Pages_EditPage&ID=\$ID");

        # load fields into schema and return result back to caller
        return $this->loadSchemaFieldsFromFile();
    }

    /**
     * Load (or update) our metadata fields from an XML file.
     * @return null|string NULL upon success, or error string upon failure.
     */
    public function loadSchemaFieldsFromFile(): ?string
    {
        $Schema = new MetadataSchema($this->getConfigSetting("MetadataSchemaId"));
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
    public function loadHelpPages(): ?string
    {
        $HelpPageContentFile = __DIR__."/install/HelpPages.xml";

        # if the help page content has not changed since we last loaded it, no
        # need to do anything
        $CurrentHash = md5_file($HelpPageContentFile);
        if ($this->getConfigSetting("HelpPagesHash") == $CurrentHash) {
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
        $this->setConfigSetting("HelpPagesHash", $CurrentHash);
        $this->setConfigSetting("VersionOfLastUpdate", CWIS_VERSION);

        # report success
        return null;
    }

    # ---- PRIVATE METHODS ---------------------------------------------------

    /**
     * Load the about page, potentially migrating content if necessary.
     */
    private function loadAboutPage(): void
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

        if (!is_null($this->getConfigSetting("MigratedAboutContent"))) {
            $AboutPage->set(
                "Content",
                $this->getConfigSetting("MigratedAboutContent")
            );
            $this->setConfigSetting("MigratedAboutContent", null);
        }
    }

    private $DB;

    private $SqlTables = [
        "Privileges" => "CREATE TABLE Pages_Privileges (
            PageId              INT NOT NULL,
            ViewingPrivileges   BLOB,
            INDEX       (PageId))",
    ];

    /**
     * Get the location of the schema definition XML file.
     * @return string Path to definition file.
     */

    public static function schemaDefinitionFile(): string
    {
        return __DIR__."/install/MetadataSchema--".static::getBaseName().".xml";
    }

    const HASH_ALGO = 'sha256';
}
