<?PHP
#
#   FILE:  PluginManager.php
#
#   Part of the ScoutLib application support library
#   Copyright 2009-2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Plugin;
use ScoutLib\PluginCaller;

/**
 * Manager to load and invoke plugins.
 */
class PluginManager
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    # sub-namespace for plugins (with trailing backslash)
    const PLUGIN_SUBNAMESPACE = "Plugins\\";

    /**
     * Get universal instance of class.
     * @return self Class instance.
     */
    public static function getInstance()
    {
        if (!isset(self::$Instance)) {
            self::$Instance = new static();
        }
        return self::$Instance;
    }

    /**
     * Set application framework within which plugins should run.
     * @param ApplicationFramework $AF Initialized and running application
     *      framework instance within which plugins should run.
     */
    public static function setApplicationFramework(ApplicationFramework $AF)
    {
        if (isset(self::$Instance)) {
            throw new Exception("Application framework must be set before"
                    ." plugin manager is instantiated.");
        }
        self::$AF = $AF;
        Plugin::setApplicationFramework($AF);
    }

    /**
     * Set plugin directory list.
     * @param array $PluginDirectories Array of names of directories
     *       containing plugins, in the order they should be searched.
     */
    public static function setPluginDirectories(array $PluginDirectories)
    {
        if (isset(self::$Instance)) {
            throw new Exception("Plugin directory list must be set before"
                    ." plugin manager is instantiated.");
        }
        self::$PluginDirectories = $PluginDirectories;
    }

    /**
     * Set function to load plugin configuration values from data.
     * @param callable $Func Loading function, that accepts a configuration
     *      parameter type and a configuration value as arguments, and
     *      returns a normalized value.
     * @throws InvalidArgumentException If function is not callable.
     */
    public static function setConfigValueLoader(callable $Func)
    {
        if (isset(self::$Instance)) {
            throw new Exception("Configuration value loader must be set before"
                    ." plugin manager is instantiated.");
        }
        if (!is_callable($Func)) {
            throw new InvalidArgumentException(
                "Invalid configuration value loading function supplied."
            );
        }
        self::$CfgValueLoader = $Func;
    }

    /**
     * Set the cache lifetime for the plugin directory list.
     * @param int $MaxAge Maximum age for cached plugin directory lists in
     *   minutes.
     */
    public static function setPluginDirListExpirationPeriod(int $MaxAge)
    {
        self::$PluginDirListExpirationPeriod = $MaxAge;
    }

    /**
     * Load and initialize plugins.
     * @param bool $ForceLoading If TRUE, full plugin classes (rather than
     *      just .ini files, for disabled plugins) will always be loaded.
     *      (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if load was successful (no problems encountered),
     *       otherwise FALSE.
     */
    public function loadPlugins(bool $ForceLoading = false): bool
    {
        $ErrMsgs = array();

        # look for plugin files
        $this->PluginFiles = $this->findPluginFiles(self::$PluginDirectories);

        # load enabled/disabled state of all plugins
        $this->DB->query("SELECT BaseName, Enabled FROM PluginInfo");
        $Enabled = $this->DB->fetchColumn("Enabled", "BaseName");

        # for each plugin file found
        foreach ($this->PluginFiles as $PluginName => $PluginFileName) {
            if ($ForceLoading
                    || !isset($Enabled[$PluginName])
                    || $Enabled[$PluginName]) {
                try {
                    # attempt to load plugin
                    $Result = $this->loadPlugin($PluginName, $PluginFileName);

                    # add plugin to list of loaded plugins
                    $this->Plugins[$PluginName] = $Result;
                } catch (Exception $Exception) {
                    # save errors
                    $ErrMsgs[$PluginName][] = $Exception->getMessage();
                }
            }
        }

        # check dependencies and drop any plugins with failed dependencies
        $DepErrMsgs = $this->checkDependencies($this->Plugins);
        $DisabledPlugins = array();
        foreach ($DepErrMsgs as $PluginName => $Msgs) {
            $DisabledPlugins[] = $PluginName;
            foreach ($Msgs as $Msg) {
                $ErrMsgs[$PluginName][] = $Msg;
            }
        }

        # sort plugins according to any loading order requests
        $this->Plugins = $this->sortPluginsByInitializationPrecedence(
            $this->Plugins
        );

        # for each plugin
        foreach ($this->Plugins as $PluginName => $Plugin) {
            # if plugin is loaded and enabled
            if (!in_array($PluginName, $DisabledPlugins)
                && $Plugin->isEnabled()) {
                # attempt to make plugin ready
                try {
                    $Result = $this->readyPlugin($Plugin);
                } catch (Exception $Except) {
                    $Result = array("Uncaught Exception: " . $Except->getMessage());
                }

                # if making plugin ready failed
                if ($Result !== null) {
                    # save error messages
                    foreach ($Result as $Msg) {
                        $ErrMsgs[$PluginName][] = $Msg;
                    }
                } else {
                    # mark plugin as ready
                    $Plugin->isReady(true);
                }
            }
        }

        # check plugin dependencies again in case an install or upgrade failed
        $DepErrMsgs = $this->checkDependencies($this->Plugins, true);

        # for any plugins that were disabled because of dependencies
        foreach ($DepErrMsgs as $PluginName => $Msgs) {
            # make sure all plugin hooks are undone
            $this->unhookPlugin($this->Plugins[$PluginName]);

            # mark the plugin as unready
            $this->Plugins[$PluginName]->isReady(false);

            # record any errors that were reported
            foreach ($Msgs as $Msg) {
                $ErrMsgs[$PluginName][] = $Msg;
            }
        }

        # save any error messages for later use
        $this->ErrMsgs = $ErrMsgs;

        # report to caller whether any problems were encountered
        return count($ErrMsgs) ? false : true;
    }

    /**
     * Retrieve any error messages generated during plugin loading.
     * @return Array of arrays of error messages, indexed by plugin base
     *       (class) name.
     */
    public function getErrorMessages(): array
    {
        return $this->ErrMsgs;
    }

    /**
     * Retrieve specified plugin.
     * @param string $PluginName Base name of plugin.
     * @param bool $EvenIfNotReady Return the plugin even if it's not
     *       marked as ready for use.  (OPTIONAL, defaults to FALSE)
     * @return mixed Plugin object or NULL if no plugin found with specified name.
     * @throws Exception If plugin is not initialized and ready.
     */
    public function getPlugin(string $PluginName, bool $EvenIfNotReady = false)
    {
        if (!$EvenIfNotReady && array_key_exists($PluginName, $this->Plugins)
            && !$this->Plugins[$PluginName]->isReady()) {
            $ExceptionMsg = "Attempt to access uninitialized plugin "
                    .$PluginName." from ".StdLib::getMyCaller();
            $ErrMsgs = $this->getErrorMessages();
            if (isset($ErrMsgs[$PluginName])) {
                $ExceptionMsg .= " (Errors: ".implode(", ", $ErrMsgs[$PluginName]).")";
            }
            throw new Exception($ExceptionMsg);
        }
        return isset($this->Plugins[$PluginName])
            ? $this->Plugins[$PluginName] : null;
    }

    /**
     * Retrieve all loaded plugins.
     * @return array Plugin objects, with base names for the index.
     */
    public function getPlugins(): array
    {
        return $this->Plugins;
    }

    /**
     * Retrieve plugin for current page (if any).  This method relies on the
     * current page having been found within the plugin directory (usually via a
     * "P_" prefix on the page name) via a call to the hooked findPluginPhpFile()
     * or findPluginHtmlFile() methods..
     * @return Plugin object or NULL if no plugin associated with current page.
     */
    public function getPluginForCurrentPage()
    {
        return $this->getPlugin($this->PageFilePlugin);
    }

    /**
     * Retrieve info about currently loaded plugins.
     * @return Array of arrays of plugin info, indexed by plugin base (class) name
     *       and sorted by case-insensitive plugin name.
     */
    public function getPluginAttributes(): array
    {
        # for each loaded plugin
        $Info = array();
        foreach ($this->Plugins as $PluginName => $Plugin) {
            # retrieve plugin attributes
            $Info[$PluginName] = $Plugin->getAttributes();

            # add in other values to attributes
            $Info[$PluginName]["Enabled"] = $Plugin->isEnabled();
            $Info[$PluginName]["Installed"] = $Plugin->isInstalled();
            $Info[$PluginName]["ClassFile"] = $this->PluginFiles[$PluginName];
        }

        # sort plugins by name
        uasort($Info, function ($A, $B) {
            $AName = strtoupper($A["Name"]);
            $BName = strtoupper($B["Name"]);
            return ($AName == $BName) ? 0
                : (($AName < $BName) ? -1 : 1);
        });

        # return plugin info to caller
        return $Info;
    }

    /**
     * Returns a list of plugins dependent on the specified plugin.
     * @param string $PluginName Base name of plugin.
     * @return Array of base names of dependent plugins.
     */
    public function getDependents(string $PluginName): array
    {
        $Dependents = array();
        $AllAttribs = $this->getPluginAttributes();
        foreach ($AllAttribs as $Name => $Attribs) {
            if (array_key_exists($PluginName, $Attribs["Requires"])) {
                $Dependents[] = $Name;
                $SubDependents = $this->getDependents($Name);
                $Dependents = array_merge($Dependents, $SubDependents);
            }
        }
        return $Dependents;
    }

    /**
     * Get list of active (i.e. enabled and ready) plugins.
     * @return array Base names of active plugins.
     */
    public function getActivePluginList(): array
    {
        $ActivePluginNames = array();
        foreach ($this->Plugins as $PluginName => $Plugin) {
            if ($Plugin->isReady()) {
                $ActivePluginNames[] = $PluginName;
            }
        }
        return $ActivePluginNames;
    }

    /**
     * Get/set whether specified plugin is enabled.  Enabling a plugin that
     * is currently disabled will not take effect until the next page load.
     * @param string $PluginName Base name of plugin.
     * @param bool $NewValue TRUE to enable, FALSE to disable.  (OPTIONAL)
     * @return bool TRUE if plugin is enabled, otherwise FALSE.
     * @throws InvalidArgumentException If invalid plugin name is supplied.
     */
    public function pluginEnabled(string $PluginName, bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            if (isset($this->Plugins[$PluginName])) {
                $this->Plugins[$PluginName]->isEnabled($NewValue);
            } else {
                $this->DB->query("UPDATE PluginInfo"
                    . " SET Enabled = " . ($NewValue ? "1" : "0")
                    . " WHERE BaseName = '" . addslashes($PluginName) . "'");
                if ($this->DB->numRowsAffected() != 1) {
                    $Action = $NewValue ? "enable" : "disable";
                    throw new InvalidArgumentException("Attempt to ".$Action
                            ." unknown plugin (".$PluginName.").");
                }
            }
        }
        return !isset($this->Plugins[$PluginName]) ? false
            : $this->Plugins[$PluginName]->isEnabled();
    }

    /**
     * Check whether specific plugin is enabled.  This is different from
     * pluginEnabled() in that it can be called without having called
     * loadPlugins() first or even instantiating PluginManager, and only
     * reports on the saved enable/disable value for the specified plugin.
     * @param string $PluginName Base name of plugin.
     * @return bool TRUE if plugin is set to be enabled, otherwise FALSE.
     */
    public static function pluginIsSetToBeEnabled(string $PluginName): bool
    {
        $DB = new Database();
        $Setting = $DB->queryValue("SELECT Enabled FROM PluginInfo"
                ." WHERE BaseName = '".addslashes($PluginName)."'", "Enabled");
        return ($Setting == 1);
    }

    /**
     * Get whether specified plugin is ready for use.  This should be used
     * to check whether a plugin can be used, rather than pluginEnabled(),
     * because plugins can be set to "Enabled" but not be ready to use due
     * to issues like an initialization failure.
     * @param string $PluginName Base name of plugin.
     * @return bool TRUE if plugin is enabled and ready for use.
     */
    public function pluginReady(string $PluginName): bool
    {
        return (array_key_exists($PluginName, $this->Plugins)
                && $this->Plugins[$PluginName]->isReady());
    }

    /**
     * Uninstall plugin and (optionally) delete any associated data.
     * @param string $PluginName Base name of plugin.
     * @return string|null Error message or NULL if uninstall succeeded.
     */
    public function uninstallPlugin(string $PluginName)
    {
        # assume success
        $Result = null;

        # if plugin is installed
        if ($this->Plugins[$PluginName]->isInstalled()) {
            if (!$this->Plugins[$PluginName]->isEnabled()) {
                $this->recognizePluginDirectories(
                    $this->Plugins[$PluginName]->getBaseName()
                );
            }

            # call uninstall method for plugin
            $Result = $this->Plugins[$PluginName]->uninstall();

            # if plugin uninstall method succeeded
            if ($Result === null) {
                # remove plugin info from database
                $this->DB->query("DELETE FROM PluginInfo"
                    . " WHERE BaseName = '" . addslashes($PluginName) . "'");

                # drop our data for the plugin
                unset($this->Plugins[$PluginName]);
                unset($this->PluginFiles[$PluginName]);

                self::$AF->clearPageCache();
                self::$AF->clearObjectLocationCache();
            }
        }

        # report results (if any) to caller
        return $Result;
    }


    /**
     * Clear cached data.
     */
    public function clearCaches()
    {
        $this->DB->query(
            "UPDATE PluginInfo SET DirectoryCache=NULL, DirectoryCacheLastUpdatedAt=NULL"
        );
        $this->PluginDirLists = [];
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $DB;
    private $ErrMsgs = array();
    private $PageFilePlugin = null;
    private $Plugins = array();
    private $PluginFiles = array();
    private $PluginHasDir = array();
    private $PluginDirLists = array();

    private static $AF;
    private static $CfgValueLoader;
    private static $Instance;
    private static $PluginDirectories;
    private static $PluginDirListExpirationPeriod = 60;

    /**
     * PluginManager class constructor.
     */
    protected function __construct()
    {
        if (!isset(self::$AF)) {
            throw new Exception("Application framework must be set before"
                    ." plugin manager is instantiated.");
        }
        if (!isset(self::$PluginDirectories)) {
            throw new Exception("Plugin directory list must be set before"
                    ." plugin manager is instantiated.");
        }
        if (!isset(self::$CfgValueLoader)) {
            throw new Exception("Configuration value loader must be set before"
                    ." plugin manager is instantiated.");
        }

        # get our own database handle
        $this->DB = new Database();

        # hook into events to load plugin PHP and HTML files
        self::$AF->hookEvent(
            "EVENT_PHP_FILE_LOAD",
            array($this, "findPluginPhpFile"),
            ApplicationFramework::ORDER_LAST
        );
        self::$AF->hookEvent(
            "EVENT_HTML_FILE_LOAD",
            array($this, "findPluginHtmlFile"),
            ApplicationFramework::ORDER_LAST
        );

        # load cache of plugin directories
        $this->loadDirectoryListCache();

        # tell PluginCaller helper object how to get to us
        PluginCaller::$Manager = $this;
    }

    /**
     * Search for available plugins.  If both a single file version and a
     * directory-based version of the same plugin are present, the directory
     * version will be returned.
     * @param array $DirsToSearch Array of strings containing names of
     *       directories in which to look for plugin files.
     * @return array Array of plugin base file names, with base plugin names
     *       for the index.
     */
    private function findPluginFiles(array $DirsToSearch): array
    {
        # for each directory
        $PluginFiles = array();
        foreach ($DirsToSearch as $Dir) {
            # if directory exists
            if (is_dir($Dir)) {
                # for each file in directory
                # (plugin directories will be in the list before
                #       similarly-named (single-file) plugin files,
                #       so directory versions will be preferred over
                #       single-file versions)
                $FileNames = scandir($Dir);
                if ($FileNames === false) {
                    continue;
                }
                foreach ($FileNames as $FileName) {
                    # if file looks like base plugin file
                    if (preg_match("/^[a-zA-Z_][a-zA-Z0-9_]*\.php$/", $FileName)) {
                        # if we do not already have a plugin with that name
                        $PluginName = substr($FileName, 0, -4);
                        if (!isset($PluginFiles[$PluginName])) {
                            # add file to list
                            $PluginFiles[$PluginName] = $Dir . "/" . $FileName;
                        }
                        # else if file looks like plugin directory
                    } elseif (is_dir($Dir . "/" . $FileName)
                        && preg_match("/^[a-zA-Z_][a-zA-Z0-9_]*/", $FileName)) {
                        # if there is a base plugin file in the directory
                        $PluginName = $FileName;
                        $PluginFile = $Dir . "/" . $PluginName . "/" . $PluginName . ".php";
                        if (file_exists($PluginFile)) {
                            # add file to list
                            $PluginFiles[$PluginName] = $PluginFile;
                        } else {
                            # record error
                            $this->ErrMsgs[$PluginName][] =
                                "Expected plugin file <i>" . $PluginName . ".php</i> not"
                                . " found in plugin subdirectory <i>"
                                . $Dir . "/" . $PluginName . "</i>";
                        }
                    }
                }
            }
        }

        # return info about found plugins to caller
        return $PluginFiles;
    }

    /**
     * Attempt to load plugin.
     * @param string $PluginName Base name (class) of plugin.
     * @param string $PluginFileName Full path to plugin class file.
     * @return Plugin New plugin object.
     * @throws Exception If plugin class is not defined in plugin file.
     * @throws Exception If plugin class is not a descendent of Plugin.
     * @throws Exception If plugin class did not have a required attribute set.
     */
    private function loadPlugin(string $PluginName, string $PluginFileName): Plugin
    {
        # bring in plugin class file
        include_once($PluginFileName);

        # check to make sure plugin class is defined by file
        $NamespacePrefix = ApplicationFramework::defaultNamespacePrefix();
        $LocalNamespacePrefix = ApplicationFramework::localNamespacePrefix();
        if ((strpos($PluginFileName, "local/") === 0) && strlen($LocalNamespacePrefix)) {
            $NamespacePrefix = $LocalNamespacePrefix;
        }
        $NamespacedPluginClassName =
                $NamespacePrefix.self::PLUGIN_SUBNAMESPACE.$PluginName;
        if (class_exists($NamespacedPluginClassName, false)) {
            $PluginClassName = $NamespacedPluginClassName;
        } elseif (class_exists($PluginName, false)) {
            $PluginClassName = $PluginName;
        } else {
            throw new Exception("Expected class <i>".$NamespacedPluginClassName
                ."</i> not found in plugin file <i>"
                .$PluginFileName."</i>");
        }

        # check that plugin class is a valid descendant of base plugin class
        if (!is_subclass_of($PluginClassName, "ScoutLib\Plugin")) {
            throw new Exception("Plugin <b>" . $PluginName . "</b>"
                . " could not be loaded because <i>" . $PluginName . "</i> class"
                . " was not a subclass of base <i>Plugin</i> class");
        }

        # instantiate and register the plugin
        $Plugin = new $PluginClassName($PluginName);

        # check required plugin attributes
        $RequiredAttribs = array("Name", "Version");
        $Attribs = $Plugin->getAttributes();
        foreach ($RequiredAttribs as $AttribName) {
            if (!strlen($Attribs[$AttribName])) {
                throw new Exception("Plugin <b>" . $PluginName . "</b>"
                    . " could not be loaded because it"
                    . " did not have a <i>"
                    . $AttribName . "</i> attribute set.");
            }
        }

        # return loaded plugin
        return $Plugin;
    }

    /**
     * Attempt to bring loaded plugin to ready state.
     * @param Plugin $Plugin Plugin to ready.
     * @return array|null Error messages or NULL if no errors.
     */
    private function readyPlugin(Plugin &$Plugin)
    {
        # tell system to recognize any plugin subdirectories
        $this->recognizePluginDirectories($Plugin->getBaseName());

        # install or upgrade plugin if needed
        $PluginInstalled = $this->installPlugin($Plugin);

        # if install/upgrade failed
        if (is_string($PluginInstalled)) {
            # report errors to caller
            return array($PluginInstalled);
        }

        # set up plugin configuration options
        $ErrMsgs = $Plugin->setUpConfigOptions();

        # if plugin configuration setup failed
        if ($ErrMsgs !== null) {
            # report errors to caller
            return is_array($ErrMsgs) ? $ErrMsgs : array($ErrMsgs);
        }

        # set default configuration values if necessary
        if ($PluginInstalled) {
            $this->setPluginDefaultConfigValues($Plugin);
        }

        # initialize the plugin
        $ErrMsgs = $Plugin->initialize();

        # if initialization failed
        if ($ErrMsgs !== null) {
            # report errors to caller
            return is_array($ErrMsgs) ? $ErrMsgs : array($ErrMsgs);
        }

        # register and hook any events for plugin
        $ErrMsgs = $this->hookPlugin($Plugin);

        # make sure all hooks are undone if hooking failed
        if ($ErrMsgs !== null) {
            $this->unhookPlugin($Plugin);
        }

        # report result to caller
        return $ErrMsgs;
    }

    /**
     * Register and hook any events for plugin.
     * @param Plugin $Plugin Plugin to hook.
     * @return array|null Error messages or NULL if no errors.
     */
    private function hookPlugin(Plugin &$Plugin)
    {
        # register any events declared by plugin
        $Events = $Plugin->declareEvents();
        if (count($Events)) {
            self::$AF->registerEvent($Events);
        }

        # if plugin has events that need to be hooked
        $EventsToHook = $Plugin->hookEvents();
        if (count($EventsToHook)) {
            # for each event
            $ErrMsgs = array();
            foreach ($EventsToHook as $EventName => $PluginMethods) {
                # for each method to hook for the event
                if (!is_array($PluginMethods)) {
                    $PluginMethods = array($PluginMethods);
                }
                foreach ($PluginMethods as $PluginMethod) {
                    # if the event only allows static callbacks
                    if (self::$AF->isStaticOnlyEvent($EventName)) {
                        # hook event with shell for static callback
                        $Caller = new PluginCaller(
                            $Plugin->getBaseName(),
                            $PluginMethod
                        );
                        $Result = self::$AF->hookEvent(
                            $EventName,
                            array($Caller, "CallPluginMethod")
                        );
                    } else {
                        # hook event
                        $Result = self::$AF->hookEvent(
                            $EventName,
                            array($Plugin, $PluginMethod)
                        );
                    }

                    # record any errors
                    if ($Result === false) {
                        $ErrMsgs[] = "Unable to hook requested event <i>"
                            . $EventName . "</i> for plugin <b>"
                            . $Plugin->getBaseName() . "</b>";
                    }
                }
            }

            # if event hook setup failed
            if (count($ErrMsgs)) {
                # report errors to caller
                return $ErrMsgs;
            }
        }

        # report success to caller
        return null;
    }

    /**
     * Unhook any events for plugin.
     * @param Plugin $Plugin Plugin to unhook.
     */
    private function unhookPlugin(Plugin &$Plugin)
    {
        # if plugin had events to hook
        $EventsToHook = $Plugin->hookEvents();
        if (count($EventsToHook)) {
            # for each event
            $ErrMsgs = array();
            foreach ($EventsToHook as $EventName => $PluginMethods) {
                # for each method to hook for the event
                if (!is_array($PluginMethods)) {
                    $PluginMethods = array($PluginMethods);
                }
                foreach ($PluginMethods as $PluginMethod) {
                    # if the event only allows static callbacks
                    if (self::$AF->isStaticOnlyEvent($EventName)) {
                        # unhook event with shell for static callback
                        $Caller = new PluginCaller(
                            $Plugin->getBaseName(),
                            $PluginMethod
                        );
                        self::$AF->unhookEvent(
                            $EventName,
                            array($Caller, "CallPluginMethod")
                        );
                    } else {
                        # unhook event
                        self::$AF->unhookEvent(
                            $EventName,
                            array($Plugin, $PluginMethod)
                        );
                    }
                }
            }
        }
    }

    /**
     * Install or upgrade specified plugin if needed.  Any errors encountered
     * cause entries to be added to the $this->ErrMsgs array.
     * @param Plugin $Plugin Plugin to install.
     * @return mixed TRUE if plugin was upgraded/installed, FALSE if no upgrade
     *       or install was needed, or error message if install/upgrade failed.
     */
    private function installPlugin(Plugin &$Plugin)
    {
        # if plugin has not been installed
        $InstallOrUpgradePerformed = false;
        $PluginName = $Plugin->getBaseName();
        $Attribs = $Plugin->getAttributes();
        $LockName = __CLASS__ . ":Install:" . $PluginName;
        if (!$Plugin->isInstalled()) {
            # set default values if present
            $this->setPluginDefaultConfigValues($Plugin, true);

            # try to get lock to prevent anyone else from trying to run
            #       install or upgrade at the same time
            $GotLock = self::$AF->getLock($LockName, false);

            # if could not get lock
            if (!$GotLock) {
                # return error
                return "Installation of plugin <b>"
                    . $PluginName . "</b> in progress.";
            }

            # install plugin
            $ErrMsg = $Plugin->install();
            $InstallOrUpgradePerformed = true;

            # if install succeeded
            if ($ErrMsg == null) {
                # mark plugin as installed
                $Plugin->isInstalled(true);

                # release lock
                self::$AF->releaseLock($LockName);
            } else {
                # release lock
                self::$AF->releaseLock($LockName);

                # return error message about installation failure
                return "Installation of plugin <b>"
                    . $PluginName . "</b> failed: <i>" . $ErrMsg . "</i>";
            }
        } else {
            # if plugin version is newer than version in database
            if (version_compare(
                $Attribs["Version"],
                $Plugin->installedVersion()
            ) == 1) {
                # set default values for any new configuration settings
                $this->setPluginDefaultConfigValues($Plugin);

                # try to get lock to prevent anyone else from trying to run
                #       upgrade or install at the same time
                $GotLock = self::$AF->getLock($LockName, false);

                # if could not get lock
                if (!$GotLock) {
                    # return error
                    return "Upgrade of plugin <b>"
                        . $PluginName . "</b> in progress.";
                }

                # upgrade plugin
                try {
                    $ErrMsg = $Plugin->upgrade($Plugin->installedVersion());
                    $InstallOrUpgradePerformed = true;
                } catch (Exception $Except) {
                    $ErrMsg = "Uncaught Exception: ".$Except->getMessage();
                }

                # if upgrade succeeded
                if ($ErrMsg === null) {
                    # update plugin version in database
                    $Plugin->installedVersion($Attribs["Version"]);

                    # release lock
                    self::$AF->releaseLock($LockName);
                } else {
                    # release lock
                    self::$AF->releaseLock($LockName);

                    # report error message about upgrade failure
                    return "Upgrade of plugin <b>"
                        . $PluginName . "</b> from version <i>"
                        . addslashes($Plugin->installedVersion())
                        . "</i> to version <i>"
                        . addslashes($Attribs["Version"]) . "</i> failed: <i>"
                        . $ErrMsg . "</i>";
                }
                # else if plugin version is older than version in database
            } elseif (version_compare(
                $Attribs["Version"],
                $Plugin->installedVersion()
            ) == -1) {
                # return error message about version conflict
                return "Plugin <b>"
                    . $PluginName . "</b> is older (<i>"
                    . addslashes($Attribs["Version"])
                    . "</i>) than previously-installed version (<i>"
                    . addslashes($Plugin->installedVersion())
                    . "</i>).";
            }
        }

        # report result to caller
        return $InstallOrUpgradePerformed;
    }

    /**
     * Tell system to recognize any plugin subdirectories for class and
     * interface file loading.
     * @param string $PluginName Base name (class) of plugin.
     */
    private function recognizePluginDirectories(string $PluginName)
    {
        # if plugin has its own subdirectory
        $PluginFileName = $this->PluginFiles[$PluginName];
        $this->PluginHasDir[$PluginName] = preg_match(
            "%/" . $PluginName . "/" . $PluginName . ".php\$%",
            $PluginFileName
        ) ? true : false;


        if (!$this->PluginHasDir[$PluginName]) {
            return;
        }

        # if plugin has its own object directory
        $Namespace = "Plugins\\".$PluginName;

        $DirLists = $this->getPluginDirectoryLists($PluginName);

        ApplicationFramework::addObjectDirectory($DirLists["PluginObjectDir"], $Namespace);

        if (count($DirLists["InterfaceDirs"]) > 0) {
            self::$AF->addInterfaceDirectories($DirLists["InterfaceDirs"], true);
        }

        if (count($DirLists["IncludeDirs"]) > 0) {
            self::$AF->addIncludeDirectories($DirLists["IncludeDirs"], true);
        }

        if (count($DirLists["ImageDirs"]) > 0) {
            self::$AF->addImageDirectories($DirLists["ImageDirs"], true);
        }

        if (count($DirLists["InterfaceObjectDirs"]) > 0) {
            foreach ($DirLists["InterfaceObjectDirs"] as $Dir) {
                self::$AF->addObjectDirectory($Dir, $Namespace);
            }
        }
    }

    /**
     * Load the cache of plugin directory information.
     */
    private function loadDirectoryListCache()
    {
        # if cache column does not exist (e.g., because the upgrade to create
        # it has not yet been run), bail
        if (!$this->DB->columnExists("PluginInfo", "DirectoryCache")) {
            return;
        }

        $this->DB->query(
            "SELECT DirectoryCache, BaseName FROM PluginInfo "
                ."WHERE DirectoryCache IS NOT NULL AND"
                ." TIMESTAMPDIFF(MINUTE, DirectoryCacheLastUpdatedAt, NOW()) < "
                .self::$PluginDirListExpirationPeriod
        );
        $this->PluginDirLists = $this->DB->fetchColumn(
            "DirectoryCache",
            "BaseName"
        );

        foreach ($this->PluginDirLists as $BaseName => $Data) {
            $this->PluginDirLists[$BaseName] = unserialize($Data);
        }
    }

    /**
     * Get the list of directories associated with a given plugin.
     * @param string $PluginName Base name (class) of plugin.
     * @return array List of directories found, divided by directory type. The
     *   PluginObjectDir key will be a string giving the directory that
     *   contains plugin objects -- an /objects subdir if the plugin has one
     *   or the plugin directory itself otherwise. The PluginObjectDir,
     *   InterfaceDirs, IncludeDirs, ImageDirs, and InterfaceObjectDirs keys
     *   will each be arrays of directories.
     */
    private function getPluginDirectoryLists(string $PluginName) : array
    {
        # use cached lists if we have them
        if (isset($this->PluginDirLists[$PluginName])) {
            return $this->PluginDirLists[$PluginName];
        }

        # othrwise, scan for plugin directories
        $Result = [
            "PluginObjectDir" => "",
            "InterfaceDirs" => [],
            "IncludeDirs" => [],
            "ImageDirs" => [],
            "InterfaceObjectDirs" => [],
        ];

        $PluginFileName = $this->PluginFiles[$PluginName];
        $Dir = dirname($PluginFileName);

        # if plugin has its own object directory
        if (is_dir($Dir . "/objects")) {
            # add object directory to class autoloading list
            $Result["PluginObjectDir"] = $Dir."/objects";
        } else {
            # add plugin directory to class autoloading list
            $Result["PluginObjectDir"] = $Dir;
        }

        # if plugin has its own interface directory
        $InterfaceDirs = [$Dir."/interface", "local/".$Dir."/interface"];
        foreach ($InterfaceDirs as $InterfaceDir) {
            if (is_dir($InterfaceDir)) {
                # add interface directory to AF search lists
                $Result["InterfaceDirs"][] = $InterfaceDir."/%DEFAULTUI%/";
                $Result["InterfaceDirs"][] = $InterfaceDir."/%ACTIVEUI%/";

                # add interface include directories if any found
                if (count((array)glob($InterfaceDir . "/*/include"))) {
                    $Result["IncludeDirs"][] = $InterfaceDir."/%DEFAULTUI%/include/";
                    $Result["IncludeDirs"][] = $InterfaceDir."/%ACTIVEUI%/include/";
                }

                # add image directories if any found
                if (count((array)glob($InterfaceDir . "/*/images"))) {
                    $Result["ImageDirs"][] = $InterfaceDir."/%DEFAULTUI%/images/";
                    $Result["ImageDirs"][] = $InterfaceDir."/%ACTIVEUI%/images/";
                }
            }
        }

        # if plugin interface dirs have object directories
        # (need to be added in the opposite order from the above because
        #  of the search order used by addObjectDirectory)
        $InterfaceDirs = ["local/".$Dir."/interface", $Dir."/interface"];
        foreach ($InterfaceDirs as $InterfaceDir) {
            if (is_dir($InterfaceDir)) {
                # add interface object directories if any found
                if (count((array)glob($InterfaceDir . "/*/objects"))) {
                    $Result["InterfaceObjectDirs"][] = $InterfaceDir."/%ACTIVEUI%/objects/";
                    $Result["InterfaceObjectDirs"][] = $InterfaceDir."/%DEFAULTUI%/objects/";
                }
            }
        }

        # update cached lists
        $this->DB->query(
            "UPDATE PluginInfo SET "
                ."DirectoryCache='".$this->DB->escapeString(serialize($Result))."',"
                ."DirectoryCacheLastUpdatedAt=NOW() WHERE BaseName='".$PluginName."'"
        );

        return $Result;
    }

    /**
     * Set any specified default configuration values for plugin.
     * @param Plugin $Plugin Plugin for which to set configuration values.
     * @param bool $Overwrite If TRUE, for those parameters that have
     *       default values specified, any existing values will be
     *       overwritten.  (OPTIONAL, default to FALSE)
     */
    private function setPluginDefaultConfigValues(Plugin $Plugin, bool $Overwrite = false)
    {
        # if plugin has configuration info
        $Attribs = $Plugin->getAttributes();
        if (isset($Attribs["CfgSetup"])) {
            foreach ($Attribs["CfgSetup"] as $CfgValName => $CfgSetup) {
                if (isset($CfgSetup["Default"]) && ($Overwrite
                        || ($Plugin->configSetting($CfgValName) === null))) {
                    if (isset(self::$CfgValueLoader)) {
                        $Plugin->configSetting(
                            $CfgValName,
                            call_user_func(
                                self::$CfgValueLoader,
                                $CfgSetup["Type"],
                                $CfgSetup["Default"]
                            )
                        );
                    } else {
                        $Plugin->configSetting($CfgValName, $CfgSetup["Default"]);
                    }
                }
            }
        }
    }

    /**
     * Check plugin dependencies.
     * @param array $Plugins Plugins to check, with plugin names for the index.
     * @param bool $CheckReady If TRUE, plugin ready state will be considered.
     * @return array Array of messages about any plugins that had failed
     *       dependencies, with base plugin name for the index.
     */
    private function checkDependencies($Plugins, bool $CheckReady = false): array
    {
        # look until all enabled plugins check out okay
        $ErrMsgs = array();
        do {
            # start out assuming all plugins are okay
            $AllOkay = true;

            # for each plugin
            foreach ($Plugins as $PluginName => $Plugin) {
                # if plugin is enabled and not checking for ready
                #       or plugin is ready
                if ($Plugin->isEnabled() && (!$CheckReady || $Plugin->isReady())) {
                    # load plugin attributes
                    if (!isset($Attribs[$PluginName])) {
                        $Attribs[$PluginName] = $Plugin->getAttributes();
                    }

                    # for each dependency for this plugin
                    foreach ($Attribs[$PluginName]["Requires"] as $ReqName => $ReqVersion) {
                        # handle PHP version requirements
                        if ($ReqName == "PHP") {
                            if (version_compare($ReqVersion, (string)phpversion(), ">")) {
                                $ErrMsgs[$PluginName][] = "PHP version "
                                    . "<i>" . $ReqVersion . "</i>"
                                    . " required by <b>" . $PluginName . "</b>"
                                    . " was not available.  (Current PHP version"
                                    . " is <i>" . phpversion() . "</i>.)";
                            }
                            # handle PHP extension requirements
                        } elseif (preg_match("/^PHPX_/", $ReqName)) {
                            list($Dummy, $ExtensionName) = explode("_", $ReqName, 2);
                            if (!extension_loaded($ExtensionName)) {
                                $ErrMsgs[$PluginName][] = "PHP extension "
                                    . "<i>" . $ExtensionName . "</i>"
                                    . " required by <b>" . $PluginName . "</b>"
                                    . " was not available.";
                            } elseif (($ReqVersion !== true)
                                && (phpversion($ExtensionName) !== false)
                                && version_compare(
                                    $ReqVersion,
                                    phpversion($ExtensionName),
                                    ">"
                                )) {
                                $ErrMsgs[$PluginName][] = "PHP extension "
                                    . "<i>" . $ExtensionName . "</i>"
                                    . " version <i>" . $ReqVersion . "</i>"
                                    . " required by <b>" . $PluginName . "</b>"
                                    . " was not available.  (Current version"
                                    . " of extension <i>" . $ExtensionName . "</i>"
                                    . " is <i>" . phpversion($ExtensionName) . "</i>.)";
                            }
                            # handle dependencies on other plugins
                        } else {
                            # load plugin attributes if not already loaded
                            if (isset($Plugins[$ReqName])
                                && !isset($Attribs[$ReqName])) {
                                $Attribs[$ReqName] =
                                    $Plugins[$ReqName]->getAttributes();
                            }

                            # if target plugin is not present or is too old
                            #       or is not enabled
                            #       or (if appropriate) is not ready
                            if (!isset($Plugins[$ReqName])
                                || version_compare(
                                    $ReqVersion,
                                    $Attribs[$ReqName]["Version"],
                                    ">"
                                )
                                || !$Plugins[$ReqName]->isEnabled()
                                || ($CheckReady
                                    && !$Plugins[$ReqName]->isReady())) {
                                # add error message
                                $ErrMsgs[$PluginName][] = "Plugin <i>"
                                    . $ReqName . " " . $ReqVersion . "</i>"
                                    . " required by <b>" . $PluginName . "</b>"
                                    . " was not available.";
                            }
                        }

                        # if problem was found with plugin
                        if (isset($ErrMsgs[$PluginName])) {
                            # remove plugin from our list
                            unset($Plugins[$PluginName]);

                            # set flag to indicate a plugin had to be dropped
                            $AllOkay = false;
                        }
                    }
                }
            }
        } while ($AllOkay == false);

        # return messages about any dropped plugins back to caller
        return $ErrMsgs;
    }

    /**
     * Sort the given array of plugins according to their initialization
     * preferences.
     * @param array $Plugins Array of Plugin objects with plugin base name
     *       for the array index.
     * @return array Sorted array of Plugin objects with plugin base name
     *       for the array index.
     */
    private function sortPluginsByInitializationPrecedence($Plugins): array
    {
        # load plugin attributes
        $PluginAttribs = [];
        foreach ($Plugins as $PluginName => $Plugin) {
            $PluginAttribs[$PluginName] = $Plugin->getAttributes();
        }

        # determine initialization order
        $PluginsAfterUs = [];
        foreach ($PluginAttribs as $PluginName => $Attribs) {
            foreach ($Attribs["InitializeBefore"] as $OtherPluginName) {
                $PluginsAfterUs[$PluginName][] = $OtherPluginName;
            }
            foreach ($Attribs["InitializeAfter"] as $OtherPluginName) {
                $PluginsAfterUs[$OtherPluginName][] = $PluginName;
            }
        }

        # infer other initialization order cues from lists of required plugins
        foreach ($PluginAttribs as $PluginName => $Attribs) {
            # for each required plugin
            foreach ($Attribs["Requires"] as $RequiredPluginName => $RequiredPluginVersion) {
                # skip the requirement if it it not for another known plugin
                if (!isset($PluginAttribs[$RequiredPluginName])) {
                    continue;
                }

                # if there is not a requirement in the opposite direction
                if (!array_key_exists(
                    $PluginName,
                    $PluginAttribs[$RequiredPluginName]["Requires"]
                )) {
                    # if the required plugin is not scheduled to be after us
                    if (!array_key_exists($PluginName, $PluginsAfterUs)
                        || !in_array(
                            $RequiredPluginName,
                            $PluginsAfterUs[$PluginName]
                        )) {
                        # if we are not already scheduled to be after the required plugin
                        if (!array_key_exists($PluginName, $PluginsAfterUs)
                            || !in_array(
                                $RequiredPluginName,
                                $PluginsAfterUs[$PluginName]
                            )) {
                            # schedule us to be after the required plugin
                            $PluginsAfterUs[$RequiredPluginName][] =
                                $PluginName;
                        }
                    }
                }
            }
        }

        # keep track of those plugins we have yet to do and those that are done
        $UnsortedPlugins = array_keys($Plugins);
        $PluginsProcessed = array();

        # limit the number of iterations of the plugin ordering loop
        # to 10 times the number of plugins we have
        $MaxIterations = 10 * count($UnsortedPlugins);
        $IterationCount = 0;

        # iterate through all the plugins that need processing
        while (($NextPlugin = array_shift($UnsortedPlugins)) !== null) {
            # check to be sure that we're not looping forever
            $IterationCount++;
            if ($IterationCount > $MaxIterations) {
                throw new Exception(
                    "Max iteration count (".$MaxIterations.") exceeded trying to"
                            ." determine plugin loading order.  Is there a dependency"
                            ." loop?  (Processed: "
                            .implode(" ", array_keys($PluginsProcessed))
                            .") (Unsorted: "
                            .implode(" ", $UnsortedPlugins)
                            .")"
                );
            }

            # if no plugins require this one, it can go last
            if (!isset($PluginsAfterUs[$NextPlugin])) {
                $PluginsProcessed[$NextPlugin] = $MaxIterations;
            } else {
                # for plugins that are required by others
                $Index = $MaxIterations;
                foreach ($PluginsAfterUs[$NextPlugin] as $GoBefore) {
                    if (!isset($PluginsProcessed[$GoBefore])) {
                        # if there is something that requires us which hasn't
                        # yet been assigned an order, then we can't determine
                        # our own place on this iteration
                        array_push($UnsortedPlugins, $NextPlugin);
                        continue 2;
                    } else {
                        # otherwise, make sure that we're loaded
                        # before the earliest of the things that require us
                        $Index = min($Index, $PluginsProcessed[$GoBefore] - 1);
                    }
                }
                $PluginsProcessed[$NextPlugin] = $Index;
            }
        }

        # arrange plugins according to our ordering
        asort($PluginsProcessed, SORT_NUMERIC);
        $SortedPlugins = array();
        foreach ($PluginsProcessed as $PluginName => $SortOrder) {
            $SortedPlugins[$PluginName] = $Plugins[$PluginName];
        }

        # return sorted list to caller
        return $SortedPlugins;
    }

    /** @cond */
    /**
     * Method hooked to EVENT_PHP_FILE_LOAD to find the appropriate PHP file
     * when a plugin page is to be loaded.  (This method is not meant to be
     * called directly.)
     * @param string $PageName Current page name.
     * @param string|null $FileName Current file name, or NULL if unknown.
     * @return array Parameter array with updated page name (if appropriate).
     */
    public function findPluginPhpFile(string $PageName, $FileName): array
    {
        # build list of possible locations for file
        $Locations = array(
            "local/plugins/%PLUGIN%/pages/%PAGE%.php",
            "plugins/%PLUGIN%/pages/%PAGE%.php",
            "local/plugins/%PLUGIN%/%PAGE%.php",
            "plugins/%PLUGIN%/%PAGE%.php",
        );

        # look for file and return (possibly) updated page to caller
        return $this->findPluginPageFile($PageName, $FileName, $Locations);
    }
    /** @endcond */

    /** @cond */
    /**
     * Method hooked to EVENT_HTML_FILE_LOAD to find the appropriate HTML file
     * when a plugin page is to be loaded.  (This method is not meant to be
     * called directly.)
     * @param string $PageName Current page name.
     * @param string|null $FileName Current file name, or NULL if unknown.
     * @return array Parameter array with updated page name (if appropriate).
     */
    public function findPluginHtmlFile(string $PageName, $FileName): array
    {
        # build list of possible locations for file
        $Locations = array(
            "local/plugins/%PLUGIN%/interface/%ACTIVEUI%/%PAGE%.html",
            "plugins/%PLUGIN%/interface/%ACTIVEUI%/%PAGE%.html",
            "local/plugins/%PLUGIN%/interface/%DEFAULTUI%/%PAGE%.html",
            "plugins/%PLUGIN%/interface/%DEFAULTUI%/%PAGE%.html",
            "local/plugins/%PLUGIN%/%PAGE%.html",
            "plugins/%PLUGIN%/%PAGE%.html",
        );

        # find HTML file
        $Params = $this->findPluginPageFile($PageName, $FileName, $Locations);

        # if plugin HTML file was found
        if ($Params["FileName"] != $FileName) {
            # add subdirectories for plugin to search paths
            $Dir = preg_replace("%^local/%", "", dirname($Params["FileName"]));
            self::$AF->addImageDirectories([
                $Dir . "/images/",
                "local/" . $Dir . "/images/",
            ], true);
            self::$AF->addIncludeDirectories([
                $Dir . "/include/",
                "local/" . $Dir . "/include/",
            ], true);
            self::$AF->addFunctionDirectories([
                $Dir . "/include/",
                "local/" . $Dir . "/include/",
            ], true);
        }

        # return possibly revised HTML file name to caller
        return $Params;
    }
    /** @endcond */

    /**
     * Find the plugin page file in one of the specified locations, based on
     * the "P_PluginName_" convention for indicating plugin pages.
     * @param string $PageName Current page name.
     * @param string|null $FileName Current file name, or NULL if unknown.
     * @param array $Locations Array of strings giving possible locations for
     *       file, with %ACTIVEUI%, %PLUGIN%, and %PAGE% used as appropriate.
     * @return array Parameter array with page and file names (updated if appropriate).
     */
    private function findPluginPageFile(string $PageName, $FileName, array $Locations): array
    {
        # set up return value assuming we will not find plugin page file
        $ReturnValue = ["PageName" => $PageName, "FileName" => $FileName];

        # look for plugin name and plugin page name in base page name
        preg_match("/P_([A-Za-z].[A-Za-z0-9]*)_([A-Za-z0-9_-]+)/", $PageName, $Matches);

        # if plugin name and plugin page name were found in base page name
        if (count($Matches) == 3) {
            # if plugin is valid and enabled and has its own subdirectory
            $PluginName = $Matches[1];
            if (isset($this->Plugins[$PluginName])
                && $this->PluginHasDir[$PluginName]
                && $this->Plugins[$PluginName]->isEnabled()) {
                # for each possible location
                $PageName = $Matches[2];
                $ActiveUI = self::$AF->activeUserInterface();
                $DefaultUI = self::$AF->defaultUserInterface();
                foreach ($Locations as $Loc) {
                    # make any needed substitutions into path
                    $FileName = str_replace(
                        array("%DEFAULTUI%", "%ACTIVEUI%", "%PLUGIN%", "%PAGE%"),
                        array($DefaultUI, $ActiveUI, $PluginName, $PageName),
                        $Loc
                    );

                    # if file exists in this location
                    if (file_exists($FileName)) {
                        # set return value to contain full plugin page file name
                        $ReturnValue["FileName"] = $FileName;

                        # save plugin name as home of current page
                        $this->PageFilePlugin = $PluginName;

                        # stop looking
                        break;
                    }
                }
            }
        }

        # return array containing page name or page file name to caller
        return $ReturnValue;
    }
}
