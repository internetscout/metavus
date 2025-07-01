<?PHP
#
#   FILE:  Bootloader.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Email;
use ScoutLib\PluginManager;

require_once("lib/ScoutLib/AFTaskManagerTrait.php");
require_once("lib/ScoutLib/AFUrlManagerTrait.php");
require_once("lib/ScoutLib/ApplicationFramework.php");
require_once("lib/ScoutLib/Database.php");
require_once("lib/ScoutLib/StdLib.php");

/**
 * This class brings the Metavus operating environment up to readiness.
 *
 * The following global variables may be set before instantiating this class,
 * to change its behavior:
 *      StartUpOpt_CLEAR_AF_CACHES - When set to TRUE, all ApplicationFramework
 *          caches will be cleared right after $GLOBALS["AF"] is loaded.
 *      StartUpOpt_DO_NOT_LOAD_PLUGINS - When set to TRUE, plugins will not
 *          be loaded at all.(Though PluginManager will still be created.)
 *      StartUpOpt_FORCE_PLUGIN_CONFIG_LOAD - When set to TRUE, all plugins will
 *          be instructed to set up their configuration options, even if the
 *          plugin is not currently enabled.
 *      StartUpOpt_USE_AXIS_USER - When set to TRUE, the current user will
 *          be a \ScoutLib\User rather than a \Metavus\User.
 */
class Bootloader
{
    # ---- CONFIGURATION -----------------------------------------------------

    # directories to search for class files
    private $ObjectDirectories = [
        "interface/%ACTIVEUI%/objects" => "",
        "interface/%DEFAULTUI%/objects" => "",
        "objects" => "",
        "lib" => "",
        "lib/Parsedown" => "",
        "lib/other" => "",
        "lib/PHPMailer/src" => "PHPMailer\\PHPMailer",
        "lib/PSR/simple-cache/src" => "Psr\\SimpleCache",
        "lib/Scrapbook/src" => "MatthiasMullie\\Scrapbook",
        "lib/scssphp/src" => "ScssPhp\\ScssPhp",
    ];

    # additional directories to search for user interface files
    private $IncludeDirectories = [
        "lib/CKEditor/",
        "lib/D3/",
        "lib/C3/",
        "lib/jsbn/",
        "lib/jquery/",
        "lib/jquery-ui/",
        "lib/Bootstrap/css/",
        "lib/Bootstrap/js/",
        "lib/FilePond/js/",
        "lib/FilePond/css/",
    ];

    # standard hookable events
    private $HookableEvents = [
        # --- User Events
        "EVENT_USER_ADDED" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_USER_VERIFIED" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_USER_DELETED" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_USER_LOGIN" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_USER_LOGIN_RETURN" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_USER_LOGOUT" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_USER_LOGOUT_RETURN" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_USER_PASSWORD_CHANGED" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_USER_EMAIL_CHANGED" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_USER_PRIVILEGES_CHANGED" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_USER_AUTHENTICATION" => ApplicationFramework::EVENTTYPE_FIRST,
        "EVENT_PRE_USER_DELETE" => ApplicationFramework::EVENTTYPE_CHAIN,
        # --- Search Events
        "EVENT_SEARCH_COMPLETE" => ApplicationFramework::EVENTTYPE_DEFAULT,
        # --- Resource Events
        "EVENT_RESOURCE_CREATE" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_RESOURCE_ADD" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_RESOURCE_MODIFY" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_RESOURCE_DELETE" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_RESOURCE_FILE_ADD" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_RESOURCE_FILE_DELETE" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_RESOURCE_AUTHOR_PERMISSION_CHECK" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_RESOURCE_EDIT_PERMISSION_CHECK" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_RESOURCE_VIEW_PERMISSION_CHECK" => ApplicationFramework::EVENTTYPE_CHAIN,
        # --- Metadata Field Events
        "EVENT_PRE_FIELD_EDIT_FILTER" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_POST_FIELD_EDIT_FILTER" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_FIELD_DISPLAY_FILTER" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_FIELD_SEARCH_FILTER" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_APPEND_HTML_TO_FIELD_DISPLAY" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_FIELD_VIEW_PERMISSION_CHECK" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_FIELD_AUTHOR_PERMISSION_CHECK" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_FIELD_EDIT_PERMISSION_CHECK" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_FIELD_ADDED" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_PRE_FIELD_DELETE" => ApplicationFramework::EVENTTYPE_DEFAULT,
        # --- User Interface Events
        "EVENT_IN_HTML_HEADER" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_COLLECTION_ADMINISTRATION_MENU" => ApplicationFramework::EVENTTYPE_NAMED,
        "EVENT_USER_ADMINISTRATION_MENU" => ApplicationFramework::EVENTTYPE_NAMED,
        "EVENT_SYSTEM_ADMINISTRATION_MENU" => ApplicationFramework::EVENTTYPE_NAMED,
        "EVENT_SYSTEM_INFO_LIST" => ApplicationFramework::EVENTTYPE_NAMED,
        "EVENT_MODIFY_PRIMARY_NAV" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_MODIFY_SECONDARY_NAV" => ApplicationFramework::EVENTTYPE_CHAIN,
        "EVENT_URL_FIELD_CLICK" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_FULL_RECORD_VIEW" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_HTML_INSERTION_POINT" => ApplicationFramework::EVENTTYPE_DEFAULT,
        # --- Plugin Events
        "EVENT_PLUGIN_CONFIG_CHANGE" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_PLUGIN_EXTEND_EDIT_RESOURCE_COMPLETE_ACCESS_LIST" =>
            ApplicationFramework::EVENTTYPE_CHAIN,
        # --- Other Events
        "EVENT_OAIPMH_REQUEST" => ApplicationFramework::EVENTTYPE_DEFAULT,
        "EVENT_LOCAL_COLLECTION_STATS" => ApplicationFramework::EVENTTYPE_CHAIN,
    ];


    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get universal instance of class.
     * @return self Class instance.
     */
    public static function getInstance()
    {
        if (!isset(static::$Instance)) {
            static::$Instance = new static();
        }
        return static::$Instance;
    }

