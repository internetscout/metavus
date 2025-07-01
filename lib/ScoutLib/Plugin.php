<?PHP
#
#   FILE:  Plugin.php
#
#   Part of the ScoutLib application support library
#   Copyright 2009-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use InvalidArgumentException;

/**
 * Base class for all plugins.
 */
abstract class Plugin
{
    # ----- PUBLIC INTERFACE -------------------------------------------------

    /**
     * Get universal instance of class.
     * @param bool $IgnoreReadyState If TRUE, the plugin will be returned
     *      even if it is not marked as ready for use.  (Defaults to FALSE.)
     * @return static Class instance.
     * @throws Exception if $IgnoreReadyState is FALSE and the plugin is not
     *      marked as ready for use.
     */
    public static function getInstance(bool $IgnoreReadyState = false)
    {
        $ClassName = get_called_class();
        if (!isset(self::$Instances[$ClassName])) {
            self::$Instances[$ClassName] = new static();
        }

        $Plugin = self::$Instances[$ClassName];
        if (!$IgnoreReadyState && !$Plugin->isReady()) {
            throw new Exception("Attempt to retrieve plugin that is not ready"
                    ." for use at ".StdLib::getMyCaller());
        }
        return $Plugin;
    }

    /**
     * Set the plugin attributes.  At minimum this method MUST set $this->Name
     * and $this->Version.  This is called when the plugin is loaded, and is
     * normally the only method called for disabled plugins (except for
     * setUpConfigOptions(), which is called for pages within the plugin
     * configuration interface).
     * @return void
     */
    abstract public function register();

    /**
     * Set up plugin configuration options.  This is called if the plugin is
     * enabled and/or when loading the plugin configuration interface.  Config
     * options must be set up using this method (rather than going into
     * register()) whenever their setup references data from outside of the
     * plugin in any fashion.  NOTE:  This method is called after the install()
     * or upgrade() methods are called.
     * @return null|string NULL if configuration setup succeeded, otherwise a
     *      string or array of strings containing error message(s) indicating
     *      why config setup failed.
     */
    public function setUpConfigOptions(): ?string
    {
        return null;
    }

    /**
     * Initialize the plugin.  This is called (if the plugin is enabled) after
     * all plugins have been loaded but before any methods for this plugin
     * (other than register()) have been called.
     * @return null|string NULL if initialization was successful, otherwise a
     *      string containing an error message indicating why initialization
     *      failed.
     */
    public function initialize(): ?string
    {
        return null;
    }

    /**
     * Hook methods to be called when specific events occur.
     * For events declared by other plugins the name string should start with
     * the plugin base (class) name followed by "::" and then the event name.
     * @return Array of method names to hook indexed by the event constants
     *       or names to hook them to.
     */
    public function hookEvents(): array
    {
        return [];
    }

    /**
     * Declare events defined by this plugin.  This is used when a plugin defines
     * new events that it signals or responds to.  Names of these events should
     * begin with the plugin base name, followed by "_EVENT_" and the event name
     * in all caps (for example "MyPlugin_EVENT_MY_EVENT").
     * @return Array with event names for the index and event types for the values.
     */
    public function declareEvents(): array
    {
        return [];
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return null|string NULL if installation succeeded, otherwise a string
     *      containing an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        return null;
    }

    /**
     * Perform any work needed when the plugin is upgraded to a new version
     * (for example, adding fields to database tables).
     * @param string $PreviousVersion The version number of this plugin that was
     *       previously installed.
     * @return null|string NULL if upgrade succeeded, otherwise a string containing
     *       an error message indicating why upgrade failed.
     */
    public function upgrade(string $PreviousVersion): ?string
    {
        # get list of upgrade files
        $UpgradeFiles = static::getUpgradeFiles($PreviousVersion, $this->Version);

        # load upgrade files
        foreach ($UpgradeFiles as $UpgradeClass => $UpgradeFile) {
            # load file
            require_once($UpgradeFile);

            # check that expected upgrade class is available
            if (!class_exists($UpgradeClass)) {
                return "Expected class \"".$UpgradeClass
                        ."\" not found in plugin upgrade file \""
                        .$UpgradeFile."\".";
            }
        }

        # perform upgrades
        foreach ($UpgradeFiles as $UpgradeClass => $UpgradeFile) {
            $PluginUpgrade = new $UpgradeClass();
            if (!($PluginUpgrade instanceof PluginUpgrade)) {
                return "Class \"".$UpgradeClass
                        ."\" in plugin upgrade file \"".$UpgradeFile
                        ."\" was not an instance of PluginUpgrade.";
            }
            $Result = $PluginUpgrade->performUpgrade();
            if ($Result !== null) {
                return $Result;
            }
        }

        # report success to caller
        return null;
    }

