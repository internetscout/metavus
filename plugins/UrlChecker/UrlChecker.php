<?PHP
#
#   FILE:  UrlChecker.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use InvalidArgumentException;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Plugins\UrlChecker\ConstraintList;
use Metavus\Plugins\UrlChecker\HttpInfo;
use Metavus\Plugins\UrlChecker\InvalidUrl;
use Metavus\Plugins\UrlChecker\Record;
use Metavus\Plugins\UrlChecker\StatusLine;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\PluginCaller;
use ScoutLib\StdLib;

/**
 * Plugin to validate URL field values.
 */
class UrlChecker extends Plugin
{
    /**
     * @const FLAG_OFF_VALUE value used by the Resource class when a flag is off
     */
    protected const FLAG_OFF_VALUE = 0;

    /**
     * @const FLAG_ON_VALUE value used by the Resource class when a flag is on
     */
    protected const FLAG_ON_VALUE = 1;

    /**
     * The timeout value in seconds for URL checking connections.
     */
    public const CONNECTION_TIMEOUT = 5.0;

    /**
     * How long to wait between checking for URLs when there was nothing to
     * check (minutes).
     */
    private const RETRY_TIME_NOTHING_TO_CHECK = 60;

    /**
     * How long to wait for currently queued checks to finish before checking
     * for new URLs (minutes).
     */
    private const RETRY_TIME_CHECKING = 5;

    /**
     * Status value for URLs that have not been checked
     */
    private const STATUS_NOT_CHECKED = -1;

    /**
     * Maximum number of redirects to follow.
     */
    private const MAX_REDIRECTS = 5;

    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Register information about this plugin.
     */
    public function register(): void
    {
        $this->Name = "URL Checker";
        $this->Version = "2.1.27";
        $this->Description = "Periodically validates URL field values."
            ."<i>System Administrator</i> or <i>Collection Administrator</i> privilege "
            ."is required to view the results.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = false;

        $this->CfgSetup["TaskPriority"] = [
            "Type" => "Option",
            "Label" => "Task Priority",
            "Help" => "Priority of the URL checking tasks in the task queue.",
            "AllowMultiple" => false,
            "Options" => [
                ApplicationFramework::PRIORITY_BACKGROUND => "Background",
                ApplicationFramework::PRIORITY_LOW => "Low",
                ApplicationFramework::PRIORITY_MEDIUM => "Medium",
                ApplicationFramework::PRIORITY_HIGH => "High"
            ],
            "Default" => ApplicationFramework::PRIORITY_BACKGROUND,
        ];

        $this->CfgSetup["FieldsToCheck"] = [
            "Type" => "Option",
            "Label" => "Checked Fields",
            "Help" => "Check links in the selected URL fields.",
            "AllowMultiple" => true,
            "OptionsFunction" => [$this, "getFieldsToCheckOptionValues"],
        ];

        $this->CfgSetup["DontCheck"] = [
            "Type" => "Option",
            "Label" => "Exclusion Conditions",
            "Help" => "Don't check the URLs of resources matching any of "
                    ."these conditions.",
            "AllowMultiple" => true,
            "OptionsFunction" => [$this, "getRuleOptionValues"],
        ];

        $this->CfgSetup["VerifySSLCerts"] = [
            "Type" => "Flag",
            "Label" => "Verify SSL Certificates",
            "Help" => "Perform SSL certificate verification when connecting to "
                ."https sites. If outbound SSL connections are not working "
                ."correctly on your server for some reason (e.g. list of root "
                ."CAs is not current), then disabling may avoid spurious "
                ."'Could Not Connect' errors for https sites.",
            "Default" => true,
        ];

        $this->CfgSetup["EnableDeveloper"] = [
            "Type" => "Flag",
            "Label" => "Enable Developer Interface",
            "Help" => "Enable an additional developer interface "
                ."to aid in debugging the plugin.",
            "Default" => false,
        ];

        $this->CfgSetup["EnableChecking"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Enable URL checks",
            "Help" => "Enable automatic URL checking.",
            "Default" => 1,
        ];

        $this->CfgSetup["NumToCheck"] = [
            "Type" => "Number",
            "Label" => "Resources to Check",
            "Help" => "The number of resources to include in a batch of checks",
            "Default" => 250,
        ];

        $this->CfgSetup["ChecksPerDomain"] = [
            "Type" => "Number",
            "Label" => "Checks Per Domain",
            "Help" => "Number of URLs from the same domain to include in a "
                ."batch of checks. Prevents hundreds of checks against the "
                ."same domain in the same batch, which can trigger rate "
                ."limits and bot mitigation on remote sites.",
            "Default" => 5,
            "MinVal" => 1,
        ];

        $this->CfgSetup["CheckDelay"] = [
            "Type" => "Number",
            "Label" => "Check Delay",
            "Help" => "The number of minutes between tasks that start batches"
                ." of URL checks. If the previous batch has not finished, nothing"
                ." new will be queued. If no URLs need to be rechecked, nothing"
                ." new will be queued.",
            "Default" => 15,
            "Units" => "minutes",
        ];

        $this->CfgSetup["InvalidationThreshold"] = [
            "Type" => "Number",
            "Label" => "Invalidation Threshold",
            "Help" => "The number of times a URL check must fail before "
                ."the link is considered invalid.",
            "MinVal" => 1,
            "Default" => 4,
        ];

        $this->CfgSetup["ResourceRecheckTime"] = [
            "Type" => "Number",
            "Label" => "Resource Recheck Time",
            "Help" => "How often to check resources for new URLs and "
                ."recheck URLs where the last check was successful.",
            "MinVal" => 1,
            "Default" => 1,
            "Units" => "days",
        ];

        $this->CfgSetup["ValidUrlRecheckTime"] = [
            "Type" => "Number",
            "Label" => "Valid URL Recheck Time",
            "Help" => "How long to wait between re-checking a URL that "
                ."failed at least one check but has not yet hit the "
                ."Invalidation Threshold.",
            "MinVal" => 1,
            "Default" => 6,
            "Units" => "hours",
        ];

        $this->CfgSetup["InvalidUrlRecheckTime"] = [
            "Type" => "Number",
            "Label" => "Invalid URL Recheck Time",
            "Help" => "How long to wait between re-checking a URL that "
                ."has hit the Invalidation Threshold.",
            "MinVal" => 1,
            "Default" => 7,
            "Units" => "days",
        ];
    }

    /**
     * Create the database tables necessary to use this plugin.
     * @return string|null NULL if everything went OK or an error message otherwise
     */
    public function install(): ?string
    {
        $Result = $this->createTables(self::SQL_TABLES);
        if (!is_null($Result)) {
            return $Result;
        }

        # set default settings
        $this->setConfigSetting("NextNormalUrlCheck", 0);
        $this->setConfigSetting("NextInvalidUrlCheck", 0);

        # default to checking all URL fields
        $FieldsToCheck = [];
        foreach (MetadataSchema::getAllSchemas() as $Schema) {
            $UrlFields = $Schema->getFields(MetadataSchema::MDFTYPE_URL);
            foreach ($UrlFields as $Field) {
                $FieldsToCheck[] = $Field->id();
            }
        }
        $this->setConfigSetting("FieldsToCheck", $FieldsToCheck);

        # set up default release/withhold/autofix actions
        $this->configureDefaultActions();

        return null;
    }

    /**
     * Uninstall the plugin.
     * @return null|string NULL if successful or an error message otherwise
     */
    public function uninstall(): ?string
    {
        return $this->dropTables(self::SQL_TABLES);
    }

    /**
     * Handle plugin initialization.
     * @return null|string NULL on success, error string on failure.
     */
    public function initialize(): ?string
    {
        $this->DB = new Database();
        $this->addAdminMenuEntry(
            "Results",
            "URL Checker Results",
            [ PRIV_COLLECTIONADMIN ]
        );
        $this->addAdminMenuEntry(
            "ConfigureActions",
            "Release/Withhold Configuration",
            [ PRIV_COLLECTIONADMIN ]
        );
        if ($this->getConfigSetting("EnableDeveloper")) {
            $this->addAdminMenuEntry(
                "Results&H=1",
                "Hidden URLs",
                [ PRIV_COLLECTIONADMIN ]
            );
            $this->addAdminMenuEntry(
                "Developer",
                "Developer Support",
                [ PRIV_COLLECTIONADMIN ]
            );
        }

        \Metavus\Record::registerObserver(
            \Metavus\Record::EVENT_SET,
            [$this, "resourceModify"]
        );
        \Metavus\Record::registerObserver(
            \Metavus\Record::EVENT_REMOVE,
            [$this, "resourceDelete"]
        );

        return null;
    }