    /**
     * Bring operating environment up to readiness.
     * @return void
     */
    public function boot(): void
    {
        if ($this->BootComplete) {
            throw new Exception("Environment already loaded.");
        }

        # save our current directory then change to Metavus base directory
        $this->StartingDir = getcwd();
        chdir(dirname(__FILE__)."/..");

        # turn error reporting up to max if running local or at Scout
        $DevServers = [ "localhost", "test.scout.wisc.edu" ];
        if (in_array(($_SERVER["HTTP_HOST"] ?? ""), $DevServers)) {
            error_reporting(~0);
            date_default_timezone_set("America/Chicago");
            # (set time zone to prevent PHP local time zone warning from E_STRICT)
        }

        $this->adjustIPAddressForCloudflare();

        # load environmental files not loaded via any other mechanism
        require_once("include/StdLib.php");

        $this->loadBasicConfigInfo();
        $this->setUpDatabaseAccess();
        $this->initializeApplicationFramework();
        $this->definePrivilegeConstants();
        $this->setUpPluginManager();
        $this->runDatabaseUpgrades();
        $this->setUpGlobalUser();
        $this->setUpWebServerEnvironment();
        $this->setUpEmailDeliverySettings();
        $this->createTempDirectories();
        $this->setSoftwareVersion();
        $this->setUpPeriodicTasks();
        $this->setUpSearchAndRecommender();
        $this->setUpUserInterface();
        $this->setUpPageCaching();
        $this->setImageSizes();
        $this->setUpBackwardCompatibility();
        $this->loadPlugins();
        $this->setUpNavigationOptions();

        # reload environmental settings so that any system configuration changes made
        #       by plugins will be reflected
        $this->setUpWebServerEnvironment();
        $this->setUpEmailDeliverySettings();
        $this->setUpSearchAndRecommender();

        # return to original directory
        if ($this->StartingDir !== false) {
            chdir($this->StartingDir);
        }

        $this->AF->registerPageTitleFilteringCallback([$this, "filterPageTitle"]);

        $this->BootComplete = true;
    }

    /**
    * Tag the current page in the page cache as being associated with the
    * specified resource.
    * @param int $ResourceId ID of resource.
    * @return void
    */
    public function tagPageCacheForViewingResource($ResourceId): void
    {
        $this->AF->AddPageCacheTag($ResourceId);
    }

    /**
    * Clear appropriate entries from page cache when a resource is modified.
    * @param Record $Resource Resource that was modified.
    * @return void
    */
    public function clearPageCacheForModifiedResource($Resource): void
    {
        $this->AF->ClearPageCacheForTag($Resource->id());
        $this->AF->ClearPageCacheForTag("ResourceList".$Resource->getSchemaId());
        $this->AF->ClearPageCacheForTag("ResourceList");
    }