    /**
     * Perform any work needed when the plugin is uninstalled.
     * @return null|string NULL if uninstall succeeded, otherwise a string containing
     *       an error message indicating why uninstall failed.
     */
    public function uninstall(): ?string
    {
        return null;
    }

    /**
     * Retrieve plugin information.
     * @return Array of attribute values indexed by attribute names.
     */
    final public function getAttributes(): array
    {
        return [
            "Author" => $this->Author,
            "CfgPage" => $this->CfgPage,
            "CfgSetup" => $this->CfgSetup,
            "Description" => $this->Description,
            "Email" => $this->Email,
            "EnabledByDefault" => $this->EnabledByDefault,
            "InitializeAfter" => is_array($this->InitializeAfter)
                ? $this->InitializeAfter : [$this->InitializeAfter],
            "InitializeBefore" => is_array($this->InitializeBefore)
                ? $this->InitializeBefore : [$this->InitializeBefore],
            "Instructions" => $this->Instructions,
            "Name" => $this->Name,
            "Requires" => $this->Requires,
            "Url" => $this->Url,
            "Version" => $this->Version,
        ];
    }

    /**
     * Get plugin base name.
     * @return string Base name.
     */
    public static function getBaseName(): string
    {
        # split our fully-qualified class name into segments
        #       and return last segment to caller
        $Pieces = explode("\\", get_called_class());
        return array_pop($Pieces);
    }

    /**
     * Get/set plugin configuration setting.  The value returned may have
     * been overridden via configSettingOverride().
     * @param string $SettingName Name of configuration value.
     * @param mixed $NewValue New setting value, or NULL
     *      to clear current value.
     * @return mixed Requested value, or NULL if value was
     *      not set or there was no configuration value with the specified name.
     * @see Plugin::configSettingOverride()
     * @deprecated
     * @see Plugin::getConfigSetting()
     * @see Plugin::setConfigSetting()
     */
    final public function configSetting(string $SettingName, $NewValue = null)
    {
        if (func_num_args() > 1) {
            static::setConfigSetting($SettingName, $NewValue);
        }

        return static::getConfigSetting($SettingName);
    }

    /**
     * Set configuration setting for plugin of called class.
     * @param string $SettingName Name of configuration setting.
     * @param mixed $NewValue New value for setting.
     */
    public static function setConfigSetting(string $SettingName, $NewValue): void
    {
        # if caller requested that setting be cleared
        $BaseName = static::getBaseName();
        if ($NewValue === null) {
            # clear setting
            unset(self::$Cfg[$BaseName][$SettingName]);
        } else {
            # save new value for setting
            self::$Cfg[$BaseName][$SettingName] = $NewValue;
        }

        # save new configuration settings
        $DB = new Database();
        $DB->query("UPDATE PluginInfo SET Cfg = '"
            .$DB->escapeString(serialize(self::$Cfg[$BaseName]))
            ."' WHERE BaseName = '"
            .addslashes($BaseName) . "'");
    }

    /**
     * Get configuration setting for plugin of called class.
     * @param string $SettingName Name of configuration setting.
     * @return mixed Current value of setting, or override values, if one
     *      has been set.
     */
    public static function getConfigSetting(string $SettingName)
    {
        $BaseName = static::getBaseName();
        if (!isset(self::$Cfg[$BaseName])) {
            $DB = new Database();
            $CfgData = $DB->queryValue("SELECT Cfg FROM PluginInfo"
                    ." WHERE BaseName = '".addslashes($BaseName)
                    ."'", "Cfg");
            self::$Cfg[$BaseName] = StdLib::isSerializedData($CfgData)
                    ? unserialize($CfgData) : [];
        }
        return self::$CfgOver[$BaseName][$SettingName]
                ?? (self::$Cfg[$BaseName][$SettingName] ?? null);
    }