    /**
     * Hook the events into the application framework.
     * @return array an array of events to be hooked into the application framework
     */
    public function hookEvents(): array
    {
        $Events = [
            "EVENT_FIELD_ADDED" => "addField",
            "EVENT_PRE_FIELD_DELETE" => "removeField",
            "EVENT_PLUGIN_CONFIG_CHANGE" => "handleConfigChange",
            "EVENT_DAILY" => "dailyMaintenance",
        ];

        if ($this->getConfigSetting("EnableChecking")) {
            $Events["EVENT_PERIODIC"] = "queueResourceCheckTasks";
        }

        return $Events;
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Queue tasks to check resource URLs for resources that need to be checked.
     * @return int Returns the amount of time before this should be called again, in
     *      minutes.
     */
    public function queueResourceCheckTasks(): int
    {
        if (!$this->getConfigSetting("EnableChecking")) {
            return self::RETRY_TIME_NOTHING_TO_CHECK;
        }

        # don't waste time and resources if there aren't any URL fields
        if (count($this->getFieldsToCheck()) == 0) {
            return self::RETRY_TIME_NOTHING_TO_CHECK;
        }

        # come back later if there are URLs still being checked
        if ($this->getQueuedTaskCount("checkUrl")) {
            return self::RETRY_TIME_CHECKING;
        }
        if ($this->getQueuedTaskCount("checkResourceUrls")) {
            return self::RETRY_TIME_CHECKING;
        }

        # dump the db cache to lower the chances of popping the memory cap
        Database::clearCaches();

        # Get the list of failing URLs that need to be checked, and the list of
        # resources that are due for a check. This will give us somewhere between
        #  0 and 2 * $NumToCheck elements.
        $Urls = $this->getNextUrlsToBeChecked();
        $Resources = $this->getNextResourcesToBeChecked();

        # If we have anything to do:
        if (count($Urls) > 0 || count($Resources) > 0) {
            # Divide our checks among Urls and Resources, with weighting
            # determined by the number of each check type. If we've got
            # equal numbers of both, then the split will be 50/50. If we've
            # got N Url checks and 2N Resource checks, then 1/3 of the
            # checks will go to URLs and 2/3 to Resources.

            $NumToCheck = $this->getConfigSetting("NumToCheck");
            $PctUrls = count($Urls) / (count($Urls) + count($Resources) );

            $Urls = array_slice(
                $Urls,
                0,
                (int)round($PctUrls * $NumToCheck),
                true
            );
            $Resources = array_slice(
                $Resources,
                0,
                (int)round((1 - $PctUrls) * $NumToCheck),
                true
            );

            # (in the code below, tasks are queued for all Urls / Resources
            # *without* first checking our exclusion rules and skipping tasks
            # for excluded items because the underlying tasks still need to do
            # some bookkeeping in the database)
            foreach ($Urls as $Url) {
                $this->queueTaskToRecheckFailingUrl($Url);
            }

            foreach ($Resources as $ResourceId => $CheckDate) {
                $Resource = new Record($ResourceId, $CheckDate);
                $this->queueResourceCheckTask($Resource);
            }
        }

        return $this->getConfigSetting("CheckDelay");
    }

    /**
     * Perform daily maintenance.
     */
    public function dailyMaintenance(): void
    {
        $this->DB->query(
            "DELETE FROM UrlChecker_TransparentUpgradeResultCache "
            ."WHERE CheckDate < (NOW() - INTERVAL 7 DAY)"
        );
    }

    /**
     * Get information/stats of the various data saved.
     * @return array of various information
     */
    public function getInformation(): array
    {
        $this->removeStaleData();

        $Info = [];

        # database settings
        $Info["EnableDeveloper"] = intval($this->getConfigSetting("EnableDeveloper"));
        $Info["NumToCheck"] = $this->getConfigSetting("NumToCheck");

        # hard-coded settings
        $Info["Timeout"] = self::CONNECTION_TIMEOUT;
        $Info["Threshold"] = $this->getConfigSetting("InvalidationThreshold");

        # the number of resources checked so far
        $this->DB->query("SELECT COUNT(*) as NumChecked FROM UrlChecker_RecordHistory");
        $Info["NumResourcesChecked"] = intval($this->DB->fetchField("NumChecked"));

        # the number of resources that haven't been checked so far (don't count
        # resources with IDs < 0 since they're probably bad)
        $this->DB->query(
            "SELECT COUNT(*) as NumResources"
                ." FROM Records"
                ." WHERE RecordId >= 0"
        );
        $Info["NumResourcesUnchecked"] = intval($this->DB->fetchField("NumResources"))
            - $Info["NumResourcesChecked"];

        # the number of the invalid URLs past the threshold and "not hidden"
        $this->DB->query(
            "SELECT COUNT(*) as NumInvalid"
                ." FROM UrlChecker_UrlHistory"
                ." WHERE Hidden = 0"
                ." AND TimesInvalid >= ".$this->getConfigSetting("InvalidationThreshold")
        );
        $Info["NumInvalid"] = intval($this->DB->fetchField("NumInvalid"));

        # the number of the invalid URLs past the threshold and hidden
        $this->DB->query(
            "SELECT COUNT(*) as NumInvalid"
                ." FROM UrlChecker_UrlHistory"
                ." WHERE Hidden = 1"
                ." AND TimesInvalid >= ".$this->getConfigSetting("InvalidationThreshold")
        );
        $Info["NumInvalidAndHidden"] = intval($this->DB->fetchField("NumInvalid"));

        # the number of possibly invalid urls
        $this->DB->query(
            "SELECT COUNT(*) as NumInvalid"
                ." FROM UrlChecker_UrlHistory"
                ." WHERE TimesInvalid < ".$this->getConfigSetting("InvalidationThreshold")
        );
        $Info["NumPossiblyInvalid"] = intval($this->DB->fetchField("NumInvalid"));

        # the number of "not hidden" invalid URLs for each status code
        $Info["InvalidUrlsForStatusCodes"] = [];
        $this->DB->query(
            "SELECT StatusCode, SchemaId, COUNT(*) as NumInvalid"
                ." FROM UrlChecker_UrlHistory URH"
                ." LEFT JOIN Records R"
                ." ON URH.RecordId = R.RecordId"
                ." WHERE Hidden = 0"
                ." AND TimesInvalid >= ".$this->getConfigSetting("InvalidationThreshold")
                ." GROUP BY StatusCode, SchemaId"
        );
        while (false !== ($Row = $this->DB->fetchRow())) {
            $Key = $Row["SchemaId"]."-".$Row["StatusCode"];
            $Info["InvalidUrlsForStatusCodes"][$Key] = intval($Row["NumInvalid"]);
        }

        # the number of "hidden" invalid URLs for each status code
        $Info["HiddenInvalidUrlsForStatusCodes"] = [];
        $this->DB->query(
            "SELECT StatusCode, SchemaId, COUNT(*) as NumInvalid"
                ." FROM UrlChecker_UrlHistory URH"
                ." LEFT JOIN Records R"
                ." ON URH.RecordId = R.RecordId"
                ." WHERE Hidden = 1"
                ." AND TimesInvalid >= ".$this->getConfigSetting("InvalidationThreshold")
                ." GROUP BY StatusCode, SchemaId"
        );
        // @phpstan-ignore-next-line
        while (false !== ($Row = $this->DB->fetchRow())) {
            $Key = $Row["SchemaId"]."-".$Row["StatusCode"];
            $Info["HiddenInvalidUrlsForStatusCodes"][$Key] = intval($Row["NumInvalid"]);
        }

        # schemas in the results
        $this->DB->query(
            "SELECT DISTINCT SchemaId AS SchemaId"
                ." FROM Records WHERE RecordId IN"
                ." (SELECT RecordId"
                ." FROM UrlChecker_UrlHistory"
                ." WHERE Hidden = 0"
                ." AND TimesInvalid >= ".$this->getConfigSetting("InvalidationThreshold")
                .") ORDER BY SchemaId"
        );
        $Info["SchemaIds"] = $this->DB->fetchColumn("SchemaId");

        $this->DB->query(
            "SELECT DISTINCT SchemaId AS SchemaId "
                ."FROM Records WHERE RecordId IN"
                ." (SELECT RecordId"
                ." FROM UrlChecker_UrlHistory"
                ." WHERE Hidden = 1"
                ." AND TimesInvalid >= ".$this->getConfigSetting("InvalidationThreshold")
                .") ORDER BY SchemaId"
        );
        $Info["HiddenSchemaIds"] = $this->DB->fetchColumn("SchemaId");

        # the last time a check was done
        $this->DB->query(
            "SELECT *"
                ." FROM UrlChecker_RecordHistory "
                ." ORDER BY CheckDate DESC LIMIT 1"
        );
        $Info["DateLastResourceChecked"] = $this->DB->fetchField("CheckDate");

        # the next time a check will be performed
        $Info["DateOfNextCheck"] = $this->getDateOfNextCheck();

        # version information
        $Info["Version"] = $this->Version;
        $Info["MetavusVersion"] = METAVUS_VERSION;
        $Info["PhpVersion"] = PHP_VERSION;

        return $Info;
    }

    /**
     * Check all of the URL metadata field values for the given resource.
     * @param int|Record $ResourceId ID of Resource to check.
     * @param string|null $CheckDate Date resource was last checked.
     */
    public function checkResourceUrls($ResourceId, $CheckDate): void
    {
        if (!$this->getConfigSetting("EnableChecking")) {
            return;
        }

        # bail if the specified resource no longer exists
        if (!Record::itemExists($ResourceId)) {
            return;
        }

        # instantiate resource
        $Resource = is_object($ResourceId) ? $ResourceId
                : new Record($ResourceId, $CheckDate);

        # the URLs for the resource should not be checked
        if ($this->shouldNotCheckUrls($Resource)) {
            # record that the resource was checked
            $this->updateResourceHistory($Resource);

            # clear out the URL history
            $this->DB->query(
                "DELETE FROM UrlChecker_UrlHistory"
                ." WHERE RecordId = ".$Resource->id()
            );

            # don't check any URLs
            return;
        }

        # get the list of fields that we will check for this resource
        $FieldsToCheck = $this->getFieldsToCheck($Resource->getSchemaId());

        # if we have no fields to check, record that this resource was
        # checked and bail
        if (count($FieldsToCheck) == 0) {
            # record that the resource was checked
            $this->updateResourceHistory($Resource);
            return;
        }

        # otherwise, make sure there's enough time to check all the fields
        $TimeRequired = $this->estimateCheckTime($Resource);

        # Note: If TimeRequired is more than our MaxExecTime, then we'll
        # never think we have enough time and will get stuck in a
        # loop of constantly requeueing ourself. To avoid that, cap
        # our TimeRequired at 90% of max execution time. If this
        # really and truly isn't enough, we'll end up orphaned (not
        # great, but better than choking the Task Queue).
        $AF = ApplicationFramework::getInstance();
        $TimeRequired = min(
            $TimeRequired,
            0.9 * $AF->maxExecutionTime()
        );

        # if we're running in the background and are low on time,
        # mark ourselves to be re-queued and stop processing
        if ($AF->isRunningInBackground() &&
            $AF->getSecondsBeforeTimeout() < $TimeRequired) {
            $AF->requeueCurrentTask(true);
            return;
        }

        $this->pruneUrlHistoryForDisabledFields($Resource->id());

        foreach ($FieldsToCheck as $Field) {
            # get all the URLs from this field
            $Urls = $this->getUrlsFromField($Resource, $Field);

            # remove urls from the UrlHistory table if they are no longer
            # associated with this record
            $this->pruneUrlHistory($Resource->id(), $Field->id(), $Urls);

            # get the list of failing urls for this record and field
            $FailingUrls = $this->getFailingUrls($Resource->id(), $Field->id());

            # we don't need to re-check failing URLs here because we process
            # them individually before checking resources
            $Urls = array_diff($Urls, $FailingUrls);

            # check each url from this field
            foreach ($Urls as $Url) {
                $this->checkUrl($Resource->id(), $Field->id(), $Url);
            }
        }

        # record that the resource was checked
        $this->updateResourceHistory($Resource);
    }

    /**
     * Check a given Url, updating failure information in the database based
     *   on the result.
     * @param int $RecordId Resource that owns this Url.
     * @param int $FieldId Field that owns this Url.
     * @param string $Url Url to check.
     */
    public function checkUrl(
        int $RecordId,
        int $FieldId,
        string $Url
    ): void {
        # get the url's http status
        $Start = time();
        $HttpStatusInfo = $this->getHttpInformation($Url);
        $CheckDuration = ceil(time() - $Start);

        # save information about this URL to the database
        $this->recordResultsOfUrlCheck(
            $RecordId,
            $FieldId,
            $Url,
            $HttpStatusInfo,
            $CheckDuration
        );
    }

    /**
     * Get the number of invalid URLs that match the given constraints
     * @param array $Constraints Array of constraints.
     * @return int The number of invalid URLs that match the constraints
     */
    public function getInvalidCount($Constraints = []): int
    {
        $this->removeStaleData();

        return (int)$this->DB->queryValue(
            "SELECT COUNT(*) AS NumInvalid "
            ."FROM UrlChecker_UrlHistory URH "
            ."LEFT JOIN Records R "
            ."ON URH.RecordId = R.RecordId "
            ."WHERE ".$this->sqlConditionFromConstraintList($Constraints),
            "NumInvalid"
        );
    }

    /**
     * Get the invalid URLs that match the given constraints.
     * @param array $Constraints Array of constraints
     * @param string|MetadataField $OrderBy Field by which the URLs should be sorted
     * @param string $OrderDirection Direction in which the URLs should be sorted
     * @param int $Limit How many URLs should be returned
     * @param int $Offset Where the result set should begin
     * @return array An array of InvalidUrl objects
     */
    public function getInvalidUrls(
        $Constraints = [],
        $OrderBy = "StatusCode",
        $OrderDirection = "DESC",
        $Limit = 15,
        $Offset = 0
    ): array {
        $this->removeStaleData();

        # construct 'ORDER BY' clause
        $ValidOrderCols = [
            "RecordId", "FieldId", "TimesInvalid", "Url", "CheckDate",
            "StatusCode", "ReasonPhrase", "FinalUrl", "FinalStatusCode",
            "FinalReasonPhrase", "Hidden"
        ];

        # valid UrlChecker_History table order
        if (is_string($OrderBy) && in_array($OrderBy, $ValidOrderCols)) {
            $OrderBy = "URH.".$OrderBy;
        } elseif ($OrderBy instanceof MetadataField) {
            # valid Resource table order
            $OrderBy = "R.".$OrderBy->dBFieldName();
        } else {
            # otherwise default the StatusCode field of the UrlChecker_History tale
            $OrderBy = "URH.StatusCode";
        }

        # make sure order direction is valid
        if ($OrderDirection != "ASC" && $OrderDirection != "DESC") {
            $OrderDirection = "DESC";
        }

        # get the url data
        $this->DB->query(
            "SELECT URH.* FROM UrlChecker_UrlHistory URH"
            ." LEFT JOIN Records R"
            ." ON URH.RecordId = R.RecordId"
            ." WHERE ".$this->sqlConditionFromConstraintList($Constraints)
            ." ORDER BY ".$OrderBy." ".$OrderDirection
            ." LIMIT ".intval($Limit)
            ." OFFSET ".intval($Offset)
        );

        # create url objects
        $Urls = [];
        foreach ($this->DB->fetchRows() as $Row) {
            $Urls[] = new InvalidUrl($Row);
        }

        return $Urls;
    }

    /**
     * Encode an identifier for a specified Url to use in links.
     * @param int $RecordId Record Id.
     * @param int $FieldId Field Id.
     * @param string $Url Subject Url.
     * @return string Opaque identifier
     */
    public function encodeUrlIdentifier(
        int $RecordId,
        int $FieldId,
        string $Url
    ): string {
        return $RecordId.":".$FieldId.":".md5($Url);
    }

    /**
     * Decode an identifier created by encodeUrlIdentifier().
     * @param string $Identifier Opaque string.
     * @return array Array having keys RecordId, FieldId, and UrlHash.
     * @see encodeUrlIdentifier()
     */
    public function decodeUrlIdentifier(
        string $Identifier
    ): array {
        list($RecordId, $FieldId, $UrlHash) = explode(":", $Identifier);
        $Data = [
            "RecordId" => $RecordId,
            "FieldId" => $FieldId,
            "UrlHash" => $UrlHash,
        ];

        return $Data;
    }

    /**
     * Get the invalid URL that is associated with the given resource and
     * metadata field, or NULL if one doesn't exist.
     * @param int $RecordId Record Id.
     * @param int $FieldId Metadata field Id.
     * @param string $UrlHash Hash of the target URL.
     * @return InvalidUrl|null an InvalidUrl object or NULL
     */
    public function getInvalidUrl(
        int $RecordId,
        int $FieldId,
        string $UrlHash
    ): ?InvalidUrl {
        $this->DB->query(
            "SELECT *"
            ." FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = ".intval($RecordId)
            ." AND FieldId = ".intval($FieldId)
        );

        $Rows = $this->DB->fetchRows();
        if (count($Rows) == 0) {
            return null;
        }

        foreach ($Rows as $Row) {
            if (md5($Row["Url"]) == $UrlHash) {
                return new InvalidUrl($Row);
            }
        }

        return null;
    }

    /**
     * Determine whether or not the resource is "released".
     * @param Record $Resource Resource.
     * @return bool TRUE if the resource is released, FALSE otherwise
     */
    public function isResourceReleased(Record $Resource): bool
    {
        # released resources are those anon users can view
        return $Resource->userCanView(User::getAnonymousUser());
    }

    /**
     * Release a resource using the configured ReleaseAction.
     * @param Record $Resource Resource.
     */
    public function releaseResource(Record $Resource): void
    {
        $ReleaseActions = $this->getConfigSetting("ReleaseConfiguration");
        if (isset($ReleaseActions[$Resource->getSchemaId()])) {
            # actions configured via the UI
            $Resource->applyListOfChanges(
                $ReleaseActions[$Resource->getSchemaId()],
                User::getCurrentUser()
            );
        }
    }

    /**
     * Withhold the given resource using the configured WithholdAction.
     * @param Record $Resource Resource.
     */
    public function withholdResource(Record $Resource): void
    {
        $WithholdActions = $this->getConfigSetting("WithholdConfiguration");
        if (isset($WithholdActions[$Resource->getSchemaId()])) {
            # actions configured via the UI
            $Resource->applyListOfChanges(
                $WithholdActions[$Resource->getSchemaId()],
                User::getCurrentUser()
            );
        }
    }

    /**
     * Apply automatic fix for a given Url.
     * @param string $Identifier Opaque Url Identifier.
     */
    public function autofixUrl($Identifier): void
    {
        $UrlInfo = $this->decodeUrlIdentifier($Identifier);

        $Resource = new Record($UrlInfo["RecordId"]);

        $AutofixActions = $this->getConfigSetting("AutofixConfiguration");
        $Changes = isset($AutofixActions[$Resource->getSchemaId()]) ?
            $AutofixActions[$Resource->getSchemaId()] : [];

        $FieldId = $UrlInfo["FieldId"];
        $Field = MetadataField::getField($FieldId);

        $Url = $this->getInvalidUrl(
            $Resource->id(),
            $FieldId,
            $UrlInfo["UrlHash"]
        );

        if (!is_null($Url) && strlen($Url->FinalUrl)) {
            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_URL:
                    $Changes[] = [
                        "FieldId" => $FieldId,
                        "Op" => Record::CHANGE_SET,
                        "Val" => $Url->FinalUrl
                    ];
                    break;

                case MetadataSchema::MDFTYPE_PARAGRAPH:
                    $Changes[] = [
                        "FieldId" => $FieldId,
                        "Op" => Record::CHANGE_FIND_REPLACE,
                        "Val" => $Url->Url,
                        "Val2" => $Url->FinalUrl,
                    ];
                    break;

                default:
                    throw new Exception(
                        "Unsupported field type: ".$Field->typeAsName()."."
                    );
            }

            # make the change
            $Resource->applyListOfChanges(
                $Changes,
                User::getCurrentUser()
            );

            # and clean out our failure information
            $this->DB->query(
                "DELETE FROM UrlChecker_UrlHistory"
                ." WHERE RecordId = '".$Url->RecordId."'"
                ." AND FieldId = '".$Url->FieldId."'"
                ." AND Url = '".addslashes($Url->Url)."'"
            );
        }
    }