    /**
     * Add portal name to the supplied title.
     * @param ?string $Title The page title.
     * @return ?string The title with the portal name prepended.
     */
    public function filterPageTitle(?string $Title): ?string
    {
        $PortalName = InterfaceConfiguration::getInstance()->getString("PortalName");
        if (strlen($PortalName)) {
            if (($Title !== null) && strlen($Title)) {
                return $PortalName . " - " . $Title;
            }
            return $PortalName;
        }
        return $Title;
    }

    # ---- PRIVATE INTERFACE -----------------------------------------------------

    private $AF;
    private $StartingDir;
    private $BootComplete = false;

    protected static $Instance;

    /**
     * Object constructor.
     */
    protected function __construct()
    {
    }

    /**
     * Load basic configuration information.
     * @return void
     */
    private function loadBasicConfigInfo(): void
    {
        # if configuration file available in expected location
        if (is_readable("local/config.php")) {
            # load configuration file
            require_once("local/config.php");
        } elseif (is_readable("config.php")) {
            # load configuration file from legacy location
            require_once("config.php");
        } else {
            throw new Exception("Could not locate \"config.php\" file.");
        }
    }

    /**
     * Set up access to configured SQL database.
     * @return void
     */
    private function setUpDatabaseAccess(): void
    {
        # set up database access
        Database::setGlobalServerInfo(
            $GLOBALS["G_Config"]["Database"]["UserName"],
            $GLOBALS["G_Config"]["Database"]["Password"],
            $GLOBALS["G_Config"]["Database"]["Host"]
        );
        Database::setGlobalDatabaseName($GLOBALS["G_Config"]["Database"]["DatabaseName"]);

        # turn on database error display if PHP error display is enabled
        if (get_cfg_var("display_errors")) {
            Database::displayQueryErrors(true);
        }

        # set default storage engine for database server to MyISAM so any
        #       new tables support full text searching
        $DB = new Database();
        $DB->setDefaultStorageEngine("MyISAM");

        # set up slow query logging
        Database::setSlowQueryLoggingFn(
            ["\\ScoutLib\\ApplicationFramework", "logSlowDBQuery"]
        );

        # set up logging of database cache pruning activity
        Database::setCachePruneLoggingFn(
            ["\\ScoutLib\\ApplicationFramework", "logDBCachePrune"]
        );
    }

    /**
     * Initialize the application framework.
     * @return void
     */
    private function initializeApplicationFramework(): void
    {
        # initialize application framework
        ApplicationFramework::defaultNamespacePrefix("Metavus");
        if (isset($GLOBALS["G_Config"]["LocalNamespacePrefix"]) &&
            strlen($GLOBALS["G_Config"]["LocalNamespacePrefix"])) {
            ApplicationFramework::localNamespacePrefix(
                $GLOBALS["G_Config"]["LocalNamespacePrefix"]
            );
        }
        foreach ($this->ObjectDirectories as $Dir => $NamespacePrefix) {
            ApplicationFramework::addObjectDirectory($Dir, $NamespacePrefix);
        }
        $this->AF = ApplicationFramework::getInstance();
        $GLOBALS["AF"] = $this->AF;

        # clear AF caches if requested
        if (array_key_exists("StartUpOpt_CLEAR_AF_CACHES", $GLOBALS) &&
            $GLOBALS["StartUpOpt_CLEAR_AF_CACHES"]) {
            $this->AF->clearTemplateLocationCache();
            $this->AF->clearObjectLocationCache();
            $this->AF->clearPageCache();
        }

        $this->AF->logFile("local/logs/metavus.log");
        $this->AF->registerEvent($this->HookableEvents);
        $this->AF->addIncludeDirectories($this->IncludeDirectories);
        $this->AF->doNotUrlFingerprint("%lib/CKEditor%");

        # hook fallback image keyword handler
        $this->AF->registerInsertionKeywordCallback(
            "IMAGEURL",
            ["\\Metavus\\ImageFactory", "imageKeywordReplacementFallback"],
            ["Id", "Size"]
        );
    }