    /**
     * Get plugin configuration setting, ignoring any override value.
     * @param string $SettingName Name of configuration value.
     * @return string|int|array|null Requested value, or NULL if value was not
     *      set or there was no configuration value with the specified name.
     */
    final public function getSavedConfigSetting(string $SettingName)
    {
        # return current saved value of setting to caller
        return self::$Cfg[static::getBaseName()][$SettingName] ?? null;
    }

    /**
     * Get type of a plugin configuration setting.
     * @param string $SettingName Name of configuration value.
     * @return string Type of setting, as specified by the plugin, or NULL if
     *       no setting available by that name.
     */
    final public function getConfigSettingType(string $SettingName): ?string
    {
        return isset($this->CfgSetup[$SettingName])
            ? $this->CfgSetup[$SettingName]["Type"] : null;
    }

    /**
     * Get plugin configuration setting parameters.
     * @param string $SettingName Name of configuration value.
     * @return array Associative array with plugin config settings, as
     *       defined by plugin, or NULL if no setting available with the
     *       specified name.
     */
    final public function getConfigSettingParameters(string $SettingName): array
    {
        return isset($this->CfgSetup[$SettingName])
            ? $this->CfgSetup[$SettingName] : null;
    }

    /**
     * Set override for configuration setting, that will be returned
     * regardless of the current saved configuration setting value.  This
     * does not affect the saved setting value.
     * @param string $SettingName Name of configuration value.
     * @param string|int|array $Value New override Value.
     * @see Plugin::configSetting()
     */
    final public function configSettingOverride(string $SettingName, $Value): void
    {
        # check that setting name was valid
        if (!isset($this->CfgSetup[$SettingName])) {
            throw new InvalidArgumentException(
                "Unknown setting name (" . $SettingName . ")."
            );
        }

        # save override value
        self::$CfgOver[static::getBaseName()][$SettingName] = $Value;
    }

    /**
     * Get/set whether the plugin is ready for use.
     * @param bool $NewValue TRUE if plugin is ready for use, otherwise FALSE.
     *       (OPTIONAL)
     * @return bool TRUE if plugin is ready for use, otherwise FALSE.
     */
    public function isReady(?bool $NewValue = null): bool
    {
        # if new ready status was supplied
        if ($NewValue !== null) {
            # make sure we are being called from the plugin manager
            StdLib::checkMyCaller(
                "ScoutLib\PluginManager",
                "Attempt to update plugin ready status at %FILE%:%LINE%."
                . "  (Plugin ready status can only be set by PluginManager.)"
            );

            # update plugin ready status
            $this->Ready = $NewValue ? true : false;
        }

        # return current ready status to caller
        return $this->Ready;
    }

    /**
     * Get/set whether the plugin is enabled.  (This is the persistent setting
     * for enabling/disabling, not whether the plugin is currently working.)
     * @param bool $NewValue TRUE to enable, or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent TRUE to make new setting persistent, or FALSE
     *       for new setting to apply only to this page load.  (OPTIONAL,
     *       defaults to TRUE)
     * @return bool TRUE if plugin is enabled, otherwise FALSE.
     */
    public function isEnabled(?bool $NewValue = null, bool $Persistent = true): bool
    {
        # if new enabled status was suppled
        if ($NewValue !== null) {
            # save new status locally
            $this->Enabled = $NewValue ? true : false;

            # update enabled status in database if appropriate
            if ($Persistent) {
                $DB = new Database();
                $DB->query("UPDATE PluginInfo"
                    . " SET Enabled = " . ($NewValue ? "1" : "0")
                    . " WHERE BaseName = '" . addslashes(static::getBaseName()) . "'");
            }
        }

        # return current enabled status to caller
        return $this->Enabled;
    }

    /**
     * Get/set whether the plugin is installed.  This should only be set by
     * the plugin manager.
     * @param bool $NewValue TRUE to mark as installed, or FALSE to mark as
     *       not installed.  (OPTIONAL)
     * @return bool TRUE if plugin is installed, otherwise FALSE.
     */
    public function isInstalled(?bool $NewValue = null): bool
    {
        # if new install status was supplied
        if ($NewValue !== null) {
            # make sure we are being called from the plugin manager
            StdLib::checkMyCaller(
                "ScoutLib\PluginManager",
                "Attempt to update plugin install status at %FILE%:%LINE%."
                . "  (Plugin install status can only be set by PluginManager.)"
            );

            # update installation setting in database
            $this->Installed = $NewValue ? true : false;
            $DB = new Database();
            $DB->query("UPDATE PluginInfo"
                . " SET Installed = " . ($NewValue ? "1" : "0")
                . " WHERE BaseName = '" . addslashes(static::getBaseName()) . "'");
        }

        # return installed status to caller
        return $this->Installed;
    }

