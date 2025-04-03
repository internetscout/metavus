<?PHP
#
#   FILE:  Developer.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use InvalidArgumentException;
use Metavus\FormUI;
use Metavus\Image;
use Metavus\Plugin;
use Metavus\PrivilegeSet;
use Metavus\SystemConfiguration;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Email;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * Plugin to provide utilities and conveniences to support development.
 */
class Developer extends Plugin
{
    # ---- CONFIGURATION -----------------------------------------------------

    # database error messages to ignore when doing upgrades
    # (IMPORTANT:  this list MUST match the one in installmv.php)
    private static $SqlErrorsWeCanIgnore = [
        "/ALTER TABLE /i" => "/Table '[a-z0-9_.]+' already exists/i",
        "/ALTER TABLE [a-z0-9_]+ (CHANGE|MODIFY) COLUMN/i" => "/Unknown column/i",
        "/ALTER TABLE [a-z0-9_]+ ADD /i" => "/Duplicate column name/i",
        "/ALTER TABLE [a-z0-9_]+ ADD INDEX/i" => "/Duplicate key name/i",
        "/ALTER TABLE [a-z0-9_]+ ADD PRIMARY KEY/i" => "/Multiple primary key/i",
        "/ALTER TABLE [a-z0-9_]+ DROP COLUMN/i" => "/Check that column/i",
        "/ALTER TABLE [a-z0-9_]+ RENAME/i" => "/Table '[a-z0-9_.]+' doesn't exist/i",
        "/CREATE (UNIQUE )?INDEX [a-z0-9_]+ ON [a-z0-9_]+/i" => "/Duplicate key name/i",
        "/CREATE TABLE /i" => "/Table '[a-z0-9_.]+' already exists/i",
        "/DROP INDEX /i" => "/check that column\/key exists/i",
        "/DROP TABLE /i" => "/Unknown table '[a-z0-9_.]+'/i",
        # (situation-specific patterns, that should eventually be removed)
        "/CREATE TABLE [a-z0-9_]+_old AS SELECT/i"
                    => "/Table '[a-z0-9_.]+' doesn't exist/i",
        "/INSERT INTO [a-z]+ SELECT \* FROM [a-z0-9_]+_old/i"
                    => "/Table '[a-z0-9_.]+' doesn't exist/i",
        "/ALTER TABLE RecordImageInts/i"
                    => "/Table '[a-z0-9_.]+' doesn't exist/i",
    ];