    /**
    * Make sure all needed temporary directories are available.
    * @return void
    */
    private function createTempDirectories(): void
    {
        $Cwd = getcwd();
        $Directories = ["tmp", "tmp/caches"];

        foreach ($Directories as $Dir) {
            # the directory must have a forward slash in the beginning since the
            # directory from getcwd() will not have a trailing slash
            $Dir = ($Dir[0] != "/") ? "/".$Dir : $Dir; // @phpstan-ignore-line

            $Dir = $Cwd.$Dir;
            if (!is_dir($Dir) && !file_exists($Dir)) {
                @mkdir($Dir);
            } elseif (is_dir($Dir) && !is_writable($Dir)) {
                @chmod($Dir, 0777);
            }
        }
    }

    /**
     * Set up constants for predefined privileges.
     * @return void
     */
    private function definePrivilegeConstants(): void
    {
        # set up constants for predefined privileges
        define("PRIV_SYSADMIN", 1);
        define("PRIV_NEWSADMIN", 2);
        define("PRIV_RESOURCEADMIN", 3);
        define("PRIV_CLASSADMIN", 5);
        define("PRIV_NAMEADMIN", 6);
        define("PRIV_RELEASEADMIN", 7);
        define("PRIV_USERADMIN", 8);
        define("PRIV_POSTCOMMENTS", 10);
        define("PRIV_USERDISABLED", 11);
        define("PRIV_COLLECTIONADMIN", 13);

        # define pseudo-privileges
        define("PRIV_ISLOGGEDIN", 75);
    }

    /**
     * Set up various backward compatibility measures.
     * @return void
     */
    private function setUpBackwardCompatibility(): void
    {
        # class_alias for PrivilegeSet to alleviate issues caused when
        # retrieving serialized objects created without namespace (in database)
        require_once("objects/PrivilegeSet.php");
        class_alias("Metavus\\PrivilegeSet", "PrivilegeSet");
        require_once("lib/ScoutLib/SearchParameterSet.php");
        class_alias("ScoutLib\\SearchParameterSet", "SearchParameterSet");

        # set legacy global variables
        $GLOBALS["DB"] = new Database();
        $GLOBALS["G_PluginManager"] = PluginManager::getInstance();

        # set up handlers for legacy events
        $this->AF->registerInsertionKeywordCallback(
            "FIELDEDIT",
            function ($FieldId, $RecordId) {
                if (!$this->AF->isHookedEvent("EVENT_APPEND_HTML_TO_FIELD_DISPLAY")) {
                    return "";
                }

                $SignalResult = $this->AF->signalEvent(
                    "EVENT_APPEND_HTML_TO_FIELD_DISPLAY",
                    [
                        "Field" => \Metavus\MetadataField::getField($FieldId),
                        "Resource" => new \Metavus\Record($RecordId),
                        "Context" => "EDIT",
                        "Html" => null,
                    ]
                );
                return $SignalResult["Html"];
            },
            ["FieldId", "RecordId"]
        );
        $this->AF->registerInsertionKeywordCallback(
            "FIELDVIEW",
            function ($FieldId, $RecordId) {
                if (!$this->AF->isHookedEvent("EVENT_APPEND_HTML_TO_FIELD_DISPLAY")) {
                    return "";
                }

                $SignalResult = $this->AF->signalEvent(
                    "EVENT_APPEND_HTML_TO_FIELD_DISPLAY",
                    [
                        "Field" => \Metavus\MetadataField::getField($FieldId),
                        "Resource" => new \Metavus\Record($RecordId),
                        "Context" => "DISPLAY",
                        "Html" => null,
                    ]
                );
                return $SignalResult["Html"];
            },
            ["FieldId", "RecordId"]
        );
    }

    /**
     * Set up global user instance and configure user-related settings.
     * @return void
     */
    private function setUpGlobalUser(): void
    {
        $SysConfig = SystemConfiguration::getInstance();

        # load current user, typically a \Metavus\User unless the USE_AXIS_USER
        #       startup option is specified and true
        if ($GLOBALS["StartUpOpt_USE_AXIS_USER"] ?? false) {
            $User = new \ScoutLib\User();
        } else {
            $User = new User();
        }

        # if the user from SESSION has logged out elsewhere, log them out here too
        if (!$User->isLoggedIn()) {
            $User->logout();
            $User = User::getAnonymousUser();
        }

        # set class value for current user
        User::setCurrentUser($User);

        # if user is logged in
        if ($User->isLoggedIn()) {
            # do not cache page
            $this->AF->DoNotCacheCurrentPage();

            # set up hook to clear login before running background tasks
            $LoginClearFunc = function () {
                $User = User::getAnonymousUser();
                User::setCurrentUser($User);
            };
            $this->AF->registerPreTaskExecutionCallback($LoginClearFunc);
        }

        # enable error display if logged in with admin privileges
        if ($User->hasPriv(PRIV_SYSADMIN)) {
            ini_set("display_errors", "1");
        }

        # set up password rules
        User::setPasswordRules(
            ($SysConfig->getBool("PasswordRequiresPunctuation") ?
                    User::PW_REQUIRE_PUNCTUATION : 0 ) |
            ($SysConfig->getBool("PasswordRequiresMixedCase") ?
                   User::PW_REQUIRE_MIXEDCASE : 0 ) |
            ($SysConfig->getBool("PasswordRequiresDigits") ?
                   User::PW_REQUIRE_DIGITS : 0 )
        );
        User::setPasswordMinLength($SysConfig->getInt("PasswordMinLength"));
        User::setPasswordMinUniqueChars($SysConfig->getInt("PasswordUniqueChars"));
    }