    /**
     * Get/set the last version recorded as installed.  This should only be
     * set by the plugin manager.
     * @param string $NewValue New installed version.  (OPTIONAL)
     * @return string Current installed version.
     */
    public function installedVersion(?string $NewValue = null): string
    {
        # if new version was supplied
        if ($NewValue !== null) {
            # make sure we are being called from the plugin manager
            StdLib::checkMyCaller(
                "ScoutLib\PluginManager",
                "Attempt to set installed version of plugin at %FILE%:%LINE%."
                . "  (Plugin installed version can only be set by PluginManager.)"
            );

            # update version in database
            $this->InstalledVersion = $NewValue;
            $DB = new Database();
            $DB->query("UPDATE PluginInfo"
                . " SET Version = '" . addslashes($NewValue) . "'"
                . " WHERE BaseName = '" . addslashes(static::getBaseName()) . "'");
        }

        # return current installed version to caller
        return $this->InstalledVersion;
    }

    /**
     * Get full name of plugin.
     * @return string Name.
     */
    public function getName(): string
    {
        return $this->Name;
    }

    /**
     * Get plugin version.
     * @return string Version.
     */
    public function getVersion(): string
    {
        return $this->Version;
    }

    /**
     * Get plugin description.
     * @return string Description.
     */
    public function getDescription(): string
    {
        return $this->Description;
    }

    /**
     * Get plugin instructions (if any).
     * @return string Instructions.
     */
    public function getInstructions(): string
    {
        return $this->Instructions;
    }

    /**
     * Get class file.
     * @return string File name with path.
     */
    public function getClassFile(): string
    {
        return (string)(new \ReflectionClass(get_class($this)))->getFileName();
    }

    /**
     * Get list of plugins upon which this plugin depends (if any).
     * @return array Versions of required plugins with base names
     *       for the index.
     */
    public function getDependencies(): array
    {
        return $this->Requires;
    }

    /**
     * Get the current plugin's data cache
     * @return DataCache Data Cache
     */
    public function getDataCache()
    {
        if (self::$DataCache === null) {
            self::$DataCache = new DataCache("Plugin-".self::getBaseName()."-");
        }
        return self::$DataCache;
    }

    /**
     * Set the application framework to be referenced within plugins.
     * (This is set by the plugin manager.)
     * @param ApplicationFramework $AF Application framework instance.
     */
    final public static function setApplicationFramework(ApplicationFramework $AF): void
    {
        self::$AF = $AF;
    }


    # ----- PROTECTED INTERFACE ----------------------------------------------

    /** Name of the plugin's author. */
    protected $Author = null;
    /** Text description of the plugin. */
    protected $Description = null;
    /** Contact email for the plugin's author. */
    protected $Email = null;
    /** Whether the plugin should be enabled by default when installed. */
    protected $EnabledByDefault = false;
    /** Plugins that should be initialized after us. */
    protected $InitializeBefore = [];
    /** Plugins that should be initialized before us. */
    protected $InitializeAfter = [];
    /** Instructions for configuring the plugin (displayed on the
     * automatically-generated configuration page if configuration
     * values are supplied). */
    protected $Instructions = null;
    /** Proper (human-readable) name of plugin. */
    protected $Name = null;
    /** Version number of plugin in the format X.X.X (for example: 1.2.12). */
    protected $Version = null;
    /** Web address for more information about the plugin. */
    protected $Url = null;

    /** Application framework. */
    protected static $AF;

    /**
     * Array with plugin base (class) names for the index and minimum version
     * numbers for the values.  Special indexes of "PHP" may be used to
     * specify a minimum required PHP version or "PHPX_xxx" to specify a required
     * PHP extension, where "xxx" is the extension name (e.g. "PHPX_GD").  The
     * version number value is ignored for PHP extensions.
     */
    protected $Requires = [];

    /**
     * Associative array describing the configuration values for the plugin.
     * The first index is the name of the configuration setting, and the second
     * indicates the type of information about that setting.
     */
    protected $CfgSetup = [];