    /**
     * Hide the URL associated with the given resource and metadata field so
     * that it doesn't show up on the results page.
     * @param string $Identifier Url Identifier
     */
    public function hideUrl(string $Identifier): void
    {
        $UrlInfo = $this->decodeUrlIdentifier($Identifier);

        $Url = $this->getInvalidUrl(
            $UrlInfo["RecordId"],
            $UrlInfo["FieldId"],
            $UrlInfo["UrlHash"]
        );

        # bail if no url found
        if (is_null($Url)) {
            return;
        }

        $this->DB->query(
            "UPDATE UrlChecker_UrlHistory"
            ." SET Hidden = 1"
            ." WHERE RecordId = '".$Url->RecordId."'"
            ." AND FieldId = '".$Url->FieldId."'"
            ." AND Url = '".addslashes($Url->Url)."'"
        );
    }

    /**
     * "Unhide" the URL associated with the given resource and metadata field so
     * that it shows up on the results page.
     * @param string $Identifier Url Identifier
     */
    public function unhideUrl(string $Identifier): void
    {
        $UrlInfo = $this->decodeUrlIdentifier($Identifier);

        $Url = $this->getInvalidUrl(
            $UrlInfo["RecordId"],
            $UrlInfo["FieldId"],
            $UrlInfo["UrlHash"]
        );

        # bail if no url found
        if (is_null($Url)) {
            return;
        }

        $this->DB->query(
            "UPDATE UrlChecker_UrlHistory"
            ." SET Hidden = 0"
            ." WHERE RecordId = '".$Url->RecordId."'"
            ." AND FieldId = '".$Url->FieldId."'"
            ." AND Url = '".addslashes($Url->Url)."'"
        );
    }

    /**
     * "Unhide" all the URLs associated with the given record so that they
     *   will show up on the results page.
     * @param \Metavus\Record $Record Subject record.
     */
    public function unhideUrlsForRecord(\Metavus\Record $Record): void
    {
        $FieldsToCheck = $this->getFieldsToCheck($Record->getSchemaId());

        foreach ($FieldsToCheck as $Field) {
            $Urls = $this->getUrlsFromField($Record, $Field);

            # if this field has no urls, move along to the next one
            if (count($Urls) == 0) {
                continue;
            }

            foreach ($Urls as $Url) {
                $Identifier = $this->encodeUrlIdentifier(
                    $Record->id(),
                    $Field->id(),
                    $Url
                );

                $this->unhideUrl($Identifier);
            }
        }
    }

