<?PHP
#
#   FILE:  BotDetector.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\User;
use Metavus\Plugins\MetricsRecorder;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Plugin;
use ScoutLib\PluginManager;

/**
 * Provides support for detecting whether a page was loaded by a person or by an
 * automated program, e.g., a web crawler or spider.
 */
class BotDetector extends Plugin
{

    /**
     * Register information about this plugin.
     */
    public function register(): void
    {
        $this->Name = "Bot Detector";
        $this->Version = "1.4.0";
        $this->Description = "Provides support for detecting whether the"
                ." current page load is by an actual person or by an automated"
                ." <a href=\"http://en.wikipedia.org/wiki/Web_crawler\""
                ." target=\"_blank\">web crawler or spider</a>.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = true;

        $this->CfgSetup["HttpBLAccessKey"] = [
            "Type" => "Text",
            "Label" => "http:BL Access Key",
            "Help" => "(Optional) Your http:BL Access Key "
            ." from <a href=\"http://www.projecthoneypot.org/\">Project Honeypot</a>"
            .", used to identify web robots by IP address. "
            ."Keys are 12 lowercase letters (e.g., <i>abcdefghjkmn</i>).",
            "Size" => 16
        ];

        $this->CfgSetup["BotPruning"] = [
            "Type" => "Flag",
            "Label" => "Bot Pruning",
            "Help" => "When a bot is detected, should all data for that bot's IP "
            ."be pruned from data collected by MetricsRecorder?",
            "OnLabel" => "Yes",
            "OffLabel" => "No",
            "Default" => true
        ];

        $this->CfgSetup["BotDomainPatterns"] = [
            "Type" => "Paragraph",
            "Label" => "Bot Domain Patterns",
            "Help" => "List of regular expression patterns to match "
                ."hostnames of known bots. Patterns should "
                ."be specified one per line, and use the syntax "
                ."expected by the PHP function preg_match().",
            "Default" => "%\\.bc\\.googleusercontent\\.com$%\n"
                ."%\\.compute(-[0-9]+)?\\.amazonaws\\.com$%"
        ];
    }

    /**
     * Perform table creation necessary when the plugin is first installed.
     * @return null|string NULL on success, string containing an error
     *      message otherwise.
     */
    public function install(): ?string
    {
        return $this->createTables(self::SQL_TABLES);
    }

    /**
     * Perform table deletion necessary when the plugin is uninstalled.
     * @return null|string NULL on success, string containing error message
     *      on failure.
     */
    public function uninstall(): ?string
    {
        return $this->dropTables(self::SQL_TABLES);
    }

    /**
     * Initialize the plugin.  This method is called after all plugins
     * are loaded but before any other plugin methods (except Register)
     * are called.
     * @return null|string NULL on success, error string otherwise.
     */
    public function initialize(): ?string
    {
        $AF = ApplicationFramework::getInstance();

        # if an access key was provided but that key is not valid, complain
        if (!is_null($this->getConfigSetting("HttpBLAccessKey")) &&
            strlen($this->getConfigSetting("HttpBLAccessKey")) > 0 &&
            !self::blacklistAccessKeyLooksValid($this->getConfigSetting("HttpBLAccessKey"))) {
            return "Incorrect Http:BL key format.  Keys are 12 lowercase letters.";
        }

        $AF->addCleanUrl(
            "%^canary/[0-9]+/canary.js%",
            "P_BotDetector_Canary",
            ["JS" => 1]
        );

        $AF->addCleanUrl(
            "%^canary/[0-9]+/canary.css%",
            "P_BotDetector_Canary"
        );

        return null;
    }

    /**
     * Declare the events this plugin provides to the application framework.
     * @return array Returns an array of events this plugin provides.
     */
    public function declareEvents(): array
    {
        return ["BotDetector_EVENT_CHECK_FOR_BOT" => ApplicationFramework::EVENTTYPE_FIRST];
    }

    /**
     * Hook the events into the application framework.
     * @return array Returns an array of events to be hooked into the
     *      application framework.
     */
    public function hookEvents(): array
    {
        return [
            "BotDetector_EVENT_CHECK_FOR_BOT" => "CheckForBot",
            "EVENT_IN_HTML_HEADER" => "GenerateHTMLForCanary",
            "EVENT_HOURLY" => "CleanCacheData"
        ];
    }

    /**
     * Generate HTML elements to display a CSS and JS Canary used to test for bots.
     */
    public function generateHTMLForCanary(): void
    {
        # nothing to do when no IP available
        if (!isset($_SERVER["REMOTE_ADDR"])) {
            return;
        }

        # if the user's IP address looks reasonably valid
        if (preg_match(
            "/[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}/",
            $_SERVER["REMOTE_ADDR"]
        )) {
            $Key = date("Ymd");
            if (PluginManager::getInstance()->pluginEnabled("CleanURLs")) {
                print "\n<link rel=\"stylesheet\" type=\"text/css\" "
                    ."href=\"canary/".$Key."/canary.css\" />\n"
                    ."<script type=\"text/javascript\" "
                    ."src=\"canary/".$Key."/canary.js\"></script>\n";
            } else {
                print "\n<link rel=\"stylesheet\" type=\"text/css\" "
                    ."href=\"index.php?P=P_BotDetector_Canary&amp;RN="
                            .$Key."\" />\n"
                    ."<script type=\"text/javascript\" "
                    ."src=\"index.php?P=P_BotDetector_Canary&amp;JS=1"
                    ."&amp;RN=".$Key."\"></script>\n";
            }

            # record in the database that the canary was shown
            $DB = new Database();
            $DB->query(
                "INSERT INTO BotDetector_CanaryData (IPAddress, CanaryLastShown) "
                ."VALUES (INET_ATON('".addslashes($_SERVER["REMOTE_ADDR"])
                        ."'), NOW()) "
                ." ON DUPLICATE KEY UPDATE CanaryLastShown=NOW()"
            );
        }
    }

