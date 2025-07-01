<?PHP
#
#   FILE:  MetricsRecorder.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use InvalidArgumentException;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\MetricsReporter;
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
use ScoutLib\PluginManager;

/**
 * Plugin for recording usage and system metrics data.
 */
class MetricsRecorder extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set the plugin attributes.At minimum this method MUST set $this->Name
     * and $this->Version.This is called when the plugin is initially loaded.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Metrics Recorder";
        $this->Version = "1.2.16";
        $this->Description = "Plugin for recording usage and web metrics data.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
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
    public function initialize(): ?string
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
    public function install(): ?string
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
    public function uninstall(): ?string
    {
        return $this->dropTables($this->SqlTables);
    }

    /**
     * Declare events defined by this plugin.This is used when a plugin defines
     * new events that it signals or responds to.Names of these events should
     * begin with the plugin base name, followed by "_EVENT_" and the event name
     * in all caps (for example "MyPlugin_EVENT_MY_EVENT").
     * @return array Event names for the index and event types for the values.
     */
    public function declareEvents(): array
    {
        return ["MetricsRecorder_EVENT_RECORD_EVENT" => ApplicationFramework::EVENTTYPE_NAMED];
    }

    /**
     * Hook the events into the application framework.
     * @return array Returns an array of events to be hooked into the
     *      application framework.
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_DAILY" => [
                "dailyPruning",
                "updateCountFields",
                "recordDailySampleData",
            ],
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
     * Update view and click counts.
     * @param string $LastRunAt Timestamp when method was last called.
     * @return void
     */
    public function updateCountFields($LastRunAt): void
    {
        # if no last run time available assume it was 24 hours ago
        if ($LastRunAt == '') {
            $LastRunAt = date(StdLib::SQL_DATE_FORMAT, strtotime("24 hours ago"));
        }

        # get records where we have a view since the last run
        $LastRunAt = $this->DB->escapeString($LastRunAt);
        $this->DB->query(
            "SELECT DISTINCT DataOne AS RecordId FROM MetricsRecorder_EventData"
                ." WHERE EventType = ".self::ET_FULLRECORDVIEW
                ." AND EventDate >= '".$LastRunAt."'"
        );
        $RecordIds = $this->DB->fetchColumn("RecordId");

        foreach ($RecordIds as $RecordId) {
            # skip records that were deleted
            if (!Record::itemExists($RecordId)) {
                continue;
            }

            $Record = Record::getRecord($RecordId);
            # if record has a count field
            if ($Record->getSchema()->fieldExists("Full Record View Count")) {
                # update current view count for resource
                $CurrentCount = $this->getFullRecordViewCount(
                    $Record->id(),
                    $this->getPrivilegesToExclude()
                );
                $Record->set("Full Record View Count", $CurrentCount);
            }
        }

        # get records where we have a url click since the last run
        $this->DB->query(
            "SELECT DISTINCT DataOne AS RecordId FROM MetricsRecorder_EventData"
                ." WHERE EventType = ".self::ET_URLFIELDCLICK
                ." AND EventDate >= '".$LastRunAt."'"
        );
        $RecordIds = $this->DB->fetchColumn("RecordId");

        foreach ($RecordIds as $RecordId) {
            # skip records that were deleted
            if (!Record::itemExists($RecordId)) {
                continue;
            }

            $Record = Record::getRecord($RecordId);
            # if record has a URL field and a count field
            if ($Record->getSchema()->fieldExists("URL Field Click Count")) {
                # update current click count for resource
                $FieldId = $Record->getSchema()->stdNameToFieldMapping("Url");
                if ($FieldId !== null) {
                    $CurrentCount = $this->getUrlFieldClickCount(
                        $Record->id(),
                        $FieldId,
                        $this->getPrivilegesToExclude()
                    );
                    $Record->set("URL Field Click Count", $CurrentCount);
                }
            }
        }
    }

    /**
     * Record periodically-sampled data.
     * @param string $LastRunAt Timestamp when method was last called.
     * @return void
     */
    public function recordDailySampleData($LastRunAt): void
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
     * @return void
     */
    public function dailyPruning($LastRunAt): void
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
     * @return void
     */
    public function recordUserLogin($UserId, $Password): void
    {
        $this->recordEventData(self::ET_USERLOGIN, null, null, $UserId);
    }

    /**
     * Record new user being added.
     * @param int $UserId ID of user that was added.
     * @param string $Password Password entered by user.
     * @return void
     */
    public function recordNewUserAdded($UserId, $Password): void
    {
        $this->recordEventData(self::ET_NEWUSERADDED, null, null, $UserId);
    }

    /**
     * Record new user account being verified.
     * @param int $UserId ID of user that was verified.
     * @return void
     */
    public function recordNewUserVerified($UserId): void
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
     * @return void
     */
    public function recordSearch(
        SearchParameterSet $SearchParameters,
        array $SearchResults
    ): void {
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
     * @return void
     */
    public function recordOAIRequest($RequesterIP, $QueryString): void
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
     * @return void
     */
    public static function recordFullRecordViewOnCacheHit($ResourceId): void
    {
        MetricsRecorder::getInstance()
            ->recordFullRecordView($ResourceId);
    }

    /**
     * Record user viewing full resource record page for cache misses,
     *   registering a callback to also record data on cache hits.
     * @param int $ResourceId ID of resource.
     * @return void
     */
    public function recordFullRecordViewOnCacheMiss($ResourceId): void
    {
        $AF = ApplicationFramework::getInstance();
        $this->recordFullRecordView($ResourceId);

        $AF->registerCallbackForPageCacheHit(
            [__CLASS__, "recordFullRecordViewOnCacheHit"],
            [$ResourceId]
        );
    }

    /**
     * Record user viewing full resource record page.
     * @param int $ResourceId ID of resource.
     * @return void
     */
    public function recordFullRecordView($ResourceId): void
    {
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
    }

    /**
     * Record user clicking on a Url metadata field value.
     * @param int $ResourceId ID of resource.
     * @param int $FieldId ID of metadata field.
     * @return void
     */
    public function recordUrlFieldClick($ResourceId, $FieldId): void
    {
        $this->recordEventData(self::ET_URLFIELDCLICK, $ResourceId, $FieldId);
    }


    # ---- CALLABLE METHODS (Administrative) ---------------------------------

    /**
     * Register custom event type as valid.Recording or retrieving data with
     * an owner and/or type that has not been registered will cause an exception.
     * @param string $Owner Name of event data owner (caller-defined string).
     * @param string $Type Type of event (caller-defined string).
     * @return void
     */
    public function registerEventType($Owner, $Type): void
    {
        # add type to list
        $this->CustomEventTypes[] = $Owner.$Type;
    }

    /**
     * Remove events recorded with a specified IP address.The starting and/or
     * ending date can be specified in any format parsable by strtotime().
     * @param string $IPAddress Address to remove for, in dotted-quad format.
     * @param string $StartDate Starting date/time of period (inclusive) for
     *       which to remove events.(OPTIONAL, defaults to NULL, which imposes
     *       no starting date)
     * @param string $EndDate Ending date/time of period (inclusive) for
     *       which to remove events.(OPTIONAL, defaults to NULL, which imposes
     *       no ending date)
     * @return void
     */
    public function removeEventsForIPAddress(
        $IPAddress,
        $StartDate = null,
        $EndDate = null
    ): void {
        $Query = "DELETE FROM MetricsRecorder_EventData"
                ." WHERE IPAddress = INET_ATON('"
                .addslashes($IPAddress)."')";
        if ($StartDate !== null) {
            $StartDate = strtotime($StartDate);
            if ($StartDate === false) {
                throw new InvalidArgumentException("Unparseable start date.");
            }
            $Query .= " AND EventDate >= '"
                    .date(StdLib::SQL_DATE_FORMAT, $StartDate)."'";
        }
        if ($EndDate !== null) {
            $EndDate = strtotime($EndDate);
            if ($EndDate === false) {
                throw new InvalidArgumentException("Unparseable end date.");
            }
            $Query .= " AND EventDate <= '"
                    .date(StdLib::SQL_DATE_FORMAT, $EndDate)."'";
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
    ): bool {
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
     * @return void
     */
    public static function logSentMessage(Email $Email, array $LogData): void
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
    ): array {
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
    ): array {
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
    ): array {
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
    ): array {
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
    public function getFullRecordViewCount($ResourceId, $PrivsToExclude = []): int
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
    ): int {
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
     * parameters may be in any format parsable by strtotime().
     * @param int $FieldId ID of URL metadata field.
     * @param string $StartDate Beginning of date range (in any format
     *       parsable by strtotime()), or NULL for no lower bound to range.
     * @param string $EndDate Beginning of date range (in any format
     *       parsable by strtotime()), or NULL for no upper bound to range.
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
    ): array {
        $Field = MetadataField::getField($FieldId);
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
     *       parsable by strtotime()), or NULL for no lower bound to range.
     * @param string $EndDate Beginning of date range (in any format
     *       parsable by strtotime()), or NULL for no upper bound to range.
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
    ): array {
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
     * @return void
     */
    private function recordSample($SampleType, $SampleValue): void
    {
        $this->DB->query("INSERT INTO MetricsRecorder_SampleData SET"
                ." SampleDate = NOW(),"
                ." SampleType = ".intval($SampleType).","
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
    ): bool {
        $AF = ApplicationFramework::getInstance();

        # if we should check if the event was triggered by a bot
        if ($CheckForBot) {
            # exit if event appears to be triggered by a bot
            $SignalResult = $AF->signalEvent(
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
            if ($User->id()) {
                $UserId = $User->id();
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
                $Query .= " AND UserId = ".intval($User->id());
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
            $Query .= ", UserId = ".intval($User->id());
        }
        if (isset($_SERVER["REMOTE_ADDR"]) && ($_SERVER["REMOTE_ADDR"] != "::1")) {
            $Query .= ", IPAddress = INET_ATON('"
                    .addslashes($_SERVER["REMOTE_ADDR"])."')";
        }
        $this->DB->query($Query);

        # retrieve ID of recorded event (for possible use in annotations)
        $EventId = $this->DB->getLastInsertId();

        # signal to give other code a chance add event annotations
        $SignalResults = $AF->signalEvent(
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
     *       parsable by strtotime()), or NULL for no lower bound to range.
     * @param string $EndDate Beginning of date range (in any format
     *       parsable by strtotime()), or NULL for no upper bound to range.
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
    ): array {
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
            $StartDate = strtotime($StartDate);
            if ($StartDate === false) {
                throw new InvalidArgumentException("Unparsable start date.");
            }
            $StartDate = date("Y-m-d H:i:s", $StartDate);
            $QueryConditional .= " AND ED.EventDate >= '".addslashes($StartDate)."'";
        }
        if ($EndDate) {
            $EndDate = strtotime($EndDate);
            if ($EndDate === false) {
                throw new InvalidArgumentException("Unparsable end date.");
            }
            $EndDate = date("Y-m-d H:i:s", $EndDate);
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
    private function getEventTypeId($OwnerName, $TypeName): int
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
    private function getPrivilegesToExclude(): array
    {
        $PluginManager = PluginManager::getInstance();

        if ($PluginManager->pluginReady("MetricsReporter")) {
            $Reporter = MetricsReporter::getInstance();
            $Privs = $Reporter->getConfigSetting("PrivsToExcludeFromCounts");
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