    /**
     * Query the status of a specified URL.
     * @param string $Url URL to look up.
     * @return int HTTP status code from the most recent failing check of the
     *     specified URL or 0 for URLs that have no failures recorded.
     */
    public function getHttpStatusCodeForUrl(string $Url): int
    {
        # NOTE: UrlHistory *ONLY* records failing checks - it does not keep
        # status information for URLs that are currently working
        $StatusCode = $this->DB->queryValue(
            "SELECT FinalStatusCode From UrlChecker_UrlHistory WHERE "
            ."Url='".addslashes($Url)."'",
            "FinalStatusCode"
        );

        return (int)$StatusCode;
    }

    /**
     * Determine if a transparent (automatic, implicit, silent) http-to-https
     * protocol upgrade of a URL will work. "Work" here means that the server
     * supports https with a valid SSL configuration, that the https version
     * of the page replies with 200 OK, and that the reply isn't a "Page Not
     * Found" indicating that the 200 OK was probably a lie. (Transparent
     * upgrades primarily occur in practice when a URL is used to load an
     * embedded resource (image, iframe, object, etc) on a page that was
     * loaded with https from a server that sent the
     * `upgrade-insecure-requests` directive in the `Content-Security-Policy`
     * header. So, <iframe src='http://...'> displayed on an https:// page. In
     * that case, http-to-https protocol upgrades are performed transparently
     * by the browser when loading the resource.)
     * @param string $Url Url to check.
     * @return bool TRUE when the https upgrade appears to work, FALSE
     *     otherwise.
     * @throws Exception if asked to check a non-http url
     // phpcs:ignore
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/upgrade-insecure-requests
     */
    public function checkIfTransparentHttpsUpgradeWorksForUrl(string $Url): bool
    {
        # check that the provided url is actually an http url, error out if not
        if (stripos($Url, "http://") !== 0) {
            throw new Exception(
                "Cannot check non-http URLs for transparent https upgrade."
            );
        }

        # if we have a cached result for this URL, don't repeat the check
        $CachedResult = $this->DB->queryValue(
            "SELECT Result FROM UrlChecker_TransparentUpgradeResultCache "
            ."WHERE Url='".addslashes($Url)."'",
            "Result"
        );
        if ($CachedResult !== null) {
            return (bool)$CachedResult;
        }

        # check the https version of this url
        $Info = $this->getHttpInformation(str_ireplace("http://", "https://", $Url));

        # if the final result was 200 OK and the page does not contain "Page Not Found"
        # messages, then we've succeeded
        $Result = ($Info->FinalStatusCode == 200 &&
                   $this->hasValidContent($Info->FinalUrl)) ? true : false;

        $this->DB->query(
            "INSERT INTO UrlChecker_TransparentUpgradeResultCache (Url, Result, CheckDate) "
                ."VALUES ('".$Url."', ".($Result ? "1" : "0").", NOW())"
        );
        return $Result;
    }

    /**
     * Get the list of records that are due to be checked or re-checked. The
     *   length of the list will be limited by the "Resources to Check" config
     *   setting. The number of checks directed each domain are limited by the
     *   "Checks Per Domain" config setting. Checks per Domain is applied to
     *   both this function and as those from getNextUrlsToBeChecked().
     * @see getNextUrlsToBeChecked()
     * @return array Resources to check [ResourceId => CheckDate]
     */
    public function getNextResourcesToBeChecked(): array
    {
        $this->removeStaleData();

        $FieldsChecked = $this->getFieldsToCheck();

        # if we aren't checking any fields, bail
        if (count($FieldsChecked) == 0) {
            return [];
        }

        # pull out the number of checks we want to do
        $NumToCheck = $this->getConfigSetting("NumToCheck");

        # start with records that have never been checked
        $Resources = $this->getRecordsThatHaveNotBeenCheckedYet($NumToCheck);

        # if we have enough, we can stop
        if (count($Resources) >= $NumToCheck) {
            return $Resources;
        }

        # otherwise add in records that need a recheck
        $Resources += $this->getRecordsThatNeedToBeRechecked(
            $NumToCheck - count($Resources)
        );

        return $Resources;
    }

    /**
     * Get the list of URLs that have at least one failure recorded and should
     *   be re-checked. This will include potentially invalid URLs (i.e. those
     *   that have failed fewer times than our configured Invalidation
     *   Threshold) that were last checked longer than our configured Valid
     *   URL Recheck Time ago. It will also include invalid URLs (i.e. those
     *   that have failed more times than our configured Invalidation
     *   Threshold) that were last checked longer than our configured Invalid
     *   URL Recheck Time ago. Result will be limited by the Resources to
     *   Check setting. The plugin tracks the domains of URLs queued for a
     *   check within a given page load and will only queue one check per
     *   domain. This tracking includes URLs returned by this function as well
     *   as those from getNextResourcesToBeChecked().
     * @see getNextResourcesToBeChecked()
     * @return array InvalidUrl objects to be checked.
     */
    public function getNextUrlsToBeChecked(): array
    {
        $this->removeStaleData();

        $Urls = [];

        $NumToCheck = $this->getConfigSetting("NumToCheck");
        $ChecksPerDomain = $this->getConfigSetting("ChecksPerDomain");

        # construct SQL condition to match URLs we should check
        $ValidCheckTime = date(
            StdLib::SQL_DATE_FORMAT,
            (int)strtotime(
                "-".$this->getConfigSetting("ValidUrlRecheckTime")." hours"
            )
        );
        $InvalidCheckTime = date(
            StdLib::SQL_DATE_FORMAT,
            (int)strtotime(
                "-".$this->getConfigSetting("InvalidUrlRecheckTime")." days"
            )
        );
        $Condition = "("
            ."  TimesInvalid < ".intval($this->getConfigSetting("InvalidationThreshold"))
            ."  AND CheckDate <= '".strval($ValidCheckTime)."'"
            .") OR ("
            ."  TimesInvalid >= ".intval($this->getConfigSetting("InvalidationThreshold"))
            ."  AND CheckDate <= '".strval($InvalidCheckTime)."'"
            .")";

        # examine URLs in chunks of 1000 each
        $BatchSize = 1000;
        $NumUrls = $this->DB->queryValue(
            "SELECT COUNT(*) AS Num FROM UrlChecker_UrlHistory"
            ." WHERE ".$Condition,
            "Num"
        );
        for ($Offset = 0; $Offset < $NumUrls; $Offset += $BatchSize) {
            $this->DB->query(
                "SELECT * FROM UrlChecker_UrlHistory"
                ." WHERE ".$Condition
                ." ORDER BY CheckDate ASC"
                ." LIMIT ".$BatchSize." OFFSET ".$Offset
            );
            $Rows = $this->DB->fetchRows();

            foreach ($Rows as $Row) {
                $Url = new InvalidUrl($Row);
                $Host = parse_url($Url->Url, PHP_URL_HOST);

                if (!isset($this->DomainsQueuedForChecking[$Host])) {
                    $this->DomainsQueuedForChecking[$Host] = 0;
                }

                # if we haven't queued any checks against this host while queuing
                # our current batch of checks, then this one can be queued
                if ($this->DomainsQueuedForChecking[$Host] < $ChecksPerDomain) {
                    $Urls[] = $Url;
                    $this->DomainsQueuedForChecking[$Host] += 1;

                    if (count($Urls) >= $NumToCheck) {
                        return $Urls;
                    }
                }
            }
        }

        return $Urls;
    }

    /**
     * Handle resource modification.
     * @param int $Events \Metavus\Record::EVENT_ values OR'd together.
     * @param \Metavus\Record $Resource Resource that was modified.
     *   (needs to be \Metavus\Record here to distinguish from
     *   \Metavus\Plugins\UrlChecker\Record and because AF passes in a
     *   \Metavus\Record when signaling the events)
     */
    public function resourceModify(int $Events, \Metavus\Record $Resource): void
    {
        # get the list of fields that we will check for this resource
        $FieldsToCheck = $this->getFieldsToCheck($Resource->getSchemaId());

        $this->pruneUrlHistoryForDisabledFields($Resource->id());

        foreach ($FieldsToCheck as $Field) {
            $Urls = $this->getUrlsFromField($Resource, $Field);

            # remove urls no longer associated with this record
            $this->pruneUrlHistory($Resource->id(), $Field->id(), $Urls);
        }
    }

    /**
     * Handle resource deletion.
     * @param int $Events \Metavus\Record::EVENT_ values OR'd together.
     * @param \Metavus\Record $Resource Resource that is about to be deleted.
     *   (needs to be \Metavus\Record here to distinguish from
     *   \Metavus\Plugins\UrlChecker\Record and because AF passes in a
     *   \Metavus\Record when signaling the events)
     */
    public function resourceDelete(int $Events, \Metavus\Record $Resource): void
    {
        $this->DB->query(
            "DELETE FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = '".intval($Resource->id())."'"
        );
        $this->DB->query(
            "DELETE FROM UrlChecker_RecordHistory"
            ." WHERE RecordId = '".intval($Resource->id())."'"
        );
    }

    /**
     * Handle the addition of a new URL field, setting it to check by default.
     * @param int $FieldId ID of field.
     */
    public function addField($FieldId): void
    {
        $FieldsToCheck = $this->getConfigSetting("FieldsToCheck") ?? [];

        $Field = MetadataField::getField($FieldId);
        if ($Field->type() == MetadataSchema::MDFTYPE_URL) {
            $FieldsToCheck[] = $FieldId;
            $this->setConfigSetting("FieldsToCheck", $FieldsToCheck);
        }
    }

    /**
     * Handle the deletion of a metadata field, removing it from the list of
     *   fields to check.
     * @param int $FieldId ID of field.
     */
    public function removeField($FieldId): void
    {
        $FieldsToCheck = $this->getConfigSetting("FieldsToCheck");

        # if we're not checking any fields, bail because there's nothing to do
        if (!$FieldsToCheck) {
            return;
        }

        # if we were checking this field, stop doing so
        $Key = array_search($FieldId, $FieldsToCheck);
        if ($Key !== false) {
            unset($FieldsToCheck[$Key]);
            $this->setConfigSetting("FieldsToCheck", $FieldsToCheck);

            # and clean out any URL history for this field
            $this->DB->query(
                "DELETE FROM UrlChecker_UrlHistory"
                ." WHERE FieldId = '".intval($FieldId)."'"
            );
        }
    }