    /**
     * Name of configuration page for plugin.
     */
    protected $CfgPage = null;


    # ----- PRIVATE INTERFACE ------------------------------------------------

    private $Enabled = false;           # whether plugin is enabled
    private $Installed = false;         # whether plugin has been installed
    private $InstalledVersion = false;  # version last installed
    private $Ready = false;             # whether plugin is ready for use

    private static $Cfg;                # configuration values
    private static $CfgOver;            # configuration override values
    private static $Instances;          # instantiated plugins
    private static $PluginInfoCache;    # cache of setting values from DB
    private static $DataCache = null;          # plugin data cache

    /**
     * Class constructor.  Plugins should be retrieved via getInstance(),
     * rather than instantiated directly.  Plugin child classes should perform
     * any needed setup in initialize(), rather than using a constructor.
     */
    private function __construct()
    {
        # register plugin
        $this->register();

        # load plugin info from database if necessary
        if (!isset(self::$PluginInfoCache)) {
            $DB = new Database();
            $DB->query("SELECT * FROM PluginInfo");
            while ($Row = $DB->fetchRow()) {
                self::$PluginInfoCache[$Row["BaseName"]] = $Row;
            }
        }

        # add plugin to database if not already in there
        $BaseName = static::getBaseName();
        if (!isset(self::$PluginInfoCache[$BaseName])) {
            if (!isset($DB)) {
                $DB = new Database();
            }
            $Attribs = $this->getAttributes();

            # lock tables to prevent inserting multiple rows
            $DB->query("LOCK TABLES PluginInfo WRITE");

            # re-run query just for this plugin in case our cache was stale
            $DB->query("SELECT * FROM PluginInfo WHERE BaseName = '"
                       . addslashes($BaseName) . "'");

            # insert row if needed and re-run query
            if ($DB->numRowsSelected() == 0) {
                $DB->query(
                    "INSERT INTO PluginInfo"
                    . " (BaseName, Version, Enabled)"
                    . " VALUES ('" . addslashes($BaseName) . "', "
                    . " '" . addslashes(
                        $Attribs["Version"]
                    ) . "', "
                    . " " . ($Attribs["EnabledByDefault"] ? 1 : 0) . ")"
                );
                $DB->query("SELECT * FROM PluginInfo WHERE BaseName = '"
                           . addslashes($BaseName) . "'");
            }

            # update cache and release lock
            self::$PluginInfoCache[$BaseName] = $DB->fetchRow();
            $DB->query("UNLOCK TABLES");
        }

        # set internal value
        $Info = self::$PluginInfoCache[$BaseName];
        $this->Enabled = $Info["Enabled"];
        $this->Installed = $Info["Installed"];
        $this->InstalledVersion = $Info["Version"];
        self::$Cfg[$BaseName] = (!is_null($Info["Cfg"]) && trim($Info["Cfg"]) !== "") ?
            unserialize($Info["Cfg"]) :
            null;
    }

    /**
     * Create database tables.  (Intended for use in Plugin::install() methods.)
     * @param array $Tables Array of table creation SQL, with table names for
     *       the index.  The class prefix may be omitted from the tables names
     *       used for the index (i.e. "MyTable" instead of "MyPlugin_MyTable").
     * @param Database $DB Database object to use.  (OPTIONAL)
     * @return string|null Error message or NULL if creation succeeded.
     * @deprecated
     * @see Database::createTables()
     */
    protected function createTables(array $Tables, ?Database $DB = null): ?string
    {
        if ($DB === null) {
            $DB = new Database();
        }
        $Tables = $this->normalizeTableDefsForLegacyMethods($Tables);
        return $DB->createTables($Tables);
    }

    /**
     * Create missing database tables.  (Intended for use in Plugin::upgrade()
     * methods.)  This will not error out if the table creation SQL includes
     * tables that already exist.
     * @param array $Tables Array of table creation SQL, with table names for
     *      the index.  The class prefix may be omitted from the tables names
     *      used for the index (i.e. "MyTable" instead of "MyPlugin_MyTable").
     * @return string|null Error message or NULL if creation succeeded.
     * @deprecated
     * @see Database::createMissingTables()
     */
    protected function createMissingTables(array $Tables): ?string
    {
        $DB = new Database();
        $Tables = $this->normalizeTableDefsForLegacyMethods($Tables);
        return $DB->createMissingTables($Tables);
    }