    /**
     * Set up email delivery settings.  IMPORTANT:  The active user interface
     * must be set before calling this method.
     * @return void
     */
    private function setUpEmailDeliverySettings(): void
    {
        $SysConfig = SystemConfiguration::getInstance();
        $IntConfig = InterfaceConfiguration::getInstance();

        # initialize saved email delivery settings if not previously set
        if (!$SysConfig->isSet("EmailDeliverySettings")) {
            $SysConfig->setString(
                "EmailDeliverySettings",
                Email::defaultDeliverySettings()
            );
        }

        # set email delivery settings
        Email::defaultDeliverySettings(
            $SysConfig->getString("EmailDeliverySettings")
        );

        # set default "From" address for emails
        Email::defaultFrom(trim($IntConfig->getString("PortalName"))
                ." <".trim($IntConfig->getString("AdminEmail")).">");

        # if we have a valid saved email line ending setting
        $LineEndings = [
            "CRLF" => "\r\n",
            "CR" => "\r",
            "LF" => "\n"
        ];
        $LineEndingSetting = $SysConfig->getString("EmailLineEnding");
        if (isset($LineEndings[$LineEndingSetting])) {
            # use the saved setting for email line endings
            Email::lineEnding($LineEndings[$LineEndingSetting]);
        } else {
            # otherwise default to CRLF for email line endings
            Email::lineEnding($LineEndings["CRLF"]);
        }
    }

    /**
     * Load software version number and set software version constants.
     * @return void
     */
    private function setSoftwareVersion(): void
    {
        # load software version number (if not already loaded)
        if (!defined("METAVUS_VERSION")) {
            if (file_exists("VERSION")) {
                $VersionArray = file("VERSION");
                if ($VersionArray !== false) {
                    define("METAVUS_VERSION", rtrim($VersionArray[0]));
                }
            }
        }
        if (!defined("METAVUS_VERSION")) {
            define("METAVUS_VERSION", "--");
        }

        # set CWIS_VERSION to METAVUS_VERSION + 4 if not already set
        #       and METAVUS_VERSION appears valid
        if (!defined("CWIS_VERSION")) {
            if (METAVUS_VERSION != "--") {
                $SplitMVVersion = explode(".", METAVUS_VERSION, 2);
                $NewCWISVersion = (string)(((int) $SplitMVVersion[0]) + 4)
                        .".".$SplitMVVersion[1];
                define("CWIS_VERSION", $NewCWISVersion);
            } else {
                define("CWIS_VERSION", "--");
            }
        }
    }

    /**
     * Set up search and recommender engines.
     * @return void
     */
    private function setUpSearchAndRecommender(): void
    {
        $SysConfig = SystemConfiguration::getInstance();

        # set priorities for search engine and recommender auto-update tasks
        SearchEngine::setUpdatePriority(
            $SysConfig->getInt("SearchEngineUpdatePriority")
        );
        Recommender::setUpdatePriority(
            $SysConfig->getInt("RecommenderEngineUpdatePriority")
        );

        # configure search engine facet support
        SearchEngine::numResourcesForFacets(
            $SysConfig->getInt("NumResourcesForSearchFacets")
        );

        # set default logic for search parameter sets
        SearchParameterSet::defaultLogic($SysConfig->getBool("SearchTermsRequired")
                ? "AND" : "OR");

        # hook supporting functions for search parameter set usage
        SearchParameterSet::canonicalFieldFunction(
            ["Metavus\\MetadataSchema", "getCanonicalFieldIdentifier"]
        );
        SearchParameterSet::printableFieldFunction(
            ["Metavus\\MetadataSchema", "getPrintableFieldName"]
        );
        SearchParameterSet::printableValueFunction(
            ["Metavus\\MetadataSchema", "getPrintableFieldValue"]
        );
        SearchParameterSet::setLegacyUrlTranslationFunction(
            ["Metavus\\MetadataSchema", "translateLegacySearchValues"]
        );
        SearchParameterSet::setTextDescriptionFilterFunction(
            ["Metavus\\SearchEngine", "filterTextDisplay"]
        );
    }