    /**
     * Handle changes to plugin configuration.
     * @param string $PluginName Name of plugin
     * @param string $ConfigSetting Setting to change.
     * @param mixed $OldValue Old value of setting.
     * @param mixed $NewValue New value of setting.
     */
    public function handleConfigChange(
        $PluginName,
        $ConfigSetting,
        $OldValue,
        $NewValue
    ): void {
        if ($PluginName == $this->Name && $ConfigSetting == "DontCheck") {
            $this->queueUniqueTask(
                "processChangedExclusionRules",
                [],
                ApplicationFramework::PRIORITY_LOW,
                "Remove URL checker data for resources excluded "
                ."by URLChecker rules change"
            );
        }
    }

    /**
     * Process a change in exclusion rules
     */
    public function processChangedExclusionRules(): void
    {
        # Clean out invalid URLs from resources that would now be skipped
        #  by our exclusion rules. This is done to prevent them from
        #  continuing to appear in the Results page after being excluded.
        $DB = new Database();
        $DB->query(
            "SELECT DISTINCT RecordId AS RecordId "
            ."FROM UrlChecker_UrlHistory WHERE StatusCode >= 300"
        );
        $ResourceIds = $DB->fetchRows();

        $SkippedResourceIds = [];

        foreach ($ResourceIds as $Row) {
            $Resource = new Record($Row["RecordId"]);
            if ($this->shouldNotCheckUrls($Resource)) {
                $SkippedResourceIds[] = $Row["RecordId"];
            }
        }

        if (count($SkippedResourceIds) > 0) {
            foreach (array_chunk($SkippedResourceIds, 100) as $Chunk) {
                $DB->query(
                    "DELETE FROM UrlChecker_UrlHistory "
                    ."WHERE RecordId IN (".implode(",", $Chunk).")"
                );
            }
        }
    }

    /**
     * Get list of options to display for the exclusion rule config setting.
     * @return array List of options.
     */
    public function getRuleOptionValues() : array
    {
        $this->loadConfigOptionValues();
        return $this->RuleOptions;
    }

    /**
     * Get list of options to display for the fields to check config setting.
     * @return array List of options.
     */
    public function getFieldsToCheckOptionValues() : array
    {
        $this->loadConfigOptionValues();
        return $this->FieldsToCheckOptions;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------


    /**
     * Store the results of a URL check in the database.
     * @param int $RecordId Resource that owns this Url.
     * @param int $FieldId Field that owns this Url.
     * @param string $Url Url that was checked.
     * @param HttpInfo $HttpStatusInfo Check result produced by
     *   self::getHttpInformation()
     * @param float $CheckDuration How long the check took.
     */
    private function recordResultsOfUrlCheck(
        int $RecordId,
        int $FieldId,
        string $Url,
        HttpInfo $HttpStatusInfo,
        float $CheckDuration
    ) : void {
        # SQL to clear failures history for our Url
        $DeleteHistoryQuery = "DELETE FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = '".$RecordId."'"
            ." AND FieldId = '".$FieldId."'"
            ." AND Url = '".addslashes($Url)."'";

        # remove old failure data, if any, if the url is ok
        if ($HttpStatusInfo->StatusCode == self::STATUS_NOT_CHECKED
                || ($HttpStatusInfo->StatusCode == 200
                && $this->hasValidContent($Url))) {
            $this->DB->query($DeleteHistoryQuery);
            return;
        }

        # if this was a 3xx redirect to a page that is okay
        if ($HttpStatusInfo->StatusCode >= 300 && $HttpStatusInfo->StatusCode < 400 &&
            $HttpStatusInfo->FinalStatusCode == 200) {
            # see if we're just switching http/https or adding/removing www.
            $PrefixToStrip = "%^https?://(www\.)?%";
            $Src = preg_replace($PrefixToStrip, "", $HttpStatusInfo->Url);
            $Dst = preg_replace($PrefixToStrip, "", $HttpStatusInfo->FinalUrl);
            if ($Src == $Dst) {
                $this->DB->query($DeleteHistoryQuery);
                return;
            }
        }

        # start off assuming this is the first failure for this URL
        $TimesInvalid = 1;
        $Hidden = 0;

        # look up existing failure information
        $this->DB->query(
            "SELECT * FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = '".intval($RecordId)."'"
            ." AND FieldId = '".intval($FieldId)."'"
            ." AND Url = '".addslashes($Url)."'"
        );
        $Row = $this->DB->fetchRow();

        # if we found failure info for this URL and we're still getting the
        # same status code
        if ($Row !== false
                && $Row["StatusCode"] == strval($HttpStatusInfo->StatusCode)
                && $Row["FinalStatusCode"] == strval($HttpStatusInfo->FinalStatusCode)) {
            # if the final URL at the end has not changed since last time,
            # increment our failure count and retain the 'hidden' setting
            if ($Row["FinalUrl"] == $HttpStatusInfo->FinalUrl) {
                $TimesInvalid = intval($Row["TimesInvalid"]) + 1;
                $Hidden = intval($Row["Hidden"]);
            } elseif ($Row["StatusCode"][0] == "3" && $HttpStatusInfo->UsesCookies) {
                # if the server uses cookies, and there is a redirect, the
                # URL is likely to change every time a check takes place.
                # we don't want this to reset our failure count every time.
                # thus, increment our failure count if we're being redirected
                # to the same host as last time
                $DbUrl = @parse_url($Row["FinalUrl"], PHP_URL_HOST);
                $NewUrl = @parse_url($HttpStatusInfo->FinalUrl, PHP_URL_HOST);
                if ($DbUrl == $NewUrl) {
                    $TimesInvalid = intval($Row["TimesInvalid"]) + 1;
                    $Hidden = intval($Row["Hidden"]);
                }
            }
        }

        # if the final URL was served with a 200 response but it appears to be
        # an error page, flag the final URL as invalid even though the response was 200
        if ($HttpStatusInfo->FinalStatusCode == 200
                && !$this->hasValidContent($HttpStatusInfo->FinalUrl)) {
            $IsFinalUrlInvalid = 1;
        } else {
            $IsFinalUrlInvalid = 0;
        }

        # delete any existing row and create a new one with updated information
        $this->DB->query("LOCK TABLES UrlChecker_UrlHistory WRITE");
        $this->DB->query($DeleteHistoryQuery);
        $this->DB->query(
            "INSERT INTO UrlChecker_UrlHistory SET"
            ." RecordId = '".intval($RecordId)."',"
            ." FieldId = '".intval($FieldId)."',"
            ." CheckDuration = '".intval($CheckDuration)."',"
            ." Hidden = '".$Hidden."',"
            ." TimesInvalid = ".intval($TimesInvalid).","
            ." Url = '".addslashes($Url)."',"
            ." StatusCode = '".intval($HttpStatusInfo->StatusCode)."',"
            ." ReasonPhrase = '".addslashes($HttpStatusInfo->ReasonPhrase)."',"
            ." IsFinalUrlInvalid = '".$IsFinalUrlInvalid."',"
            ." FinalUrl = '".addslashes($HttpStatusInfo->FinalUrl)."',"
            ." FinalStatusCode = '".intval($HttpStatusInfo->FinalStatusCode)."',"
            ." FinalReasonPhrase = '".addslashes($HttpStatusInfo->FinalReasonPhrase)."'"
        );
        $this->DB->query("UNLOCK TABLES");
    }


    /**
     * Load option values to be used on the config screen.
     */
    private function loadConfigOptionValues(): void
    {
        if ($this->OptionValuesLoaded) {
            return;
        }

        foreach (MetadataSchema::getAllSchemas() as $Schema) {
            $SchemaFields = $Schema->getFields(
                MetadataSchema::MDFTYPE_FLAG |
                    MetadataSchema::MDFTYPE_TIMESTAMP |
                    MetadataSchema::MDFTYPE_URL |
                    MetadataSchema::MDFTYPE_PARAGRAPH
            );

            foreach ($SchemaFields as $Field) {
                $QualifiedFieldName = $Schema->name()." : ".$Field->name();

                switch ($Field->type()) {
                    case MetadataSchema::MDFTYPE_FLAG:
                        $this->RuleOptions[$Field->id().":".self::FLAG_OFF_VALUE] =
                            $QualifiedFieldName
                            ." is set to \"".$Field->flagOffLabel()."\"";
                        $this->RuleOptions[$Field->id().":".self::FLAG_ON_VALUE] =
                            $QualifiedFieldName
                            ." is set to \"".$Field->flagOnLabel()."\"";

                        break;

                    case MetadataSchema::MDFTYPE_TIMESTAMP:
                        $this->RuleOptions[$Field->id().":PAST"] =
                            $QualifiedFieldName." is in the past";
                        break;

                    case MetadataSchema::MDFTYPE_PARAGRAPH:
                        if (!$Field->allowHTML()) {
                            break;
                        }
                        /* fall through */

                    case MetadataSchema::MDFTYPE_URL:
                        $this->FieldsToCheckOptions[$Field->id()] = $QualifiedFieldName;
                        break;
                }
            }
        }

        $this->OptionValuesLoaded = true;
    }

    /**
     * Set up default release/withhold/autofix actions on plugin installation.
     */
    private function configureDefaultActions(): void
    {
        # actions we need to configure
        $Actions = ["Withhold", "Release", "Autofix"];

        $NewSettings = [];

        # configure default actions for each schema
        foreach (MetadataSchema::getAllSchemas() as $Schema) {
            # assume we won't find any fields to set
            $ToSet = null;
            $PublishedVal = null;
            $UnpublishedVal = null;

            # if this schema has a Record Status field containing "Published" and
            # "Extinct" values, configure our actions to toggle those
            # Meant to handle default schemas with no actions configured,
            # not meant for every possible schema
            if ($Schema->getItemIdByName("Record Status") !== false) {
                $Field = $Schema->getItemByName("Record Status");
                $Factory = $Field->getFactory();

                $PublishedId = $Factory->getItemIdByName("Published");
                $DeaccId = $Factory->getItemIdByName("Extinct");

                if ($PublishedId !== false && $DeaccId !== false) {
                    $ToSet = $Field->id();
                    $PublishedVal = $PublishedId;
                    $UnpublishedVal = $DeaccId;
                }
            } elseif ($Schema->getItemIdByName("Release Flag") !== false) {
                # otherwise, if this schema has a Release Flag field, configure our actions
                # to toggle that
                $ToSet = $Schema->getItemIdByName("Release Flag");

                $PublishedVal = "1";
                $UnpublishedVal = "0";
            }

            # for each action, determine the changes we want to make
            foreach ($Actions as $Action) {
                $Changes = [];
                if ($ToSet !== null) {
                    $Changes[] = [
                        "FieldId" => $ToSet,
                        "Op" => Record::CHANGE_SET,
                        "Val" => ($Action == "Withhold") ?
                                $UnpublishedVal : $PublishedVal,
                    ];
                }

                $NewSettings[$Action][$Schema->id()] = $Changes;
            }
        }

        # save the list of changes we've computed
        foreach ($Actions as $Action) {
            $this->setConfigSetting($Action."Configuration", $NewSettings[$Action]);
        }
    }

    /**
     * Convert an array of constraint lists to an SQL condition for use in a
     * WHERE clause. Within each constraint list, constraints are ANDed
     * together. Multiple lists are ORed together.
     * @param array $Constraints Array of constraint lists.
     * @return string SQL for use in a WHERE clause.
     */
    private function sqlConditionFromConstraintList(array $Constraints) : string
    {
        $Conditions = [];
        foreach ($Constraints as $ConstraintList) {
            if (!($ConstraintList instanceof ConstraintList)) {
                throw new Exception(
                    "Invalid constraint in constraint list."
                );
            }
            $Conditions[] = "(".$ConstraintList->toSql().")";
        }

        return "URH.TimesInvalid >= ".$this->getConfigSetting("InvalidationThreshold")
            ." AND (".implode(" OR ", $Conditions).")";
    }

    /**
     * Estimate how long it will take to check all the URLs for a resource.
     * @param Record $Resource Resource for the estimate.
     * @return int Expected number of seconds
     */
    private function estimateCheckTime($Resource): int
    {
        $Fields = $this->getFieldsToCheck($Resource->getSchemaId());

        # pull out the time taken for the checks when they last ran
        $this->DB->query(
            "SELECT Url, CheckDuration FROM UrlChecker_UrlHistory "
            ."WHERE RecordId=".intval($Resource->id())
        );
        $LastFetchDuration = $this->DB->fetchColumn("CheckDuration", "Url");

        # sum up these times, adding on a margin of 5s per url or 30s
        # for urls where we have no timing information
        $Estimate = 0;
        foreach ($Fields as $Field) {
            $Url = $Resource->get($Field);
            $Estimate += (isset($LastFetchDuration[$Url]) ?
                          $LastFetchDuration[$Url] + 5 : 30);
        }

        return $Estimate;
    }

    /**
     * Get the list of records that should be checked where we have never
     * checked a field from that record.
     * @param int $NumToCheck Number of records to retrieve.
     * @return array Resources to check [ResourceId => "N/A"] (where N/A is a
     * placeholder for a check date)
     */
    private function getRecordsThatHaveNotBeenCheckedYet($NumToCheck): array
    {
        $FieldsChecked = $this->getFieldsToCheck();

        # assemble a list of schemas that we are checking
        $SchemasChecked = [];
        foreach ($FieldsChecked as $Field) {
            if (!in_array($Field->schemaId(), $SchemasChecked)) {
                $SchemasChecked[] = $Field->schemaId();
            }
        }

        # start building the list of resources to check
        $Resources = [];

        # iterate over records in batches of 1000
        $BatchSize = 1000;

        # get the list of RecordIds from schemas containing a field that we
        # check where we've never checked a Url from the given record
        # (a LEFT JOIN produces a row for every row in the left table, with NULL
        # filled in for columns where the ON did not match anything in the right
        # table. Because of this, the URH.RecordId IS NULL finds rows in
        # Records that had no corresponding row in URH. Including R.SchemaId
        # restricts us to resources from schemas where we check one or more
        # fields.)
        $Source = "Records R LEFT JOIN UrlChecker_RecordHistory URH"
            ." ON R.RecordId = URH.RecordId";
        $Condition = "URH.RecordId IS NULL"
            ." AND R.RecordId >= 0"
            ." AND R.SchemaId IN (".implode(",", $SchemasChecked).")";

        $NumRecords = $this->DB->queryValue(
            "SELECT COUNT(*) AS Num FROM ".$Source ." WHERE ".$Condition,
            "Num"
        );
        for ($Offset = 0; $Offset < $NumRecords; $Offset += $BatchSize) {
            $this->DB->query(
                "SELECT R.RecordId as RecordId"
                ." FROM ".$Source." WHERE ".$Condition
                ." LIMIT ".$BatchSize." OFFSET ".$Offset
            );
            $RecordIds = $this->DB->fetchColumn("RecordId");

            foreach ($RecordIds as $RecordId) {
                $Record = new Record($RecordId);
                if ($this->shouldCheckDomainsFromRecordUrls($Record)) {
                    $Resources[$RecordId] = "N/A";

                    if (count($Resources) >= $NumToCheck) {
                        return $Resources;
                    }
                }
            }
        }

        return $Resources;
    }

    /**
     * Get the list of records that need to be rechecked.
     * @param int $NumToCheck Number of records to retrieve.
     * @return array Resources to check [ResourceId => CheckDate]
     */
    private function getRecordsThatNeedToBeRechecked(int $NumToCheck): array
    {
        if ($NumToCheck <= 0) {
            throw new InvalidArgumentException(
                "NumToCheck must be positive. "
                ."(Value given was ".$NumToCheck.")"
            );
        }

        # build the list of resources to check
        $Resources = [];

        # iterate over records in batches of 1000
        $BatchSize = 1000;

        # resources that need to be rechecked
        $CheckDate = date(
            StdLib::SQL_DATE_FORMAT,
            (int)strtotime(
                "-".$this->getConfigSetting("ResourceRecheckTime")." days"
            )
        );
        $Condition = "CheckDate <= '".strval($CheckDate)."'";

        $NumRecords = $this->DB->queryValue(
            "SELECT COUNT(*) AS Num FROM UrlChecker_RecordHistory"
            ." WHERE ".$Condition,
            "Num"
        );
        for ($Offset = 0; $Offset < $NumRecords; $Offset += $BatchSize) {
            $this->DB->query(
                "SELECT * FROM UrlChecker_RecordHistory"
                ." WHERE ".$Condition
                ." ORDER BY CheckDate ASC"
                ." LIMIT ".$BatchSize." OFFSET ".$Offset
            );
            $Rows = $this->DB->fetchRows();

            foreach ($Rows as $Row) {
                $Record = new Record($Row["RecordId"]);
                if ($this->shouldCheckDomainsFromRecordUrls($Record)) {
                    $Resources[$Row["RecordId"]] = $Row["CheckDate"] ;

                    if (count($Resources) >= $NumToCheck) {
                        return $Resources;
                    }
                }
            }
        }

        return $Resources;
    }

    /**
     * Determine whether or not the URLs for the given resource should be
     * checked.
     * @param Record $Resource Resource.
     * @return bool TRUE if the URLs should not be checked and FALSE otherwise
    */
    private function shouldNotCheckUrls($Resource): bool
    {
        $Rules = $this->getConfigSetting("DontCheck");

        # if there are no exclusions, then nothing should be excluded
        if (!$Rules) {
            return false;
        }

        # check if the resource matches any of the rules
        foreach ($Rules as $Rule) {
            # parse out the field ID and flag value
            list($FieldId, $Flag) = explode(":", $Rule);

            try {
                $Field = MetadataField::getField(intval($FieldId));
            } catch (Exception $e) {
                # If the ID was invalid, causing an exception to be thrown,
                # move along to the next rule.
                continue;
            }

            # If this rule applies to a field that we couldn't retrieve,
            #  skip it.
            if ($Field->status() != MetadataSchema::MDFSTAT_OK) {
                continue;
            }

            # If this rule applies to a different schema, skip it.
            if ($Field->schemaId() != $Resource->getSchemaId()) {
                continue;
            }

            $Value = $Resource->get($Field);
            if (empty($Value)) {
                $Value = self::FLAG_OFF_VALUE;
            }

            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_FLAG:
                    # the rule matches if the field value equals the flag value
                    # specified in the rule. the checks with empty() are used in case
                    # NULLs are in the database, which are assumed to be "off"
                    if ($Value == $Flag) {
                        return true;
                    }
                    break;
                case MetadataSchema::MDFTYPE_TIMESTAMP:
                    if ($Flag == "PAST" && strtotime($Value) < time()) {
                        return true;
                    }
                    break;
                default:
                    break;
            }
        }

        return false;
    }