    /**
     * Determine whether the page was loaded by a person or an automated program.
     * @return bool Returns TRUE if the page was loaded by an automated program.
     */
    public function checkForBot(): bool
    {
        static $BotCheckValue = null;

        # if we already have a value for this client, return it
        if ($BotCheckValue !== null) {
            return $BotCheckValue;
        }

        # otherwise, perform generic robot checks
        $BotCheckValue = $this->performChecks([
            "checkIfUserLoggedIn",
            "checkUserAgent",
            "checkHostname",
            "checkForSqlInjection",
            "checkHttpBlForBot",
            "checkIfCanaryWasLoaded",
        ]);
        return $BotCheckValue;
    }

    /**
     * Check if the current client is likely to be some flavor of spam
     * robot. Check for SQL injection scans and uses Project Honeypot's
     * Http:BL service when configured.
     * @return bool TRUE for spambots, FALSE otherwise.
     */
    public function checkForSpamBot(): bool
    {
        static $BotCheckValue = null;

        # if we already have a value for this client, return it
        if ($BotCheckValue !== null) {
            return $BotCheckValue;
        }

        # otherwise, check for spam robots
        $BotCheckValue = $this->performChecks([
            "checkForSqlInjection",
            "checkHttpBLForSpamBot",
        ]);
        return $BotCheckValue;
    }

    /**
     * Remove stale cache entries.
     */
    public function cleanCacheData(): void
    {
        $AF = ApplicationFramework::getInstance();
        $DB = new Database();

        # clean out Hostname cache data that was last fetched > 48 hours ago
        $DB->query(
            "DELETE FROM BotDetector_HostnameCache "
            ."WHERE RetrievalDate < (NOW() - INTERVAL 48 HOUR)"
        );

        # clean out DNS cache data that was last used > 2 hours ago
        $DB->query(
            "DELETE FROM BotDetector_HttpBLCache "
            ."WHERE LastUsed < (NOW() - INTERVAL 2 HOUR)"
        );

        # queue background tasks to refresh DNS cache data for IPs
        # that are still being used
        $DB->query(
            "SELECT INET_NTOA(IPAddress) AS IP FROM BotDetector_HttpBLCache "
            ."WHERE Retrieved < (NOW() - INTERVAL 2 HOUR)"
        );
        $IPs = $DB->fetchColumn("IP");
        foreach ($IPs as $IP) {
            $AF->queueUniqueTask(
                [__CLASS__, "updateHttpBLCacheForIP"],
                [$IP],
                ApplicationFramework::PRIORITY_BACKGROUND,
                "Update HttpBL DNS cache data for ".$IP
            );
        }

        # if we're recording metrics, we'll want to clean out metrics data
        #  recorded in the 1 hour window between showing the canary and deciding
        #  that a particular IP is likely a bot because they didn't load it
        if (PluginManager::getInstance()->pluginEnabled("MetricsRecorder")) {
            $DB->query(
                "SELECT CanaryLastShown, INET_NTOA(IPAddress) AS IP "
                ." FROM BotDetector_CanaryData"
                ." WHERE CanaryLastShown < (NOW() - INTERVAL 1 HOUR) "
                ." AND CanaryLastLoaded IS NULL"
            );

            $BadIps = $DB->fetchRows();
            foreach ($BadIps as $Row) {
                $AF->queueUniqueTask(
                    [__CLASS__, "cleanBotFromMetrics"],
                    [ $Row["IP"], $Row["CanaryLastShown"] ],
                    ApplicationFramework::PRIORITY_LOW,
                    "Clean out metrics data for a bot at ".$Row["IP"]
                );
            }
        }

        # clean out the canary data for IPs where we've not showed the
        #  canary in a long time AND they've never loaded it or haven't
        #    loaded it for a long time
        $DB->query(
            "DELETE FROM BotDetector_CanaryData "
            ."WHERE CanaryLastShown < (NOW() - INTERVAL 2 HOUR) "
            ."  AND (CanaryLastLoaded IS NULL OR "
            ."       CanaryLastLoaded < (NOW() - INTERVAL 2 HOUR)) "
        );
    }

    /**
     * Clean out MetricsRecorder logs for a Bot.
     * @param string $TargetIP IP address to clean up.
     * @param string $StartTime Oldest date/time to remove.
     */
    public static function cleanBotFromMetrics($TargetIP, $StartTime): void
    {
        $PluginMgr = PluginManager::getInstance();
        if ($PluginMgr->pluginEnabled("MetricsRecorder")) {
            $MetricsRecorderPlugin = MetricsRecorder::getInstance();
            $MetricsRecorderPlugin->removeEventsForIPAddress(
                $TargetIP,
                $StartTime
            );
        }
    }

    /**
     * Perform background update of cached HttpBL result for a given IP address.
     * @param string $RemoteIP Remote address to update.
     */
    public static function updateHttpBLCacheForIP($RemoteIP): void
    {
        $BotDetector = BotDetector::getInstance();
        $AccessKey = $BotDetector->getConfigSetting("HttpBLAccessKey");

        # if access key setting is valid
        if (self::blacklistAccessKeyLooksValid($AccessKey)) {
            # do dns query
            $Result = self::doHttpBLDNSLookup($AccessKey, $RemoteIP);

            # and update database cache
            $DB = new Database();
            $DB->query(
                "UPDATE BotDetector_HttpBLCache "
                ."SET Result=INET_ATON('".addslashes($Result)."'), Retrieved=NOW() "
                ."WHERE IPAddress=INET_ATON('".addslashes($RemoteIP)."')"
            );
        }
    }

