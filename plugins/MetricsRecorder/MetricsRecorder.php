<?PHP
#
#   FILE:  MetricsRecorder.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Exception;
use InvalidArgumentException;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\PrivilegeSet;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\SavedSearchFactory;
use Metavus\SearchParameterSet;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Email;
use ScoutLib\Plugin;
use ScoutLib\StdLib;

/**
 * Plugin for recording usage and system metrics data.
 */
class MetricsRecorder extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set the plugin attributes.At minimum this method MUST set $this->Name
     * and $this->Version.This is called when the plugin is initially loaded.
     */
    public function register()
    {
        $this->Name = "Metrics Recorder";
        $this->Version = "1.2.15";
        $this->Description = "Plugin for recording usage and web metrics data.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.0.0",
            "BotDetector" => "1.0.0",
        ];
        $this->EnabledByDefault = true;
    }

    /**
     * Initialize the plugin.This is called after all plugins have been loaded
     * but before any methods for this plugin (other than Register() or Initialize())
     * have been called.
     * @return null|string NULL if initialization was successful, otherwise
     *      a string containing an error message indicating why it failed.
     */
    public function initialize()
    {
        # register email logging function
        Email::registerLoggingFunction(
            ["Metavus\Plugins\MetricsRecorder", "LogSentMessage"]
        );

        # set up database for our use
        $this->DB = new Database();

        # report successful initialization to caller
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
        $Result = $this->createTables($this->SqlTables);
        if (!is_null($Result)) {
            return $Result;
        }

        $DB = new Database();
        if ($DB->isStorageEngineAvailable("InnoDB")) {
            $DB->query(
                "ALTER TABLE MetricsRecorder_EventData ENGINE = InnoDB"
            );
        }

        # add needed fields
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
        $FieldsFile = "plugins/".$this->getBaseName()
            ."/install/MetadataSchema--Resource.xml";
        if ($Schema->addFieldsFromXmlFile($FieldsFile) === false) {
            return "Error loading User metadata fields from XML: "
                .implode(" ", $Schema->errorMessages("addFieldsFromXmlFile"));
        }

        return null;
    }

    /**
     * Perform any work needed when the plugin is uninstalled.
     * @return null|string NULL if uninstall succeeded, otherwise a string
     *       containing an error message indicating why uninstall failed.
     */
    public function uninstall()
    {
        return $this->dropTables($this->SqlTables);
    }

    /**
     * Perform any work needed when the plugin is upgraded to a new version
     * (for example, adding fields to database tables).
     * @param string $PreviousVersion The version number of this plugin that was
     *       previously installed.
     * @return NULL if upgrade succeeded, otherwise a string containing
     *       an error message indicating why upgrade failed.
     */
    public function upgrade(string $PreviousVersion)
    {
        # if previously-installed version is earlier than 1.1.4
        if (version_compare($PreviousVersion, "1.1.4", "<")) {
            # replace old Type/User index with Type/User/Date index for event data
            $DB = new Database();
            $DB->setQueryErrorsToIgnore([
                '/DROP\s+INDEX\s+[^\s]+\s+\([^)]+\)/i'
                    => '/Can\'t\s+DROP\s+[^\s]+\s+check\s+that/i'
            ]);
            $DB->query("DROP INDEX EventType_2 ON MetricsRecorder_EventData");
            $DB->query("CREATE INDEX Index_TUD ON MetricsRecorder_EventData"
                    ." (EventType,UserId,EventDate)");
        }

        # if previously-installed version is earlier than 1.2.0
        if (version_compare($PreviousVersion, "1.2.0", "<")) {
            # add table for tracking custom event types
            $DB = new Database();
            $DB->query("CREATE TABLE IF NOT EXISTS MetricsRecorder_EventTypeIds (
                    OwnerName       TEXT,
                    TypeName        TEXT,
                    TypeId          SMALLINT NOT NULL,
                    INDEX           (TypeId));");
        }

        # if previously-installed version is earlier than 1.2.1
        if (version_compare($PreviousVersion, "1.2.1", "<")) {
            # set the errors that can be safely ignord
            $DB = new Database();
            $DB->setQueryErrorsToIgnore([
                '/^RENAME\s+TABLE/i' => '/already\s+exists/i'
            ]);

            # fix the custom event type ID mapping table name
            $DB->query("RENAME TABLE MetricsRecorder_EventTypes
                    TO MetricsRecorder_EventTypeIds");

            # remove full record views and resource URL clicks for resources
            # that don't use the default schema
            $DB->query("DELETE ED FROM MetricsRecorder_EventData ED
                    LEFT JOIN Records R ON ED.DataOne = R.RecordId
                    WHERE (EventType = '".intval(self::ET_FULLRECORDVIEW)."'
                    OR EventType = '".intval(self::ET_URLFIELDCLICK)."')
                    AND R.SchemaId != '".intval(MetadataSchema::SCHEMAID_DEFAULT)."'");
        }

        # if previously-installed version is earlier than 1.2.3
        if (version_compare($PreviousVersion, "1.2.3", "<")) {
            # set the errors that can be safely ignord
            $DB = new Database();
            $DB->setQueryErrorsToIgnore([
                "/ALTER TABLE [a-z]+ ADD INDEX/i" => "/Duplicate key name/i"
            ]);

            # add indexes to speed view and click count retrieval
            $DB->query("ALTER TABLE MetricsRecorder_EventData"
                    ." ADD INDEX Index_TO (EventType,DataOne(8))");
            $DB->query("ALTER TABLE MetricsRecorder_EventData"
                    ." ADD INDEX Index_TOW (EventType,DataOne(8),DataTwo(8))");
        }

        # if previously-installed version is earlier than 1.2.5
        if (version_compare($PreviousVersion, "1.2.5", "<")) {
            # update ownership of count metadata fields if they exist
            $Schema = new MetadataSchema();
            if ($Schema->fieldExists("Full Record View Count")) {
                $Field = $Schema->getField("Full Record View Count");
                $Field->owner("MetricsRecorder");
                $Field->description(str_replace(
                    "Reporter",
                    "Recorder",
                    $Field->description()
                ));
            }
            if ($Schema->fieldExists("URL Field Click Count")) {
                $Field = $Schema->getField("URL Field Click Count");
                $Field->owner("MetricsRecorder");
                $Field->description(str_replace(
                    "Reporter",
                    "Recorder",
                    $Field->description()
                ));
            }
        }

        if (version_compare($PreviousVersion, "1.2.6", "<")) {
            # this may take a while, avoid timing out
            set_time_limit(3600);

            # find all the places where we might have stored legacy format search URLs
            # load them into SearchParameterSets, then stuff in the Data() from them
            $DB = new Database();
            $DB->caching(false);

            # this is less than ideal, but the LIKE clauses below are meant to
            #  prevent already-updated rows that don't contain SearchParameter data
            # from being re-updated if two requests try to run the
            # migration one after another

            # Note, it's necessary to specify an explicit
            # EvnetDate to avoid mysql's "helpful" behavior of
            # auto-updateing the first TIMESTAMP column in a table

            # get the event IDs that use the old format (not pulling the data to avoid
            #   potentially running out of memory)
            $DB->query("SELECT EventId FROM MetricsRecorder_EventData WHERE "
                    ."EventType IN (".self::ET_SEARCH.",".self::ET_ADVANCEDSEARCH.") "
                    ."AND DataOne IS NOT NULL "
                    ."AND LENGTH(DataOne)>0 "
                    ."AND DataOne NOT LIKE 'a:%'");
            $EventIds = $DB->fetchColumn("EventId");

            foreach ($EventIds as $EventId) {
                $DB->query("SELECT DataOne, EventDate FROM "
                           ."MetricsRecorder_EventData WHERE "
                           ."EventId=".$EventId);
                $Row = $DB->fetchRow();
                if ($Row === false) {
                    throw new Exception("Unable to retrieve data for event ID "
                            .$EventId.".");
                }

                # if this event has already been converted, don't try to re-convert it
                if (StdLib::isSerializedData($Row["DataOne"])) {
                    continue;
                }

                # attempt to convert to the new format, saving if we succeed
                try {
                    $SearchParams = new SearchParameterSet();
                    $SearchParams->setFromLegacyUrl($Row["DataOne"]);

                    $DB->query("UPDATE MetricsRecorder_EventData "
                               ."SET DataOne='".addslashes($SearchParams->data())."', "
                               ."EventDate='".$Row["EventDate"]."' "
                               ."WHERE EventId=".$EventId);
                } catch (Exception $e) {
                    ; # continue in the case of invalid metadata fields
                }
            }

            # pull out Full Record views that have search data
            $DB->query("SELECT EventId FROM MetricsRecorder_EventData WHERE "
                    ."EventType=".self::ET_FULLRECORDVIEW." "
                    ."AND DataTwo IS NOT NULL "
                    ."AND LENGTH(DataTwo)>0 "
                    ."AND DataTwo NOT LIKE 'a:%'");
            $EventIds = $DB->fetchColumn("EventId");

            # iterate over them, converting each to a
            # SearchParameterSet and updating the DB
            foreach ($EventIds as $EventId) {
                $DB->query("SELECT DataTwo, EventDate FROM "
                           ."MetricsRecorder_EventData WHERE "
                           ."EventId=".$EventId);
                $Row = $DB->fetchRow();
                if ($Row === false) {
                    throw new Exception("Unable to retrieve data for event ID "
                            .$EventId.".");
                }

                # if this event has already been converted, don't try to re-convert it
                if (StdLib::isSerializedData($Row["DataTwo"])) {
                    continue;
                }

                try {
                    $SearchParams = new SearchParameterSet();
                    $SearchParams->setFromLegacyUrl($Row["DataTwo"]);

                    $DB->query("UPDATE MetricsRecorder_EventData "
                               ."SET DataTwo='".addslashes($SearchParams->data())."', "
                               ."EventDate='".$Row["EventDate"]."' "
                               ."WHERE EventId=".$EventId);
                } catch (Exception $e) {
                    ; # continue in the case of invalid metadata fields
                }
            }
        }

        if (version_compare($PreviousVersion, "1.2.9", "<")) {
            $DB = new Database();
            $DB->query(
                "CREATE TABLE IF NOT EXISTS MetricsRecorder_SentEmails (
                    FromAddr        TEXT,
                    ToAddr          TEXT,
                    Subject         TEXT,
                    LogData         BLOB,
                    DateSent        DATETIME);"
            );
        }

        if (version_compare($PreviousVersion, "1.2.10", "<")) {
            $DB = new Database();
            $DB->query(
                "ALTER TABLE MetricsRecorder_EventData "
                ."ADD INDEX Index_IpD (IPAddress,EventDate)"
            );
        }

        # clean out full record view data that is incorrect because no data
        # was recorded on cache hits (2015-05-20 is when page caching support
        # was checked in)
        if (version_compare($PreviousVersion, "1.2.11", "<") &&
            $GLOBALS["AF"]->pageCacheEnabled()) {
            $DB = new Database();
            $DB->query(
                "DELETE FROM MetricsRecorder_EventData WHERE"
                ." EventType=".self::ET_FULLRECORDVIEW
                ." AND EventDate >= '2015-05-20 09:25:00'"
            );
        }

        if (version_compare($PreviousVersion, "1.2.13", "<")) {
            ApplicationFramework::getInstance()
                ->queueUniqueTask(
                    "\\Metavus\\Plugins\\MetricsRecorder::runDatabaseUpdates",
                    [$PreviousVersion],
                    \ScoutLib\ApplicationFramework::PRIORITY_LOW,
                    "Perform database updates for MetricsRecorder upgrade."
                );
        }

        if (version_compare($PreviousVersion, "1.2.14", "<")) {
            ApplicationFramework::getInstance()
                ->queueUniqueTask(
                    "\\Metavus\\Plugins\\MetricsRecorder::runDatabaseUpdates",
                    [$PreviousVersion],
                    \ScoutLib\ApplicationFramework::PRIORITY_LOW,
                    "Perform database updates for MetricsRecorder upgrade."
                );
        }

        if (version_compare($PreviousVersion, "1.2.15", "<")) {
            ApplicationFramework::getInstance()
                ->queueUniqueTask(
                    "\\Metavus\\Plugins\\MetricsRecorder::runDatabaseUpdates",
                    [$PreviousVersion],
                    \ScoutLib\ApplicationFramework::PRIORITY_LOW,
                    "Perform database updates for MetricsRecorder upgrade."
                );
        }

        # report successful upgrade to caller
        return null;
    }

    /**
     * Declare events defined by this plugin.This is used when a plugin defines
     * new events that it signals or responds to.Names of these events should
     * begin with the plugin base name, followed by "_EVENT_" and the event name
     * in all caps (for example "MyPlugin_EVENT_MY_EVENT").
     * @return array Event names for the index and event types for the values.
     */
    public function declareEvents()
    {
        return ["MetricsRecorder_EVENT_RECORD_EVENT" => ApplicationFramework::EVENTTYPE_NAMED];
    }

    /**
     * Hook the events into the application framework.
     * @return array Returns an array of events to be hooked into the
     *      application framework.
     */
    public function hookEvents()
    {
        return [
            "EVENT_DAILY" => ["recordDailySampleData", "dailyPruning"],
            "EVENT_USER_LOGIN" => "recordUserLogin",
            "EVENT_USER_ADDED" => "recordNewUserAdded",
            "EVENT_USER_VERIFIED" => "recordNewUserVerified",
            "EVENT_SEARCH_COMPLETE" => "recordSearch",
            "EVENT_OAIPMH_REQUEST" => "recordOAIRequest",
            "EVENT_FULL_RECORD_VIEW" => "recordFullRecordViewOnCacheMiss",
            "EVENT_URL_FIELD_CLICK" => "recordUrlFieldClick",
        ];
    }

    /**
     * Handle long-running updates to our DB tables in a background task so
     * that plugin upgrades will not block until they are complete.Pageloads
     * that need to access these tables will still wait for them, but those
     * that do not (e.g., hits from the static page cache) will be able to
     * proceed.
     * @param string $PreviousVersion Previous version.
     */
    public static function runDatabaseUpdates(string $PreviousVersion): void
    {
        # allow this task to take up to an hour
        set_time_limit(3600);

        $AF = ApplicationFramework::getInstance();
        $DB = new Database();

        $QueryParts = [];

        if (version_compare($PreviousVersion, "1.2.13", "<")) {
            $QueryParts[] = "DROP INDEX Index_T";
            $QueryParts[] = "DROP INDEX Index_TO";
            $QueryParts[] = "DROP INDEX Index_TUD";
            $QueryParts[] = "CHANGE COLUMN DataOne DataOne BLOB";
            $QueryParts[] = "CHANGE COLUMN DataTwo DataTwo BLOB";
            $QueryParts[] = "ADD INDEX Index_TDU (EventType,EventDate,UserId)";
        }

        if (version_compare($PreviousVersion, "1.2.14", "<")) {
            $QueryParts[] = "ADD INDEX Index_TU (EventType,UserId)";
        }

        $AF->logMessage(
            ApplicationFramework::LOGLVL_INFO,
            "MetricsRecorder: Beginning update of MetricsRecorder_EventData table."
        );

        foreach ($QueryParts as $QueryPart) {
            $Query = "ALTER TABLE MetricsRecorder_EventData ".$QueryPart;
            $AF->logMessage(
                ApplicationFramework::LOGLVL_INFO,
                "MetricsRecorder: Running ".$Query
            );
            $DB->query($Query);
        }

        if (version_compare($PreviousVersion, "1.2.15", "<")) {
            if ($DB->isStorageEngineAvailable("InnoDB")) {
                $DB->query(
                    "ALTER TABLE MetricsRecorder_EventData ENGINE = InnoDB"
                );
            }
        }

        $AF->logMessage(
            ApplicationFramework::LOGLVL_INFO,
            "MetricsRecorder: Update complete."
        );
    }

    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Record periodically-sampled data.
     * @param string $LastRunAt Timestamp when method was last called.
     */
    public function recordDailySampleData($LastRunAt)
    {
        # if no last run time available assume it was 24 hours ago
        if ($LastRunAt == '') {
            $LastRunAt = date(StdLib::SQL_DATE_FORMAT, strtotime("24 hours ago"));
        }

        # record total number of registered users
        $UserFactory = new UserFactory();
        $this->recordSample(self::ST_REGUSERCOUNT, $UserFactory->getUserCount());

        # record number of privileged users
        $this->recordSample(
            self::ST_PRIVUSERCOUNT,
            count($UserFactory->getUsersWithPrivileges(
                PRIV_SYSADMIN,
                PRIV_NEWSADMIN,
                PRIV_RESOURCEADMIN,
                PRIV_CLASSADMIN,
                PRIV_NAMEADMIN,
                PRIV_USERADMIN,
                PRIV_COLLECTIONADMIN
            ))
        );

        # record total number of resources
        $ResourceFactory = new RecordFactory();
        $this->recordSample(self::ST_RESOURCECOUNT, $ResourceFactory->getItemCount());

        # record number of resources that have been rated
        $this->recordSample(
            self::ST_RATEDRESOURCECOUNT,
            $ResourceFactory->getRatedRecordCount()
        );

        # record number of users who have rated resources
        $this->recordSample(
            self::ST_RRESOURCEUSERCOUNT,
            $ResourceFactory->getRatedRecordUserCount()
        );

        # record number of searches currently saved
        $SavedSearchFactory = new SavedSearchFactory();
        $this->recordSample(
            self::ST_SAVEDSEARCHCOUNT,
            $SavedSearchFactory->getItemCount()
        );

        # record number of users who currently have searches saved
        $this->recordSample(
            self::ST_SSEARCHUSERCOUNT,
            $SavedSearchFactory->getSearchUserCount()
        );

        # record number of users that have logged in within the last day
        $this->recordSample(
            self::ST_DAILYLOGINCOUNT,
            $UserFactory->getUserCount(
                "LastLoginDate > '".addslashes($LastRunAt)."'"
            )
        );

        # record number of new accounts created and verified within the last day
        $this->recordSample(
            self::ST_DAILYNEWACCTS,
            $UserFactory->getUserCount(
                "CreationDate > '".addslashes($LastRunAt)
                ."' AND RegistrationConfirmed > 0"
            )
        );
    }


    /**
     * Daily pruning of IPs that generated too much load to plausibly be
     * humans.
     * @param string $LastRunAt Timestamp when method was last called.
     */
    public function dailyPruning($LastRunAt)
    {
        # if provided LastRunAt wasn't an SQL date
        if (!preg_match(StdLib::SQL_DATE_REGEX, (string)$LastRunAt)) {
            $LastRunAt = date(StdLib::SQL_DATE_FORMAT, strtotime("24 hours ago"));
        }

        # prune hosts that generated more than 120 events in a 60 second window
        $this->DB->query(
            "SELECT DISTINCT IPAddress FROM MetricsRecorder_EventData"
            ." WHERE EventDate >= '".$LastRunAt."'"
            ." GROUP BY IPAddress, FLOOR(UNIX_TIMESTAMP(EventDate)/60)"
            ." HAVING COUNT(*) >= 120"
        );
        $IPs = $this->DB->fetchColumn("IPAddress");
        foreach ($IPs as $IP) {
            $this->removeEventsForIPAddress((string)long2ip($IP), $LastRunAt);
        }

        # prune hosts that viewed the same record more than 60 times in an hour
        $this->DB->query(
            "SELECT DISTINCT IPAddress FROM MetricsRecorder_EventData"
            ." WHERE EventDate >= '".$LastRunAt."'"
            ." AND EventType = ".self::ET_FULLRECORDVIEW
            ." GROUP BY IPAddress, DataOne, FLOOR(UNIX_TIMESTAMP(EventDate)/3600)"
            ." HAVING COUNT(*) >= 60"
        );
        $IPs = $this->DB->fetchColumn("IPAddress");
        foreach ($IPs as $IP) {
            $this->removeEventsForIPAddress((string)long2ip($IP), $LastRunAt);
        }

        # prune hosts that clicked the same URL more than 20 times in 10 min
        $this->DB->query(
            "SELECT DISTINCT IPAddress FROM MetricsRecorder_EventData"
            ." WHERE EventDate >= '".$LastRunAt."'"
            ." AND EventType = ".self::ET_URLFIELDCLICK
            ." GROUP BY IPAddress, DataOne, DataTwo, FLOOR(UNIX_TIMESTAMP(EventDate)/600)"
            ." HAVING COUNT(*) >= 20"
        );
        $IPs = $this->DB->fetchColumn("IPAddress");
        foreach ($IPs as $IP) {
            $this->removeEventsForIPAddress((string)long2ip($IP), $LastRunAt);
        }
    }

    /**
     * Record user logging in.
     * @param int $UserId ID of user that logged in.
     * @param string $Password Password entered by user.
     */
    public function recordUserLogin($UserId, $Password)
    {
        $this->recordEventData(self::ET_USERLOGIN, null, null, $UserId);
    }

    /**
     * Record new user being added.
     * @param int $UserId ID of user that was added.
     * @param string $Password Password entered by user.
     */
    public function recordNewUserAdded($UserId, $Password)
    {
        $this->recordEventData(self::ET_NEWUSERADDED, null, null, $UserId);
    }

    /**
     * Record new user account being verified.
     * @param int $UserId ID of user that was verified.
     */
    public function recordNewUserVerified($UserId)
    {
        $this->recordEventData(self::ET_NEWUSERVERIFIED, null, null, $UserId);
    }

    /**
     * Record search being performed.
     * @param SearchParameterSet $SearchParameters Array containing search parameters.
     * @param array $SearchResults Two dimensional array, keyed by
     *       SchemaId with inner arrays containing search results, with
     *       search scores for the values and resource IDs for the
     *       indexes.
     */
    public function recordSearch(
        SearchParameterSet $SearchParameters,
        array $SearchResults
    ) {
        # searches are 'advanced' when they contain more than just Keywords
        # (i.e., they have values specified for specific fields and/or they
        #  contain subgroups)
        $AdvancedSearch =
            count($SearchParameters->getSearchStrings()) > 0 ||
            count($SearchParameters->getSubgroups()) > 0  ? true : false;

        $ResultsTotal = 0;
        foreach ($SearchResults as $SchemaResults) {
            $ResultsTotal += count($SchemaResults);
        }

        $this->recordEventData(
            ($AdvancedSearch ? self::ET_ADVANCEDSEARCH : self::ET_SEARCH),
            $SearchParameters->data(),
            $ResultsTotal
        );
    }

    /**
     * Record OAI-PMH harvest request.
     * @param string $RequesterIP IP address of requester.
     * @param string $QueryString GET string for query.
     */
    public function recordOAIRequest($RequesterIP, $QueryString)
    {
        $this->recordEventData(
            self::ET_OAIREQUEST,
            $RequesterIP,
            $QueryString,
            null,
            0
        );
    }

    /**
     * Record user viewing full resource record page served from the static
     *   page cache.
     * @param int $ResourceId ID of resource.
     */
    public static function recordFullRecordViewOnCacheHit($ResourceId)
    {
        $GLOBALS["G_PluginManager"]
            ->getPlugin("MetricsRecorder")
            ->recordFullRecordView($ResourceId);
    }

    /**
     * Record user viewing full resource record page for cache misses,
     *   registering a callback to also record data on cache hits.
     * @param int $ResourceId ID of resource.
     */
    public function recordFullRecordViewOnCacheMiss($ResourceId)
    {
        $this->recordFullRecordView($ResourceId);

        $GLOBALS["AF"]->registerCallbackForPageCacheHit(
            [__CLASS__, "recordFullRecordViewOnCacheHit"],
            [$ResourceId]
        );
    }

    /**
     * Record user viewing full resource record page.
     * @param int $ResourceId ID of resource.
     */
    public function recordFullRecordView($ResourceId)
    {
        $Resource = new Record($ResourceId);

        # if referring URL is available
        $SearchData = null;
        if (isset($_SERVER["HTTP_REFERER"])) {
            # if GET parameters are part of referring URL
            $Pieces = parse_url($_SERVER["HTTP_REFERER"]);
            if (($Pieces != false) && isset($Pieces["query"])) {
                # if search parameters look to be available from GET parameters
                parse_str($Pieces["query"], $Args);
                if (isset($Args["P"]) && ($Args["P"] == "AdvancedSearch")) {
                    $SearchParams = new SearchParameterSet();
                    $SearchParams->urlParameters($Pieces["query"]);

                    $SearchData = $SearchParams->urlParameterString();
                }
            }
        }

        # record full record view
        $this->recordEventData(
            self::ET_FULLRECORDVIEW,
            $ResourceId,
            $SearchData
        );

        if ($Resource->getSchema()->fieldExists("Full Record View Count")) {
            # update current view count for resource
            $CurrentCount = $this->getFullRecordViewCount(
                $Resource->id(),
                $this->getPrivilegesToExclude()
            );
            $Resource->set("Full Record View Count", $CurrentCount);
        }
    }

    /**
     * Record user clicking on a Url metadata field value.
     * @param int $ResourceId ID of resource.
     * @param int $FieldId ID of metadata field.
     */
    public function recordUrlFieldClick($ResourceId, $FieldId)
    {
        $Resource = new Record($ResourceId);

        $this->recordEventData(self::ET_URLFIELDCLICK, $ResourceId, $FieldId);

        # update current click count for resource
        if ($Resource->getSchema()->fieldExists("URL Field Click Count")) {
            $FieldId = $Resource->getSchema()->stdNameToFieldMapping("Url");
            if ($FieldId !== null) {
                $CurrentCount = $this->getUrlFieldClickCount(
                    $Resource->id(),
                    $FieldId,
                    $this->getPrivilegesToExclude()
                );
                $Resource->set("URL Field Click Count", $CurrentCount);
            }
        }
    }


    # ---- CALLABLE METHODS (Administrative) ---------------------------------

    /**
     * Register custom event type as valid.Recording or retrieving data with
     * an owner and/or type that has not been registered will cause an exception.
     * @param string $Owner Name of event data owner (caller-defined string).
     * @param string $Type Type of event (caller-defined string).
     */
    public function registerEventType($Owner, $Type)
    {
        # add type to list
        $this->CustomEventTypes[] = $Owner.$Type;
    }

    /**
     * Remove events recorded with a specified IP address.The starting and/or
     * ending date can be specified in any format parseable by strtotime().
     * @param string $IPAddress Address to remove for, in dotted-quad format.
     * @param string $StartDate Starting date/time of period (inclusive) for
     *       which to remove events.(OPTIONAL, defaults to NULL, which imposes
     *       no starting date)
     * @param string $EndDate Ending date/time of period (inclusive) for
     *       which to remove events.(OPTIONAL, defaults to NULL, which imposes
     *       no ending date)
     */
    public function removeEventsForIPAddress(
        $IPAddress,
        $StartDate = null,
        $EndDate = null
    ) {
        $Query = "DELETE FROM MetricsRecorder_EventData"
                ." WHERE IPAddress = INET_ATON('"
                .addslashes($IPAddress)."')";
        if ($StartDate !== null) {
            if (strtotime($StartDate) === false) {
                throw new InvalidArgumentException("Unparseable start date.");
            }
            $Query .= " AND EventDate >= '"
                    .date(StdLib::SQL_DATE_FORMAT, strtotime($StartDate))."'";
        }
        if ($EndDate !== null) {
            if (strtotime($EndDate) === false) {
                throw new InvalidArgumentException("Unparseable end date.");
            }
            $Query .= " AND EventDate <= '"
                    .date(StdLib::SQL_DATE_FORMAT, strtotime($EndDate))."'";
        }
        $this->DB->query($Query);
    }


    # ---- CALLABLE METHODS (Storage) ----------------------------------------

    /**
     * Record event data.Any of the optional arguments can be supplied
     * as NULL to be skipped.Event data is not recorded if it is a duplicate
     * of previously-recorded data that falls within the dedupe time, or if
     * it was reported as being triggered by a bot via the BotDetector plugin.
     * @param string $Owner Name of event data owner (caller-defined string).
     * @param string $Type Type of event (caller-defined string).
     * @param mixed $DataOne First piece of event data.(OPTIONAL)
     * @param mixed $DataTwo Second piece of event data.(OPTIONAL)
     * @param int $UserId ID of user associated with event.(OPTIONAL)
     * @param int $DedupeTime Time in seconds that must elapse between events
     *       for them not to be considered as duplicates and ignored.(OPTIONAL,
     *       defaults to 10)
     * @param bool $CheckForBot Determines whether to check if the event was
     *       triggered by a bot and, if so, to ignore the event.(OPTIONAL,
     *       defaults to TRUE)
     * @return bool TRUE if event data was recorded (not considered a duplicate
     *       or triggered by a bot), otherwise FALSE.
     * @throws Exception If event type was not found.
     * @see MetricsRecorder::GetEventData()
     */
    public function recordEvent(
        $Owner,
        $Type,
        $DataOne = null,
        $DataTwo = null,
        $UserId = null,
        $DedupeTime = 10,
        $CheckForBot = true
    ) {
        # map owner and type names to numerical event type
        $EventTypeId = $this->getEventTypeId($Owner, $Type);

        # store data and report success of storage attempt to caller
        return $this->recordEventData(
            $EventTypeId,
            $DataOne,
            $DataTwo,
            $UserId,
            $DedupeTime,
            $CheckForBot
        );
    }

    /**
     * Log when an email message has been sent.
     * @param Email $Email Email that was just sent.
     * @param array $LogData Additional log data.
     */
    public static function logSentMessage(Email $Email, array $LogData)
    {
        $From = $Email->from();
        $To = implode(", ", $Email->to());
        $Subject = $Email->subject();

        $DB = new Database();
        $DB->query(
            'INSERT INTO MetricsRecorder_SentEmails '
            .'(FromAddr, ToAddr, Subject, LogData, DateSent) VALUES ('
            .'"'.addslashes($From).'","'.addslashes($To).'",'
            .'"'.addslashes($Subject).'","'
            .(count($LogData) ? $DB->escapeString(serialize($LogData)) : "")
            .'",NOW())'
        );
    }

    # ---- CALLABLE METHODS (Retrieval) --------------------------------------

    /**
     * Retrieve event data.
     * @param mixed $Owner Name of event data owner (caller-defined string
     *       or "MetricsRecorder" if a native MetricsRecorder event).May be an
     *       array if multiple event types specified and searching for multiple
     *       types of events with different owners.
     * @param mixed $Type Type(s) of event (a caller-defined string or a constant
     *       for native MetricsRecorder events).May be an array to search
     *       for multiple types of events at once.
     * @param string $StartDate Beginning of date of range to search (inclusive),
     *       in SQL date format.(OPTIONAL, defaults to NULL)
     * @param string $EndDate End of date of range to search (inclusive),
     *       in SQL date format.(OPTIONAL, defaults to NULL)
     * @param int $UserId ID of user associated with event.(OPTIONAL, defaults to NULL)
     * @param mixed $DataOne First generic data value associated with the event.
     *       This may also be an array of values.(OPTIONAL, defaults to NULL)
     * @param mixed $DataTwo Second generic data value associated with the event.
     *       This may also be an array of values.(OPTIONAL, defaults to NULL)
     * @param array|PrivilegeSet $PrivsToExclude Users with these privileges will be
     *       excluded from the results.(OPTIONAL, defaults to empty array)
     * @param int $Offset Zero-based offset into results array.(OPTIONAL, defaults to 0)
     * @param int $Count Number of results to return (NULL to retrieve all).
     *       (OPTIONAL, defaults to NULL)
     * @return array Array with unique event IDs for the index and associative arrays
     *       for the value, with the indexes "EventDate", "UserId", "IPAddress",
     *       "DataOne", and "DataTwo".
     * @throws Exception If no matching event owner found for event type.
     * @throws Exception If event type was not found.
     * @throws Exception If no event types specified.
     * @see MetricsRecorder::RecordEvent()
     */
    public function getEventData(
        $Owner,
        $Type,
        $StartDate = null,
        $EndDate = null,
        $UserId = null,
        $DataOne = null,
        $DataTwo = null,
        $PrivsToExclude = [],
        $Offset = 0,
        $Count = null
    ) {
        # normalize privilege exclusion info if necessary
        if ($PrivsToExclude instanceof PrivilegeSet) {
            $PrivsToExclude = $PrivsToExclude->getPrivileges();
        }

        # map owner and type names to numerical event types
        $Types = is_array($Type) ? $Type : [$Type];
        foreach ($Types as $Index => $EventType) {
            if (is_array($Owner)) {
                if (array_key_exists($Index, $Owner)) {
                    $EventOwner = $Owner[$Index];
                } else {
                    throw new Exception("No corresponding owner found"
                            ." for event type ".$EventType);
                }
            } else {
                $EventOwner = $Owner;
            }
            $EventTypeIds[$Index] = $this->getEventTypeId($EventOwner, $EventType);
        }
        if (!isset($EventTypeIds)) {
            throw new Exception("No event types specified.");
        }

        # begin building database query
        $Query = "SELECT * FROM MetricsRecorder_EventData";

        # add claus(es) for event type(s) to query
        $Separator = " WHERE (";
        foreach ($EventTypeIds as $Id) {
            $Query .= $Separator."EventType = ".$Id;
            $Separator = " OR ";
        }
        $Query .= ")";

        # add clauses for user and date range to query
        $Query .= (($UserId === null) ? "" : " AND UserId = ".intval($UserId))
                .(($StartDate === null) ? ""
                        : " AND EventDate >= '".addslashes($StartDate)."'")
                .(($EndDate === null) ? ""
                        : " AND EventDate <= '".addslashes($EndDate)."'");

        # add clauses for data values
        foreach (["DataOne" => $DataOne, "DataTwo" => $DataTwo] as $Name => $Data) {
            if ($Data !== null) {
                if (is_array($Data) && count($Data)) {
                    $Query .= " AND ".$Name." IN (";
                    $Separator = "";
                    foreach ($Data as $Value) {
                        $Query .= $Separator."'".addslashes($Value)."'";
                        $Separator = ",";
                    }
                    $Query .= ")";
                } else {
                    $Query .= " AND ".$Name." = '".addslashes($Data)."'";
                }
            }
        }

        # add clause for user exclusion if specified
        if (count($PrivsToExclude)) {
            $Query .= " AND (UserId IS NULL OR UserId NOT IN ("
                    .\ScoutLib\User::getSqlQueryForUsersWithPriv($PrivsToExclude)."))";
        }

        # add sorting and (if specified) return value limits to query
        $Query .= " ORDER BY EventDate ASC";
        if ($Count) {
            $Query .= " LIMIT ".($Offset ? intval($Offset)."," : "").intval($Count);
        } elseif ($Offset) {
            $Query .= " LIMIT ".intval($Offset).",".PHP_INT_MAX;
        }

        # retrieve data
        $Data = [];
        $this->DB->query($Query);
        while ($Row = $this->DB->fetchRow()) {
            $EventId = $Row["EventId"];
            unset($Row["EventId"]);
            unset($Row["EventType"]);
            $Row["IPAddress"] = long2ip($Row["IPAddress"]);
            $Data[$EventId] = $Row;
        }

        # return array with retrieved data to caller
        return $Data;
    }

    /**
     * Retrieve count of events matching specified parameters.
     * @param mixed $Owner Name of event data owner (caller-defined string
     *       or "MetricsRecorder" if a native MetricsRecorder event).May be an
     *       array if multiple event types specified and searching for multiple
     *       types of events with different owners.
     * @param mixed $Type Type(s) of event (a caller-defined string or a constant
     *       for native MetricsRecorder events).May be an array to search
     *       for multiple types of events at once.
     * @param mixed $Period Either "HOUR", "DAY", "WEEK", "MONTH", "YEAR", or
     *       NULL to total up all available events.The "WEEK" period begins on
     *       Sunday.(OPTIONAL, defaults to NULL)
     * @param string $StartDate Beginning of date of range to search (inclusive),
     *       in SQL date format.(OPTIONAL, defaults to NULL)
     * @param string $EndDate End of date of range to search (inclusive),
     *       in SQL date format.(OPTIONAL, defaults to NULL)
     * @param int $UserId ID of user associated with event.(OPTIONAL, defaults to NULL)
     * @param mixed $DataOne First generic data value associated with the event.
     *       This may also be an array of values.(OPTIONAL, defaults to NULL)
     * @param mixed $DataTwo Second generic data value associated with the event.
     *       This may also be an array of values.(OPTIONAL, defaults to NULL)
     * @param array|PrivilegeSet $PrivsToExclude Users with these privileges will be
     *       excluded from the results.(OPTIONAL, defaults to empty array)
     * @return array Count of events found that match the specified parameters,
     *       with unix timestamps for the index if a period was specified.
     * @throws Exception If no matching event owner found for event type.
     * @throws Exception If event type was not found.
     * @throws Exception If no event types specified.
     * @throws Exception If $Period value is invalid.
     * @see MetricsRecorder::RecordEvent()
     */
    public function getEventCounts(
        $Owner,
        $Type,
        $Period = null,
        $StartDate = null,
        $EndDate = null,
        $UserId = null,
        $DataOne = null,
        $DataTwo = null,
        $PrivsToExclude = []
    ) {
        # normalize privilege exclusion info if necessary
        if ($PrivsToExclude instanceof PrivilegeSet) {
            $PrivsToExclude = $PrivsToExclude->getPrivileges();
        }

        # map owner and type names to numerical event types
        $Types = is_array($Type) ? $Type : [$Type];
        foreach ($Types as $Index => $EventType) {
            if (is_array($Owner)) {
                if (array_key_exists($Index, $Owner)) {
                    $EventOwner = $Owner[$Index];
                } else {
                    throw new Exception("No corresponding owner found"
                            ." for event type ".$EventType);
                }
            } else {
                $EventOwner = $Owner;
            }
            $EventTypeIds[$Index] = $this->getEventTypeId($EventOwner, $EventType);
        }
        if (!isset($EventTypeIds)) {
            throw new Exception("No event types specified.");
        }

        # begin constructing database query (it's a modest start)
        $Query = "SELECT";

        # if a period was specified
        if ($Period !== null) {
            # normalize period string
            $Period = strtoupper($Period);
            $Period = ($Period == "DAILY") ? "DAY"
                    : preg_replace("/LY\$/", "", $Period);

            # add extra calculated value to query to retrieve and group values by
            $PeriodFilters = [
                "HOUR" => "%Y-%m-%d %k:00:00",
                "DAY" => "%Y-%m-%d 00:00:00",
                "WEEK" => "%X-%V",
                "MONTH" => "%Y-%m-01 00:00:00",
                "YEAR" => "%Y-01-01 00:00:00",
            ];
            if (!array_key_exists($Period, $PeriodFilters)) {
                throw new Exception("Invalid period specified (".$Period.").");
            }
            $Query .= " DATE_FORMAT(EventDate, '"
                    .$PeriodFilters[$Period]."') as Period,";
        }

        # continue building query
        $Query .= " COUNT(*) AS EventCount FROM MetricsRecorder_EventData";

        # add claus(es) for event type(s) to query
        $Separator = " WHERE (";
        foreach ($EventTypeIds as $Id) {
            $Query .= $Separator."EventType = ".$Id;
            $Separator = " OR ";
        }
        $Query .= ")";

        # add clauses for user and date range to query
        $Query .= (($UserId === null) ? ""
                        : " AND UserId = '".addslashes((string)$UserId)."'")
                .(($StartDate === null) ? ""
                        : " AND EventDate >= '".addslashes($StartDate)."'")
                .(($EndDate === null) ? ""
                        : " AND EventDate <= '".addslashes($EndDate)."'");

        # add clauses for data values
        foreach (["DataOne" => $DataOne, "DataTwo" => $DataTwo] as $Name => $Data) {
            if ($Data !== null) {
                if (is_array($Data) && count($Data)) {
                    $Query .= " AND ".$Name." IN (";
                    $Separator = "";
                    foreach ($Data as $Value) {
                        $Query .= $Separator."'".addslashes($Value)."'";
                        $Separator = ",";
                    }
                    $Query .= ")";
                } else {
                    $Query .= " AND ".$Name." = '".addslashes($Data)."'";
                }
            }
        }

        # add clause to exclude users if supplied
        if (count($PrivsToExclude)) {
            $Query .= " AND (UserId IS NULL OR UserId NOT IN ("
                    .\ScoutLib\User::getSqlQueryForUsersWithPriv($PrivsToExclude)."))";
        }

        # add grouping to query if period was specified
        if ($Period !== null) {
            $Query .= " GROUP BY Period";
        }

        # run query against database
        $this->DB->query($Query);

        # if no period was specified
        if ($Period === null) {
            # retrieve total count
            $Data = $this->DB->fetchColumn("EventCount");
        } else {
            # retrieve counts for each period
            $Data = $this->DB->fetchColumn("EventCount", "Period");

            # for each count
            $NewData = [];
            foreach ($Data as $Date => $Count) {
                # if period is a week
                if ($Period == "WEEK") {
                    # split returned date into week number and year values
                    list($Year, $Week) = explode("-", $Date);

                    # adjust values if week is at end of year
                    if ($Week < 53) {
                        $Week = (int)$Week + 1;
                    } else {
                        $Week = 1;
                        $Year = (int)$Year + 1;
                    }

                    # convert week number and year into Unix timestamp
                    $Timestamp = strtotime(sprintf("%04dW%02d0", $Year, $Week));
                } else {
                    # convert date into Unix timestamp
                    $Timestamp = strtotime($Date);
                }

                # save count with new Unix timestamp index value
                $NewData[$Timestamp] = $Count;
            }
            $Data = $NewData;
        }

        # return count(s) to caller
        return $Data;
    }

    /**
     * Retrieve sample data recorded by MetricsRecorder.
     * @param mixed $Type Type of sample (ST_ constant).
     * @param string $StartDate Beginning of date of range to search (inclusive),
     *       in SQL date format.(OPTIONAL, defaults to NULL)
     * @param string $EndDate End of date of range to search (inclusive),
     *       in SQL date format.(OPTIONAL, defaults to NULL)
     * @param int $Offset Zero-based offset into results array.(OPTIONAL, defaults to 0)
     * @param int $Count Number of results to return (NULL to retrieve all).
     *       (OPTIONAL, defaults to NULL)
     * @return array Array with sample timestamps (in SQL date format) for the
     *       index and sample values for the values.
     * @see MetricsRecorder::RecordDailySampleData()
     */
    public function getSampleData(
        $Type,
        $StartDate = null,
        $EndDate = null,
        $Offset = 0,
        $Count = null
    ) {
        # construct database query to retrieve data
        $Query = "SELECT * FROM MetricsRecorder_SampleData"
                ." WHERE SampleType = ".intval($Type)
                .(($StartDate === null) ? ""
                        : " AND SampleDate >= '".addslashes($StartDate)."'")
                .(($EndDate === null) ? ""
                        : " AND SampleDate <= '".addslashes($EndDate)."'")
                ." ORDER BY SampleDate ASC";
        if ($Count) {
            $Query .= " LIMIT ".($Offset ? intval($Offset)."," : "").intval($Count);
        } elseif ($Offset) {
            $Query .= " LIMIT ".intval($Offset).",".PHP_INT_MAX;
        }

        # retrieve data and return to caller
        $this->DB->query($Query);
        return $this->DB->fetchColumn("SampleValue", "SampleDate");
    }

    /**
     * Retrieve list of full record view events.
     * @param User $User User to retrieve full record views for.Specify
     *       NULL for all users.(OPTIONAL - defaults to NULL)
     * @param int $Count Number of views to return.(OPTIONAL - defaults to 10)
     * @param int $Offset Offset into list of views to begin.(OPTIONAL -
     *       defaults to 0)
     * @param bool $NoDupes Whether to eliminate duplicate records with
     *       record IDs, returning only the newest.(OPTIONAL - defaults to TRUE)
     * @return Array with event ID as index and associative array of view attributes
     *       as value ("Date", "ResourceId", "IPAddress", "QueryString", "UserId")
     */
    public function getFullRecordViews(
        $User = null,
        $Count = 10,
        $Offset = 0,
        $NoDupes = true
    ) {
        # do while we need more views and there are records left in DB
        $Views = [];
        do {
            # retrieve block of view records from database
            $this->DB->query("SELECT * FROM MetricsRecorder_EventData"
                    ." WHERE EventType = ".self::ET_FULLRECORDVIEW
                    .(($User !== null) ? " AND UserId = ".intval($User->id()) : " ")
                    ." ORDER BY EventDate DESC"
                    ." LIMIT ".intval($Offset).",".$Count);
            $Offset += $Count;

            # while we need more views and records are left in the current block
            while ((count($Views) < $Count) && ($Row = $this->DB->fetchRow())) {
                # if caller did not care about dupes or record is not a dupe
                if (!$NoDupes || (!isset($SeenIDs[$Row["DataOne"]]))) {
                    # add record to views
                    if ($NoDupes) {
                        $SeenIDs[$Row["DataOne"]] = true;
                    }
                    $Views[$Row["EventId"]] = [
                        "Date" => $Row["EventDate"],
                        "ResourceId" => $Row["DataOne"],
                        "IPAddress" => $Row["IPAddress"],
                        "QueryString" => $Row["DataTwo"],
                        "UserId" => $Row["UserId"],
                    ];
                }
            }
        } while ((count($Views) < $Count) && $this->DB->NumRowsSelected());

        # return views to caller
        return $Views;
    }

    /**
     * Get count of full record views for specified resource.
     * @param int $ResourceId ID of resource.
     * @param array $PrivsToExclude Users with these privileges will be
     *       excluded from the results.(OPTIONAL, defaults to empty array)
     * @return int Record view count.
     */
    public function getFullRecordViewCount($ResourceId, $PrivsToExclude = [])
    {
        $Counts = $this->getEventCounts(
            "MetricsRecorder",
            self::ET_FULLRECORDVIEW,
            null,
            null,
            null,
            null,
            $ResourceId,
            null,
            $PrivsToExclude
        );
        return array_shift($Counts);
    }

    /**
     * Get count of URL clicks for specified resource and field.
     * @param int $ResourceId ID of resource.
     * @param int $FieldId ID of metadata field.
     * @param array $PrivsToExclude Users with these privileges will be
     *       excluded from the results.(OPTIONAL, defaults to empty array)
     * @return int Record view count.
     */
    public function getUrlFieldClickCount(
        $ResourceId,
        $FieldId,
        $PrivsToExclude = []
    ) {
        $Counts = $this->getEventCounts(
            "MetricsRecorder",
            self::ET_URLFIELDCLICK,
            null,
            null,
            null,
            null,
            $ResourceId,
            $FieldId,
            $PrivsToExclude
        );
        return array_shift($Counts);
    }

    /**
     * Retrieve count of clicks on specified URL field for each resource.Date
     * parameters may be in any format parseable by strtotime().
     * @param int $FieldId ID of URL metadata field.
     * @param string $StartDate Beginning of date range (in any format
     *       parseable by strtotime()), or NULL for no lower bound to range.
     * @param string $EndDate Beginning of date range (in any format
     *       parseable by strtotime()), or NULL for no upper bound to range.
     * @param int $Offset Zero-based offset into results array.(OPTIONAL,
     *       defaults to 0)
     * @param int $Count Number of results to return, or 0 to retrieve all.
     *       (OPTIONAL, defaults to 0)
     * @param array $PrivsToExclude Users with these privileges will be
     *       excluded from the results.(OPTIONAL)
     * @return array Associative array of count data, with "Counts" containing
     *       array of full record view counts, indexed by resource IDs and
     *       sorted in descending order of view counts, "Total" containing the
     *       sum of all those counts, and "StartDate" and "EndDate" containing
     *       dates of first and last recorded views.
     */
    public function getUrlFieldClickCounts(
        $FieldId,
        $StartDate = null,
        $EndDate = null,
        $Offset = 0,
        $Count = 0,
        $PrivsToExclude = []
    ) {
        $Field = new MetadataField($FieldId);
        $AddedClause = "ED.DataTwo = ".intval($FieldId)
                ." AND (ED.DataOne = R.RecordId AND R.SchemaId = "
                        .intval($Field->schemaId()).")";
        $AddedTable = "Records AS R";
        return $this->getClickCounts(
            self::ET_URLFIELDCLICK,
            $StartDate,
            $EndDate,
            $Offset,
            $Count,
            $PrivsToExclude,
            $AddedClause,
            $AddedTable
        );
    }

    /**
     * Retrieve count of full record views for each resource.
     * @param int $SchemaId Metadata schema ID for resource records.(OPTIONAL,
     *       defaults to SCHEMAID_DEFAULT)
     * @param string $StartDate Beginning of date range (in any format
     *       parseable by strtotime()), or NULL for no lower bound to range.
     * @param string $EndDate Beginning of date range (in any format
     *       parseable by strtotime()), or NULL for no upper bound to range.
     * @param int $Offset Zero-based offset into results array.(OPTIONAL,
     *       defaults to 0)
     * @param int $Count Number of results to return, or 0 to retrieve all.
     *       (OPTIONAL, defaults to 0)
     * @param array $PrivsToExclude Users with these privileges will be
     *       excluded from the results.(OPTIONAL)
     * @return array Associative array of count data, with "Counts" containing
     *       array of full record view counts, indexed by resource IDs and
     *       sorted in descending order of view counts, "Total" containing the
     *       sum of all those counts, and "StartDate" and "EndDate" containing
     *       dates of first and last recorded views.
     */
    public function getFullRecordViewCounts(
        $SchemaId = MetadataSchema::SCHEMAID_DEFAULT,
        $StartDate = null,
        $EndDate = null,
        $Offset = 0,
        $Count = 0,
        $PrivsToExclude = []
    ) {
        $AddedClause = "(ED.DataOne = R.RecordId AND R.SchemaId = "
                        .intval($SchemaId).")";
        $AddedTable = "Records AS R";
        return $this->getClickCounts(
            self::ET_FULLRECORDVIEW,
            $StartDate,
            $EndDate,
            $Offset,
            $Count,
            $PrivsToExclude,
            $AddedClause,
            $AddedTable
        );
    }

    /** @name Sample data types */ /*@{*/
    const ST_REGUSERCOUNT = 1;       /**< Number of registered users */
    const ST_PRIVUSERCOUNT = 2;      /**< Number of privileged users */
    const ST_RESOURCECOUNT = 3;      /**< Number of resources  */
    const ST_RATEDRESOURCECOUNT = 4; /**< Number of rated resources  */
    const ST_RRESOURCEUSERCOUNT = 5; /**< Number of users who rated resources  */
    const ST_SAVEDSEARCHCOUNT = 6;   /**< Number of saved searches */
    const ST_SSEARCHUSERCOUNT = 7;   /**< Number of users with saved searches */
    const ST_DAILYLOGINCOUNT = 8;    /**< Number of logins in last day */
    const ST_DAILYNEWACCTS = 9;      /**< Number new accounts in last day */
    /*@}*/

    /** @name Event data types */ /*@{*/
    const ET_NONE = 0;                /**< no event (do not record) */
    const ET_USERLOGIN =  1;          /**< User logged in */
    const ET_NEWUSERADDED =  2;       /**< User signed up for new account */
    const ET_NEWUSERVERIFIED =  3;    /**< User verified new account */
    const ET_SEARCH =  4;             /**< Keyword search performed */
    const ET_ADVANCEDSEARCH =  5;     /**< Fielded search performed */
    const ET_OAIREQUEST =  6;         /**< OAI-PMH harvest request */
    const ET_FULLRECORDVIEW =  7;     /**< Full record page viewed */
    const ET_URLFIELDCLICK =  8;      /**< URL field clicked */
    /* (recording not yet implemented for following events) */
    const ET_RSSREQUEST = 9;          /**< RSS feed request */
    /*@}*/


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $DB;
    private $CustomEventTypes = [];

    /**
     * Utility method to record sample data to database.
     * @param int $SampleType Type of sample.
     * @param int $SampleValue Data for sample.
     */
    private function recordSample($SampleType, $SampleValue)
    {
        $this->DB->query("INSERT INTO MetricsRecorder_SampleData SET"
                ." SampleType = ".intval($SampleType).", "
                ." SampleValue = ".intval($SampleValue));
    }

    /**
     * Record event data.Any of the optional arguments can be supplied
     * as NULL to be skipped.Event data is not recorded if it is a duplicate
     * of previously-recorded data that falls within the dedupe time, or if
     * it was reported as being triggered by a bot via the BotDetector plugin.
     * @param int $EventTypeId Type of event.
     * @param mixed $DataOne First piece of event data.(OPTIONAL)
     * @param mixed $DataTwo Second piece of event data.(OPTIONAL)
     * @param int $UserId ID of user associated with event.(OPTIONAL)
     * @param int $DedupeTime Time in seconds that must elapse between events
     *       for them not to be considered as duplicates and ignored.(OPTIONAL,
     *       defaults to 10)
     * @param bool $CheckForBot Determines whether to check if the event was
     *       triggered by a bot and, if so, to ignore the event.(OPTIONAL,
     *       defaults to TRUE)
     * @return bool TRUE if event data was recorded (not considered a duplicate
     *       or triggered by a bot), otherwise FALSE.
     */
    private function recordEventData(
        $EventTypeId,
        $DataOne = null,
        $DataTwo = null,
        $UserId = null,
        $DedupeTime = 10,
        $CheckForBot = true
    ) {
        # if we should check if the event was triggered by a bot
        if ($CheckForBot) {
            # exit if event appears to be triggered by a bot
            $SignalResult = $GLOBALS["AF"]->SignalEvent(
                "BotDetector_EVENT_CHECK_FOR_BOT"
            );
            if ($SignalResult === true) {
                return false;
            }
        }

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # use ID of currently-logged-in user if none supplied
        if ($UserId === null) {
            if ($User->Id()) {
                $UserId = $User->Id();
            }
        }

        # if deduplication time specified
        if ($DedupeTime > 0) {
            $Query = "SELECT COUNT(*) AS RowCount FROM MetricsRecorder_EventData"
                    ." WHERE EventType = ".intval($EventTypeId)
                    ." AND EventDate >=  FROM_UNIXTIME('".(time() - $DedupeTime)."')";
            if ($DataOne !== null) {
                $Query .= " AND DataOne = '".addslashes($DataOne)."'";
            }
            if ($DataTwo !== null) {
                $Query .= " AND DataTwo = '".addslashes($DataTwo)."'";
            }
            if ($UserId !== null) {
                $Query .= " AND UserId = ".intval($User->Id());
            }
            if (isset($_SERVER["REMOTE_ADDR"]) && ($_SERVER["REMOTE_ADDR"] != "::1")) {
                $Query .= " AND IPAddress = INET_ATON('"
                        .addslashes($_SERVER["REMOTE_ADDR"])."')";
            }
            $RowCount = $this->DB->query($Query, "RowCount");
            if ($RowCount > 0) {
                return false;
            }
        }

        # build query and save event info to database
        $Query = "INSERT INTO MetricsRecorder_EventData SET"
                ." EventDate = NOW(), "
                ." EventType = ".intval($EventTypeId);
        if ($DataOne !== null) {
            $Query .= ", DataOne = '".addslashes($DataOne)."'";
        }
        if ($DataTwo !== null) {
            $Query .= ", DataTwo = '".addslashes($DataTwo)."'";
        }
        if ($UserId !== null) {
            $Query .= ", UserId = ".intval($User->Id());
        }
        if (isset($_SERVER["REMOTE_ADDR"]) && ($_SERVER["REMOTE_ADDR"] != "::1")) {
            $Query .= ", IPAddress = INET_ATON('"
                    .addslashes($_SERVER["REMOTE_ADDR"])."')";
        }
        $this->DB->query($Query);

        # retrieve ID of recorded event (for possible use in annotations)
        $EventId = $this->DB->getLastInsertId();

        # signal to give other code a chance add event annotations
        $SignalResults = $GLOBALS["AF"]->SignalEvent(
            "MetricsRecorder_EVENT_RECORD_EVENT",
            [
                "EventType" => $EventTypeId,
                "DataOne" => $DataOne,
                "DataTwo" => $DataTwo,
                "UserId" => $UserId,
            ]
        );

        # if annotation data was returned by signal
        if (count($SignalResults)) {
            # for each annotation
            foreach ($SignalResults as $Annotator => $Annotation) {
                # if annotation supplied
                if ($Annotation !== null) {
                    # look up ID for annotator
                    $AnnotatorId = $this->DB->queryValue(
                        "SELECT AnnotatorId"
                            ." FROM MetricsRecorder_Annotators"
                            ." WHERE AnnotatorName = '".addslashes($Annotator)."'",
                        "AnnotatorId"
                    );

                    # if no annotator ID found
                    if (!$this->DB->NumRowsSelected()) {
                        # add ID for annotator
                        $this->DB->query("INSERT INTO MetricsRecorder_Annotators"
                                ." SET AnnotatorName = '".addslashes($Annotator)."'");
                        $AnnotatorId = $this->DB->getLastInsertId();
                    }

                    # save annotation to database
                    $this->DB->query("INSERT INTO MetricsRecorder_EventAnnotations"
                            ." (EventId, AnnotatorId, Annotation) VALUES ("
                            .$EventId.", ".$AnnotatorId.", "
                            ."'".addslashes($Annotation)."')");
                }
            }
        }

        # report to caller that event was stored
        return true;
    }

    /**
     * Retrieve count data for click events (URL clicks, full record page
     * views, etc).
     * @param int $ClickType Event type.
     * @param string $StartDate Beginning of date range (in any format
     *       parseable by strtotime()), or NULL for no lower bound to range.
     * @param string $EndDate Beginning of date range (in any format
     *       parseable by strtotime()), or NULL for no upper bound to range.
     * @param int $Offset Beginning offset into count data results.
     * @param int $Count Number of count data results to retrieve.(Pass
     *       in NULL to retrieve all results.A count must be specified
     *       if an offset greater than zero is specified.)
     * @param array|PrivilegeSet $PrivsToExclude Users with these privileges will
     *       be excluded from the results.(OPTIONAL, defaults to empty array)
     * @param string $AddedClause Additional SQL conditional clause (without
     *       leading AND).(OPTIONAL)
     * @param string $AddedTable Additional table(s) to include in query,
     *       usually in conjunction with $AddedClause.(OPTIONAL)
     * @return array Associative array of count data, with "Counts" containing
     *       array of full record view counts, indexed by resource IDs and
     *       sorted in descending order of view counts, "Total" containing the
     *       sum of all those counts, and "StartDate" and "EndDate" containing
     *       dates of first and last recorded views.
     */
    private function getClickCounts(
        $ClickType,
        $StartDate,
        $EndDate,
        $Offset,
        $Count,
        $PrivsToExclude = [],
        $AddedClause = null,
        $AddedTable = null
    ) {
        # normalize privilege exclusion info if necessary
        if ($PrivsToExclude instanceof PrivilegeSet) {
            $PrivsToExclude = $PrivsToExclude->getPrivileges();
        }

        # build query from supplied parameters
        $DBQuery = "SELECT ED.DataOne, COUNT(ED.DataOne) AS Clicks"
                ." FROM MetricsRecorder_EventData AS ED";
        if ($AddedTable) {
            $DBQuery .= ", ".$AddedTable;
        }
        $QueryConditional = " WHERE ED.EventType = ".$ClickType;
        if ($StartDate) {
            if (strtotime($StartDate) === false) {
                throw new InvalidArgumentException("Unparseable start date.");
            }
            $StartDate = date("Y-m-d H:i:s", strtotime($StartDate));
            $QueryConditional .= " AND ED.EventDate >= '".addslashes($StartDate)."'";
        }
        if ($EndDate) {
            if (strtotime($EndDate) === false) {
                throw new InvalidArgumentException("Unparseable end date.");
            }
            $EndDate = date("Y-m-d H:i:s", strtotime($EndDate));
            $QueryConditional .= " AND ED.EventDate <= '".addslashes($EndDate)."'";
        }
        if (count($PrivsToExclude)) {
            $QueryConditional .= " AND (ED.UserId IS NULL OR ED.UserId NOT IN ("
                    .\ScoutLib\User::getSqlQueryForUsersWithPriv($PrivsToExclude)."))";
        }
        if ($AddedClause) {
            $QueryConditional .= " AND ".$AddedClause;
        }
        $DBQuery .= $QueryConditional." GROUP BY ED.DataOne ORDER BY Clicks DESC";
        if ($Count) {
            $DBQuery .= " LIMIT ";
            if ($Offset) {
                $DBQuery .= intval($Offset).", ";
            }
            $DBQuery .= intval($Count);
        }

        # retrieve click counts from database
        $this->DB->query($DBQuery);
        $Data["Counts"] = $this->DB->fetchColumn("Clicks", "DataOne");

        # sum up counts
        $Data["Total"] = 0;
        foreach ($Data["Counts"] as $ResourceId => $Count) {
            $Data["Total"] += $Count;
        }

        # retrieve earliest click date
        $DBQuery = "SELECT ED.EventDate FROM MetricsRecorder_EventData AS ED"
                .($AddedTable ? ", ".$AddedTable : "")
                .$QueryConditional." ORDER BY ED.EventDate ASC LIMIT 1";
        $Data["StartDate"] = $this->DB->query($DBQuery, "EventDate");

        # retrieve latest click date
        $DBQuery = "SELECT ED.EventDate FROM MetricsRecorder_EventData AS ED"
                .($AddedTable ? ", ".$AddedTable : "")
                .$QueryConditional." ORDER BY ED.EventDate DESC LIMIT 1";
        $Data["EndDate"] = $this->DB->query($DBQuery, "EventDate");

        # return click data to caller
        return $Data;
    }

    /**
     * Map custom event owner and type names to numerical event type.
     * @param string $OwnerName Name of event owner.
     * @param mixed $TypeName Name or (for native events) ID of event type.
     * @return int Numerical event type.
     * @throws Exception If event type was not found.
     */
    private function getEventTypeId($OwnerName, $TypeName)
    {
        # if event is native
        if ($OwnerName == "MetricsRecorder") {
            # return ID unchanged
            return $TypeName;
        }

        # error out if event was not registered
        if (!in_array($OwnerName.$TypeName, $this->CustomEventTypes)) {
            throw new Exception("Unknown event type ("
                    .$OwnerName." - ".$TypeName.")");
        }

        # load custom event type data (if not already loaded)
        static $TypeIds;
        if (!isset($TypeIds)) {
            $TypeIds = [];
            $this->DB->query("SELECT * FROM MetricsRecorder_EventTypeIds");
            while ($Row = $this->DB->fetchRow()) {
                $TypeIds[$Row["OwnerName"].$Row["TypeName"]] = $Row["TypeId"];
            }
        }

        # if event type is not already defined
        if (!isset($TypeIds[$OwnerName.$TypeName])) {
            # find next available event type ID
            $HighestTypeId = count($TypeIds) ? max($TypeIds) : 1000;
            $TypeId = $HighestTypeId + 1;

            # add ID to local cache
            $TypeIds[$OwnerName.$TypeName] = $TypeId;

            # add event type to custom event type data in database
            $this->DB->query("INSERT INTO MetricsRecorder_EventTypeIds"
                    ." (OwnerName, TypeName, TypeId) VALUES ("
                    ."'".addslashes($OwnerName)."',"
                    ."'".addslashes($TypeName)."',"
                    .$TypeId.")");
        }

        # return numerical event type to caller
        return $TypeIds[$OwnerName.$TypeName];
    }

    /**
     * Get privilege flags to use when deciding which users to exclude from
     * ongoing count updates.
     * @return array Privileges to exclude.
     */
    private function getPrivilegesToExclude()
    {
        if ($GLOBALS["G_PluginManager"]->PluginEnabled("MetricsReporter")) {
            $Reporter = $GLOBALS["G_PluginManager"]->GetPlugin("MetricsReporter");
            $Privs = $Reporter->ConfigSetting("PrivsToExcludeFromCounts");
            if ($Privs instanceof PrivilegeSet) {
                $Privs = $Privs->getPrivileges();
            }
        } else {
            $Privs = [];
        }
        return $Privs;
    }

    private $SqlTables = [
        "SampleData" => "CREATE TABLE IF NOT EXISTS MetricsRecorder_SampleData (
                    SampleDate      TIMESTAMP,
                    SampleType      SMALLINT NOT NULL,
                    SampleValue     INT,
                    INDEX           (SampleDate,SampleType));",
        "EventData" => "CREATE TABLE IF NOT EXISTS MetricsRecorder_EventData (
                    EventId         INT NOT NULL AUTO_INCREMENT,
                    EventDate       TIMESTAMP,
                    EventType       SMALLINT NOT NULL,
                    UserId          INT,
                    IPAddress       INT UNSIGNED,
                    DataOne         BLOB,
                    DataTwo         BLOB,
                    INDEX           Index_I (EventId),
                    INDEX           Index_IpD (IPAddress,EventDate),
                    INDEX           Index_TU (EventType,UserId),
                    INDEX           Index_TDU (EventType,EventDate,UserId),
                    INDEX           Index_TOW (EventType,DataOne(8),DataTwo(8)) );",
        "EventAnnotations" => "CREATE TABLE IF NOT EXISTS MetricsRecorder_EventAnnotations (
                    EventId         INT NOT NULL,
                    AnnotatorId     SMALLINT,
                    Annotation      TEXT,
                    INDEX           (EventId));",
        "Annotators" => "CREATE TABLE IF NOT EXISTS MetricsRecorder_Annotators (
                    AnnotatorId     SMALLINT NOT NULL AUTO_INCREMENT,
                    AnnotatorName   TEXT,
                    INDEX           (AnnotatorId));",
        "EventTypeIds" => "CREATE TABLE IF NOT EXISTS MetricsRecorder_EventTypeIds (
                    OwnerName       TEXT,
                    TypeName        TEXT,
                    TypeId          SMALLINT NOT NULL,
                    INDEX           (TypeId));",
        "SentEmails" => "CREATE TABLE IF NOT EXISTS MetricsRecorder_SentEmails (
                    FromAddr        TEXT,
                    ToAddr          TEXT,
                    Subject         TEXT,
                    LogData         BLOB,
                    DateSent        DATETIME);",
    ];
}
