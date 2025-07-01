<?PHP
#
#   FILE:  MetricsReporter.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\FormUI;
use Metavus\Plugin;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\PluginManager;

/**
 * Plugin for generating reports using data previously recorded by
 * MetricsRecorder plugin.
 */
class MetricsReporter extends Plugin
{

    /**
     * Set the plugin attributes.  At minimum this method MUST set $this->Name
     * and $this->Version.  This is called when the plugin is initially loaded.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Metrics Reporter";
        $this->Version = "1.1.3";
        $this->Description = "Generates usage and web metrics reports"
                ." from data recorded by the <i>Metrics Recorder</i> plugin.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "MetricsRecorder" => "1.1.3"
        ];
        $this->EnabledByDefault = true;

        $this->CfgSetup["PrivsToExcludeFromCounts"] = [
            "Type" => FormUI::FTYPE_PRIVILEGES,
            "Label" => "Exclude Users with",
            "AllowMultiple" => true,
            "Help" => "Users with any of the selected privilege flags "
                    ." will be excluded from full record view counts,"
                    ." URL click counts, and other calculated metrics.",
            "Default" => [
                PRIV_SYSADMIN,
                PRIV_RESOURCEADMIN,
                PRIV_CLASSADMIN,
                PRIV_NAMEADMIN,
                PRIV_RELEASEADMIN,
                PRIV_COLLECTIONADMIN
            ],
        ];
    }

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
        $AdminPages = [
            "CollectionReports" => "Collection Usage Metrics",
            "ListSentEmail" => "View Email Log",
            "SearchLog" => "View Search Log",
            "SearchLog&V=Frequency" => "View Search Frequency",
            "UserReports" => "User Metrics"
        ];
        $PluginMgr = PluginManager::getInstance();
        if ($PluginMgr->pluginEnabled("CalendarEvents")) {
            $AdminPages["EventReports"] = "Event Usage Metrics";
        }
        if ($PluginMgr->pluginEnabled("OAIPMHServer")) {
            $AdminPages["OAILog"] = "OAI Harvest Log";
        }
        foreach ($AdminPages as $Link => $Label) {
            $this->addAdminMenuEntry($Link, $Label, [ PRIV_COLLECTIONADMIN ]);
        }
        return null;
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return string|null NULL if installation succeeded, otherwise a string containing
     *       an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        $DB = new Database();
        $DB->query("CREATE TABLE IF NOT EXISTS MetricsReporter_SpamSearches ("
                   ."SearchKey VARCHAR(32), UNIQUE (SearchKey) )");
        return null;
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Get a cached value.
     * @param string $Key Key to use when looking up value.
     * @return mixed Cached value.
     */
    public function cacheGet($Key)
    {
        $AF = ApplicationFramework::getInstance();
        $FullKey = $AF->getPageName()."-".$Key;
        return self::getDataCache()->get($FullKey);
    }

    /**
     * Store a value in the cache.
     * @param string $Key Key to use for later retrieval.
     * @param mixed $Data Value to store (must be serializable).
     * @return void
     */
    public function cachePut($Key, $Data): void
    {
        $AF = ApplicationFramework::getInstance();
        $FullKey = $AF->getPageName()."-".$Key;

        $Cache = self::getDataCache();

        $KeyList = $Cache->get("KeyList") ?? [];
        $KeyList[$FullKey] = true;
        $Cache->set("KeyList", $KeyList);

        $Cache->set($FullKey, $Data, self::CACHED_DATA_TTL);
    }

    /**
     * Clear all cache entries for the current page.
     * @return void
     */
    public function cacheClear(): void
    {
        $Cache = self::getDataCache();

        $KeyList = $Cache->get("KeyList");
        if ($KeyList === null) {
            return;
        }

        foreach ($KeyList as $Key => $Value) {
            $Cache->delete($Key);
        }
        $Cache->delete("KeyList");
    }

    /**
     * Converts array keys from UNIX timestamps to ISO 8601 dates.
     * @param array $InputArray Input data.
     * @return array with keys converted.
     */
    public static function formatDateKeys($InputArray): array
    {
        $Result = [];
        foreach ($InputArray as $Key => $Val) {
            $Result[date("c", $Key)] = $Val;
        }

        return $Result;
    }

    /**
     * Determine if an HTTP request appears to be an SQL injection attempt.
     * @param string $RequestString Request to filter.
     * @return bool TRUE for injection attempts.
     */
    public static function requestIsSqlInjection($RequestString): bool
    {
        $RequestString = urldecode($RequestString);

      # check each injection pattern
        foreach (self::$SqlInjectionPatterns as $Pattern) {
          # if we find a match
            if (stripos($RequestString, $Pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private static $SqlInjectionPatterns = [
        " and 1=1",
        " and 1>1",
        " name_const(char(",
        " unhex(hex(",
        "'a=0",
    ];

    private const CACHED_DATA_TTL = 4 * 60 * 60; # time to live value for cached items in seconds
}