    /**
     * Perform background update of cached hostname for a given IP address.
     * @param string $IP IP address to look up.
     */
    public static function updateHostnameCacheForIP(string $IP): void
    {
        $Hostname = gethostbyaddr($IP);
        $Result = (($Hostname !== false) && ($Hostname != $IP)) ? $Hostname : "";

        $DB = new Database();
        $DB->query(
            "INSERT INTO BotDetector_HostnameCache (IPAddress, Hostname)"
            ." VALUES (INET_ATON('".addslashes($IP)."'),'".addslashes($Result)."') "
            ." ON DUPLICATE KEY UPDATE "
            ."Hostname='".addslashes($Result)."', RetrievalDate=NOW()"
        );
    }

    # ---- PRIVATE INTERFACE ---------------------------------------------------

    /**
     * Iterate over a series of methods that check for robots.
     * @param array $CheckMethods List of check methods to call.
     * @return bool TRUE if any of the methods detected a bot, FALSE otherwise.
     */
    private function performChecks($CheckMethods) : bool
    {
        foreach ($CheckMethods as $Method) {
            # if any of them locate a bot, we can stop checking
            $Result = $this->$Method();
            if ($Result !== null) {
                $this->pruneMetricsIfNecessary($Result);
                return $Result;
            }
        }

        return false;
    }

    /**
     * Check if a user is logged in, assuming that the client is not a bot if so.
     * @return bool|null TRUE for bots, NULL (indicating 'unsure') otherwise
     */
    private function checkIfUserLoggedIn()
    {
        if (User::getCurrentUser()->isLoggedIn()) {
            return false;
        }

        return null;
    }

    /**
     * Check if the hostname of the client matches any of our configured bot
     * domain patterns.
     * @return bool|null TRUE for bots, NULL (indicating 'unsure') otherwise
     */
    private function checkHostname(): ?bool
    {
        # if we don't know the remote hostname, nothing to do
        if (!isset($_SERVER["REMOTE_ADDR"])) {
            return null;
        }

        # if we have no bot domain patterns, nothing to do
        $PatternSetting = $this->getConfigSetting("BotDomainPatterns");
        if (strlen(trim($PatternSetting)) == 0) {
            return null;
        }

        $IP = $_SERVER["REMOTE_ADDR"];

        # check to see if we have a cached hostname for this IP
        $DB = new Database();
        $DB->query(
            "SELECT Hostname, RetrievalDate FROM BotDetector_HostnameCache "
            ."WHERE IPAddress=INET_ATON('".addslashes($IP)."')"
        );
        $Row = $DB->fetchRow();

        # if we have no data or if what we have is older than a day, refresh it
        if (($Row === false) || ((time() - strtotime($Row["RetrievalDate"])) > 86400)) {
            ApplicationFramework::getInstance()
                ->queueUniqueTask(
                    [get_class($this), "updateHostnameCacheForIP"],
                    [$IP],
                    ApplicationFramework::PRIORITY_BACKGROUND,
                    "Update hostname cache data for ".$IP
                );
        }

        # if no data, return unsure
        if ($Row === false || $Row["Hostname"] == "") {
            return null;
        }

        # if hostname matches pattern for known bot domain, report a bot
        $Hostname = $Row["Hostname"];
        $Patterns = explode("\n", trim($PatternSetting));
        foreach ($Patterns as $Pattern) {
            if (preg_match($Pattern, $Hostname)) {
                return true;
            }
        }

        # otherwise, unsure
        return null;
    }

    /**
     * Check if the provided Useragent is a known robot.
     * @return bool|null TRUE for bots, NULL otherwise
     */
    private function checkUserAgent(): ?bool
    {
        # if no useragent, assume it is not a bot
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return null;
        }

        # check against a blacklist of known crawlers
        foreach ($this->RobotRegexes as $Robot) {
            if (preg_match('/'.$Robot.'/i', $_SERVER['HTTP_USER_AGENT'])) {
                return true;
            }
        }