    /**
     * Set up page caching, including hooks for clearing page cache.
     * @return void
     */
    private function setUpPageCaching(): void
    {
        $this->AF->addPageCacheTag(
            "ResourceList",
            [
                "SearchResults"
            ]
        );
        $this->AF->addPageCacheTag(
            "ResourceList".MetadataSchema::SCHEMAID_DEFAULT,
            [
                "BrowseResources",
                "Home"
            ]
        );
        $this->AF->addPageCacheTag(
            "SearchResults",
            [
                "DisplayCollection",
                "ListCollections",
                "SearchResults"
            ]
        );

        $this->AF->hookEvent(
            "EVENT_FULL_RECORD_VIEW",
            [$this, "tagPageCacheForViewingResource"]
        );
        $this->AF->hookEvent(
            "EVENT_RESOURCE_MODIFY",
            [$this, "clearPageCacheForModifiedResource"]
        );
    }

    /**
     * Hook periodic events to be run.
     * @return void
     */
    private function setUpPeriodicTasks(): void
    {
        # hook FilePond cleanup to run hourly
        $this->AF->hookEvent(
            "EVENT_HOURLY",
            "Metavus\\FormUI::cleanFilePondUploadDir"
        );

        # hook file fixity checks to run daily
        $this->AF->hookEvent(
            "EVENT_DAILY",
            "Metavus\\FileFactory::queueFixityChecks"
        );
        $this->AF->hookEvent(
            "EVENT_DAILY",
            "Metavus\\ImageFactory::queueFixityChecks"
        );

        # hook scaled image expiration task to run daily
        $this->AF->hookEvent(
            "EVENT_DAILY",
            "Metavus\\ImageFactory::cleanOldScaledImages"
        );
    }

    /**
     * Perform any needed user-interface-related setup tasks.
     * @return void
     */
    private function setUpUserInterface(): void
    {
        $SysConfig = SystemConfiguration::getInstance();

        # set UI for logged-in user (or default UI if not logged in)
        $ActiveUI = $SysConfig->getString("DefaultActiveUI");
        $User = User::getCurrentUser();
        if ($User->isLoggedIn() && ($SysConfig->getBool("AllowMultipleUIsEnabled") ||
            $User->hasPriv(PRIV_SYSADMIN))) {
            $UserUI = $User->get("ActiveUI");
            if (!is_null($UserUI)) {
                $ActiveUI = $UserUI;
            }
        }
        $this->AF->activeUserInterface($ActiveUI);
        # (interface configuration must be loaded after active UI is set)
        $IntConfig = InterfaceConfiguration::getInstance();
        $this->AF->htmlCharset($IntConfig->getString("DefaultCharacterSet"));

        # configure record editing UI
        RecordEditingUI::groupsOpenByDefault(
            $IntConfig->getBool("CollapseMetadataFieldGroups") ? false : true
        );

        # add known exceptions for JavaScript minimization
        $this->AF->doNotMinimizeFile(["ckeditor.js"]);
    }

    /**
     * Configure server-related settings.
     * @return void
     */
    private function setUpWebServerEnvironment(): void
    {
        # set whether to prefer HTTP_HOST using the system configuration setting
        $SysConfig = SystemConfiguration::getInstance();
        ApplicationFramework::preferHttpHost($SysConfig->getBool("PreferHttpHost"));
    }

    /**
     * Set up plugin manager.  (Does not include loading plugins.)
     * @return void
     */
    private function setUpPluginManager(): void
    {
        PluginManager::setConfigValueLoader(["Metavus\\FormUI", "LoadValue"]);
        PluginManager::setApplicationFramework($this->AF);
        PluginManager::setPluginDirectories(["plugins", "local/plugins"]);
        PluginManager::setPluginDirListExpirationPeriod(
            $this->AF->objectLocationCacheExpirationInterval()
        );
    }