    /**
     * Determine if the URLs in a record should be checked based on which
     * domains have already been queued for a check in the current batch. If
     * any of the URLs contain a domain that has exceeded its check threshold
     * then none of the URLs from this record will be checked. (Done this way
     * to avoid needing to keep track of partially checked records.)
     * @param Record $Record Record to check URLs for.
     * @return bool TRUE when the given record should be checked, FALSE otherwise.
     *   when TRUE is returned, domains from this record's URLs will be added to
     *   $this->DomainsQueuedForChecking.
     */
    private function shouldCheckDomainsFromRecordUrls(Record $Record): bool
    {
        # get the list of fields that we will check for this resource
        $FieldsToCheck = $this->getFieldsToCheck($Record->getSchemaId());

        # if we have no fields to check, then we can't have already checked those domains
        if (count($FieldsToCheck) == 0) {
            return false;
        }

        $ChecksPerDomain = $this->getConfigSetting("ChecksPerDomain");

        # list of hosts referenced by this record
        $Domains = [];

        # iterate over all our checkable fields
        foreach ($FieldsToCheck as $Field) {
            $Urls = $this->getUrlsFromField($Record, $Field);

            # nothing to do if field has no URLs
            if (count($Urls) == 0) {
                continue;
            }

            # iterate over URLs in field
            foreach ($Urls as $Url) {
                $Domain = parse_url($Url, PHP_URL_HOST);

                # if we've already queued a URL for checking that has the
                # same domain, then this record should not be queued for checking
                if (isset($this->DomainsQueuedForChecking[$Domain]) &&
                    $this->DomainsQueuedForChecking[$Domain] >= $ChecksPerDomain) {
                    return false;
                }

                # otherwise add this domain to our list
                $Domains[] = $Domain;
            }
        }

        # add the domains from this record to our list of domains that are
        # queued for checking
        foreach ($Domains as $Domain) {
            if (!isset($this->DomainsQueuedForChecking[$Domain])) {
                $this->DomainsQueuedForChecking[$Domain] = 0;
            }
            $this->DomainsQueuedForChecking[$Domain] += 1;
        }

        # report that this record should be checked
        return true;
    }