    const OLDEST_UPGRADABLE_VERSION = "3.2.0";


    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set plugin attributes.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Developer Support";
        $this->Version = "1.2.4";
        $this->Description = "Provides various conveniences useful during"
                ." software development.";
        $this->Author = "Internet Scout";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
        ];
        $this->InitializeBefore = [ "MetavusCore" ];
        $this->EnabledByDefault = false;

        $AF = ApplicationFramework::getInstance();
        $this->Instructions = $AF->escapeInsertionKeywords(
            "<b>Usage Note:</b>  For Page Load Info, Variable Monitor, "
            ."Database Profiling, or Execution Profiling "
            ."to appear, the currently-active interface must include "
            ."<code>{{P-DEVELOPER-PAGELOADINFO}}</code>, "
            ."<code>{{P-DEVELOPER-VARIABLEMONITOR}}</code> "
            ."<code>{{P-DEVELOPER-DBINFO}}</code>, or"
            ."<code>{{P-DEVELOPER-EXECUTIONINFO}}</code> "
            ."at the points where they should be displayed. "
            ."For the watermark to appear, the currently-active interface "
            ."must include the text <code>{{P-DEVELOPER-WATERMARK}}</code> "
            ."at some point. (See <code>StdPageEnd.html</code> in the "
            ."<code>default</code> interface for an example.) "
            ."Database query profiling is currently "
            .(Database::queryTimeRecordingIsEnabled() ? "" : "not ")
            ."enabled. "
        );

        $this->CfgSetup[] = [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "Variable Monitor",
        ];
        $this->CfgSetup["VariableMonitorEnabled"] = [
            "Label" => "Display Variable Monitor",
            "Type" => FormUI::FTYPE_FLAG,
            "Default" => true,
            "Help" => "When enabled, the Variable Monitor displays the"
                        ." global (G_), page (H_), and form (F_) variables"
                        ." available to the HTML file for the current page",
        ];
        $this->CfgSetup["VariableDisplayThreshold"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Variable Display Threshold",
            "Default" => 300,
            "Help" => "The Variable Monitor will not attempt to display"
                        ." values where the var_dump() output for the"
                        ." value is more than this number of characters.",
        ];
        $this->CfgSetup["VariableMonitorPrivilege"] = [
            "Type" => FormUI::FTYPE_PRIVILEGES,
            "Label" => "Display Variable Monitor For",
            "Default" => PRIV_SYSADMIN,
            "AllowMultiple" => true,
            "Help" => "The Variable Monitor will only be displayed for"
                        ." users with these privilege flags.  If no privileges"
                        ." are specified, it will be displayed for all users,"
                        ." including anonymous users.",
        ];
        $this->CfgSetup[] = [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "Page Load Info",
        ];
        $this->CfgSetup["PageLoadInfoEnabled"] = [
            "Label" => "Display Page Load Info",
            "Type" => FormUI::FTYPE_FLAG,
            "Default" => true,
            "Help" => "When enabled, page load information is displayed"
                        ." (usually at the bottom of the page, though location"
                        ." and whether it's displayed at all may depend on the"
                        ." currently-active interface).",
        ];
        $this->CfgSetup["PageLoadInfoPrivilege"] = [
            "Type" => FormUI::FTYPE_PRIVILEGES,
            "Label" => "Display Page Load Info For",
            "Default" => PRIV_SYSADMIN,
            "AllowMultiple" => true,
            "Help" => "The page load info will only be displayed for"
                        ." users with these privilege flags.  If no privileges"
                        ." are specified, it will be displayed for all users,"
                        ." including anonymous users.",
        ];
        $this->CfgSetup[] = [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "Profiling",
        ];
        $this->CfgSetup["AutoOpenThreshold"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Auto Open Threshold",
            "Default" => 0.5,
            "Help" => "Profiles will be automatically expanded when "
              ."pages take longer than this to load.",
            "Units" => "seconds",
            "AllowFloats" => true
        ];
        $this->CfgSetup["ProfileCallCount"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Function Calls",
            "Default" => 40,
            "Help" => "Number of function calls to include in the profile.",
        ];
        $this->CfgSetup["ProfileQueryCount"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Database Queries",
            "Default" => 40,
            "Help" => "Maximum number of database queries to display.",
        ];
        $this->CfgSetup[] = [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "Watermark",
        ];
        $this->CfgSetup["WatermarkEnabled"] = [
            "Label" => "Display Watermark",
            "Type" => FormUI::FTYPE_FLAG,
            "Default" => true,
            "Help" => "When enabled, a transparent watermark is displayed"
                        ." in the top left corner of the display window on"
                        ." every page, floating above the content.",
        ];
        $this->CfgSetup["WatermarkText"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Watermark Text",
            "Default" => "Developer: {{OWNER}}",
            "Help" => $AF->escapeInsertionKeywords("The"
                        ." text to display for the watermark.  The"
                        ." keyword {{OWNER}} will be replaced with the"
                        ." OS user name of the owner of the base directory"
                        ." for the software installation."),
        ];
        $this->CfgSetup["WatermarkColor"] = [
            "Label" => "Watermark Color",
            "Type" => FormUI::FTYPE_OPTION,
            "Default" => self::WATERMARKCOLOR_WHITE,
            "Options" => [
                self::WATERMARKCOLOR_WHITE => "White",
                self::WATERMARKCOLOR_TEXT => "Based On Watermark Text",
                self::WATERMARKCOLOR_PATH => "Based On Host+Path",
            ],
            "Help" => "When either of the <i>Based On</i> options are"
                        ." selected, a random color will be generated"
                        ." based on the specified text string.",
        ];
        $this->CfgSetup[] = [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "Auto-Upgrade",
        ];
        $this->CfgSetup["AutoUpgradeDatabase"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Auto-Upgrade Database",
            "Default" => false,
            "Help" => "When auto-upgrade is enabled, any updated database"
                        ." upgrade SQL files (in install/DBUpgrades or"
                        ." local/install/DBUpgrades) will be"
                        ." executed when user is logged in with PRIV_SYSADMIN"
                        ." or has a matching IP address and a change in an"
                        ." upgrade file is detected.",
        ];
        $this->CfgSetup["AutoUpgradeSite"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Auto-Upgrade Site",
            "Default" => false,
            "Help" => "When auto-upgrade is enabled, any updated site"
                        ." upgrade PHP files (in install/SiteUpgrades or"
                        ." local/install/SiteUpgrades) will be"
                        ." executed when user is logged in with PRIV_SYSADMIN"
                        ." or has a matching IP address and a change in an"
                        ." upgrade file is detected.",
        ];
        $this->CfgSetup["AutoUpgradeInterval"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Auto-Upgrade Interval",
            "Default" => 5,
            "Units" => "minutes",
            "Size" => 4,
            "Help" => "How often to check upgrade files for updates.",
        ];
        $this->CfgSetup["AutoUpgradeIPMask"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Auto-Upgrade IP Addresses",
            "Help" => "When the user's IP address matches any of these values,"
                        ." auto-upgrades will be run, regardless of whether the user"
                        ." is logged in or has specific privileges.Addresses should"
                        ." be specified one per line, and may be PHP regular"
                        ." expressions framed by /'s.",
        ];
        $this->CfgSetup[] = [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "PHP Configuration",
        ];
        $this->CfgSetup["ErrorReportingFlags"] = [
            "Label" => "PHP Error Reporting Flags",
            "Type" => FormUI::FTYPE_OPTION,
            "Default" => [],
            "AllowMultiple" => true,
            "Rows" => 4,
            "Help" => "Flags controlling PHP error reporting, as defined in the"
                        ." PHP documentation.",
                # (the indexes for "Options" are intentionally strings rather
                #       than the predefined PHP error level constants because
                #       the value of those constants can change from PHP version
                #       to version)
            "Options" => [
                "E_WARNING" => "Warning",
                "E_NOTICE" => "Notice",
                "E_STRICT" => "Strict",
                "E_DEPRECATED" => "Deprecated",
            ],
        ];
        $this->CfgSetup[] = [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "Other",
        ];
        $this->CfgSetup["UseFileUrlFallbacks"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Use File URL Fallbacks",
            "Default" => false,
            "Help" => "When this option is enabled, uploaded images that"
                        ." are not found locally will have the external file"
                        ." URL prefix prepended to their URL."
                        ." <br/><b>PLEASE NOTE:</b>"
                        ." This mechanism adds a high overhead to every page"
                        ." load, and is NOT recommended for use on a"
                        ." production site.",
        ];
        $this->CfgSetup["FileUrlFallbackPrefix"] = [
            "Type" => FormUI::FTYPE_URL,
            "Label" => "File URL Fallback Prefix",
            "Help" => "Prefix for uploaded image files that are not found"
                        ." in the local directory structure.  The purpose of"
                        ." this option is to allow a copy of a database from"
                        ." a live site to be used in a test installation and"
                        ." still have images appear as needed for development.",
            "SettingFilter" => "NormalizeFileUrlFallbackPrefix",
        ];
        $this->CfgSetup["UseEmailWhitelist"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Use Email Whitelist",
            "Default" => false,
            "Help" => "When this option is enabled and one or more email"
                        ." whitelist patterns are set, outgoing email will"
                        ." only be sent to addresses that match one of the"
                        ." patterns.",
        ];
        $this->CfgSetup["EmailWhitelist"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Email Whitelist",
            "Help" => "List of regular expression patterns that must be"
                        ." matched for outgoing email to be set (when <i>Use"
                        ." Email Whitelist</i> is enabled).Patterns should"
                        ." be specified one per line, and use the syntax"
                        ." expected by the PHP function preg_match()."
                        ." X-DBUSER-X will be replaced with the the database"
                        ." username set in config.php.",
        ];

        # (disable until DB integrity check is updated to actually do some checking)
        # $this->addAdminMenuEntry(
        #     "DBIssues",
        #     "Check Database Integrity",
        #     [ PRIV_SYSADMIN ]
        # );
        $this->addAdminMenuEntry(
            "PageCacheStats",
            "Page Cache Statistics",
            [ PRIV_SYSADMIN ]
        );
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return string|null NULL if installation succeeded, otherwise a string
     *      containing an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        # calculate and store upgrade file checksums
        $this->updateUpgradeFileCheckdata(self::DBUPGRADE_FILEPATTERN);
        $this->updateUpgradeFileCheckdata(self::SITEUPGRADE_FILEPATTERN);

        # report successful execution
        return null;
    }

    /**
     * Initialize the plugin.This is called after all plugins have been loaded
     * but before any methods for this plugin (other than Register() or Initialize())
     * have been called.
     * @return string|null NULL if initialization was successful, otherwise a
     *      string containing an error message indicating why initialization failed.
     */
    public function initialize(): ?string
    {
        # load local developer settings if available
        $this->loadLocalSettings();

        # set the PHP error reporting level
        $ErrorFlags = $this->getConfigSetting("ErrorReportingFlags");
        if (count($ErrorFlags)) {
            $CurrentFlags = error_reporting();
            foreach ($ErrorFlags as $Flag) {
                switch ($Flag) {
                    case "E_WARNING":
                        $CurrentFlags |= E_WARNING;
                        break;
                    case "E_NOTICE":
                        $CurrentFlags |= E_NOTICE;
                        break;
                    case "E_STRICT":
                        $CurrentFlags |= E_STRICT;
                        break;
                    case "E_DEPRECATED":
                        $CurrentFlags |= E_DEPRECATED;
                        break;
                }
            }
            error_reporting($CurrentFlags);
        }

        # if email whitelisting is turned on
        if ($this->getConfigSetting("UseEmailWhitelist")) {
            $DB = new Database();

            # set whitelist addresses if any
            $Whitelist = explode("\n", str_replace(
                "X-DBUSER-X",
                $DB->DBUserName(),
                $this->getConfigSetting("EmailWhitelist")
            ));
            Email::toWhitelist($Whitelist);

            # register callback to display info about the whitelist
            Email::registerWhitelistNoticeCallback([$this, "printWhitelistNotice"]);
        }

        # register insertion keyword callbacks
        if (!ApplicationFramework::reachedViaAjax()) {
            $AF = ApplicationFramework::getInstance();
            if ($this->getConfigSetting("VariableMonitorEnabled")) {
                $AF->registerInsertionKeywordCallback(
                    "P-DEVELOPER-VARIABLEMONITOR",
                    [$this, "getVariableMonitorHtml"]
                );
            }
            if ($this->getConfigSetting("PageLoadInfoEnabled")) {
                $AF->registerInsertionKeywordCallback(
                    "P-DEVELOPER-PAGELOADINFO",
                    [$this, "getPageLoadInfoHtml"]
                );
            }
            if ($this->getConfigSetting("WatermarkEnabled")) {
                $AF->registerInsertionKeywordCallback(
                    "P-DEVELOPER-WATERMARK",
                    [$this, "getWatermarkHtml"]
                );
            }

            $AF->registerInsertionKeywordCallback(
                "P-DEVELOPER-DBINFO",
                [$this, "getDBInfoHtml"]
            );

            $AF->registerInsertionKeywordCallback(
                "P-DEVELOPER-EXECUTIONINFO",
                [$this, "getExecutionInfoHtml"]
            );

            $AF->registerInsertionKeywordCallback(
                "P-DEVELOPER-MEMORYINFO",
                [$this, "getMemoryInfoHtml"]
            );
        }

        # set up file path fallback for images (if appropriate)
        if ($this->getConfigSetting("UseFileUrlFallbacks")) {
            $Prefix = $this->getConfigSetting("FileUrlFallbackPrefix");
            if (strlen($Prefix)) {
                Image::setFilePathFallbackPrefix($Prefix);
            }
        }

        # report that initialization was successful
        return null;
    }

    /**
     * Hook methods to be called when specific events occur.
     * For events declared by other plugins the name string should start with
     * the plugin base (class) name followed by "::" and then the event name.
     * @return array Method names to hook indexed by the event constants
     *       or names to hook them to.
     */
    public function hookEvents(): array
    {
        $Hooks = [];
        if ($this->getConfigSetting("AutoUpgradeDatabase") ||
            $this->getConfigSetting("AutoUpgradeSite")) {
            $Hooks["EVENT_PERIODIC"][] = "checkForUpgrades";
        }
        if ($this->getConfigSetting("UseFileUrlFallbacks")) {
            $Hooks["EVENT_PAGE_OUTPUT_FILTER"][] = "insertImageUrlFallbackPrefixes";
        }
        if (!ApplicationFramework::reachedViaAjax()) {
            if ($this->getConfigSetting("VariableMonitorEnabled")) {
                $Hooks["EVENT_IN_HTML_HEADER"] = "addVariableMonitorStyles";
            }
        }
        return $Hooks;
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * HOOKED METHOD:  Check for any database or site upgrade files that have
     * changed and perform upgrades as needed.
     * @param string|null $LastRunAt When task was last run or NULL if no last run info.
     * @return int Number of minutes until task should run again.
     */
    public function checkForUpgrades($LastRunAt): int
    {
        if (static::autoUpgradeShouldRun()) {
            if (self::getConfigSetting("AutoUpgradeSite")) {
                $this->checkForSiteUpgrades();
            }
        }

        # tell caller how many minutes to wait before calling us again
        return $this->getConfigSetting("AutoUpgradeInterval");
    }

    /**
     * HOOKED METHOD:  Add CSS styles used by Variable Monitor to page header.
     */
    public function addVariableMonitorStyles(): void
    {
        ?><style type="text/css">
        .VariableMonitor {
            border:         1px solid #999999;
            background:     #E0E0E0;
            font-family:    verdana, arial, helvetica, sans-serif;
            margin-top:     10px;
            width:          100%;
        }
        .VariableMonitor th {
            padding:        5px;
            text-align:     left;
            vertical-align: center;
        }
        .VariableMonitor th span {
            float:          right;
            font-weight:    normal;
            font-style:     italic;
        }
        .VariableMonitor td {
            padding:        10px;
        }
        .VariableMonitor th {
            background:     #D0D0D0;
        }
        .VariableMonitor h2 {
            margin:         0;
        }
        .VariableMonitor h3 {
            margin:         0;
        }
        .VariableMonitor div {
            font-family:    Courier New, Courier, monospace;
            color:          #000000;
        }
        .VarMonValue {
            display:        none;
            background:     #F0F0F0;
            border:         1px solid #FFFFFF;
            padding:        0 10px 0 10px;
        }
        .VarMonName {
            cursor:         pointer;
        }
        </style><?PHP
    }

    /**
     * HOOKED METHOD:  Generate and return HTML for Variable Monitor.
     * @return string Generated HTML.
     */
    public function getVariableMonitorHtml(): string
    {
        # return nothing if current user does not have needed privilege
        $VMPrivs = $this->getConfigSetting("VariableMonitorPrivilege");
        if (!$VMPrivs->MeetsRequirements(User::getCurrentUser())) {
            return "";
        }

        # begin Variable Monitor display
        ob_start();
        ?><table class="VariableMonitor">
        <tr><th colspan="3">
            <span>(click on variable names to see values available
                    at the beginning of HTML file execution)</span>
            <h2>Variable Monitor</h2>
        </th></tr>
        <tr style="vertical-align: top;"><?PHP
        # retrieve all variables
        $AF = ApplicationFramework::getInstance();
        $Vars = $AF->getHtmlFileContext();

        # list page variables
        $VarIndex = 0;
        print "<td><h3>Page Variables (H_)</h3><div>";
        $VarFound = false;
        foreach ($Vars as $VarName => $VarValue) {
            if (preg_match("/^H_/", $VarName)) {
                $this->displayVariable($VarName, $VarValue, $VarIndex);
                $VarIndex++;
                $VarFound = true;
            }
        }
        if (!$VarFound) {
            print "(none)<br/>";
        }
        print "</div></td>";

        # list global variables
        print "<td><h3>Global Variables (G_)</h3><div>";
        $VarFound = false;
        foreach ($Vars as $VarName => $VarValue) {
            if (preg_match("/^G_/", $VarName)) {
                $this->displayVariable($VarName, $VarValue, $VarIndex);
                $VarIndex++;
                $VarFound = true;
            }
        }
        if (!$VarFound) {
            print "(none)<br/>";
        }
        print "</div></td>";

        # list form variables
        if (count($_POST)) {
            print "<td><h3>Form Variables (POST)</h3><div>";
            foreach ($_POST as $VarName => $VarValue) {
                $this->displayVariable($VarName, $VarValue, $VarIndex);
                $VarIndex++;
            }
            print"</div></td>";
        }

        # end Variable Monitor display
        print "</tr></table>";
        return (string)ob_get_clean();
    }

    /**
     * HOOKED METHOD:  Add fallback URL prefix to URLs for any images that
     * don't seem to be available locally.
     * @param string $PageOutput Whole page output.
     * @return array Array of incoming arguments, possibly modified.
     */
    public function insertImageUrlFallbackPrefixes(string $PageOutput): array
    {
        $PageOutput = preg_replace_callback(
            [
                "%src=\"/?([^?*:;{}\\\\\" ]+)\.(gif|png|jpg)\"%i",
                "%src='/?([^?*:;{}\\\\' ]+)\.(gif|png|jpg)'%i"
            ],
            [
                $this,
                "insertImageUrlFallbackPrefixesCallback"
            ],
            $PageOutput
        );
        return ["PageOutput" => $PageOutput];
    }

    /**
     * HOOKED METHOD:  Generate and return HTML for page load info line.
     * @return string Generated HTML.
     */
    public function getPageLoadInfoHtml(): string
    {
        # return nothing if current user does not have needed privilege
        $PLIPrivs = $this->getConfigSetting("PageLoadInfoPrivilege");
        if (!$PLIPrivs->MeetsRequirements(User::getCurrentUser())) {
            return "";
        }

        # retrieve values to be printed
        $AF = ApplicationFramework::getInstance();
        $GenTime = sprintf("%.2f", $AF->getElapsedExecutionTime());
        $Version = METAVUS_VERSION;
        $DB = new Database();
        $DBName = $DB->DBName();
        $DBCacheRate = sprintf(
            "%d%% (%d/%d)",
            $DB->cacheHitRate(),
            $DB->numCacheHits(),
            $DB->numQueries()
        );
        $MemUsed = memory_get_peak_usage();
        $MemLimit = StdLib::getPhpMemoryLimit();
        $MemPercent = round(($MemUsed * 100) / $MemLimit);
        $MemUsage = sprintf(
            "%d%% (%4.2fM/%dM)",
            $MemPercent,
            ($MemUsed / (1024 * 1024)),
            ($MemLimit / (1024 * 1024))
        );
        if (is_readable("LASTSITEUPDATE")) {
            $Lines = file("LASTSITEUPDATE");
            if ($Lines === false) {
                throw new Exception("Unabled to read LASTSITEUPDATE file contents.");
            }
            $Line = array_shift($Lines);
            $LastUpdate = StdLib::getPrettyTimestamp(strtotime($Line));
        } else {
            $LastUpdate = "(unknown)";
        }

        ob_start();
        ?><div id="mv-content-pagestats" class="container-fluid">
            <div class="row">
                <div class="col text-center">
                    <b>Gen Time:</b> <?= $GenTime ?> seconds
                </div>
                <div class="col text-center">
                    <b>Version:</b> <?= $Version ?>
                </div>
                <div class="col text-center">
                    <b>DB:</b> <?= $DBName ?>
                </div>
                <div class="col text-center">
                    <b>DB Cache Rate:</b> <?= $DBCacheRate ?>
                </div>
                <div class="col text-center">
                    <b>Mem Usage:</b> <?= $MemUsage ?>
                </div>
                <div class="col text-center">
                    <b>Last Update:</b> <?= $LastUpdate ?>
                </div>
            </div>
        </div><?PHP
        return (string)ob_get_clean();
    }

    /**
     * HOOKED METHOD:  Generate and return HTML for database profiling
     * @return string Generated HTML.
     */
    public function getDBInfoHtml() : string
    {
        # nothing to do if query timing was disabled
        if (!Database::queryTimeRecordingIsEnabled()) {
            return "";
        }

        # see if we should open the profiling panel by default
        $GenTime = ApplicationFramework::getInstance()->getElapsedExecutionTime();
        $ProfileCSS = ($GenTime <= $this->getConfigSetting("AutoOpenThreshold")) ?
            "display: none;" : "";

        $BaseDir = dirname($_SERVER["SCRIPT_FILENAME"])."/";
        $DB = new Database();

        # get the slowest queries
        $SlowQueries = Database::getMostTimeConsumingQueries(
            $this->getConfigSetting("ProfileQueryCount")
        );

        # and disable timing of further queries
        Database::queryTimeRecordingIsEnabled(false);

        # regex search/replace patterns to normalize queries for display
        $QueryReplacements = [
            "% IN \([0-9,-]+\)%" => " IN (...)",
            "% VALUES (?:\([^)]+\),)*\([^)]+\)%" => " VALUES (...)",
            "% SET Cfg = '.*' %s" => " SET Cfg = '...' ",
            "%Callback = '[^']+' %" => "Callback = '...'",
            "%Parameters = '[^']+'%" => "Parameters = '...'",
        ];

        # regex search/replace to apply to backtraces
        #  (used to elide super common prefixes)
        $LocationReplacements = [
            '%^ScoutLib\\\\ApplicationFramework::loadPage\(\) ->'
            .' ScoutLib\\\\ApplicationFramework::loadPhpFileForPage\(\) ->'
            .' ScoutLib\\\\ApplicationFramework::includeFile\(\) ->'
            .' lib/ScoutLib/ApplicationFramework\.php:[0-9]+ -> %'
            => '',
            '%^ScoutLib\\\\ApplicationFramework::loadPage\(\) ->'
            .' ScoutLib\\\\ApplicationFramework::loadHtmlFileForPage\(\) ->'
            .' ScoutLib\\\\ApplicationFramework::includeFile\(\) ->'
            .' lib/ScoutLib/ApplicationFramework\.php:[0-9]+ -> %'
            => '',
            '%^Metavus\\\\Bootloader::boot\(\) ->'
            .' Metavus\\\\Bootloader::loadPlugins\(\) ->'
            .' ScoutLib\\\\PluginManager::loadPlugins\(\) ->'
            .' ScoutLib\\\\PluginManager::readyPlugin\(\) -> %'
            => '',
        ];

        # construct container and table header
        $Result = '<div id="mv-content-dbstats" class="container-fluid">'
            .'<div class="row p-2"><div class="col">'
            .'<h2 title="click to expand" '
            .'onclick=\'$("#mv-p-developer-dbprofile").toggle();\''
            .'>Database Profile</h2>'
            .'<table id="mv-p-developer-dbprofile" style="width: 100%; '.$ProfileCSS.'">'
            .'<tr>'
            .'<th colspan=3></th>'
            .'<th colspan=3 class="text-center">Time&nbsp;(s)</th>'
            .'</tr>'
            .'<tr>'
            .'<th colspan=2>Query</th>'
            .'<th class="text-right">N</th>'
            .'<th class="text-right">Tot</th>'
            .'<th class="text-right">Avg</th>'
            .'<th class="text-right">Max</th></tr>';

        # (colspan = 2 on the 'Query' rows because the Query Locations and Explain
        # sections displayed below the query use two cells to align the query counts
        # and keys used)

        # iterate over our slow queries
        $QueryCounter = 0;
        foreach ($SlowQueries as $QueryString => $QueryInfo) {
            $CssClass = "";
            $DetailCssClass = "mv-p-developer-qdetails-".$QueryCounter;

            # get EXPLAIN for statements that support it
            if (preg_match("%^(SELECT|DELETE|INSERT|REPLACE|UPDATE) %i", $QueryString)) {
                $DB->query("EXPLAIN ".$QueryString);
                $ExplainResults = $DB->fetchRows();

                # if this was a single SELECT/DELETE/REPLACE/UPDATE that had a
                # WHERE clause but didn't use an index highlight it in the output
                if (preg_match("%^(SELECT|DELETE|REPLACE|UPDATE) %i", $QueryString) &&
                    strpos($QueryString, " WHERE ") !== false &&
                    count($ExplainResults) == 1 &&
                    strlen($ExplainResults[0]["key"] ?? "") == 0) {
                    $CssClass = "fst-italic font-weight-bold";
                }
            } else {
                $ExplainResults = [];
            }

            # normalize query for display
            $QueryString = preg_replace(
                array_keys($QueryReplacements),
                array_values($QueryReplacements),
                $QueryString
            );

            # and ensure that the normalized version isn't excessively long
            if (strlen($QueryString) > 1024) {
                $QueryString = substr($QueryString, 0, 1024)."...";
            }

            # basic query info
            $BgColor = $QueryCounter % 2 == 1 ? 'ddd' : 'eee';
            $TimeFormat = "%6.4f";
            $Result .= '<tr style="background-color: #'.$BgColor.'">'
                .'<td colspan=2>'
                .'<code class="'.$CssClass.'" '
                .'onclick=\'$(".'.$DetailCssClass.'").toggle();\''
                .'>'.$QueryString.'</code></td>'
                .'<td valign="top" class="text-right pr-2">'.$QueryInfo["Count"].'</td>'
                .'<td valign="top" class="text-right pr-2">'
                        .sprintf($TimeFormat, $QueryInfo["TotalTime"]).'</td>';
            if ($QueryInfo["Count"] > 1) {
                $AvgTime = $QueryInfo["TotalTime"] / $QueryInfo["Count"];
                $Result .=
                    '<td valign="top" class="text-right pr-2">'
                            .sprintf($TimeFormat, $QueryInfo["LongestTime"]).'</td>'
                    .'<td valign="top" class="text-right">'
                            .sprintf($TimeFormat, $AvgTime).'</td>';
            } else {
                $Result .= '<td></td><td></td>';
            }
            $Result .= '</tr>';

            # backtraces of query locations
            $Result .=
                '<tr class="'.$DetailCssClass.'" style="display: none;">'
                .'<td colspan=2 class="pl-2"><u>Query Locations</u></td>'
                .'</tr>';
            foreach ($QueryInfo["Locations"] as $Location => $Count) {
                # normalize and format location
                $Location = str_replace(
                    "->",
                    "&rarr;",
                    preg_replace(
                        array_keys($LocationReplacements),
                        array_values($LocationReplacements),
                        str_replace($BaseDir, "", (string)$Location)
                    )
                );

                $Result .=
                    '<tr class="'.$DetailCssClass.'" style="display: none;">'
                    .'<td valign="top" class="pl-4">'.$Count.'</td>'
                    .'<td>'.$Location.'</td>'
                    .'</tr>';
            }

            # query profile when available
            if (count($ExplainResults)) {
                $Result .=
                    '<tr class="'.$DetailCssClass.'" style="display: none;">'
                    .'<td colspan=2 class="pl-2"><u>Explain</u></td>'
                    .'</tr>';
                foreach ($ExplainResults as $ExplainResult) {
                    foreach ($ExplainResult as $Key => $Val) {
                        $Result .=
                            '<tr class="'.$DetailCssClass.'" style="display: none;">'
                            .'<td class="pl-4">'.$Key.'</td>'
                            .'<td>'.$Val.'</td>'
                            .'</tr>';
                    }
                }
            }

            $QueryCounter++;
        }

        $Result .= '</table></div></div></div>';

        return $Result;
    }

    /**
     * HOOKED METHOD:  Generate and return HTML for execution profiling.
     * @return string Generated HTML.
     */
    public function getExecutionInfoHtml() : string
    {
        $ProfileData = $this->getProfileData();
        if ($ProfileData === false) {
            return "";
        }

        uasort(
            $ProfileData,
            function ($a, $b) {
                return $b["wt"] <=> $a["wt"];
            }
        );

        $ProfileData = array_slice(
            $ProfileData,
            0,
            $this->getConfigSetting("ProfileCallCount"),
            true
        );

        $GenTime = ApplicationFramework::getInstance()->getElapsedExecutionTime();
        $ProfileCSS = ($GenTime <= $this->getConfigSetting("AutoOpenThreshold")) ?
            "display: none;" : "";

        $Result = '<div id="mv-content-executionstats" class="container-fluid">'
            .'<div class="row p-2"><div class="col">'
            .'<h2 title="click to expand" '
            .'onclick=\'$("#mv-p-developer-executionprofile").toggle();\''
            .'>Execution Profile</h2>'
            .'<table id="mv-p-developer-executionprofile" style="width: 100%; '.$ProfileCSS.'">'
            .'<tr>'
            .'<th colspan=2></th>'
            .'<th colspan=2 class="text-center">Time&nbsp;(s)</th></tr>'
            .'<tr>'
            .'<th>Call</th>'
            .'<th class="text-right">N</th>'
            .'<th class="text-right">Tot</th>'
            .'<th class="text-right">Avg</th>'
            .'</tr>';

        $Count = 0;
        foreach ($ProfileData as $FunctionCall => $CallInfo) {
            $BgColor = $Count % 2 == 1 ? 'ddd' : 'eee';
            $FunctionCall = str_replace("==>", "&nbsp;&rarr;&nbsp;", $FunctionCall);
            $TimeFormat = "%6.4f";
            $Result .= "<tr style='background-color: #".$BgColor."'>"
                ."<td><code>".$FunctionCall."</code></td>"
                ."<td class='text-right pr-2'>".$CallInfo["ct"]."</td>"
                ."<td class='text-right pr-2'>"
                        .sprintf($TimeFormat, $CallInfo["wt"] / 1000000)."</td>";

            if ($CallInfo["ct"] > 1) {
                $Result .= "<td class='text-right'>"
                    .sprintf($TimeFormat, ($CallInfo["wt"] / $CallInfo["ct"]) / 1000000)
                    ."</td>";
            } else {
                $Result .= "<td></td>";
            }

            $Result .= "</tr>";
            $Count++;
        }
        $Result .= '</table></div></div></div>';

        return $Result;
    }

    /**
     * HOOKED METHOD:  Generate and return HTML for memory profiling.
     * @return string Generated HTML.
     */
    public function getMemoryInfoHtml() : string
    {
        $ProfileData = $this->getProfileData();
        if ($ProfileData === false) {
            return "";
        }

        # if no memory info available, bail
        if (!isset(reset($ProfileData)["pmu"])) {
            return "";
        }

        # memory profiles add two additional rows for each function call
        # mu = change in allocated memory from the beginning of the function
        #   to the end (can be negative when functions free memory and does not
        #   account for memory that is allocated and freed w/in the function call)
        # pmu = change in total memory ever allocated from the beginning of the
        #   function to the end. Always >= 0.
        #
        # rows in the table sum these values over every function call

        # sort by average increase in peak mem usage
        uasort(
            $ProfileData,
            function ($a, $b) {
                return ($b["pmu"] / $b["ct"]) <=> ($a["pmu"] / $a["ct"]);
            }
        );

        $ProfileData = array_slice(
            $ProfileData,
            0,
            $this->getConfigSetting("ProfileCallCount"),
            true
        );

        $Result = '<div id="mv-content-memstats" class="container-fluid">'
            .'<div class="row p-2"><div class="col">'
            .'<h2 title="click to expand" '
            .'onclick=\'$("#mv-p-developer-mumprofile").toggle();\''
            .'>Memory Profile</h2>'
            .'<table id="mv-p-developer-mumprofile" style="width: 100%; display: none;">'
            .'<tr>'
            .'<th colspan=2></th>'
            .'<th colspan=2 class="text-center"'
            .' title="Change in max memory allocated by PHP from the beginning of function'
            .' execution to the end (always >= 0)."'
            .'>Peak&nbsp;Mem&nbsp;Usage&nbsp;(KiB)</th>'
            .'<th colspan=2 class="text-center"'
            .' title="Change in total memory allocated by PHP from the beginning of function'
            .' execution to the end (can be negative)."'
            .'>Mem&nbsp;Usage&nbsp;(KiB)</th>'
            .'</tr><tr>'
            .'<th>Call</th>'
            .'<th class="text-right">N</th>'
            .'<th class="text-right">Avg</th>'
            .'<th class="text-right">Tot</th>'
            .'<th class="text-right">Avg</th>'
            .'<th class="text-right">Tot</th>'
            .'</tr>';

        $Count = 0;
        foreach ($ProfileData as $FunctionCall => $CallInfo) {
            $CallCount = $CallInfo["ct"];

            # get usage, converted to KiB
            $TotalMemUsage = $CallInfo["mu"] / 1024;
            $TotalPeakMemUsage = $CallInfo["pmu"] / 1024;

            # compute average per call
            $AvgMemUsage = $TotalMemUsage / $CallCount;
            $AvgPeakMemUsage = $TotalPeakMemUsage / $CallCount;

            $BgColor = $Count % 2 == 1 ? 'ddd' : 'eee';
            $FunctionCall = str_replace("==>", "&nbsp;&rarr;&nbsp;", $FunctionCall);

            # cols describing the function
            $Result .= "<tr style='background-color: #".$BgColor."'>"
                ."<td><code>".$FunctionCall."</code></td>"
                ."<td class='text-right pr-2'>".$CallCount."</td>";

            # cols for peak mem usage
            $Result .= "<td class='text-right pr-2'>"
                .number_format($AvgPeakMemUsage)."</td>";
            if ($CallCount > 1) {
                $Result .= "<td class='text-right pr-2'>"
                    .number_format($TotalPeakMemUsage)."</td>";
            } else {
                $Result .= "<td></td>";
            }

            # cols for mem usage
            $Result .= "<td class='text-right pr-2'>"
                .number_format($AvgMemUsage)."</td>";
            if ($CallCount > 1) {
                $Result .= "<td class='text-right pr-2'>"
                    .number_format($TotalMemUsage)."</td>";
            } else {
                $Result .= "<td></td>";
            }

            $Result .= "</tr>";
            $Count++;
        }
        $Result .= '</table></div></div></div>';


        return $Result;
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Check whether auto-grade should run, based in user privilege and
     * user IP address.
     * @return boolean TRUE if upgrade should be run.
     */
    public static function autoUpgradeShouldRun(): bool
    {
        # run upgrade if user is logged in with Sys Admin privileges
        if (User::getCurrentUser()->hasPriv(PRIV_SYSADMIN)) {
            return true;
        }

        # run upgrade if it looks like we're coming from "mvus upgrade"
        $Argv = $_SERVER["argv"] ?? [];
        if ((PHP_SAPI == "cli")
                && isset($Argv[0]) && (basename($Argv[0]) == "mvus")
                && isset($Argv[1]) && (substr($Argv[1], 0, 2) == "up")) {
            return true;
        }

        # don't run automatic upgrade if user IP address is not available
        if (!isset($_SERVER["REMOTE_ADDR"])) {
            return false;
        }

        # get IP Mask setting
        $IPMaskSetting = self::getConfigSetting("AutoUpgradeIPMask");

        # if no value, do not run upgrade
        if (is_null($IPMaskSetting)) {
            return false;
        }

        # otherwise see if current IP matches one of the IPs on our list
        $IPAddresses = explode("\n", $IPMaskSetting);
        foreach ($IPAddresses as $IPAddress) {
            # if address looks like regular expression
            $IPAddress = trim($IPAddress);
            if (preg_match("%^/.+/\$%", $IPAddress)) {
                # if expression matches user's IP address
                if (preg_match($IPAddress, $_SERVER["REMOTE_ADDR"])) {
                    # report that upgrade should run
                    return true;
                }
            } else {
                # if address matches user's IP address
                if ($_SERVER["REMOTE_ADDR"] == $IPAddress) {
                    # report that upgrade should run
                    return true;
                }
            }
        }

        # report that upgrade should not run
        return false;
    }

    /**
     * Check for any database upgrade SQL files that have changed and perform
     * upgrades as needed.
     * @return array Progress and error messages.
     */
    public static function checkForDatabaseUpgrades(): array
    {
        $DB = new Database();
        $Messages = [];

        # get lock to prevent two upgrades from running simultaneously
        $AF = ApplicationFramework::getInstance();
        $AF->getLock();

        # check for changed upgrade files
        $ChangedFiles = self::checkForChangedUpgradeFiles(self::DBUPGRADE_FILEPATTERN);

        # if changed files found
        if (count($ChangedFiles)) {
            $DB->setQueryErrorsToIgnore(self::$SqlErrorsWeCanIgnore);

            # for each changed file
            foreach ($ChangedFiles as $FilePath) {
                # log database upgrade
                $Msg = "Running database upgrade (".basename($FilePath).").";
                $Messages[] = $Msg;
                $AF->logMessage(ApplicationFramework::LOGLVL_INFO, $Msg);

                # execute queries in file
                $Result = $DB->executeQueriesFromFile($FilePath);

                # if queries succeeded
                if ($Result !== null) {
                    self::updateUpgradeFileCheckdata($FilePath);
                } else {
                    # log error
                    $Msg = "Database upgrade SQL Error from file ".$FilePath
                            .": ".$DB->queryErrMsg();
                    $Messages[] = $Msg;
                    $AF->logError(ApplicationFramework::LOGLVL_ERROR, $Msg);
                }
            }

            $NewMessages = self::cleanOutDuplicateDatabaseIndexes();
            $Messages = array_merge($Messages, $NewMessages);
        }

        # release lock so that other upgrade checks can run
        $AF->releaseLock();

        return $Messages;
    }

    /**
     * Check for and run upgrades via CLI.
     * @return void
     */
    public function commandUpgrade(): void
    {
        $DbErrorDisplayState = Database::displayQueryErrors();
        Database::displayQueryErrors(false);

        $Messages = $this->checkForDatabaseUpgrades();
        foreach ($Messages as $Msg) {
            print $Msg."\n";
        }
        $Messages = $this->checkForSiteUpgrades();
        foreach ($Messages as $Msg) {
            print $Msg."\n";
        }

        Database::displayQueryErrors($DbErrorDisplayState);
    }

    /**
     * Get any settings set and error messages as HTML for display.
     * @return string HTML for display.
     */
    public function getSettingsInfoHtml(): string
    {
        return $this->SettingsInfoHtml;
    }

    /**
     * Generate and return HTML for watermark.
     * @return string Generated HTML.
     */
    public function getWatermarkHtml(): string
    {
        # retrieve base directory owner
        $Owner = "(unknown)";
        $BaseDir = getcwd();
        if ($BaseDir !== false) {
            $OwnerId = @fileowner($BaseDir);
            if ($OwnerId !== false) {
                $OwnerInfo = posix_getpwuid($OwnerId);
                if ($OwnerInfo !== false) {
                    $Owner = $OwnerInfo["name"];
                }
            }
        }

        # perform substitutions in watermark text
        $WatermarkText = $this->getConfigSetting("WatermarkText");
        $WatermarkText = str_replace("{{OWNER}}", $Owner, $WatermarkText);

        # get color for text
        $TextColor = "#FFFFFF";
        $TextOpacity = "0.7";
        switch ($this->getConfigSetting("WatermarkColor")) {
            case self::WATERMARKCOLOR_TEXT:
                $Hue = crc32($WatermarkText) % 360;
                break;

            case self::WATERMARKCOLOR_PATH:
                $Hue = crc32(gethostname().__DIR__) % 360;
                break;
        }
        if (isset($Hue)) {
            $TextColor = StdLib::hslToHexColor($Hue, 100, 75);
            $TextOpacity = "0.5";
        }

        # define watermark style
        ob_start();
        ?><style type="text/css">
        #mv-p-developer-watermark a {
            position: fixed;
            top: 10px;
            left: 10px;
            margin: 0px;
            opacity: <?= $TextOpacity ?>;
            z-index: 99;
            font-size: 36px;
            font-weight: bold;
            font-family: arial, sans-serif;
            color: <?= $TextColor ?>;
            text-shadow: 1px 1px #999999;
            text-transform: uppercase;
            text-decoration: none;
        }
        </style><?PHP

        # add watermark output
        ?><p id="mv-p-developer-watermark"><a href="index.php?P=Home"><?=
                $WatermarkText ?></a></p><?PHP

        # return generated HTML to caller
        return (string)ob_get_clean();
    }

    /**
     * Function to print a notice about the email whitelist.
     * @param array $Whitelist Email whitelist.
     * @return void
     */
    public function printWhitelistNotice(array $Whitelist): void
    {
        array_walk(
            $Whitelist,
            function (&$Value) {
                $Value = trim($Value);
                $Length = strlen($Value);
                if ($Length >= 2) {
                    $Value = substr($Value, 1, $Length - 2);
                }
            }
        );

        print '<div class="alert alert-warning">'
            .'<p><b>NOTE:</b> This site is configured to only send email to a '
            .'restricted list of addresses. Messages will only be sent to '
            .'addresses that match one the following regular expressions. You '
            .'can contact your site administrator for more information. </p>'
            .'<pre>'.implode("\n", $Whitelist).'</pre>'
            .'</div>';
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $CurrentSettingsFile;
    private $SettingsErrorMsgs = [];
    private $SettingsInfoHtml = "";

    const DBUPGRADE_FILEPATTERN = "install/DBUpgrades/DBUpgrade--*.sql";
    const SITEUPGRADE_FILEPATTERN = "install/SiteUpgrades/SiteUpgrade--*.php";
    const WATERMARKCOLOR_WHITE = 0;
    const WATERMARKCOLOR_TEXT = 1;
    const WATERMARKCOLOR_PATH = 2;

    /**
     * Check for and remove duplicate database indexes.
     * Check for and remove duplicate database indexes.
     * @return array Progress and error messages.
     */
    private static function cleanOutDuplicateDatabaseIndexes(): array
    {
        $DB = new Database();
        $Messages = [];

        # get a list of all the indexes for our database
        $AF = ApplicationFramework::getInstance();
        $DB->query("SELECT TABLE_NAME, INDEX_TYPE, INDEX_NAME, COLUMN_NAME "
                   ."FROM information_schema.statistics "
                   ."WHERE table_schema='"
                   .$GLOBALS["G_Config"]["Database"]["DatabaseName"]."'");
        $Rows = $DB->fetchRows();

        # build a list of columns for each type * index_name * table
        $IndexByName = [];
        foreach ($Rows as $Row) {
            $RowKey = $Row["TABLE_NAME"]."-".$Row["INDEX_TYPE"];
            $IndexByName[$RowKey][$Row["INDEX_NAME"]][] = $Row["COLUMN_NAME"];
        }

        # use the above to determine which indexes cover distinct sets of
        # columns
        $IndexByContents = [];
        foreach ($IndexByName as $Table => $IndexData) {
            foreach ($IndexData as $IxName => $IxColumns) {
                sort($IxColumns);
                $Key = implode("-", $IxColumns);
                $IndexByContents[$Table][$Key][] = $IxName;
            }
        }

        # iterate through our indexes and remove those which cover the same
        #  set of columns with the same index type
        foreach ($IndexByContents as $Table => $IndexData) {
            foreach ($IndexData as $IxCols => $IxNames) {
                if (count($IxNames) > 1) {
                    # keep the last index (more likely to match naming conventions)
                    array_pop($IxNames);

                    $TableName = explode("-", $Table);
                    $TableName = array_shift($TableName);
                    $AF = ApplicationFramework::getInstance();
                    foreach ($IxNames as $Tgt) {
                        if ($AF->getSecondsBeforeTimeout() > 30) {
                            $DB->query("ALTER TABLE ".$TableName
                                       ." DROP INDEX ".$Tgt);
                            $Messages[] = "Dropped index ".$Tgt
                                    ." from table ".$TableName.".";
                        }
                    }
                }
            }
        }

        return $Messages;
    }

    /**
     * Check for any site upgrade PHP files that have changed and perform
     * upgrades as needed.
     * @return array Messages from upgrade files.
     */
    private function checkForSiteUpgrades(): array
    {
        $Messages = [];

        # check for changed upgrade files
        $ChangedFiles = self::checkForChangedUpgradeFiles(self::SITEUPGRADE_FILEPATTERN);

        # if changed files found
        if (count($ChangedFiles)) {
            # set up environment for file
            global $G_MsgFunc;
            $G_MsgFunc = [__CLASS__, "msg"];

            # for each changed file
            $AF = ApplicationFramework::getInstance();
            foreach ($ChangedFiles as $FilePath) {
                # log system upgrade
                $Msg = "Running system upgrade (".basename($FilePath).").";
                $Messages[] = $Msg;
                $AF->logMessage(ApplicationFramework::LOGLVL_INFO, $Msg);

                # execute code in file
                include($FilePath);

                # if error encountered
                if (isset($GLOBALS["G_ErrMsgs"]) && count($GLOBALS["G_ErrMsgs"])) {
                    # log errors
                    foreach ($GLOBALS["G_ErrMsgs"] as $ErrMsg) {
                        $Msg = "Site upgrade error from file ".$FilePath
                                .": ".$ErrMsg;
                        $Messages[] = $Msg;
                        $AF->logError(
                            ApplicationFramework::LOGLVL_ERROR,
                            $Msg
                        );
                    }

                    # stop executing
                    break;
                } else {
                    self::updateUpgradeFileCheckdata($FilePath);
                }
            }
        }

        return $Messages;
    }

    /**
     * Message logging function for use during site upgrades.
     * @param int $VerbosityLevel Minimum verbosity level required to display message.
     * @param string $Message Message string.
     * @return void
     */
    public static function msg($VerbosityLevel, $Message): void
    {
        # if running from command line
        if (php_sapi_name() == "cli") {
            # output message to STDOUT
            print str_repeat("  ", ($VerbosityLevel - 1)).$Message."\n";
        } else {
            # write message to log file
            static $FHandle = false;
            if ($FHandle == false) {
                $InstallLogFile = "local/logs/install.log";
                if (!is_dir(dirname($InstallLogFile)) &&
                    is_writable(dirname(dirname($InstallLogFile)))) {
                    mkdir(dirname(dirname($InstallLogFile)));
                }
                if ((file_exists($InstallLogFile) && is_writable($InstallLogFile)) ||
                    (!file_exists($InstallLogFile) && is_writable(dirname($InstallLogFile)))) {
                    $FHandle = fopen($InstallLogFile, "a");
                }
            }
            if ($FHandle) {
                $LogMsg = date("Y-m-d H:i:s")."  "
                        .strip_tags($Message)." (DP:"
                        .$VerbosityLevel.")\n";
                fwrite($FHandle, $LogMsg);
                fflush($FHandle);
            }
        }
    }

    /**
     * Pattern replacement callback for fallback URL prefix insertion,
     * intended to be used with preg_replace_callback().
     * @param array $Matches Matching segments from replace function.
     * @return string Replacement string.
     * @see Developer::InsertImageUrlFallbackPrefixes()
     */
    private function insertImageUrlFallbackPrefixesCallback(array $Matches): string
    {
        $Url = $Matches[1].".".$Matches[2];

        # if URL is relative
        if ((stripos($Url, "http://") !== 0) && (stripos($Url, "https://") !== 0)) {
            # cache some values because we will be called a lot
            static $UrlFingerprintingEnabled;
            static $FileUrlFallbackPrefix;
            if (!isset($UrlFingerprintingEnabled)) {
                $AF = ApplicationFramework::getInstance();
                $UrlFingerprintingEnabled = $AF->urlFingerprintingEnabled();
                $FileUrlFallbackPrefix = $this->getConfigSetting("FileUrlFallbackPrefix");
            }

            # strip any URL fingerprinting out of file name before looking for local copy
            $TestUrl = $Url;
            if ($UrlFingerprintingEnabled) {
                $TestUrl = preg_replace('%\.[0-9A-F]{6}\.([A-Za-z]+)$%', '.\1', $Url);
            }

            # if file could not be found locally
            if (!is_readable($TestUrl)) {
                # prepend fallback prefix to URL
                $Url = $FileUrlFallbackPrefix.$Url;
            }
        }
        return "src=\"".$Url."\"";
    }

    /**
     * Display variable formatted for Variable Monitor.
     * @param string $VarName Name of variable.
     * @param mixed $VarValue Current value of variable.
     * @param int $VarIndex Numerical index into list of variables.
     * @return void
     */
    private function displayVariable(string $VarName, $VarValue, int $VarIndex): void
    {
        print "<span onclick=\"$('#VMVal".$VarIndex."').toggle()\" class=\"VarMonName\">"
                .$VarName."</span><br/>\n"
                ."<div id='VMVal".$VarIndex."' class=\"VarMonValue\">";
        ob_start();
        var_dump($VarValue);
        $VarDump = (string)ob_get_clean();
        # (strip out any file/line info added by Xdebug)
        $VarDump = str_replace(__FILE__.":".(__LINE__ - 3).":", "", $VarDump);
        if (strlen($VarDump) < $this->getConfigSetting("VariableDisplayThreshold")) {
            print $VarDump;
        } else {
            if (is_object($VarValue)) {
                print "(<i>".get_class($VarValue)."</i> object)";
            } elseif (is_array($VarValue)) {
                print "(array:".count($VarValue).")";
            } elseif (is_string($VarValue)) {
                print "(string:".strlen($VarValue).")";
            } else {
                print "(value too long to display - length:".strlen($VarDump).")";
            }
        }
        print "</div>\n";
    }

    /**
     * Calculate and store new checkdata for all upgrade files in specified area.
     * In addition to checking for files matching the supplied pattern, the pattern
     * with "local/" prepended to it will be checked for files.
     * @param string $FilePattern File pattern to pass to glob() to find upgrade files.
     * @return void
     */
    private static function updateUpgradeFileCheckdata(string $FilePattern): void
    {
        # retrieve list of upgrade files
        $Files = glob($FilePattern);
        if ($Files === false) {
            throw new Exception("File pattern search failed.");
        }
        $LocalFiles = glob("local/".$FilePattern);
        if ($LocalFiles === false) {
            throw new Exception("Local file pattern search failed.");
        }
        $Files = array_merge($Files, $LocalFiles);

        foreach ($Files as $FileName) {
            self::updateCheckdataForUpgradeFile($FileName);
        }
    }

    /**
     * Compare checksums of upgrade files against stored values and return
     * names of any files that have changed.  In addition to checking for
     * files matching the supplied pattern, the pattern with "local/" prepended
     * to it will be checked.
     * @param string $FilePattern File pattern to pass to glob() to find upgrade files.
     * @return array Full relative paths for any files that have changed.
     */
    private static function checkForChangedUpgradeFiles(string $FilePattern): array
    {
        # retrieve list of upgrade files
        $Files = glob($FilePattern);
        if ($Files === false) {
            throw new Exception("File pattern search failed.");
        }
        $LocalFiles = glob("local/".$FilePattern);
        if ($LocalFiles === false) {
            throw new Exception("Local file pattern search failed.");
        }
        $Files = array_merge($Files, $LocalFiles);

        $Files = self::sortUpgradeFileList($Files);

        # retrieve list of checksums
        $Checksums = static::getConfigSetting("UpgradeFileChecksums") ?? [];

        # retrieve list of modification times
        $MTimes = static::getConfigSetting("UpgradeFileModificationTimes") ?? [];

        # for each file
        $ChangedFiles = [];
        foreach ($Files as $FileName) {
            # skip file if modification time has not changed
            if (array_key_exists($FileName, $MTimes)
                    && (filemtime($FileName) == $MTimes[$FileName])) {
                continue;
            }

            # calculate checksum for file
            $Checksum = md5_file($FileName);

            # if checksum is different
            if (!array_key_exists($FileName, $Checksums)
                    || ($Checksum != $Checksums[$FileName])) {
                # add file to list
                $ChangedFiles[] = $FileName;
            }
        }

        # return list of any changed files to caller
        return $ChangedFiles;
    }

    /**
     * Update saved data (checksum and modification time) used to check
     * whether specified upgrade file has changed.
     * @param string $FileName Name of upgrade file.
     * @return void
     * @see checkForChangedUpgradeFiles()
     */
    private static function updateCheckdataForUpgradeFile(string $FileName): void
    {
        $Checksums = self::getConfigSetting("UpgradeFileChecksums");
        $Checksums[$FileName] = md5_file($FileName);
        self::setConfigSetting("UpgradeFileChecksums", $Checksums);
        $MTimes = self::getConfigSetting("UpgradeFileModificationTimes");
        $MTimes[$FileName] = filemtime($FileName);
        self::setConfigSetting("UpgradeFileModificationTimes", $MTimes);
    }

    /**
     * Normalize the file URL fallback prefix value when it's set.
     * @param string $SettingName Name of configuration setting.
     * @param string $NewValue New value of configuration setting.
     * @return string Updated value of configuration setting.
     */
    protected function normalizeFileUrlFallbackPrefix(
        string $SettingName,
        string $NewValue
    ): string {
        # add a trailing slash to the new value if not present
        $NewValue = trim($NewValue);
        if (strlen($NewValue) && (substr($NewValue, -1) != "/")) {
            $NewValue = $NewValue."/";
        }

        # return updated value to caller
        return $NewValue;
    }

    /**
     * Load settings from local file if available.
     * @return void
     */
    private function loadLocalSettings(): void
    {
        # for each settings file
        $SetMsgs = [];
        $SetBy = [];
        foreach ($this->findSettingsFiles() as $FileName) {
            # load settings
            $FileSetMsgs = $this->loadSettingsFromFile($FileName);

            # save any "settings set" messages for file
            $SetMsgs[$FileName] = $FileSetMsgs;

            # record what file set each setting
            foreach ($FileSetMsgs as $FullParam => $Msg) {
                # ($SetBy is needed because settings can be overridden by
                #       entries encountered in a later config file, and we
                #       want to display the file that set the final value)
                $SetBy[$FullParam] = $FileName;
            }
        }

        # add any results to plugin instructions message
        $this->buildSettingsInfoHtml($SetMsgs, $SetBy);
    }

    /**
     * Find all local settings files.
     * @return array Settings file names, with relative paths.
     */
    private function findSettingsFiles(): array
    {
        # possible settings file in ascending order of precedence
        $AdditionalDevFiles = glob("developer-*.ini");
        $AdditionalLocalFiles = glob("local/developer-*.ini");
        $PossibleSettingsFiles = array_merge(
            $AdditionalDevFiles ? $AdditionalDevFiles : [],
            ["developer.ini"],
            $AdditionalLocalFiles ? $AdditionalLocalFiles : [],
            ["local/developer.ini"]
        );

        $SettingsFiles = [];
        foreach ($PossibleSettingsFiles as $File) {
            if (file_exists($File)) {
                if (is_readable($File)) {
                    $SettingsFiles[] = $File;
                } else {
                    $this->SettingsErrorMsgs[] = "Developer settings file ".$File
                            ." was not readable.";
                }
            }
        }
        return $SettingsFiles;
    }

    /**
     * Load settings from specified file.
     * @param string $FileName Name of file.
     * @return array Messages about any values set by file, with full setting
     *      names for index.
     */
    private function loadSettingsFromFile(string $FileName): array
    {
        $NonPluginSections = [
            "ApplicationFramework",
            "SystemConfiguration",
        ];

        # read settings from file
        $Settings = @parse_ini_file($FileName, true, INI_SCANNER_TYPED);

        # skip file if syntax error
        if ($Settings === false) {
            $this->SettingsErrorMsgs[] = "Syntax error encountered in"
                    ." developer settings file ".$FileName;
            return [];
        }

        # for each setting section found
        $this->CurrentSettingsFile = $FileName;
        $FileSetMsgs = [];
        foreach ($Settings as $SectionHeading => $SectionData) {
            # parse section heading
            $Conditions = explode(" ", $SectionHeading);
            $SectionName = array_shift($Conditions);

            # for each section condition
            foreach ($Conditions as $Condition) {
                # check condition and skip section if not met
                if ($this->isSettingsConditionMet($SectionName, $Condition) != true) {
                    continue 2;
                }
            }

            # for each setting found
            foreach ($SectionData as $Param => $Value) {
                if (in_array($SectionName, $NonPluginSections)) {
                    $Msg = $this->setNonPluginSetting($SectionName, $Param, $Value);
                } else {
                    $Msg = $this->setPluginSetting($SectionName, $Param, $Value);
                }
                if ($Msg !== null) {
                    $FullParam = $SectionName.":".$Param;
                    $FileSetMsgs[$FullParam] = $Msg;
                }
            }
        }

        return $FileSetMsgs;
    }

    /**
     * Set non-plugin system setting.
     * @param string $SectionName Settings section name.
     * @param string $Param Setting name.
     * @param mixed $Value Value to set.
     * @return string|null Setting message if value was set, or NULL if error
     *      encountered.
     */
    private function setNonPluginSetting(string $SectionName, string $Param, $Value): ?string
    {
        $Msg = null;
        $FullParam = $SectionName.":".$Param;
        $PrintableValue = $this->getPrintableSettingValue($Value);

        switch ($SectionName) {
            case "ApplicationFramework":
                $AF = ApplicationFramework::getInstance();
                if (method_exists($AF, $Param)) {
                    $AF->$Param($Value);
                    $Msg = "<i>".$FullParam."</i> set to ".$PrintableValue;
                } else {
                    $this->SettingsErrorMsgs[] = "Unknown setting \"".$Param
                            ."\" for ApplicationFramework in "
                            .$this->CurrentSettingsFile.".";
                }
                break;

            case "SystemConfiguration":
                $SysConfig = SystemConfiguration::getInstance();
                if (!$SysConfig->fieldExists($Param)) {
                    $this->SettingsErrorMsgs[] = "Unrecognized system"
                            ." configuration setting \"".$Param
                            ."\" found in ".$this->CurrentSettingsFile.".";
                } else {
                    switch ($SysConfig->getFieldType($Param)) {
                        case SystemConfiguration::TYPE_ARRAY:
                            $this->SettingsErrorMsgs[] = "Unsupported system"
                                    ." configuration setting \"".$Param
                                    ."\" found in ".$this->CurrentSettingsFile."."
                                    ." (Array settings cannot be set via this method.)";
                            break;

                        case SystemConfiguration::TYPE_BOOL:
                            $SysConfig->overrideBool($Param, (bool)$Value);
                            break;

                        case SystemConfiguration::TYPE_DATETIME:
                            $SysConfig->overrideDatetime($Param, $Value);
                            break;

                        case SystemConfiguration::TYPE_FLOAT:
                            $SysConfig->overrideFloat($Param, (float)$Value);
                            break;

                        case SystemConfiguration::TYPE_INT:
                            $SysConfig->overrideInt($Param, (int)$Value);
                            break;

                        default:
                            $SysConfig->overrideString($Param, $Value);
                            break;
                    }
                    $Msg = "<i>".$FullParam."</i> set to ".$PrintableValue;
                }
                break;

            default:
                $this->SettingsErrorMsgs[] = "Unrecognized non-plugin section \""
                        .$SectionName."\" found in ".$this->CurrentSettingsFile.".";
                break;
        }

        return $Msg;
    }

    /**
     * Set plugin setting.
     * @param string $PluginName Base name of plugin.
     * @param string $Param Setting name.
     * @param mixed $Value Value to set.
     * @return string|null Setting message if value was set, or NULL if error
     *      encountered.
     */
    private function setPluginSetting(string $PluginName, string $Param, $Value): ?string
    {
        $Msg = null;
        $FullParam = $PluginName.":".$Param;
        $PrintableValue = $this->getPrintableSettingValue($Value);
        $DefaultMsg = "<i>".$FullParam."</i> set to ".$PrintableValue;

        # load plugin
        static $Plugins;
        if (isset($Plugins[$PluginName])) {
            $Plugin = $Plugins[$PluginName];
        } else {
            try {
                $Plugin = (PluginManager::getInstance())->getPlugin(
                    $PluginName,
                    true
                );
            } catch (Exception $Exception) {
                $Plugin = null;
            }
            if ($Plugin === null) {
                $this->SettingsErrorMsgs[] = "Skipped section <i>"
                        .$PluginName."</i> in "
                        .$this->CurrentSettingsFile." because"
                        ." corresponding plugin was not available.";
                return null;
            } else {
                $Plugins[$PluginName] = $Plugin;
            }
        }

        # process special case settings
        switch ($Param) {
            case "Enabled":
                $Plugin->IsEnabled($Value ? true : false, false);
                $Msg = "<i>".$PluginName."</i> plugin "
                        .($Value ? "ENABLED" : "DISABLED");
                break;
        }
        switch ($FullParam) {
            case "Developer:AutoUpgradeIPMask":
            case "Developer:EmailWhitelist":
                $Msg = $DefaultMsg;
                $Value = str_replace(",", "\n", $Value);
                $Plugin->ConfigSettingOverride($Param, $Value);
                break;
        }
        if ($Msg !== null) {
            return $Msg;
        }

        # handle other settings based on type
        switch ($Plugin->getConfigSettingType($Param)) {
            case FormUI::FTYPE_FLAG:
                $Msg = $DefaultMsg;
                $Plugin->ConfigSettingOverride($Param, $Value);
                break;

            case FormUI::FTYPE_TEXT:
            case FormUI::FTYPE_URL:
            case FormUI::FTYPE_NUMBER:
            case FormUI::FTYPE_PARAGRAPH:
                $Msg = $DefaultMsg;
                $Plugin->ConfigSettingOverride($Param, $Value);
                break;

            case FormUI::FTYPE_OPTION:
                $SParams = $Plugin->GetConfigSettingParameters($Param);
                $Pieces = explode(",", $Value);
                $SelectedOpts = [];
                $NoErrors = true;
                foreach ($Pieces as $Piece) {
                    $Piece = trim($Piece);
                    if ($OptKey = array_search($Piece, $SParams["Options"])) {
                        $SelectedOpts[$OptKey] = $Piece;
                    } else {
                        $this->SettingsErrorMsgs[] = "Unknown value <i>".$Piece
                                ."</i> found for setting <i>".$Param
                                ."</i> for plugin <i>".$PluginName
                                ."</i> in ".$this->CurrentSettingsFile;
                        $NoErrors = false;
                    }
                }
                if ($NoErrors || count($SelectedOpts)) {
                    $Msg = "<i>".$FullParam."</i> set to <i>"
                            .implode(", ", $SelectedOpts)."</i>";
                    $SelectedValues = array_keys($SelectedOpts);
                    if (!isset($SParams["AllowMultiple"]) || !$SParams["AllowMultiple"]) {
                        $SelectedValues = reset($SelectedValues);
                    }
                    $Plugin->configSettingOverride(
                        $Param,
                        $SelectedValues
                    );
                }
                break;

            default:
                $this->SettingsErrorMsgs[] = "Unknown or unsupported setting <i>"
                        .$Param."</i> found for plugin <i>".$PluginName."</i> in "
                        .$this->CurrentSettingsFile;
                break;
        }

        return $Msg;
    }

    /**
     * Test whether condition from settings file is met.
     * @param string $SectionName Name of current settings section.
     * @param string $Condition Condition string.
     * @return bool TRUE if met, otherwise FALSE.
     */
    private function isSettingsConditionMet(string $SectionName, string $Condition): bool
    {
        # log error and bail out if condition appears invalid
        if (preg_match("/([a-z]+)(=|!=)(.+)/", $Condition, $Pieces) !== 1) {
            $this->SettingsErrorMsgs[] = "Invalid condition \"".$Condition
                    ."\" encountered for section ".$SectionName
                    ." found in developer settings file "
                    .$this->CurrentSettingsFile;
            return false;
        }

        # parse condition
        $ConditionKeyword = $Pieces[1];
        $ConditionOperator = $Pieces[2];
        $ConditionValues = explode("|", $Pieces[3]);

        switch ($ConditionKeyword) {
            case "host":
                $HostName = gethostname();
                if ((($ConditionOperator == "=") && in_array($HostName, $ConditionValues)) ||
                    (($ConditionOperator == "!=") && !in_array($HostName, $ConditionValues))) {
                    return true;
                }
                break;

            case "interface":
                $AF = ApplicationFramework::getInstance();
                $Interface = $AF->activeUserInterface();
                if ((($ConditionOperator == "=") && in_array($Interface, $ConditionValues)) ||
                    (($ConditionOperator == "!=") && !in_array($Interface, $ConditionValues))) {
                    return true;
                }
                break;

            default:
                $this->SettingsErrorMsgs[] = "Invalid condition keyword \""
                        .$ConditionKeyword
                        ."\" found for section ".$SectionName
                        ." found in developer settings file "
                        .$this->CurrentSettingsFile;
        }

        return false;
    }

    /**
     * Get setting value formatted for printing.
     * @param mixed $Value Value.
     * @return string Formatted value.
     */
    private function getPrintableSettingValue($Value): string
    {
        return is_bool($Value)
                ? ($Value ? "TRUE" : "FALSE")
                : ((is_string($Value) && !strlen($Value))
                    ? "(empty string)"
                    : "<i>".$Value."</i>");
    }

    /**
     * Build HTML for settings info.
     * @param array $SetMsgs Array of arrays of settings messages, with
     *      settings file name for the outer index and full parameter name
     *      for the inner index.
     * @param array $SetBy Array with full parameter names for the index
     *      and what file set them for the value.
     * @return void
     */
    private function buildSettingsInfoHtml(array $SetMsgs, array $SetBy): void
    {
        # bail out if there is nothing to add
        if (!count($this->SettingsErrorMsgs) && !count($SetMsgs)) {
            return;
        }

        # add any error messages
        if (count($this->SettingsErrorMsgs)) {
            foreach ($this->SettingsErrorMsgs as $Msg) {
                $this->SettingsInfoHtml .= "<b>ERROR:</b> ".$Msg."<br>\n";
            }
            $this->SettingsInfoHtml .= "<br>\n";
        }

        # add any setting messages
        if (count($SetMsgs)) {
            foreach ($SetMsgs as $SettingsFile => $Msgs) {
                ksort($Msgs);
                $this->SettingsInfoHtml .= "Values forced via ".$SettingsFile
                        .":<br>\n<ul>\n";
                $NoSetFound = true;
                foreach ($Msgs as $FullParam => $Msg) {
                    if ($SetBy[$FullParam] == $SettingsFile) {
                        $this->SettingsInfoHtml .= "<li>".$Msg."</li>\n";
                        $NoSetFound = false;
                    }
                }
                if ($NoSetFound) {
                    $this->SettingsInfoHtml .= "(none - all settings overridden)\n";
                }
                $this->SettingsInfoHtml .= "</ul>\n";
            }
        }
    }

    /**
     * Sort list of upgrade files so that they appear in the order we would
     * like them to be run (CWIS upgrades before Metavus, database upgrades
     * before PHP upgrades).
     * @param array $FileNames List of upgrade files.
     * @return array Sorted list.
     * @see Installer::sortUpgradeFiles()  (identical method)
     */
    private static function sortUpgradeFileList(array $FileNames): array
    {
        $VerExtractFunc = function (string $FileName) {
            $FileName = pathinfo($FileName, PATHINFO_FILENAME);
            $Version = preg_replace("/[A-Z]+--/i", "", $FileName);
            return $Version;
        };
        $SortFunc = function ($AFileName, $BFileName) use ($VerExtractFunc) {
            if ($AFileName == $BFileName) {
                return 0;
            }
            $AVersion = $VerExtractFunc($AFileName);
            $BVersion = $VerExtractFunc($BFileName);
            # if versions are equal compare file names so that SQL ugprades run first
            if ($AVersion == $BVersion) {
                return $AFileName <=> $BFileName;
            }
            return (int)self::legacyVersionCompare($AVersion, $BVersion);
        };
        usort($FileNames, $SortFunc);
        return $FileNames;
    }

    /**
     * Compare version numbers, with adjustments to understand CWIS vs Metavus.
     * (Version number is assumed to be CWIS if it's equal to or above the oldest
     * upgradable version number.)
     * @param string $VersionOne First version number.
     * @param string $VersionTwo Second version number.
     * @param string $Operator Comparison operator (same as that supported
     *      for version_compare()).
     * @return bool|int If operator supplied, then TRUE if
     *      (VersionOne Operator VersionTwo) is true, otherwise FALSE.  If no
     *      operator supplied, then 1/0/-1 like the spaceship operator.
     *      (Similar to version_compare().)  (OPTIONAL)
     * @see Installer::legacyVersionCompare()  (identical method)
     */
    private static function legacyVersionCompare(
        string $VersionOne,
        string $VersionTwo,
        ?string $Operator = null
    ) {
        $AdjustFunc = function (string $Version): string {
            if (version_compare($Version, self::OLDEST_UPGRADABLE_VERSION, "<")) {
                $Pieces = explode(".", $Version, 2);
                $Version = ((string)((int)$Pieces[0] + 4)).".".$Pieces[1];
            }
            return $Version;
        };
        $VersionOne = $AdjustFunc($VersionOne);
        $VersionTwo = $AdjustFunc($VersionTwo);
        if ($Operator === null) {
            return version_compare($VersionOne, $VersionTwo);
        } else {
            return version_compare($VersionOne, $VersionTwo, $Operator);
        }
    }

    /**
     * Get xhprof profiling information if available.
     * @return array|false Array of profile information or FALSE when no
     *   profile can be retrieved. See https://github.com/longxinH/xhprof for
     *   docs (such as they are) on the format returned.
     *
     * Per those docs, the array returned is keyed by caller/callee pairs, and
     * each row contains:
     *  - wt: The execution time of the function
     *  - ct: The number of times the function was called
     *
     * When enabled with XHPROF_FLAGS_CPU, it will also contain:
     *  - cpu: The CPU time consumed by the function method execution
     *      (excludes time spent waiting for IO)
     *
     * And when enabled with XHPROF_FLAGS_MEMORY:
     *  - mu: Memory used by function methods. The call is zend_memory_usage
     *      to get the memory usage. (Computed as the difference in allocated
     *      memory from when the function was called till it returns. May be
     *      negative when functions free memory. Does not account for memory
     *      allocated and then freed within the function.)
     * - pmu: Peak memory used by the function method. The call is
     *     zend_memory_peak_usage to get the memory. (Computed as the
     *     difference in peak memory ever allocated by PHP from when
     *     the function was called till it returns. Always >= 0.)
     *
     * zend_memory_usage() is the underlying C function for
     *   https://www.php.net/manual/en/function.memory-get-usage.php
     *
     * zend_memory_peak_usage() is the underlying C function for
     *   https://www.php.net/manual/en/function.memory-get-peak-usage.php
     *
     * Rows in the array are totals that were summed over each caller/callee
     * pair (See hp_mode_hier_endfn_cb() and hp_inc_count() in
     * extension/xhprof.c)
     */
    private function getProfileData()
    {
        static $ProfileData = null;

        if (is_null($ProfileData)) {
            if (!function_exists("xhprof_disable")) {
                $ProfileData = false;
            } else {
                $ProfileData = xhprof_disable();
                if (!is_array($ProfileData)) {
                    $ProfileData = false;
                }
            }
        }

        return $ProfileData;
    }
}