    /**
     * Run database upgrades (if Developer plugin is enabled).  Requires
     * plugin manager to have been previously set up.
     * @return void
     */
    private function runDatabaseUpgrades(): void
    {
        if (PluginManager::pluginIsSetToBeEnabled("Developer")) {
            require_once("plugins/Developer/Developer.php");
            if (\Metavus\Plugins\Developer::autoUpgradeShouldRun()) {
                $Messages = \Metavus\Plugins\Developer::checkForDatabaseUpgrades();
                if (PHP_SAPI == "cli") {
                    foreach ($Messages as $Message) {
                        print $Message."\n";
                    }
                }
            }
        }
    }

    /**
     * Load plugins.
     * @return void
     */
    private function loadPlugins(): void
    {
        # if plugin loading has not been forbidden
        if (!array_key_exists("StartUpOpt_DO_NOT_LOAD_PLUGINS", $GLOBALS) ||
            !$GLOBALS["StartUpOpt_DO_NOT_LOAD_PLUGINS"]) {
            # load plugins
            $PluginMgr = PluginManager::getInstance();
            $Result = $PluginMgr->loadPlugins(
                $GLOBALS["StartUpOpt_FORCE_PLUGIN_CONFIG_LOAD"] ?? false
            );

            # log any error messages from plugin loading
            if ($Result == false) {
                foreach ($PluginMgr->getErrorMessages() as $PluginName => $ErrMsgs) {
                    foreach ($ErrMsgs as $ErrMsg) {
                        $Msg = "Plugin Loading Error [".$PluginName."]: "
                                .strip_tags($ErrMsg);
                        $this->AF->logError(ApplicationFramework::LOGLVL_DEBUG, $Msg);
                    }
                }
            }
        }

        # set ownership of metadata fields by plugins
        MetadataSchema::setOwnerListRetrievalFunction(
            [PluginManager::getInstance(), "getActivePluginList"]
        );
        MetadataSchema::normalizeOwnedFields();
    }

    /**
     * Set up navigation menu options.
     * @return void
     */
    private function setUpNavigationOptions(): void
    {
        $PluginMgr = PluginManager::getInstance();
        if ($PluginMgr->pluginEnabled("SecondaryNavigation")) {
            $SecondaryNav = $PluginMgr->getPlugin("SecondaryNavigation");
            $SecondaryNav->offerNavItem(
                "Metadata Tool",
                "index.php?P=MDHome",
                new PrivilegeSet([
                    PRIV_RESOURCEADMIN,
                    PRIV_CLASSADMIN,
                    PRIV_NAMEADMIN,
                    PRIV_RELEASEADMIN
                ]),
                "Cataloging, metadata, and controlled vocabulary stats and tools."
            );
            $SecondaryNav->offerNavItem(
                "Administration",
                "index.php?P=SysAdmin",
                new PrivilegeSet([
                    PRIV_SYSADMIN,
                    PRIV_COLLECTIONADMIN,
                    PRIV_USERADMIN
                ]),
                "See administration commands and system statistics."
            );
            $SecondaryNav->offerNavItem(
                "Edit Users",
                "index.php?P=UserList",
                new PrivilegeSet([
                    PRIV_SYSADMIN,
                    PRIV_USERADMIN
                ]),
                "View and edit user accounts."
            );
            $Schema = new MetadataSchema();
            $SecondaryNav->offerNavItem(
                "Add Resource",
                str_replace('$ID', "NEW", $Schema->getEditPage())."&SC=".$Schema->id(),
                $Schema->authoringPrivileges(),
                "Create a new resource record."
            );
            $EmptyPrivilegeSet = new PrivilegeSet([]);
            $SecondaryNav->offerNavItem(
                "Advanced Search",
                "index.php?P=AdvancedSearch",
                $EmptyPrivilegeSet,
                "Perform an advanced search."
            );
            $SecondaryNav->offerNavItem(
                "List Collections",
                "index.php?P=ListCollections",
                $EmptyPrivilegeSet,
                "List collections of items."
            );
        }
    }