    /**
     * Extract all the Urls from a given field for a provided record.
     * @param \Metavus\Record $Record Subject record.
     * @param MetadataField $Field Field to search.
     * @return array Extracted Urls
     */
    private function getUrlsFromField(
        \Metavus\Record $Record,
        MetadataField $Field
    ): array {
        $Urls = [];

        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_URL:
                $FieldData = $Record->get($Field);
                if (!is_null($FieldData) && strlen(trim($FieldData)) > 0) {
                    $Urls[] = $FieldData;
                }
                break;

            case MetadataSchema::MDFTYPE_PARAGRAPH:
                if ($Field->allowHtml()) {
                    $Text = $Record->get($Field);

                    $Patterns = [
                        '%<a\b[^>]*\bhref="([^"]+)"%i',
                        '%<a\b[^>]*\bhref=\'([^\'])+\'"%i',
                        '%<img\b[^>]*\bsrc="([^"]+)"%i',
                        '%<img\b[^>]*\bsrc=\'([^\']+)\'%i',
                    ];

                    foreach ($Patterns as $Pattern) {
                        preg_match_all($Pattern, $Text, $Matches);
                        if (count($Matches[1])) {
                            $Urls = array_merge(
                                $Urls,
                                $Matches[1]
                            );
                        }
                    }

                    # go over the list of URLs and prepend the base path to all relative URLs
                    $Urls = array_map(function ($Url) {
                        # parse the current URL to get its components
                        $UrlComponents = parse_url($Url);

                        # do nothing if URL could not be parsed
                        if ($UrlComponents === false) {
                            return $Url;
                        }

                        # check if the current URL is a relative one
                        if ((!isset($UrlComponents["scheme"]) && !isset($UrlComponents["host"]))
                                && isset($UrlComponents["path"])
                                && preg_match('%[a-z0-9_.\%+-]+%i', $UrlComponents["path"]) ) {
                            # prepend the baseURL
                            return ApplicationFramework::getInstance()->baseUrl()
                                .$UrlComponents["path"];
                        }

                        # if the current URL isn't a relative one, then return it as is
                        return $Url;
                    }, $Urls);

                    # filter out URLs that contain an insertion keyword
                    # (e.g., for scaled images)
                    $Urls = array_filter(
                        $Urls,
                        function ($Url) {
                            # if the current URL is not a syntactically valid URL
                            # or it contains an insertion keyword, then filter it out
                            if (!filter_var($Url, FILTER_VALIDATE_URL)
                                    || preg_match("%{{[A-Za-z0-9]+(\|[^}]+)?}}%", $Url)) {
                                return false;
                            }

                            # otherwise, keep it
                            return true;
                        }
                    );
                }
                break;
        }