    /**
     * Drop database tables.  (Intended for use in Plugin::uninstall() methods.)
     * (This method as implemented here currently will not return an error message,
     * but callers should still check for and potentially handle an error message
     * because child classes or future updates to this method may do so.)
     * @param array $Tables Array of table creation SQL, with table names for
     *      the index.  The class prefix may be omitted from the tables names
     *      used for the index (i.e. "MyTable" instead of "MyPlugin_MyTable").
     * @return string|null Error message or NULL if table drops succeeded.
     * @deprecated
     * @see Database::dropTables()
     */
    protected function dropTables(array $Tables): ?string
    {
        $DB = new Database();
        $Tables = $this->normalizeTableDefsForLegacyMethods($Tables);
        return $DB->dropTables($Tables);
    }

    /**
     * Normalize table definitions in old format, for use in deprecated
     * legacy methods.
     * @param array $Tables Array of table creation SQL, with table names for
     *      the index.
     * @return array Array of table creation SQL, with class prefixes (i.e.
     *      plugin base names) added to the index where not already present.
     */
    private function normalizeTableDefsForLegacyMethods(array $Tables): array
    {
        $BaseName = static::getBaseName();
        $NormalizedTables = [];
        foreach ($Tables as $TableName => $TableSql) {
            if (strpos($TableName, $BaseName) !== 0) {
                $TableName = $BaseName."_".$TableName;
            }
            $NormalizedTables[$TableName] = $TableSql;
        }
        return $NormalizedTables;
    }

    /**
     * Queue task to run plugin method.  This should be used rather than
     * queueing the task directly via ApplicationFramework, to ensure that
     * the current instance of the plugin is used to run the task.
     * @param string $MethodName Method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL, pass NULL for no parameters)
     * @param int $Priority Priority to assign to task.  (OPTIONAL, defaults
     *       to PRIORITY_LOW)
     * @param string $Description Text description of task.  (OPTIONAL)
     * @throws InvalidArgumentException If method does not exist in plugin class.
     * @see ApplicationFramework::queueTask()
     */
    protected function queueTask(
        string $MethodName,
        ?array $Parameters = null,
        int $Priority = ApplicationFramework::PRIORITY_LOW,
        string $Description = ""
    ): void {
        $this->callAFTaskMethod(
            "queueUniqueTask",
            $MethodName,
            $Parameters,
            $Priority,
            $Description
        );
    }

    /**
     * Queue task to run plugin method, if not already in queue or currently
     * running.  This should be used rather than queueing the task directly
     * via ApplicationFramework, to ensure that the current instance of the
     * plugin is used to run the task.
     * @param string $MethodName Method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function or
     *       method.  (OPTIONAL, pass NULL for no parameters)
     * @param int $Priority Priority to assign to task.  (OPTIONAL, defaults
     *       to PRIORITY_LOW)
     * @param string $Description Text description of task.  (OPTIONAL)
     * @return bool TRUE if task was added, otherwise FALSE.
     * @throws InvalidArgumentException If method does not exist in plugin class.
     * @see ApplicationFramework::queueUniqueTask()
     */
    protected function queueUniqueTask(
        string $MethodName,
        ?array $Parameters = null,
        int $Priority = ApplicationFramework::PRIORITY_LOW,
        string $Description = ""
    ): bool {
        return (bool)$this->callAFTaskMethod(
            "queueUniqueTask",
            $MethodName,
            $Parameters,
            $Priority,
            $Description
        );
    }

    /**
     * Get number of queued tasks that match supplied values.  This should be
     * used rather than calling ApplicationFramework directly to get the count,
     * to ensure that the supplied values are interpreted correctly.
     * plugin is used to run the task.
     * @param string $MethodName Method to call to perform task.
     * @param array $Parameters Array containing parameters to pass to function
     *       or method.  Pass in empty array to match tasks with no parameters.
     *       (OPTIONAL)
     * @param int $Priority Priority to assign to task.  (OPTIONAL)
     * @param string $Description Text description of task.  (OPTIONAL)
     * @return int Number of tasks queued that match supplied parameters.
     */
    protected function getQueuedTaskCount(
        string $MethodName,
        ?array $Parameters = null,
        ?int $Priority = null,
        ?string $Description = null
    ): int {
        return (int)$this->callAFTaskMethod(
            "getQueuedTaskCount",
            $MethodName,
            $Parameters,
            $Priority,
            $Description
        );
    }