        return null;
    }

    /**
     * Use Project Honeypot's Http:BL service to determine if the current
     *   client is likely to be a bot.
     * @return bool|null TRUE for bots, NULL (indicating unsure) otherwise.
     */
    private function checkHttpBLForBot(): ?bool
    {
        return $this->checkHttpBLWithCallback(
            function ($BLValue) {
                # if HttpBL has a record, but it's only marked as "suspicious" with no
                # other annotations, then we're unsure
                if ($BLValue["BotType"] == self::BT_SUSPICIOUS) {
                    return null;
                }

                # but any other HttpBL record indicates a bot
                return true;
            }
        );
    }

    /**
     * Use Project Honeypot's Http:BL service to determine if the current
     *   client is a know spam robot.
     * @return bool|null TRUE for spam bots, NULL (indicating unsure) otherwise.
     */
    private function checkHttpBLForSpamBot(): ?bool
    {
        return $this->checkHttpBLWithCallback(
            function ($BLValue) {
                # it httpBL has a listing for this IP, but it does not have
                # the "comment spammer" flag set, then this is not a spam bot
                if (($BLValue["BotType"] & self::BT_COMMENTSPAMMER) == 0) {
                    return null;
                }

                # otherwise, the record does have the "comment spammer" flag set, and
                # is a spam robot
                return true;
            }
        );
    }

    /**
     * Use Project Honeypot's Http:BL service, providing a callback that
     *   evaluates the Http:BL record to determine if the current client is the
     *   kind of bot we are looking for.
     * @param callable $Callback Callback taking an array of Http:BL data as
     *   the first parameter and returning TRUE for bots, FALSE for verified
     *   humans (when possible), or NULL when unsure
     * @return bool|null TRUE for bots, FALSE for humans, NULL when unsure
     * @see getHttpBLRecordForClient
     */
    private function checkHttpBLWithCallback($Callback): ?bool
    {
        $BLValue = $this->getHttpBLRecordForClient();

        # if HttpBL is not configured, then we're unsure
        if ($BLValue === null) {
            return null;
        }

        # if HttpBL has no record for this IP, then we're unsure
        if ($BLValue === false) {
            return null;
        }

        return $Callback($BLValue);
    }

    /**
     * Check the client IP against Project Honeypot's Http:BL service.
     * @return null|false|array NULL when no Access Key is configured or on
     *      errors, FALSE for non-bots, and an array of host information for
     *      bots.  For all bots, this array contains a BotType.  For search
     *      bots, it will also have a SearchEngine.  For other kinds of bot,
     *      it will have LastActivity and ThreatScore.  Meaning for these
     *      elements is described in the Http:BL API documentation:
     *      http://www.projecthoneypot.org/httpbl_api.php
     */
    private function getHttpBLRecordForClient()
    {
        static $HttpBLValue;

        # if we don't know the remote hostname, nothing to do
        if (!isset($_SERVER["REMOTE_ADDR"])) {
            return null;
        }

        $AF = ApplicationFramework::getInstance();

        if (!isset($HttpBLValue)) {
            $RemoteIP = $_SERVER["REMOTE_ADDR"];
            $AccessKey = $this->getConfigSetting("HttpBLAccessKey");

            # if not from localhost and a key is set and of the right length
            if (($RemoteIP !== "::1")
                    && ($RemoteIP !== "127.0.0.1")
                    && self::blacklistAccessKeyLooksValid($AccessKey)) {
                # grab an AF Lock named for this IP
                $LockName = "BotDetector_IP_".$RemoteIP;
                $AF->getLock($LockName);

                # check to see if we have a cached status for this IP
                # so that we're not doing a dnslookup on every pageload
                $DB = new Database();
                $DB->query(
                    "SELECT INET_NTOA(Result) as Rx FROM BotDetector_HttpBLCache "
                    ."WHERE IPAddress=INET_ATON('".addslashes($RemoteIP)."')"
                );

                $Row = $DB->fetchRow();
                # if a cached HttpBL result was found
                if ($Row !== false) {
                    # use it and update the LastUsed time for this cache row
                    $Result = $Row["Rx"];
                    $DB->query(
                        "UPDATE BotDetector_HttpBLCache SET LastUsed=NOW()"
                        ." WHERE IPAddress=INET_ATON('".addslashes($RemoteIP)."')"
                    );
                } else {
                    # if nothing was in the cache, do the DNS lookup in the foreground
                    $Result = self::doHttpBLDNSLookup($AccessKey, $RemoteIP);

                    # and store the result in the cache
                    $DB->query(
                        "INSERT INTO BotDetector_HttpBLCache (IPAddress, Result, LastUsed) VALUES ("
                        ."INET_ATON('".addslashes($RemoteIP)."'),"
                        .(is_null($Result) ? "NULL" : "INET_ATON('".addslashes($Result)."')").","
                        ."NOW())"
                    );
                }
                $AF->releaseLock($LockName);

                if ($Result === null) {
                    # no blacklist entry found = not a bot
                    $HttpBLValue = false;
                } else {
                    # found blacklist entry; parse the reply to figure out what it said
                    $Data = explode('.', $Result);

                    # first octet should be 127 for correctly formed queries
                    if ($Data[0] == 127) {
                        # pull the Bot Type information out of the fourth octet
                        $HttpBLValue = ["BotType" => $Data[3]];

                        if ($Data[3] == 0) {
                            # if the bot was a search engine, then the engine type can be
                            # extracted from the third octet
                            $HttpBLValue["SearchEngine"] = $Data[2];
                        } else {
                            # for other bot types, the number of days since last activity
                            # is in the second octet, and a Threat Score is in the third
                            $HttpBLValue["LastActivity"] = $Data[1];
                            $HttpBLValue["ThreatScore"]  = $Data[2];
                        }
                    } else {
                        # return NULL when the query indicates an error
                        # the API documentation suggests that the most common problem
                        #  is an incorrect access key
                        $HttpBLValue = null;
                    }
                }
            } else {
                # return NULL when no keys are configured
                $HttpBLValue = null;
            }
        }

        return $HttpBLValue;
    }

    /**
     * Check if the client viewing this page failed to load loaded the
     * BotDetector CSS/JS canary, indicating that they are probably a bot.
     * @return bool TRUE for IPs that failed to load the canary, FALSE otherwise
     */
    private function checkIfCanaryWasLoaded(): ?bool
    {
        # if we don't know the remote hostname, nothing to do
        if (!isset($_SERVER["REMOTE_ADDR"])) {
            return null;
        }

        $DB = new Database();
        $DB->query(
            "SELECT CanaryLastShown, CanaryLastLoaded"
            ." FROM BotDetector_CanaryData WHERE"
            ." IPAddress=INET_ATON('".addslashes($_SERVER["REMOTE_ADDR"])."')"
        );
        $Data = $DB->fetchRow();
        if ($Data === false
            || $Data["CanaryLastLoaded"] !== null
            || (time() - strtotime($Data["CanaryLastShown"])  < 3600 )) {
            # presume not a bot when
            #  - We've never shown them the canary
            #  - When they've loaded the canary
            #  - Or when it's been less than 3600s since they
            #    were last shown the canary
            return false;
        }

        # but if we *have* shown them the canary
        # and it's been more than 3600s, presume a bot
        return true;
    }

    /**
     * Check if the current pageload is an SQL injection scan.
     * @return mixed TRUE for SQL injections, NULL otherwise.
     */
    private function checkForSqlInjection()
    {
        foreach ($this->SqlInjectionRegexes as $Injection) {
            if (preg_match('/'.$Injection.'/i', $_SERVER['REQUEST_URI'])) {
                return true;
            }
        }

        return null;
    }

    /**
     * Prune metrics if configured to do so.
     * @param mixed $IsBot TRUE for bots, FALSE for humans, NULL if unsure.
     */
    private function pruneMetricsIfNecessary($IsBot): void
    {
        # if we don't know the remote hostname, nothing to do
        if (!isset($_SERVER["REMOTE_ADDR"])) {
            return;
        }

        $PluginMgr = PluginManager::getInstance();
        if ($IsBot === true && $this->getConfigSetting("BotPruning") &&
            $PluginMgr->pluginEnabled("MetricsRecorder")) {
            MetricsRecorder::getInstance()->removeEventsForIPAddress($_SERVER["REMOTE_ADDR"]);
        }
    }

    /**
     * Query HttpBL with a DNS lookup of their API-specified synthetic hostname.
     * @param string $AccessKey HttpBL access key.
     * @param string $IpAddress Address to query in dotted-quad notation.
     * @return string|null Synthetic IP address returned from dnsbl or NULL
     *     when nothing is returned.
     */
    private static function doHttpBLDNSLookup($AccessKey, $IpAddress): ?string
    {

        $ReversedIp = implode('.', array_reverse(explode('.', $IpAddress)));
        $DnsQuery =  $AccessKey.".".$ReversedIp.".dnsbl.httpbl.org.";

        $Result = gethostbyname($DnsQuery);

        # (gethostbyname() returns the argument on failure)
        if ($Result == $DnsQuery) {
            $Result = null;
        }

        return $Result;
    }

    /**
     * Determine if the configured HttpBL access key is in the correct format.
     * @param ?string $AccessKey Access key value to test.
     * @return bool TRUE for valid-looking keys
     */
    private static function blacklistAccessKeyLooksValid($AccessKey): bool
    {
        if (is_null($AccessKey)) {
            return false;
        }
        return (bool)preg_match('/[a-z]{12}/', $AccessKey);
    }

    # constants describing BotType bitset returned by Http:BL
    const BT_SEARCHENGINE   = 0;
    const BT_SUSPICIOUS     = 1;
    const BT_HARVESTER      = 2;
    const BT_COMMENTSPAMMER = 4;

    # constants describing the Search Engines returned by Http:BL
    const SE_UNDOCUMENTED =  0;
    const SE_ALTAVIST     =  1;
    const SE_ASK          =  2;
    const SE_BAIDU        =  3;
    const SE_EXCITE       =  4;
    const SE_GOOGLE       =  5;
    const SE_LOOKSMART    =  6;
    const SE_LYCOS        =  7;
    const SE_MSN          =  8;
    const SE_YAHOO        =  9;
    const SE_CUIL         = 10;
    const SE_INFOSEEK     = 11;
    const SE_MISC         = 12;

    ## Borrow patterns for known bots from awstats-7.3, lib/robots.pm
    ## Here, we're talking all three of their bots lists (common, uncommon, and generic)
    // @codingStandardsIgnoreStart
    private $RobotRegexes = [
        ## From RobotsSearchIdOrder_list1
        'appie', 'architext', 'bingpreview', 'bjaaland', 'contentmatch',
        'ferret', 'googlebot\-image', 'googlebot', 'google\-sitemaps',
        'google[_+ ]web[_+ ]preview', 'grabber', 'gulliver',
        'virus[_+ ]detector', 'harvest', 'htdig', 'jeeves', 'linkwalker',
        'lilina', 'lycos[_+ ]', 'moget', 'muscatferret', 'myweb', 'nomad',
        'scooter', 'slurp', '^voyager\/', 'weblayers',
        'antibot', 'bruinbot', 'digout4u', 'echo!', 'fast\-webcrawler',
        'ia_archiver\-web\.archive\.org', 'ia_archiver', 'jennybot', 'mercator',
        'netcraft', 'msnbot\-media', 'msnbot', 'petersnews', 'relevantnoise\.com',
        'unlost_web_crawler', 'voila', 'webbase', 'webcollage', 'cfetch', 'zyborg',
        'wisenutbot',
        ## From RobotsSearchIdOrder_list2
        '[^a]fish', 'abcdatos', 'abonti\.com', 'acme\.spider', 'ahoythehomepagefinder',
        'ahrefsbot', 'alkaline', 'anthill', 'arachnophilia', 'arale', 'araneo',
        'aretha', 'ariadne', 'powermarks', 'arks', 'aspider', 'atn\.txt',
        'atomz', 'auresys', 'backrub', 'bbot', 'bigbrother', 'blackwidow',
        'blindekuh', 'bloodhound', 'borg\-bot', 'brightnet', 'bspider', 'cactvschemistryspider',
        'calif[^r]', 'cassandra', 'cgireader', 'checkbot', 'christcrawler',
        'churl', 'cienciaficcion', 'collective', 'combine', 'conceptbot',
        'coolbot', 'core', 'cosmos', 'cruiser', 'cusco',
        'cyberspyder', 'desertrealm', 'deweb', 'dienstspider', 'digger',
        'diibot', 'direct_hit', 'dnabot', 'download_express', 'dragonbot',
        'dwcp', 'e\-collector', 'ebiness', 'elfinbot', 'emacs',
        'emcspider', 'esther', 'evliyacelebi', 'fastcrawler', 'feedcrawl',
        'fdse', 'felix', 'fetchrover', 'fido', 'finnish',
        'fireball', 'fouineur', 'francoroute', 'freecrawl', 'funnelweb',
        'gama', 'gazz', 'gcreep', 'getbot', 'geturl',
        'golem', 'gougou', 'grapnel', 'griffon', 'gromit',
        'gulperbot', 'hambot', 'havindex', 'hometown', 'htmlgobble',
        'hyperdecontextualizer', 'iajabot', 'iaskspider', 'hl_ftien_spider', 'sogou',
        'icjobs\.de', 'iconoclast', 'ilse', 'imagelock', 'incywincy',
        'informant', 'infoseek', 'infoseeksidewinder', 'infospider', 'inspectorwww',
        'intelliagent', 'irobot', 'iron33', 'israelisearch', 'javabee',
        'jbot', 'jcrawler', 'jobo', 'jobot', 'joebot',
        'jubii', 'jumpstation', 'kapsi', 'katipo', 'kilroy',
        'ko[_+ ]yappo[_+ ]robot', 'kummhttp', 'labelgrabber\.txt', 'larbin', 'legs',
        'linkidator', 'linkscan', 'lockon', 'logo_gif', 'macworm',
        'magpie', 'marvin', 'mattie', 'mediafox', 'merzscope',
        'meshexplorer', 'mindcrawler', 'mnogosearch', 'momspider', 'monster',
        'motor', 'muncher', 'mwdsearch', 'ndspider', 'nederland\.zoek',
        'netcarta', 'netmechanic', 'netscoop', 'newscan\-online', 'nhse',
        'northstar', 'nzexplorer', 'objectssearch', 'occam', 'octopus',
        'openfind', 'orb_search', 'packrat', 'pageboy', 'parasite',
        'patric', 'pegasus', 'perignator', 'perlcrawler', 'phantom',
        'phpdig', 'piltdownman', 'pimptrain', 'pioneer', 'pitkow',
        'pjspider', 'plumtreewebaccessor', 'poppi', 'portalb', 'psbot',
        'python', 'raven', 'rbse', 'resumerobot', 'rhcs',
        'road_runner', 'robbie', 'robi', 'robocrawl', 'robofox',
        'robozilla', 'roverbot', 'rules', 'safetynetrobot', 'search\-info',
        'search_au', 'searchprocess', 'senrigan', 'sgscout', 'shaggy',
        'shaihulud', 'sift', 'simbot', 'site\-valet', 'sitetech',
        'skymob', 'slcrawler', 'smartspider', 'snooper', 'solbot',
        'speedy', 'spider[_+ ]monkey', 'spiderbot', 'spiderline', 'spiderman',
        'spiderview', 'spry', 'sqworm', 'ssearcher', 'suke',
        'sunrise', 'suntek', 'sven', 'tach_bw', 'tagyu_agent',
        'tailrank', 'tarantula', 'tarspider', 'techbot', 'templeton',
        'titan', 'titin', 'tkwww', 'tlspider', 'ucsd',
        'udmsearch', 'universalfeedparser', 'urlck', 'valkyrie', 'verticrawl',
        'victoria', 'visionsearch', 'voidbot', 'vwbot', 'w3index',
        'w3m2', 'wallpaper', 'wanderer', 'wapspIRLider', 'webbandit',
        'webcatcher', 'webcopy', 'webfetcher', 'webfoot', 'webinator',
        'weblinker', 'webmirror', 'webmoose', 'webquest', 'webreader',
        'webreaper', 'websnarf', 'webspider', 'webvac', 'webwalk',
        'webwalker', 'webwatch', 'whatuseek', 'whowhere', 'wired\-digital',
        'wmir', 'wolp', 'wombat', 'wordpress', 'worm',
        'woozweb', 'wwwc', 'wz101', 'xget',
        '1\-more_scanner', '360spider', 'a6-indexer', 'accoona\-ai\-agent', 'activebookmark',
        'adamm_bot', 'adsbot-google', 'almaden', 'aipbot', 'aleadsoftbot',
        'alpha_search_agent', 'allrati', 'aport', 'archive\.org_bot', 'argus',
        'arianna\.libero\.it', 'aspseek', 'asterias', 'awbot', 'backlinktest\.com',
        'baiduspider', 'becomebot', 'bender', 'betabot', 'biglotron',
        'bittorrent_bot', 'biz360[_+ ]spider', 'blogbridge[_+ ]service', 'bloglines', 'blogpulse',
        'blogsearch', 'blogshares', 'blogslive', 'blogssay', 'bncf\.firenze\.sbn\.it\/raccolta\.txt',
        'bobby', 'boitho\.com\-dc', 'bookmark\-manager', 'boris', 'bubing',
        'bumblebee', 'candlelight[_+ ]favorites[_+ ]inspector', 'careerbot', 'cbn00glebot', 'cerberian_drtrs',
        'cfnetwork', 'cipinetbot', 'checkweb_link_validator', 'commons\-httpclient',
        'computer_and_automation_research_institute_crawler',
        'converamultimediacrawler', 'converacrawler', 'copubbot', 'cscrawler',
        'cse_html_validator_lite_online', 'cuasarbot', 'cursor', 'custo', 'datafountains\/dmoz_downloader',
        'dataprovider\.com', 'daumoa', 'daviesbot', 'daypopbot', 'deepindex',
        'dipsie\.bot', 'dnsgroup', 'domainchecker', 'domainsdb\.net', 'dulance',
        'dumbot', 'dumm\.de\-bot', 'earthcom\.info', 'easydl', 'eccp',
        'edgeio\-retriever', 'ets_v', 'exactseek', 'extreme[_+ ]picture[_+ ]finder', 'eventax',
        'everbeecrawler', 'everest\-vulcan', 'ezresult', 'enteprise', 'facebook',
        'fast_enterprise_crawler.*crawleradmin\.t\-info@telekom\.de',
        'fast_enterprise_crawler.*t\-info_bi_cluster_crawleradmin\.t\-info@telekom\.de',
        'matrix_s\.p\.a\._\-_fast_enterprise_crawler',
        'fast_enterprise_crawler', 'fast\-search\-engine', 'favicon', 'favorg', 'favorites_sweeper',
        'feedburner', 'feedfetcher\-google', 'feedflow', 'feedster', 'feedsky',
        'feedvalidator', 'filmkamerabot', 'filterdb\.iss\.net', 'findlinks', 'findexa_crawler',
        'firmilybot', 'foaf-search\.net', 'fooky\.com\/ScorpionBot', 'g2crawler', 'gaisbot',
        'geniebot', 'gigabot', 'girafabot', 'global_fetch', 'gnodspider',
        'goforit\.com', 'goforitbot', 'gonzo', 'grapeshot', 'grub',
        'gpu_p2p_crawler', 'henrythemiragorobot', 'heritrix', 'holmes', 'hoowwwer',
        'hpprint', 'htmlparser', 'html[_+ ]link[_+ ]validator', 'httrack', 'hundesuche\.com\-bot',
        'i-bot', 'ichiro', 'iltrovatore\-setaccio', 'infobot', 'infociousbot',
        'infohelfer', 'infomine', 'insurancobot', 'integromedb\.org', 'internet[_+ ]ninja',
        'internetarchive', 'internetseer', 'internetsupervision', 'ips\-agent', 'irlbot',
        'isearch2006', 'istellabot', 'iupui_research_bot',
        'jrtwine[_+ ]software[_+ ]check[_+ ]favorites[_+ ]utility', 'justview',
        'kalambot', 'kamano\.de_newsfeedverzeichnis', 'kazoombot', 'kevin', 'keyoshid',
        'kinjabot', 'kinja\-imagebot', 'knowitall', 'knowledge\.com', 'kouaa_krawler',
        'krugle', 'ksibot', 'kurzor', 'lanshanbot', 'letscrawl\.com',
        'libcrawl', 'linkbot', 'linkdex\.com', 'link_valet_online', 'metager\-linkchecker',
        'linkchecker', 'livejournal\.com', 'lmspider', 'ltbot', 'lwp\-request',
        'lwp\-trivial', 'magpierss', 'mail\.ru', 'mapoftheinternet\.com', 'mediapartners\-google',
        'megite', 'metaspinner', 'miadev', 'microsoft bits', 'microsoft.*discovery',
        'microsoft[_+ ]url[_+ ]control', 'mini\-reptile', 'minirank', 'missigua_locator', 'misterbot',
        'miva', 'mizzu_labs', 'mj12bot', 'mojeekbot', 'msiecrawler',
        'ms_search_4\.0_robot', 'msrabot', 'msrbot', 'mt::telegraph::agent', 'mydoyouhike',
        'nagios', 'nasa_search', 'netestate ne crawler', 'netluchs', 'netsprint',
        'newsgatoronline', 'nicebot', 'nimblecrawler', 'noxtrumbot', 'npbot',
        'nutchcvs', 'nutchosu\-vlib', 'nutch', 'ocelli', 'octora_beta_bot',
        'omniexplorer[_+ ]bot', 'onet\.pl[_+ ]sa', 'onfolio', 'opentaggerbot', 'openwebspider',
        'oracle_ultra_search', 'orbiter', 'yodaobot', 'qihoobot', 'passwordmaker\.org',
        'pear_http_request_class', 'peerbot', 'perman', 'php[_+ ]version[_+ ]tracker', 'pictureofinternet',
        'ping\.blo\.gs', 'plinki', 'pluckfeedcrawler', 'pogodak', 'pompos',
        'popdexter', 'port_huron_labs', 'postfavorites', 'projectwf\-java\-test\-crawler', 'proodlebot',
        'pyquery', 'rambler', 'redalert', 'rojo', 'rssimagesbot',
        'ruffle', 'rufusbot', 'sandcrawler', 'sbider', 'schizozilla',
        'scumbot', 'searchguild[_+ ]dmoz[_+ ]experiment', 'searchmetricsbot', 'seekbot', 'semrushbot',
        'sensis_web_crawler', 'seokicks\.de', 'seznambot', 'shim\-crawler', 'shoutcast',
        'siteexplorer\.info', 'slysearch', 'snap\.com_beta_crawler', 'sohu\-search', 'sohu',
        'snappy', 'spbot', 'sphere_scout', 'spiderlytics', 'spip',
        'sproose_crawler', 'ssearch_bot', 'steeler', 'steroid__download', 'suchfin\-bot',
        'superbot', 'surveybot', 'susie', 'syndic8', 'syndicapi',
        'synoobot', 'tcl_http_client_package', 'technoratibot', 'teragramcrawlersurf', 'test_crawler',
        'testbot', 't\-h\-u\-n\-d\-e\-r\-s\-t\-o\-n\-e', 'topicblogs', 'turnitinbot', 'turtlescanner',
        'turtle', 'tutorgigbot', 'twiceler', 'ubicrawler', 'ultraseek',
        'unchaos_bot_hybrid_web_search_engine', 'unido\-bot', 'unisterbot', 'updated', 'ustc\-semantic\-group',
        'vagabondo\-wap', 'vagabondo', 'vermut', 'versus_crawler_from_eda\.baykan@epfl\.ch', 'vespa_crawler',
        'vortex', 'vse\/', 'w3c\-checklink', 'w3c[_+ ]css[_+ ]validator[_+ ]jfouffa', 'w3c_validator',
        'watchmouse', 'wavefire', 'waybackarchive\.org', 'webclipping\.com', 'webcompass',
        'webcrawl\.net', 'web_downloader', 'webdup', 'webfilter', 'webindexer',
        'webminer', 'website[_+ ]monitoring[_+ ]bot', 'webvulncrawl', 'wells_search', 'wesee:search',
        'wonderer', 'wume_crawler', 'wwweasel', 'xenu\'s_link_sleuth', 'xenu_link_sleuth',
        'xirq', 'y!j', 'yacy', 'yahoo\-blogs', 'yahoo\-verticalcrawler',
        'yahoofeedseeker', 'yahooseeker\-testing', 'yahooseeker', 'yahoo\-mmcrawler', 'yahoo!_mindset',
        'yandex', 'flexum', 'yanga', 'yet-another-spider', 'yooglifetchagent',
        'z\-add_link_checker', 'zealbot', 'zhuaxia', 'zspider', 'zeus',
        'ng\/1\.', 'ng\/2\.', 'exabot',
        'alltop', 'applesyndication', 'asynchttpclient', 'bingbot', 'blogged_crawl',
        'bloglovin', 'butterfly', 'buzztracker', 'carpathia', 'catbot',
        'chattertrap', 'check_http', 'coldfusion', 'covario', 'daylifefeedfetcher',
        'discobot', 'dlvr\.it', 'dreamwidth', 'drupal', 'ezoom',
        'feedmyinbox', 'feedroll\.com', 'feedzira', 'fever\/', 'freenews',
        'geohasher', 'hanrss', 'inagist', 'jacobin club', 'jakarta',
        'js\-kit', 'largesmall crawler', 'linkedinbot', 'longurl', 'metauri',
        'microsoft\-webdav\-miniredir', '^motorola$', 'movabletype', 'netnewswire', ' netseer ',
        'netvibes', 'newrelicpinger', 'newsfox', 'nextgensearchbot', 'ning',
        'pingdom', 'pita', 'postpost', 'postrank', 'printfulbot',
        'protopage', 'proximic', 'quipply', 'r6\_', 'ratingburner',
        'regator', 'rome client', 'rpt\-httpclient', 'rssgraffiti', 'sage\+\+',
        'scoutjet', 'simplepie', 'sitebot', 'summify\.com', 'superfeedr',
        'synthesio', 'teoma', 'topblogsinfo', 'topix\.net', 'trapit',
        'trileet', 'tweetedtimes', 'twisted pagegetter', 'twitterbot', 'twitterfeed',
        'unwindfetchor', 'wazzup', 'windows\-rss\-platform', 'wiumi', 'xydo',
        'yahoo! slurp', 'yahoo pipes', 'yahoo\-newscrawler', 'yahoocachesystem', 'yahooexternalcache',
        'yahoo! searchmonkey', 'yahooysmcm', 'yammer', 'yeti', 'yie8',
        'youdao', 'yourls', 'zemanta', 'zend_http_client', 'zumbot',
        'wget', 'libwww', '^java\/[0-9]',
        ## From RobotsSearchIdOrder_listgen
        'robot', 'checker', 'crawl', 'discovery', 'hunter',
        'scanner', 'spider', 'sucker', 'bot[\s_+:,\.\;\/\\\-]', '[\s_+:,\.\;\/\\\-]bot',
        'curl', 'php', 'ruby\/', 'no_user_agent'
    ];
    // @codingStandardsIgnoreEnd

    # patterns to detect SQL injection attempts (%2520 is a double-encoded space)
    private $SqlInjectionRegexes = [
        'UNION(%2520|%20)ALL',
        'SELECT(%2520|%20)NULL',
        'SELECT(%2520|%20)\d+(%20|%2520)FROM',
        'FROM(%20|%2520)(PG_SLEEP|INFORMATION_SCHEMA)',
        'CASE(%20|%2520)WHEN',
        'SELECT(%20|%2520)NAME_CONST\(CHAR\(',
    ];

    public const SQL_TABLES = [
        "HttpBLCache" => "CREATE TABLE BotDetector_HttpBLCache (
               IPAddress INT UNSIGNED,
               Result INT UNSIGNED,
               Retrieved TIMESTAMP DEFAULT NOW(),
               LastUsed TIMESTAMP,
               INDEX (IPAddress),
               INDEX (LastUsed),
               INDEX (Retrieved) )",
        "HostnameCache" => "CREATE TABLE BotDetector_HostnameCache (
               IPAddress INT UNSIGNED,
               Hostname TEXT,
               RetrievalDate TIMESTAMP DEFAULT NOW(),
               PRIMARY KEY (IPAddress),
               INDEX (RetrievalDate) )",
        "CanaryData" => "CREATE TABLE BotDetector_CanaryData (
               IPAddress INT UNSIGNED,
               CanaryLastShown TIMESTAMP NULL DEFAULT NULL,
               CanaryLastLoaded TIMESTAMP NULL DEFAULT NULL,
               PRIMARY KEY (IPAddress),
               INDEX (CanaryLastShown),
               INDEX (CanaryLastLoaded) )",
    ];
}