        return $Urls;
    }

    /**
     * Update the resource history for the given resource.
     * @param Record $Resource The resource for which to update the history.
     */
    private function updateResourceHistory($Resource): void
    {
        $this->DB->query(
            "INSERT INTO UrlChecker_RecordHistory"
            ." (RecordId) VALUES (".$Resource->id().")"
            ." ON DUPLICATE KEY UPDATE CheckDate = CURRENT_TIMESTAMP"
        );
    }

    /**
     * Remove URLs from the UrlHistory table that are no longer associated
     * with a given record and field.
     * @param int $RecordId Record to update history for.
     * @param int $FieldId Field to update history for.
     * @param array $UrlsToKeep List of URLs currently associated with the given
     *   record and field.
     */
    private function pruneUrlHistory(
        int $RecordId,
        int $FieldId,
        array $UrlsToKeep
    ): void {
        # if this field has no urls, clean out all the history entries
        # for this field and record
        if (count($UrlsToKeep) == 0) {
            $this->DB->query(
                "DELETE FROM UrlChecker_UrlHistory"
                ." WHERE RecordId = '".$RecordId."'"
                ." AND FieldId = '".$FieldId."'"
            );
            return;
        }

        # clean out history entries for URLs that are no longer associated
        # with this field and record
        $EscapedUrls = array_map(
            function ($Url) {
                return "'".addslashes($Url)."'";
            },
            $UrlsToKeep
        );
        $this->DB->query(
            " DELETE FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = '".$RecordId."'"
            ." AND FieldId = '".$FieldId."'"
            ." AND Url NOT IN (".implode(",", $EscapedUrls).")"
        );
    }

    /**
     * Delete all history entries for disabled fields and this record.
     * (Not part of pruneUrlHistory() because that function works on a per-field
     * basis and is only called for enabled fields.)
     * @param int $RecordId Record to update history for.
     */
    private function pruneUrlHistoryForDisabledFields(int $RecordId): void
    {
        $this->DB->query(
            "DELETE FROM UrlChecker_UrlHistory"
            ." WHERE RecordId = '".$RecordId."'"
            ." AND FieldId IN ("
            ."  SELECT FieldId FROM MetadataFields WHERE Enabled=FALSE"
            ." )"
        );
    }

    /**
     * Get the list of currently failing URLs for a given record and field.
     * @param int $RecordId Record to check.
     * @param int $FieldId Field to check.
     * @return array Failing URLs.
     */
    private function getFailingUrls(
        int $RecordId,
        int $FieldId
    ): array {
        $this->DB->query(
            "SELECT Url FROM UrlChecker_UrlHistory"
                ." WHERE RecordId = '".$RecordId."'"
                ." AND FieldId = '".$FieldId."'"
        );

        return $this->DB->fetchColumn("Url");
    }



    /**
     * Get status info for a URL, following up to five redirects. If there is
     * no redirection, this will be the status line for the URL. If there are
     * redirects, this will be the status line for the URL and the status line
     * for the last URL after redirection.
     * @param string $Url URL
     * @return HttpInfo Results of check
     */
    private function getHttpInformation($Url): HttpInfo
    {
        # information for the URL
        list($Info, $Redirect) = $this->getHttpInformationWithoutRedirect($Url);

        # information for redirects, if any
        if (!is_null($Redirect)) {
            $FinalUrl = "";
            $FinalInfo = [];

            $MaxIterations = self::MAX_REDIRECTS;
            while (isset($Redirect) && --$MaxIterations >= 0) {
                $FinalUrl = $Redirect;
                list($FinalInfo, $Redirect) =
                    $this->getHttpInformationWithoutRedirect($Redirect);

                $Info->UsesCookies = $Info->UsesCookies || $FinalInfo->UsesCookies;

                if (is_null($Redirect)) {
                    unset($Redirect);
                }
            }

            $Info->FinalUrl = $FinalUrl;
            $Info->FinalStatusCode = $FinalInfo->StatusCode;
            $Info->FinalReasonPhrase = $FinalInfo->ReasonPhrase;
        }

        return $Info;
    }

    /**
     * Get status info for a URL without following redirects.
     * Note that this only supports HTTP and HTTPS.
     * @param string $Url URL
     * @return array Array where the first element is an HttpInfo object
     *   giving the results of the check and the second is a null for
     *   non-redirect responses or a string giving the a destination for
     *   redirects
     */
    private function getHttpInformationWithoutRedirect($Url): array
    {
        $Info = new HttpInfo();

        # blank url (code defaults to self::STATUS_NOT_CHECKED)
        if (!strlen(trim($Url))) {
            return [$Info, null];
        }

        # default to HTTP if not protocol is specified
        if (!@preg_match('/^[a-z]+:/', $Url)) {
            $Url = "http://".$Url;
        }

        # only check HTTP/HTTPS URLs
        if (!@preg_match('/^https?:\/\//', $Url)) {
            return [$Info, null];
        }

        # assume that we can't connect to the URL
        $Info->Url = $Url;
        $Info->StatusCode = 0;

        # make sure there are no spaces in the url and parse it
        $ParsedUrl = @parse_url(str_replace(" ", "%20", $Url));

        if (!$ParsedUrl || !isset($ParsedUrl["host"])) {
            return [$Info, null];
        }

        $HostName = $ParsedUrl["host"];

        # username and password specified in the URL, add to the hostname
        if (isset($ParsedUrl["user"]) && strlen($ParsedUrl["user"]) > 0 &&
            isset($ParsedUrl["pass"]) && strlen($ParsedUrl["pass"]) > 0) {
            $HostName = $ParsedUrl["user"].":".$ParsedUrl["pass"]."@".$HostName;
        }

        # port specified in the URL, so get it out
        if (isset($ParsedUrl["port"])) {
            $Port = intval($ParsedUrl["port"]);
        }

        # HTTPS needs to use the ssl:// protocol with fsockopen
        if (isset($ParsedUrl["scheme"]) && $ParsedUrl["scheme"] == "https") {
            $HostName = "ssl://".$HostName;

            # default to port 443 if no port is specified
            if (!isset($Port)) {
                $Port = 443;
            }
        }

        # default to port 80 if no port specified
        if (!isset($Port)) {
            $Port = 80;
        }

        $Context = stream_context_create([
            "ssl" => [
                "verify_peer" => $this->getConfigSetting("VerifySSLCerts"),
            ],
        ]);

        $Stream = @stream_socket_client(
            $HostName.":".$Port,
            $ErrNo,
            $ErrStr,
            self::CONNECTION_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $Context
        );

        if ($Stream === false) {
            return [$Info, null];
        }

        # construct the path that's going to be GET'ed
        if (isset($ParsedUrl["path"])) {
            $Path = $ParsedUrl["path"];

            if (isset($ParsedUrl["query"])) {
                $Path .= "?".$ParsedUrl["query"];
            }
        } else {
            $Path = "/";
        }

        # basic headers required for HTTP version 1.1
        $RequestHeaders = "GET ".$Path." HTTP/1.1\r\n";
        $RequestHeaders .= "Host: ".$ParsedUrl["host"]."\r\n";

        # set the User-Agent header since some servers erroneously require it
        $RequestHeaders .= "User-Agent: URL-Checker/".$this->Version." "
           ."METAVUS/".METAVUS_VERSION." PHP/".PHP_VERSION."\r\n";

        # some servers erroneously require the Accept header too
        $RequestHeaders .= "Accept: text/html,application/xhtml+xml,"
            ."application/xml;q=0.9,*/*;q=0.8\r\n";

        # final newline to signal that we're done sending headers
        $RequestHeaders .= "\r\n";

        if (false === fwrite($Stream, $RequestHeaders)) {
            # couldn't send anything
            fclose($Stream);
            return [$Info, null];
        }

        # HTTP status line
        if (!feof($Stream) && false !== ($Line = fgets($Stream))) {
            # remove trailing newline from the HTTP status line
            $Line = trim($Line);

            $StatusLine = new StatusLine($Line);
            $Info->StatusCode = $StatusLine->getStatusCode();
            $Info->ReasonPhrase = $StatusLine->getReasonPhrase();
        } else {
            # the server responded with nothing so mark the URL as an internal
            # server error (500)
            fclose($Stream);
            $Info->StatusCode = 500;
            $Info->ReasonPhrase = "Internal Server Error";
            return [$Info, null];
        }

        # this might cause hangs for line > 8KB. trim() removes trailing newline
        while (!feof($Stream) && (($Line = fgets($Stream)) !== false)) {
            $Line = trim($Line);

            # stop before reading any content
            if ($Line == "") {
                break;
            }

            # a Location header
            if (substr($Line, 0, 9) == "Location:") {
                list(, $Location) = explode(":", $Line, 2);
                $Location = ltrim($Location);
            }

            # a Set-Cookie header
            if (substr($Line, 0, 11) == "Set-Cookie:") {
                $Info->UsesCookies = true;
            }
        }

        # given a Location value; need to make sure it's absolute
        if (isset($Location) && strlen($Location) && substr($Location, 0, 4) != "http") {
            # relative path, relative URI, so add in the path info
            if ($Location[0] != "/") {
                $BasePath = isset($ParsedUrl["path"]) ?
                    dirname($ParsedUrl["path"]) : "";
                $Location = $BasePath."/".$Location;
            }

            if (substr($HostName, 0, 6) == "ssl://") {
                $Location = "https://".substr($HostName, 5).$Location;
            } else {
                $Location = "http://".$HostName.$Location;
            }
        }

        return [$Info, isset($Location) ? $Location : null];
    }

    /**
     * Determine if a given URL has valid content, that is, if it doesn't match
     * some rudimentary regular expressions. Checks for "Page Not Found"-type
     * strings.
     * @param string $Url URL
     * @return bool TRUE if the content for the given URL is valid, FALSE otherwise
     */
    private function hasValidContent($Url): bool
    {
        # set up stream options
        $Options = [
            "http" => [
                # set the default protocol version to 1.1, this may cause issues with
                # PHP < 5.3 if the request isn't HTTP 1.1 compliant
                "protocol_version" => 1.1,

                # timeout
                "timeout" => self::CONNECTION_TIMEOUT,

                # set the User-Agent HTTP header since some servers
                # erroneously require it
                "user_agent" => "URL-Checker/".$this->Version." "
                    ."Metavus/".METAVUS_VERSION." PHP/".PHP_VERSION,

                # some servers erroneously require the Accept header too
                "header" => "Accept: text/html,application/xhtml+xml,"
                    ."application/xml;q=0.9,*/*;q=0.8"

                    # try to prevent hangs in feof by telling the server to close the
                    # connection after retrieving all of the content
                    ."\r\nConnection: close",

                # fetch content even when the HTTP status code is not 200
                "ignore_errors" => true,
            ]
        ];

        $Stream = stream_context_create($Options);

        # escape spaces so that we don't mess up the http method header line
        $Url = str_replace(" ", "%20", $Url);

        $Handle = @fopen($Url, "r", false, $Stream);
        if ($Handle === false) {
            return true;
        }

        # sleep for 0.15s to allow some of the content to buffer to avoid having
        # the opening HTML tag not show up in the first fread
        usleep(150000);

        # get the first 8KB and do a basic check to see if the file is HTML.
        # since fread might stop before getting 8KB, e.g., if a packet is
        # received or the server is slow, there is a chance that the file is
        # HTML, but it's opening tag won't have arrived in the first fread, and
        # therefore won't be checked. this should be OK since it probably means
        # the server is really slow and it shouldn't be checked anyway
        $Html = @fread($Handle, 8192);
        if ($Html === false ||
            (stripos($Html, "<html") === false &&
             stripos($Html, "<!doctype html") === false)
        ) {
            return true;
        }

        # this will be used to prevent hangs in feof in case the server doesn't
        # support the Connection header
        $Time = microtime(true);

        # read until the end of the file, the timeout is reached, or if at least
        # 500 KB have been read
        $Failsafe = 1000;
        while (!feof($Handle) && (microtime(true) - $Time) < self::CONNECTION_TIMEOUT
               && strlen($Html) < 512000 && $Failsafe--) {
            $Chunk = @fread($Handle, 8192);
            if ($Chunk === false) {
                return true;
            }
            $Html .= $Chunk;
        }

        fclose($Handle);

        # parse out the title and the body to search within
        $Title = (preg_match('/<title[^>]*>(.*?)<\/title>/is', $Html, $Matches))
            ? trim($Matches[1]) : "" ;
        $Body = (preg_match('/<body[^>]*>(.*?)<\/body>/is', $Html, $Matches))
            ? trim($Matches[1]) : "";
        $Html = $Title." ".$Body;

        # strip out tags that contain data that is probably not HTML
        $Html = preg_replace(
            '/<(script|noscript|style)[^>]*>.*?<\/\1>/is',
            '',
            $Html
        );

        # remove HTML tags so we only have text to search
        $Html = strip_tags($Html);

        if (preg_match('/(file|url|page|document)\s+([^\s]+\s+)?(couldn\'t\s+be|'
            .'could\s+not\s+be|cannot\s+be|can\'t\s+be|was\s+not)\s+found/i', $Html)
        ) {
            return false;
        } elseif (preg_match('/(file|url|page|404|document)\s+not\s+found|'
            .'(http|error)\s+404/i', $Html)
        ) {
            return false;
        } elseif (preg_match('/(couldn\'t|could\s+not|cannot|can\'t)\s+find\s+'
            .'(the|that)\s+(file|url|page|document)/i', $Html)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Queue a task to check non-failing URLs from a resource.
     * @param Record $Resource Resource to be checked.
     */
    private function queueResourceCheckTask(Record $Resource): void
    {
        $TaskDescription =
            "Validate good URLs associated with <a href=\"r".$Resource->id()."\"><i>"
            .$Resource->getMapped("Title")."</i></a>";

        $this->queueUniqueTask(
            "checkResourceUrls",
            [$Resource->id(), $Resource->getCheckDate()],
            $this->getConfigSetting("TaskPriority"),
            $TaskDescription
        );
    }

    /**
     * Queue a task to re-check a failing URL.
     * @param InvalidUrl $Url Url to re-check.
     */
    private function queueTaskToRecheckFailingUrl(
        InvalidUrl $Url
    ): void {
        $TaskDescription = "Re-check failing URL ".$Url->Url
            ." (via Field ".$Url->FieldId." in Record ".$Url->RecordId.")";

        $this->queueUniqueTask(
            "checkUrl",
            [$Url->RecordId, $Url->FieldId, $Url->Url],
            $this->getConfigSetting("TaskPriority"),
            $TaskDescription
        );
    }

    /**
     * Remove any stale data from deleted resources or changed URLs.
     */
    private function removeStaleData(): void
    {
        static $RemovedStaleData = false;

        # so that the following queries are executed only once per load
        if ($RemovedStaleData) {
            return;
        }

        # clean URL history table of data for fields that aren't URL or Paragraph fields
        # (from when field types are changed)
        $this->DB->query(
            "DELETE FROM UrlChecker_UrlHistory WHERE "
            ."FieldId NOT IN (SELECT FieldId FROM MetadataFields "
            ."WHERE FieldType IN ('Url', 'Paragraph')) "
        );

        # remove entries for records that no longer exist
        $this->DB->query(
            "DELETE FROM UrlChecker_UrlHistory WHERE "
            ."RecordId NOT IN (SELECT RecordId FROM Records)"
        );
        $this->DB->query(
            "DELETE FROM UrlChecker_RecordHistory WHERE "
            ."RecordId NOT IN (SELECT RecordId FROM Records)"
        );

        $RemovedStaleData = true;
    }

    /**
     * Get metadata fields that should be checked for broken links, optionally
     *   restricted to a specific schema.
     * @param int $SchemaId Schema restriction (OPTIONAL, default none)
     * @return array of all the metadata fields in the given schema
     */
    private function getFieldsToCheck($SchemaId = null): array
    {
        static $Fields;

        if (!isset($Fields)) {
            $FieldsToCheck = $this->getConfigSetting("FieldsToCheck");

            $Fields = [];
            foreach ($FieldsToCheck as $FieldId) {
                if (MetadataSchema::fieldExistsInAnySchema($FieldId)) {
                    $Fields[] = MetadataField::getField($FieldId);
                }
            }
        }

        $Result = [];
        foreach ($Fields as $Field) {
            if ((is_null($SchemaId) || $Field->SchemaId() == $SchemaId)
                    && $Field->enabled()) {
                $Result[] = $Field;
            }
        }

        return $Result;
    }

    /**
     * Get the date/time that the URL checking method will run.
     * @return string|null Returns the date/time that the URL checking method will run.
     */
    private function getDateOfNextCheck()
    {
        $AF = ApplicationFramework::getInstance();

        # find the URL checking method
        foreach ($AF->getKnownPeriodicEvents() as $PeriodicEvent) {
            $Callback = $PeriodicEvent["Callback"];

            # if its the URL checking method
            if (is_array($Callback)
                && $Callback[0] instanceof PluginCaller
                && $Callback[0]->getCallbackAsText()
                        == "UrlChecker::QueueResourceCheckTasks"
            ) {
                # return the next run date
                return date("Y-m-d H:i:s", $PeriodicEvent["NextRun"]);
            }
        }

        # no next run date
        return null;
    }

    private $DB;

    # domains queued for a check in the current batch of checks
    # (used to prevent a single domain from being repeatedly checked in the
    # same batch)
    private $DomainsQueuedForChecking = [];

    # values for options shown on config screen
    private $OptionValuesLoaded = false;
    private $RuleOptions = [];
    private $FieldsToCheckOptions = [];

    public const SQL_TABLES = [
        "RecordHistory" => "CREATE TABLE IF NOT EXISTS UrlChecker_RecordHistory (
                RecordId       INT,
                CheckDate      TIMESTAMP,
                PRIMARY KEY    (RecordId)
            )",
        "UrlHistory" => "CREATE TABLE IF NOT EXISTS UrlChecker_UrlHistory (
                RecordId            INT,
                FieldId             INT,
                Hidden              INT,
                CheckDate           TIMESTAMP,
                CheckDuration       INT DEFAULT NULL,
                TimesInvalid        INT,
                Url                 TEXT,
                StatusCode          SMALLINT,
                ReasonPhrase        TEXT,
                IsFinalUrlInvalid   INT,
                FinalUrl            TEXT,
                FinalStatusCode     SMALLINT,
                FinalReasonPhrase   TEXT,
                INDEX               Index_RF (RecordId, FieldId)
            )",
        "TransparentUpgradeResultCache" =>
            "CREATE TABLE IF NOT EXISTS UrlChecker_TransparentUpgradeResultCache (
                Url                 TEXT,
                Result              INT,
                CheckDate           TIMESTAMP,
                PRIMARY KEY         (Url(32)),
                INDEX               Index_C (CheckDate)
            )",
    ];
}