    /**
     * Load image size configuration.
     * @return void
     */
    private function setImageSizes(): void
    {
        $Sizes = $this->AF->getMultiValueInterfaceSetting("ImageSizes");

        # if no sizes were found, try clearing cache and reloading sizes
        if ($Sizes === null) {
            $this->AF->clearTemplateLocationCache();
            $Sizes = $this->AF->getMultiValueInterfaceSetting("ImageSizes");
            # if still no sizes found, log error and fall back to defaults
            if ($Sizes === null) {
                $this->AF->logError(
                    ApplicationFramework::LOGLVL_ERROR,
                    "No ImageSizes specifed for current interfaces."
                    ."Falling back to default values."
                );
                $Sizes = [
                    "mv-image-large" => "800x600",
                    "mv-image-preview" => "300x300",
                    "mv-image-thumbnail" => "150x150",
                    "mv-image-screenshot" => "224x224",
                ];
            }
        }

        foreach ($Sizes as $SizeName => $SizeDefinition) {
            unset($Width, $Height);
            $Options = [];

            $Pieces = preg_split("%\\s+%", $SizeDefinition, 0, PREG_SPLIT_NO_EMPTY);
            if ($Pieces === false) {
                $this->AF->logError(
                    ApplicationFramework::LOGLVL_ERROR,
                    "Parsing failed for image size ".$SizeName
                            ." (\"".$SizeDefinition."\")."
                );
                continue;
            }
            foreach ($Pieces as $Piece) {
                # if piece looks like it matches a defined image option constant
                $PossibleConstantName = "ScoutLib\\RasterImageFile::".$Piece;
                if (defined($PossibleConstantName)) {
                    # add constant to image size options
                    $Options[] = $PossibleConstantName;
                } else {
                    # if piece looks like it matches WxH (width and height in pixels)
                    if (preg_match("/^([0-9]+)x([0-9]+)$/", $Piece, $Matches)) {
                        # save width and height
                        $Width = (int)$Matches[1];
                        $Height = (int)$Matches[2];
                    } else {
                        $this->AF->logError(
                            ApplicationFramework::LOGLVL_ERROR,
                            "Unrecognized option for image size ".$SizeName
                                    ." (\"".$SizeDefinition."\")."
                        );
                    }
                }
            }

            if (isset($Width) && isset($Height)) {
                Image::addImageSize($SizeName, $Width, $Height, $Options);
            } else {
                $this->AF->logError(
                    ApplicationFramework::LOGLVL_ERROR,
                    "No dimensions specified for image size ".$SizeName."."
                );
            }
        }
    }

    /**
     * Adjust IP address in REMOTE_ADDR for Cloudflare's CDN. If the request
     * bears a CF-Connecting-IP HTTP header and came from Cloudflare's IP
     * range, update REMOTE_ADDR to reflect the IP of the client rather than
     * the Cloudflare proxy that is forwarding the request.
     * @see https://developers.cloudflare.com/fundamentals/reference/http-request-headers/
     * @return void
     */
    private function adjustIPAddressForCloudflare(): void
    {
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])
            && isset($_SERVER["REMOTE_ADDR"])
            && $this->isCloudflareIP($_SERVER["REMOTE_ADDR"])) {
            $_SERVER["REMOTE_ADDR"] = $_SERVER["HTTP_CF_CONNECTING_IP"];
        }
    }

    /**
     * Check if an IP is within Cloudflare's IP ranges.
     * @param string $IP Address of the client in dot-decimal format
     *   (e.g., 192.168.0.1)
     * @return bool TRUE for Cloudflare IPs, FALSE otherwise.
     */
    private function isCloudflareIP(string $IP): bool
    {
        # keep a local cache of CF's IP list
        $CacheFile = "local/data/caches/CloudflareIPAddressRangeList.txt";
        $MaxCacheAge = 2592000; # 30 days in seconds

        # if cache has not yet been created or is too old, refresh it
        if (!file_exists($CacheFile)
            || (time() - filemtime($CacheFile) > $MaxCacheAge)) {
            $Data = file_get_contents("https://www.cloudflare.com/ips-v4/");
            if ($Data !== false) {
                file_put_contents($CacheFile, $Data);
            }
        } else {
            $Data = file_get_contents($CacheFile);
        }

        # if cache could not be loaded, assume this isn't a CF IP
        if ($Data === false) {
            return false;
        }

        # otherwise see if remote IP matches one of the ranges
        $IPBinary = ip2long($IP);
        $CFRanges = explode("\n", $Data);
        foreach ($CFRanges as $Range) {
            list ($NetworkDottedQuad, $MaskBits) = explode('/', $Range);

            $NetworkBinary = ip2long($NetworkDottedQuad);
            $Mask = ~((1 << (32 - (int)$MaskBits)) - 1);
            if (($IPBinary & $Mask) == ($NetworkBinary & $Mask)) {
                return true;
            }
        }

        return false;
    }
}