    /**
     * Encapsulate plugin method and use it to call task-related method
     * in ApplicationFramework.
     * @param string $AFMethodName ApplicationFramework method to call.
     * @param string $PluginMethodName Plugin method to call to perform task.
     * @param array|null $Parameters Array containing parameters to pass to function
     *       or method.  Pass in empty array to match tasks with no parameters.
     * @param int|null $Priority Priority to assign to task.
     * @param string|null $Description Text description of task.
     * @return int|bool Value returned by AF method.
     */
    private function callAFTaskMethod(
        string $AFMethodName,
        string $PluginMethodName,
        $Parameters,
        $Priority,
        $Description
    ) {
        if (!method_exists($this, $PluginMethodName)) {
            throw new InvalidArgumentException("Attempt to call"
                    ." ApplicationFramework task method ".$AFMethodName
                    ." with nonexistent plugin method (\"".$PluginMethodName."\").");
        }
        $Caller = new PluginCaller(static::getBaseName(), $PluginMethodName);
        $AFCallback = [ApplicationFramework::getInstance(), $AFMethodName];
        assert(is_callable($AFCallback), "Attempt to call nonexistent AF method.");
        return call_user_func(
            $AFCallback,
            [$Caller, "callPluginMethod"],
            $Parameters,
            $Priority,
            $Description
        );
    }

    /** @cond */
    /**
     * Set all configuration values (only for use by PluginManager).
     * @param array $NewValues Array of new configuration values.
     */
    final public function setAllCfg($NewValues): void
    {
        self::$Cfg[static::getBaseName()] = $NewValues;
    }
    /** @endcond */

    /**
     * Get list of available upgrade files for upgrading from specified
     * old plugin version to specfied new plugin version.
     * @param string $OldVersion Old version number if canonical format.
     * @param string $NewVersion New version number if canonical format.
     * @return array<string, string> Names of upgrade files with leading
     *      paths, indexed by fully-qualified names of upgrade classes.
     */
    protected static function getUpgradeFiles(
        string $OldVersion,
        string $NewVersion
    ): array {
        # get list of all core upgrade files for this plugin
        $PluginName = static::getBaseName();
        $CoreUpgradeFilePattern = "plugins/".$PluginName
                ."/install/PluginUpgrade_*.php";
        $CoreUpgradeFiles = glob($CoreUpgradeFilePattern);
        if ($CoreUpgradeFiles === false) {
            $CoreUpgradeFiles = [];
        }

        # get list of all local upgrade files for this plugin
        $LocalUpgradeFilePattern = "local/".$CoreUpgradeFilePattern;
        $LocalUpgradeFiles = glob($LocalUpgradeFilePattern);
        if ($LocalUpgradeFiles === false) {
            $LocalUpgradeFiles = [];
        }

        # add list of local upgrade files to end of list of core upgrade files
        $AllUpgradeFiles = array_merge($CoreUpgradeFiles, $LocalUpgradeFiles);

        # for all upgrade files found for this plugin
        $PluginClass = get_called_class();
        $UpgradeFiles = [];
        foreach ($AllUpgradeFiles as $UpgradeFile) {
            # extract upgrade version number from upgrade file name
            # (trim off leading path, "PluginUpgrade_", and ".php")
            $UpgradeVersion = substr(basename($UpgradeFile, ".php"), 14);
            # (convert underscores to periods)
            $UpgradeVersion = str_replace("_", ".", $UpgradeVersion);

            # if version is within upgrade range
            if (version_compare($UpgradeVersion, $OldVersion, ">")
                    && (version_compare($UpgradeVersion, $NewVersion, "<="))) {
                # add file to list (with fully-qualified upgrade class name for index)
                # (local versions will replace core versions if both are
                #       present, because they appear later in list of files)
                $UpgradeClass = $PluginClass."\\".basename($UpgradeFile, ".php");
                $UpgradeFiles[$UpgradeClass] = $UpgradeFile;
            }
        }

        # sort resulting upgrade file list by version number
        # (sort array by keys, using a "natural order" comparison)
        uksort($UpgradeFiles, "strnatcmp");

        return $UpgradeFiles;
    }
}
