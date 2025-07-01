<?PHP
#
#   FILE:  WhatCMS.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\ControlledName;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Record;
use Metavus\SearchEngine;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

/**
 * Use the WhatCMS technology detection API (see whatcms.org) to detect which
 * CMS, and other technologies such as web server software and programming
 * languages are being utilized by websites whose URLs are specified by
 * configured fields on schemas' resources.
 * Run a daily task that detects what CMS (and other technology) is being
 * used at websites specified by URLs in one or more configured URL fields
 * on records that match a set of check conditions. The values returned for
 * the URLs on matching records are stored in the configured fields on those
 * records.
 */
class WhatCMS extends Plugin
{
    #  WhatCMS API requests use this URL
    const API_BASE_URL = "https://whatcms.org/API/Tech";
    const API_STATUS_URL = "https://whatcms.org/API/Status";

    # values from https://whatcms.org/API/List
    # and the list of social media networks on the WhatCMS API documentation
    const VALID_RESULT_CATEGORIES = [
        "Blog",
        "CMS",
        "Other CMS",
        "Message Board",
        "Website Builder",
        "Wiki",
        "E-commerce",
        "Landing Page Builder",
        "Issue Tracker",
        "Static Site Generator",
        "Editor",
        "Learning Management",
        "Web Framework",
        "Documentation",
        "Document Management",
        "Web Server",
        "CDN",
        "Programming Language",
        "Operating System",
        "Database",
        "Social/Twitter",
        "Social/Facebook",
        "Social/Instagram",
        "Social/LinkedIn",
        "Social/YouTube",
    ];

    const API_RESPONSE_SUCCESS = 200;
    const RECHECK_INTERVALS_TO_KEEP_UNCHECKED_URLS = 10;

    const ALLOWED_TARGET_FIELD_TYPES = [
        MetadataSchema::MDFTYPE_TEXT,
        MetadataSchema::MDFTYPE_PARAGRAPH,
        MetadataSchema::MDFTYPE_CONTROLLEDNAME,
        MetadataSchema::MDFTYPE_OPTION,
    ];

    const ALLOWED_TARGET_FIELD_TYPES_MESSAGE = "Allowed target field types ".
            "include Text, Paragraph, Controlled Name, and Option.";

    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set the plugin attributes.
     */
    public function register(): void
    {
        $this->Name = "WhatCMS";
        $this->Version = "1.0.1";
        $this->Description = "Detects the CMS in use by a remote site using ".
                "the WhatCMS API.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
        ];
        $this->EnabledByDefault = false;

        # no API key by default
        $this->CfgSetup["APIKey"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "API Key",
            "Help" => "API key obtained from ".
                    "<a href='https://whatcms.org/API'>WhatCMS</a>.",
            "ValidateFunction" => [$this, "validateWhatcmsApiKey"]
        ];

        $this->CfgSetup["CheckConditions"] = [
            "Type" => FormUI::FTYPE_SEARCHPARAMS,
            "Label" => "Check Conditions",
            "Help" => "Specify records to check.",
        ];

        $this->CfgSetup["RecheckInterval"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Recheck Interval",
            "Help" => "How often to recheck a given URL with WhatCMS.",
            "Default" => 8,
            "MinVal" => 1,
            "Units" => "weeks"
        ];

        $this->CfgSetup["QueryInterval"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Query Interval",
            "Help" => "Minimum amount of time to wait between API requests.",
            "Units" => "seconds",
            "Default" => 10
        ];

        $this->CfgSetup["Actions"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Actions",
            "Help" =>  "Specify actions that determine which pieces of "
                    ."information the plugin will retrieve from the WhatCMS "
                    ."API and store into records' fields. "
                    ."This setting consists of one or more blocks that "
                    ."consist of a fully qualified URL field, "
                    ."followed by one or more actions, each represented by "
                    ."pairs of lines that specify a result category and a "
                    ."metadata field."
                    ."The first line of a block begins with "
                    ."\"Url:\" followed by the fully qualified name of the "
                    ."field that a URL will be read from for CMS detection. "
                    ."Each pair of lines that follows the URL line in a block "
                    ."defines an action"
                    ."The first line of each action should begin with "
                    ."\"Result:\" followed by a WhatCMS result category (e.g. "
                    ."CMS, Web Server, Programming Language, Social/Facebook, "
                    ."Social/Instagram, etc) to extract from the WhatCMS API's "
                    ."response when checked for a record's URL in the \"Url\""
                    ."field specified for the block. The second line in each "
                    ."action should begin with \"Field:\", followed by the "
                    ."fully qualified name of the field that you want to be "
                    ."populated by the result from WhatCMS named in the "
                    ."action. ".self::ALLOWED_TARGET_FIELD_TYPES_MESSAGE
                    ." Please note that the Resource schema "
                    ."does NOT include the schema name in fully qualified "
                    ."names. All other schemas require the schema name, for "
                    ."example, the Description field in the Pages schema has "
                    ."the fully-qualified name: \"Page: Description\". \n "
                    ."(For a more comprehensive listing of result categories, "
                    ."see the <a href='https://whatcms.org/Technologies'> "
                    ."WhatCMS Technologies page.</a>)",
            "Rows" => 15,
            "ValidateFunction" => [$this, "validateActions"]
        ];

        $this->CfgSetup["ExcludedDomains"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Excluded Domains",
            "Help" =>  "Specify domain names to be excluded from checking "
                    ."through the WhatCMS API. Enter items in a list "
                    ."seperated by spaces or one per line.",
            "Rows" => 15,
        ];

        $this->CfgSetup["ExcludedHosts"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Excluded Hosts",
            "Help" =>  "Specify hosts to be excluded from checking "
                    ."through the WhatCMS API. Enter items in a list "
                    ."seperated by spaces or one per line.",
            "Rows" => 15,
        ];
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return null|string NULL if installation succeeded, otherwise a string
     *      containing an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        $Result = $this->createTables(self::SQL_TABLES);
        if ($Result !== null) {
            return $Result;
        }

        # populate request info row
        $DB = new Database();
        $DB->query(
            "INSERT INTO WhatCMS_RequestInfo"
            ." (MostRecentQuery, RequestsRemaining)"
            ." VALUES (NULL, NULL)"
        );

        return null;
    }

    /**
     * Initialize the plugin. This is called (if the plugin is enabled) after
     * all plugins have been loaded but before any methods for this plugin
     * (other than register()) have been called.
     * @return null|string NULL if initialization was successful, otherwise a
     *      string or array of strings containing error message(s) indicating
     *      why initialization failed.
     */
    public function initialize(): ?string
    {
        $this->DB = new Database();

        if (is_null($this->getConfigSetting("APIKey"))) {
            return "What CMS API key is not set.";
        }

        return null;
    }

    /**
     * Update the setting that tracks the number of requests remaining on the
     * WhatCMS API key until the period ends and the number of allowed requests
     * is refreshed.
     * If the status endpoint request fails, log an error.
     */
    private function updateRequestsRemaining(): void
    {
        # use the WhatCMS status endpoint to determine if the API key has
        # reached the maximum number of detections allowed for a period
        # see: https://whatcms.org/Documentation#toc-status-endpoint
        $StatusResponse =
                $this->performApiRequest(
                    [ "key" => $this->getConfigSetting("APIKey")],
                    self::API_STATUS_URL
                );

        if (is_null($StatusResponse)) {
            $AF = ApplicationFramework::getInstance();
            $AF->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "WhatCMS: API status endpoint check for remaining requests "
                        ."check failed."
            );
            return;
        }

        if (!array_key_exists("result", $StatusResponse)
                || !array_key_exists(
                    "period_remaining",
                    $StatusResponse["result"]
                )
        ) {
            $AF = ApplicationFramework::getInstance();
            $AF->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "WhatCMS: API status endpoint check for remaining requests "
                ."check is missing the number of requests left in the "
                ."period."
            );
            return;
        }
        $RequestsLeftInPeriod =
                (int) $StatusResponse["result"]["period_remaining"];

        $this->DB->query(
            "UPDATE WhatCMS_RequestInfo"
            ." SET RequestsRemaining = ".$RequestsLeftInPeriod
        );
    }

    /**
     * Hook event callbacks into the application framework.
     * @return array Events to be hooked into the application framework.
     */
    public function hookEvents(): array
    {
        $Events = [
            "EVENT_DAILY" => "performDailyMaintenance",
            "EVENT_PLUGIN_CONFIG_CHANGE"  => "clearAllCheckedUrls"
        ];
        return $Events;
    }

    /**
     * Perform any work needed when the plugin is uninstalled.
     * @return null|string NULL if uninstall succeeded, otherwise a string
     *       containing an error message indicating why uninstall failed.
     */
    public function uninstall(): ?string
    {
        return $this->dropTables(self::SQL_TABLES);
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Remove all URLs from the list of checked URLs.
     * The extra parameters in the method signature are there because this
     * method is intended to be called by EVENT_PLUGIN_CONFIG_CHANGE.
     * @param string $PluginName The name of the plugin that had a configuration
     *         setting changed.
     * @param string $ConfigSetting The name of the configuration setting that
     *         changed.
     * @param mixed $OldValue is the old configuration setting value.
     * @param mixed $NewValue is the new configuration setting value.
     */
    public function clearAllCheckedUrls(
        string $PluginName,
        string $ConfigSetting,
        $OldValue,
        $NewValue
    ) : void {
        if ($PluginName != $this->Name) {
            return;
        }
        if (in_array($ConfigSetting, ["Actions", "CheckConditions"])) {
            $this->DB->query("DELETE FROM WhatCMS_CheckedUrls;");
        }
    }

    /**
     * Each day: Prepare a list of IDs of records with URLs to submit to the
     * WhatCMS technology detection API, then queue a task to submit the URLs on
     * those records to the WhatCMS technology detection API.
     */
    public function performDailyMaintenance(): void
    {
        $this->pruneCheckedUrls();

        # use API status endpoint to find out how many requests remain for this
        # API key
        $this->updateRequestsRemaining();

        $this->queueRecordsForChecking();

        $AF = ApplicationFramework::getInstance();
        $AF->queueUniqueTask(
            [$this, "checkQueuedRecords"],
            [],
            ApplicationFramework::PRIORITY_LOW,
            "Check for records with URLs to run CMS detection against."
        );
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Get record IDs from the record queue and make requests of the WhatCMS
     * technology detection API on their URLs as specified by the Actions
     * setting. Run until remaining execution time is less than the minimum
     * time to check the URLs on the next record to check plus 30 seconds, at
     * which point, requeue the task if there are still records to
     * check.
     */
    public function checkQueuedRecords(): void
    {
        $AF = ApplicationFramework::getInstance();

        # do not run the task if the API key is not set
        if (is_null($this->getConfigSetting("APIKey"))) {
            return;
        }

        # get ID of record from the check queue table
        $this->DB->query(
            "SELECT RecordId from WhatCMS_RecordCheckQueue"
        );

        $RecordIds = $this->DB->fetchColumn("RecordId");
        $Actions = $this->getActions();
        $UrlFieldIds = array_keys($Actions);
        $QueryInterval = $this->getConfigSetting("QueryInterval");

        foreach ($RecordIds as $RecordId) {
            $RecordUrls =
                    $this->getUrlsToCheckForRecord($RecordId, $UrlFieldIds);

            # ensure there is enough time to check the URLs on the record
            $MinimumCheckTime = count($RecordUrls) * $QueryInterval;

            if ($AF->getSecondsBeforeTimeout() < 30 + $MinimumCheckTime) {
                $AF->requeueCurrentTask();
                return;
            }

            # ensure enough API requests remain to check the URLs on the record
            $RequestsRemaining = $this->DB->query(
                "SELECT RequestsRemaining FROM WhatCMS_RequestInfo",
                "RequestsRemaining"
            );
            if (count($RecordUrls) > $RequestsRemaining) {
                $AF->logMessage(
                    ApplicationFramework::LOGLVL_ERROR,
                    "WhatCMS: Cannot perform CMS detection for record "
                            ."(Id=".$RecordId.", with ".count($RecordUrls)
                            ." URLs) because the API Key has "
                            .$RequestsRemaining
                            ." requests remaining."
                );
                return;
            }

            $this->checkUrlsForRecord($RecordId, $RecordUrls, $Actions);
        }
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
    private $DB;

    /**
     * Add records to the queue for checking with the WhatCMS technology
     * detection API that match the search parameters specified by the
     * CheckConditions setting.
     */
    private function queueRecordsForChecking(): void
    {
        $SearchParameters = $this->getConfigSetting("CheckConditions");
        $Engine = new SearchEngine();

        $SearchResults = $Engine->search($SearchParameters);
        $ItemIds = array_keys($SearchResults);

        $this->DB->query("LOCK TABLE WhatCMS_RecordCheckQueue WRITE");

        $this->DB->query("SELECT RecordId from WhatCMS_RecordCheckQueue");

        $RecordIdsInQueue = $this->DB->fetchColumn("RecordId");

        # determine which record IDs to check are not already present in the
        # record check queue table
        $RecordIdsToAddToCheckQueue = array_diff($ItemIds, $RecordIdsInQueue);

        if (count($RecordIdsToAddToCheckQueue) > 0) {
            $this->DB->insertArray(
                "WhatCMS_RecordCheckQueue",
                "RecordId",
                $RecordIdsToAddToCheckQueue
            );
        }
        $this->DB->query("UNLOCK TABLES");
    }

    /**
     * Return the URLs to be checked for a record.
     * @param int $RecordId ID of the record to find URLs to check for.
     * @param array $UrlFieldIds ID of each URL field that is to be checked.
     * @return array Array indexed on URL field ID, where the value for each
     *         URL field ID is a URL the record has to be checked by the
     *         WhatCMS API.
     */
    private function getUrlsToCheckForRecord(
        int $RecordId,
        array $UrlFieldIds
    ) : array {
        $Record = Record::getRecord($RecordId);
        $SchemaIdForRecord = $Record->getSchemaId();

        $UrlsToCheckForRecord = [];

        foreach ($UrlFieldIds as $UrlFieldId) {
            $UrlField = MetadataField::getField($UrlFieldId);
            $UrlFieldSchemaId = $UrlField->schemaId();

            if ($UrlFieldSchemaId != $SchemaIdForRecord) {
                # the record is not in the same schema as this URL field,
                # move on to the next one
                continue;
            }

            $Url = $Record->get($UrlField);

            # if the record has no URL value for this field, skip detection
            if (is_null($Url)) {
                continue;
            }

            if ($this->urlWasRecentlyChecked($Url)) {
                continue;
            }

            $ParsedHost = parse_url($Url, PHP_URL_HOST);
            if (!is_string($ParsedHost)) {
                continue;
            }

            $ExcludedHosts = $this::getExcludedHosts();
            if (in_array($ParsedHost, $ExcludedHosts)) {
                continue;
            }

            $ParsedDomain = implode(".", array_slice(explode(".", $ParsedHost), -2));
            $ExcludedDomains = $this::getExcludedDomains();
            if (in_array($ParsedDomain, $ExcludedDomains)) {
                continue;
            }

            $UrlsToCheckForRecord[$UrlFieldId] = $Url;
        }
        return $UrlsToCheckForRecord;
    }

    /**
     * Check URLs in a record's configured field(s) using the WhatCMS
     * technology detection API if the URL(s) have not been checked since the
     * configured recheck interval.
     * @param int $RecordId ID of record to check URL(s) for.
     * @param array $UrlsToCheckForRecord Array indexed on URL field IDs with
     *        values that are URLs to be checked for the specified record by the
     *        WhatCMS API.
     * @param array $Actions Array indexed on URL field ID.
     *         The value under each URL field ID is an array indexed on result
     *         category, the value under each result category is an array of one
     *         or more target metadata field IDs that will receive the WhatCMS
     *         result value for that result category.
     */
    private function checkUrlsForRecord(
        int $RecordId,
        array $UrlsToCheckForRecord,
        array $Actions
    ): void {
        $Record = Record::getRecord($RecordId);

        foreach ($Actions as $UrlFieldId => $ActionsForUrlField) {
            if (!array_key_exists($UrlFieldId, $UrlsToCheckForRecord)) {
                continue;
            }

            # get the list of desired results
            $DesiredResults = array_keys($ActionsForUrlField);

            # if query interval has not elapsed, sleep until it has
            $this->ensureQueryIntervalHasElapsedSinceLastQuery();

            $Url = $UrlsToCheckForRecord[$UrlFieldId];
            $Response = $this->checkUrl($Url, $DesiredResults);

            # don't save if response is a failure
            if (count($Response) == 0) {
                # if the check didn't succeed, there is nothing to save
                continue;
            }
            $this->saveResultsForRecord(
                $RecordId,
                $Response,
                $ActionsForUrlField
            );
        }

        # record has been checked, remove it from the check queue
        $this->DB->query("DELETE FROM WhatCMS_RecordCheckQueue ".
                "WHERE RecordId = ".$RecordId);
    }

    /**
     * Send an HTTP GET request to the WhatCMS technology detection API
     * for a specified URL. Check for and log errors from network
     * failure, invalid JSON, or those reported by the WhatCMS API.
     * @param string $Url URL to request WhatCMS detection for.
     * @return array|null array Response data (as JSON) from the WhatCMS API
     *         request that was sent, NULL if an error occurred.
     */
    private function queryWhatcmsAboutUrl(string $Url) : ?array
    {
        $AF = ApplicationFramework::getInstance();

        $QueryData = [
            "key" => $this->getConfigSetting("APIKey"),
            "url" => $Url
        ];

        $ResponseJson =
                $this->performApiRequest($QueryData, self::API_BASE_URL);

        if (is_null($ResponseJson)) {
            # request was not successful or could not be parsed as JSON
            return null;
        }

        # Count this query against the total allowed requests for the API key.
        # If a response is returned for a detection request, but the response
        # from WhatCMS has a unsuccessful result code (if the detecton fails),
        # it still counts against the allowed detection request total for the
        # API key.

        $this->DB->query(
            "UPDATE WhatCMS_RequestInfo SET RequestsRemaining = RequestsRemaining - 1"
        );

        $WhatcmsResponseCode = $ResponseJson["result"]["code"];
        if ($WhatcmsResponseCode != self::API_RESPONSE_SUCCESS) {
            $SkipLoggingCodes = [201, 202];
            if (!in_array($WhatcmsResponseCode, $SkipLoggingCodes)) {
                # WhatCMS indicates that the request was not successful
                # codes and messages from WhatCMS API:
                # https://whatcms.org/Documentation#toc-result-codes
                $AF->logMessage(
                    ApplicationFramework::LOGLVL_ERROR,
                    "WhatCMS: API response not successful. code: ".
                        $WhatcmsResponseCode." message: ".
                        $ResponseJson["result"]["msg"]
                );
            }

            # if the response says that we've exceeded our quota, then
            # set our RequestsRemaining to zero
            $ExceededQuotaCodes = [121, 125];
            if (in_array($WhatcmsResponseCode, $ExceededQuotaCodes)) {
                $this->DB->query(
                    "UPDATE WhatCMS_RequestInfo SET RequestsRemaining = 0"
                );
            }

            # if we've been rate limited, insert an additional pause
            # (free plans allow 1 URL for per 10s, so a 10s pause should
            # always be enough)
            $RateLimitedCodes = [120, 124];
            if (in_array($WhatcmsResponseCode, $ExceededQuotaCodes)) {
                sleep(10);
            }

            return null;
        }
        return $ResponseJson;
    }

   /**
    * Make a GET request to an API at the URL specified with the provided query
    * data and return the resopnse as JSON. Log an error if the request is
    * unsuccessful or if the result can not be parsed as JSON.
    * @param array $QueryData. Array containing keys and values to be included
    *         in the GET request.
    * @param string $BaseUrl The base of the URL to make the query to with the
    *         provided query data.
    * @return array|NULL API response parsed as JSON, or NULL if the resposne
    *         is not successful or the result can not be parsed as JSON.
    */
    private function performApiRequest(array $QueryData, string $BaseUrl)
    : ?array
    {
        $AF = ApplicationFramework::getInstance();

        $this->DB->query(
            "UPDATE WhatCMS_RequestInfo SET MostRecentQuery = NOW()"
        );

        $FullUrl = $BaseUrl."?".http_build_query($QueryData);

        static $Context;
        if (!isset($Context)) {
            $Context = curl_init();
        }

        # CURLOPT_HEADER option added so the header is included in the output
        # so the response status code can be obtained
        # RETURNTRANSFER option has cURL return the response as a string
        # as opposed to outputting it directly
        curl_setopt_array($Context, [
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $FullUrl
        ]);

        $CurlResponse = curl_exec($Context);
        if ($CurlResponse === false) {
            # cURL exec failed
            $ErrNo = curl_errno($Context);
            $ErrMsg = curl_error($Context);
            $AF->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "WhatCMS: Unable to make cURL request. "
                ."cURL error: ".$ErrMsg
                ."cURL error number: ".$ErrNo." "
            );
            return null;
        }

        # header size is used to slice the header off of the cURL response
        # to obtain the HTTP status code
        $HeaderSize = curl_getinfo($Context, CURLINFO_HEADER_SIZE);

        if (!is_string($CurlResponse)) {
            throw new Exception("cURL response is not a string.");
        }

        $Headers = substr($CurlResponse, 0, $HeaderSize);

        # get the first line of the HTTP response header
        $StatusLine = strtok($Headers, "\n");

        # if strtok is handed an empty string, it returns false
        if ($StatusLine === false) {
            $AF->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "WhatCMS: cURL request got a response that is missing headers."
            );
            return null;
        }

        # expect the status line to contain the HTTP version, followed by a
        # space, then a 3-digit status code
        $StatusLineRegex = '/^HTTP\/[0-9.]+ ([0-9]+)/';
        if (!(preg_match($StatusLineRegex, $StatusLine, $StatusCode))) {
            $AF->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "WhatCMS: cURL response header is missing status code."
            );
            return null;
        }

        # first element of the preg_match matches array contains the text that
        # matches the full pattern (with spaces), the second element contains
        # the first captured parenthesized subpattern, the status code sans
        # whitespace
        $StatusCode = (int)$StatusCode[1];

        if ($StatusCode != self::API_RESPONSE_SUCCESS) {
            $AF->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "WhatCMS: cURL request returned non-200 response: ".
                        "status header: ".$StatusLine
            );
            return null;
        }

        # exclude headers, then the rest of the response *is* valid JSON
        $CurlResponseJson = substr($CurlResponse, $HeaderSize);

        $ResponseJson = json_decode($CurlResponseJson, true);
        if (is_null($ResponseJson)) {
            $AF->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "WhatCMS: API response could not be decoded as JSON."
            );
        }
        return $ResponseJson;
    }

    /**
     * Query the WhatCMS technology detection API to gather and return
     * specified information about which CMS (and other technology) the site at
     * a specified URL is using, as well as any social media links reported by
     * WhatCMS.
     * @param string $Url URL to query WhatCMS technology detection API for.
     * @param array $DesiredResultTypes List of strings that specify results
     *         from the WhatCMS technology detection API.
     *         Valid result categories are enumerated in
     *         self::VALID_RESULT_CATEGORIES.
     * @return Array of WhatCMS API results indexed on the result categories
     *         specified by $DesiredResultTypes if the request is successful.
     *         Returns an empty array if the request is not successful.
     */
    private function checkUrl(string $Url, array $DesiredResultTypes): array
    {
        # request from the WhatCMS API
        $Response = $this->queryWhatcmsAboutUrl($Url);

        if ($Response === null) {
            # error occurred with the request
            return [];
        }
        $WhatcmsResults = $this->parseWhatcmsResponse($Response);

        $DesiredResults = [];
        foreach ($DesiredResultTypes as $ResultCategory) {
            if (array_key_exists($ResultCategory, $WhatcmsResults)) {
                $DesiredResults[$ResultCategory] =
                        $WhatcmsResults[$ResultCategory];
            }
        }

        # result was successful, mark the URL as having been checked
        $this->markUrlAsChecked($Url);

        return $DesiredResults;
    }

    /**
     * Make a record that a URL has been checked with the WhatCMS technology
     * detection API at the current time.
     */
    private function markUrlAsChecked(string $Url): void
    {
        $this->DB->query("LOCK TABLE WhatCMS_CheckedUrls WRITE");

        $this->DB->query(
            "UPDATE WhatCMS_CheckedUrls SET TimeLastChecked = NOW() ".
                    "WHERE Url = '".addslashes($Url)."';'"
        );

        if ($this->DB->numRowsAffected() == 0) {
            # UPDATE did not affect any rows, so this URL has not yet been
            # checked, add it to the table
            $this->DB->query(
                "INSERT INTO WhatCMS_CheckedUrls (Url, TimeLastChecked) ".
                        "VALUES  ('".addslashes($Url)."', NOW())"
            );
        }

        $this->DB->query("UNLOCK TABLES");
    }

    /**
     * Check if the amount of time configured for the query interval has passed
     * since the last WhatCMS API call. Sleep for the difference if the time
     * since the last API call is less than the query interval.
     */
    private function ensureQueryIntervalHasElapsedSinceLastQuery(): void
    {
        $LastCheckTime = $this->DB->query(
            "SELECT MostRecentQuery FROM WhatCMS_RequestInfo",
            "MostRecentQuery"
        );

        if ($LastCheckTime === null) {
            return; # no check has been recorded yet
        }

        $QueryInterval = $this->getConfigSetting("QueryInterval");
        $LastCheckTime = strtotime($LastCheckTime);
        $SecondsLeftInInterval = $QueryInterval - (time() - $LastCheckTime);

        if ($SecondsLeftInInterval > 0) {
            sleep($SecondsLeftInInterval);
        }
    }

    /**
     * Read the Actions configuration setting that represents its value.
     * Lines that refer to fields that are invalid as URL or target
     * fields are omitted. (Fields must exist, URL fields must have the type
     * URL, target fields must be in the same schema as the URL field for the
     * section, and must have one of the acceptable field types.
     * Acceptable field types are enumerated in the constant:
     * self::ALLOWED_TARGET_FIELD_TYPES.
     * Option fields must be able to accept multiple values.)
     * Assumes that the structure of Actions has already been validated.
     * @return array Array indexed on IDs of URL fields. The value under each
     *         URL field ID is an array indexed on result category, the value
     *         under each result category is an array of one or more target
     *         metadata field IDs that will receive the WhatCMS result value(s)
     *         for that result category.
     */
    private function getActions(): array
    {
        $UrlFieldId = null;
        $UrlFieldName = null;
        $ResultCategory = null;
        $SkipUntilNextUrl = false;
        $Actions = [];
        $ActionsLines = explode("\n", $this->getConfigSetting("Actions"));

        foreach ($ActionsLines as $Line) {
            if (strlen($Line) == 0) {
                # ignore blank lines
                continue;
            }
            $LineParts = explode(":", $Line, 2);
            $LinePrefix = trim($LineParts[0]);
            $LineContent = trim($LineParts[1]);
            if ($SkipUntilNextUrl) {
                 # if a URL field was deleted or otherwise became invalid,
                 # skip past all of the actions in this block
                 continue;
            }
            switch ($LinePrefix) {
                case "Url":
                    # the name of the URL field for this block  is kept track
                    # of in order to check that all of the target fields in this
                    # block are in the same schema as the URL field
                    $UrlFieldName = $LineContent;

                    if (!is_null($this->checkUrlFieldForErrors($UrlFieldName))) {
                        # if this line has an error on it, don't try to get the
                        # field ID, omit the entire block, skip until the next
                        # URL line
                        $SkipUntilNextUrl = true;
                        break;
                    }
                    $SkipUntilNextUrl = false;
                    $UrlFieldId =
                            MetadataSchema::getCanonicalFieldIdentifier(
                                $UrlFieldName
                            );
                    $Actions[$UrlFieldId] = [];
                    break;
                case "Result":
                    $ResultCategory = $LineContent;
                    break;
                case "Field":
                    $FieldError = $this->checkTargetFieldForErrors(
                        $LineContent,
                        $UrlFieldName
                    );
                    if (!is_null($FieldError)) {
                        # an error has been found with this target field,
                        # skip this action
                        break;
                    }
                    $TargetFieldId =
                            MetadataSchema::getCanonicalFieldIdentifier(
                                $LineContent
                            );

                    if (!isset($Actions[$UrlFieldId][$ResultCategory])) {
                        $Actions[$UrlFieldId][$ResultCategory] = [];
                    }

                    # add the ID of the target field to receive the WhatCMS
                    # result specified by the result category for a URLs in the
                    # URL field specified for the block
                    $Actions[$UrlFieldId][$ResultCategory] [] = $TargetFieldId;
            }
        }
        return $Actions;
    }

    /**
     * Read the ExcludedDomains configuration setting that represents its value.
     * Lines that are blank or only contain whitespace are omitted.
     * @return array Array contains Excluded Domain values
     */
    private function getExcludedDomains(): array
    {
        $DomainLines = $this->getConfigSetting("ExcludedDomains");
        $Domains = preg_split('/\s/', $DomainLines, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($Domains)) {
            return [];
        }

        return $Domains;
    }

    /**
     * Read the ExcludedHosts configuration setting that represents its value.
     * Lines that are blank or only contain whitespace are omitted.
     * @return array Array contains Excluded Host values
     */
    private function getExcludedHosts(): array
    {
        $HostLines = $this->getConfigSetting("ExcludedHosts");
        $Hosts = preg_split('/\s/', $HostLines, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($Hosts)) {
            return [];
        }

        return $Hosts;
    }

    /**
     * Check if time elapsed since the specified URL was checked with the
     * WhatCMS technology detection API is greater than the recheck interval
     * configuration setting.
     * @param string $Url Determine if this URL has been checked within the
     *         recheck interval.
     * @return bool TRUE if the time since the specified URL has last been
     *         checked is less than the configured recheck interval. Otherwise
     *         return FALSE.
     */
    private function urlWasRecentlyChecked(string $Url): bool
    {
        # get the time the URL was last checked.
        $LastChecked = $this->DB->queryValue(
            "SELECT TimeLastChecked from WhatCMS_CheckedUrls"
                ." WHERE Url = '".addslashes($Url)."'",
            "TimeLastChecked"
        );

        # queryValue returns NULL if no value is available
        if (is_null($LastChecked)) {
            return false;
        }

        $LastCheckedTime = strtotime($LastChecked);
        $Interval = $this->getConfigSetting("RecheckInterval");
        $LastCheckPlusInterval =
                strtotime("+".$Interval." weeks", $LastCheckedTime);

        if (time() > $LastCheckPlusInterval) {
            return false;
        }

        return true;
    }

    /**
     * Save WhatCMS technology detection API results to configured fields on
     * a record.
     * @param int $RecordId Record to save WhatCMS API results to.
     * @param array $ApiResponse Array indexed by result category with values
     *         to be saved to fields on the specified record.
     * @param array $FieldActions Array indexed on WhatCMS result category,
     *         each value is an array of metadata field IDs to receive
     *         values from that WhatCMS result category.
     */
    private function saveResultsForRecord(
        int $RecordId,
        array $ApiResponse,
        array $FieldActions
    ): void {
        $TargetRecord = Record::getRecord($RecordId);
        $ValuesToSave = [];
        foreach ($FieldActions as $ResultCategory => $TargetFieldIds) {
            if (!array_key_exists($ResultCategory, $ApiResponse)) {
                # API response does not contain a result in this category
                continue;
            }
            $ValueToSave = $ApiResponse[$ResultCategory];
            foreach ($TargetFieldIds as $TargetFieldId) {
                switch (MetadataField::getField($TargetFieldId)->type()) {
                    case MetadataSchema::MDFTYPE_TEXT:
                    case MetadataSchema::MDFTYPE_PARAGRAPH:
                        $ToSave = implode(", ", $ValueToSave);
                        break;
                    case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                    case MetadataSchema::MDFTYPE_OPTION:
                        # create returns the ID of any existing controlled name
                        # or option for field with the provided value or creates
                        # a new one if one matching the provided value does not
                        # currently exist, then returns the ID of the newly
                        # created controlled name
                        $ToSave = [];
                        foreach ($ValueToSave as $Value) {
                            $CName = ControlledName::create(
                                strval($Value),
                                $TargetFieldId
                            );
                            $ToSave [] = $CName->id();
                        }
                        break;
                    default:
                        throw new Exception(
                            "Encountered unexpected field type."
                        );
                }
                $ValuesToSave[$TargetFieldId] = $ToSave;
            }
        }
        # no invalid fields have been found, save values to record
        foreach ($ValuesToSave as $FieldId => $FieldValue) {
            $TargetRecord->set($FieldId, $FieldValue);
        }
    }

    /**
     * Parse a JSON response from the WhatCMS technology detection API and
     * return an array of results with result category as the index.
     * @param array $JsonResponse JSON response from the WhatCMS API described
     *         in the WhatCMS documentation: https://whatcms.org/Documentation.
     *         The response includes one or more elements for "results". Each
     *         element in "results" includes a value under "name" that names
     *         a technology, like "WordPress", and also includes one or more
     *         values under "categories" that state what type of technology,
     *         the result describes. Any social media results are included under
     *         the "meta" key, results are included. Social media results
     *         include the name of social media network, and the name of and
     *         link to a profile on that network.
     * @return array Results from WhatCMS API response indexed on result
     *         categories. The value under each result category is an array of
     *         the names of one or more technologies in that category. If no
     *         "results" key is present in the response, an empty array is
     *         returned.
     */
    private function parseWhatcmsResponse(array $JsonResponse): array
    {
        if (!array_key_exists("results", $JsonResponse)) {
            return [];
        }

        $ResultsByResultCategory = [];
        foreach ($JsonResponse["results"] as $Result) {
            # a WhatCMS result value may appear under multiple result categories
            # there may be an array of several values under one result category

            # the categories array contains a WhatCMS url, which is discarded
            $Categories = $Result["categories"];
            unset($Categories["url"]);
            $ResultValue = $Result["name"]; # name of detected technology
            foreach ($Categories as $ResultCategory) {
                if (!isset($ResultsByResultCategory[$ResultCategory])) {
                    $ResultsByResultCategory[$ResultCategory] = [];
                }
                $ResultsByResultCategory[$ResultCategory] [] = $ResultValue;
            }
        }
        return $ResultsByResultCategory;
    }

    /**
     * Validate WhatCMS API key.
     * @param string $FieldName Name of config setting being validated.
     * @param string $NewValue Setting value to validate.
     * @return string|null Error message or NULL if no error found.
     */
    public function validateWhatcmsApiKey(string $FieldName, $NewValue)
    : ?string
    {
        if ($NewValue == $this->getConfigSetting("APIKey")) {
            # no need to check the key if it has not changed
            return null;
        }

        # use the WhatCMS API status endpoint to determine if the new API
        # key is valid
        # see: https://whatcms.org/Documentation#toc-status-endpoint
        $StatusResponse =
                $this->performApiRequest(
                    [ "key" => $NewValue ],
                    self::API_STATUS_URL
                );

        if (is_null($StatusResponse)) {
            return "Unable to verify WhatCMS API key.";
        }

        $ResultCode = $StatusResponse["result"]["code"];

        if ($ResultCode !== self::API_RESPONSE_SUCCESS) {
            return "WhatCMS reports that API key is invalid.";
        }

        # use API status endpoint to find out how many requests remain for this
        # API key
        $this->updateRequestsRemaining();

        return null;
    }

    /**
     * Determine if a value is valid to use for the Actions configuration
     * setting. A valid setting for Actions fulfills the following conditions:
     * Lines must begin with either: "Url", "Result" or "Field", followed by a
     * colon.
     * The first line in each block must begin with "Url", and be followed by
     * one or more actions, which consist of pairs of lines where the first
     * begins with "Result", and the second begins with "Field".
     * The first block can followed by additional blocks of the same form.
     * Lines that begin with "Url" must have the fully qualified name of a
     * metadata field with the type URL following the colon.
     * Lines that begin with "Result" must have a valid WhatCMS result category
     * (enumerated in self::VALID_RESULT_CATEGORIES) following the colon.
     * Lines that begin with "Field" must have the fully qualified name of a
     * metadata field of an appropriate type following the colon. The named
     * field must be in the same schema as the Url field specified by the line
     * at the top of the same block.
     * @param string $FieldName Setting name. (This parameter is included
     *         because of the required arguments for a validation callback.)
     * @param string|null $Actions String value for the Actions setting, to
     *         validate according to above described criteria.
     * @return string|null Returns NULL if Actions is free of errors, otherwise
     *        returns an error message.
     */
    public function validateActions(string $FieldName, ?string $Actions)
    : ?string
    {
        $UrlFieldForSection = null;
        $NextLinePrefixMustBe = ["Url"];
        $LineNumber = 0;
        $FieldErrors = [];
        $ActionsLines = explode("\n", $Actions ?? "");
        foreach ($ActionsLines as $Line) {
            $LineNumber++;
            $LineId = "Line ".$LineNumber.": ";
            if (strlen($Line) == 0) {
                # ignore blank lines
                continue;
            }
            # limit the number of parts in the line to 2, colons after the
            # first are included in the line content
            $LineParts = explode(":", $Line, 2);
            if (count($LineParts) != 2) {
                # the line is missing a colon
                return $LineId."Lines must have the form: ".
                         "\"Prefix: Field Name or Result Category\"";
            }
            $LinePrefix = trim($LineParts[0]);
            $LineContent = trim($LineParts[1]);
            if (strlen($LinePrefix) == 0 || strlen($LineContent) == 0) {
                return $LineId."Lines must have the form: ".
                         "\"Prefix: Field Name or Result Category\"";
            }
            # check that the prefix is appropriate based on where the line
            # appears relative to others
            if (!in_array($LinePrefix, $NextLinePrefixMustBe)) {
                $PrefixesPhrase = implode(" or ", $NextLinePrefixMustBe);
                return $LineId." must begin with ".$PrefixesPhrase.".";
            }
            switch ($LinePrefix) {
                case "Url":
                    $NextLinePrefixMustBe = ["Result"];
                    # the URL field for this block is kept track of in order
                    # to check that all of the target fields in the block are
                    # in the same schema as the URL field
                    $UrlFieldForSection = $LineContent;
                    $FieldError =
                            $this->checkUrlFieldForErrors($UrlFieldForSection);
                    if (!is_null($FieldError)) {
                        $FieldErrors [] = $LineId.$FieldError;
                    }
                    break;
                case "Result":
                    if (!in_array(
                        $LineContent,
                        self::VALID_RESULT_CATEGORIES
                    )) {
                        return $LineId."Result category ".$LineContent.
                                " is invalid";
                    }
                    $NextLinePrefixMustBe = ["Field"];
                    break;
                case "Field":
                    $FieldError = $this->checkTargetFieldForErrors(
                        $LineContent,
                        $UrlFieldForSection
                    );
                    if (!is_null($FieldError)) {
                        $FieldErrors [] =  $LineId.$FieldError;
                    }
                    $NextLinePrefixMustBe = ["Result", "Url"];
                    break;
                default:
                    throw new Exception("Unexpected line prefix in Actions.");
            }
        }

        if (count($FieldErrors) > 0) {
            # field errors will be displayed at the top of the plugin
            # configuration page
            $FieldMessages = "Error(s) found with Actions setting metadata ".
                    "field configuration:";
            $FieldMessages .= "<ul>";
            foreach ($FieldErrors as $Error) {
                $FieldMessages .= "<li>".$Error."</li>";
            }
            $FieldMessages .= "</ul>";
            return $FieldMessages;
        }
        return null;
    }

    /**
     * Check that a metadata field exists, and is of an appropriate type.
     * Check that it is in the same schema as the provided fully qualified URL
     * field. (Assumes that the existence and type of the URL field have already
     * been checked.)
     * If the field is an option field, check that it accepts multiple values.
     * Return an error message about the field if any of those conditions are
     * not met.
     * @param string $TargetFieldName Fully qualified name of field (formatted
     *         as <SchemaName>:<FieldName>, with schema name omitted if the
     *         schema is the default Resource schema) to check.
     * @param string $UrlFieldName Fully qualified name of URL field that the
     *         field named in the first argument must be in the same schema as.
     * @return string|null Error message describing why the named field did
     *         not meet the stated conditions or NULL if no error is found.
     */
    private function checkTargetFieldForErrors(
        string $TargetFieldName,
        string $UrlFieldName
    ): ?string {

        if (!MetadataSchema::fieldExistsInAnySchema($TargetFieldName)) {
            return "Specified field ".$TargetFieldName." does not exist.";
        }
        $TargetFieldId =
                MetadataSchema::getCanonicalFieldIdentifier($TargetFieldName);
        $TargetMdField = MetadataField::getField($TargetFieldId);

        $UrlFieldId =
                MetadataSchema::getCanonicalFieldIdentifier($UrlFieldName);
        $UrlField = MetadataField::getField($UrlFieldId);
        $UrlFieldSchemaId = $UrlField->schemaId();
        if ($UrlFieldSchemaId != $TargetMdField->schemaId()) {
            # since getCanonicalFieldIdentifier() will find a field without a
            # schema prefix in any schema, not just the default schema, get the
            # name of the schema the field was found in, rather than restating
            # the provided prefix (or lack thereof)
            $SchemaNames = MetadataSchema::getAllSchemaNames();
            return "Target field ".$TargetFieldName.", found in the "
                   .$SchemaNames[$TargetMdField->schemaId()]." schema, is not "
                   ."in the same schema as the URL field ".$UrlFieldName
                   ." , found in the ".$SchemaNames[$UrlFieldSchemaId]
                   ." schema. (Check that you used fully-qualified field "
                   ."names.)";
        }

        if (!in_array(
            $TargetMdField->type(),
            self::ALLOWED_TARGET_FIELD_TYPES
        )) {
            return "Target field ".$TargetFieldName." does not have one of the".
                    " allowed types. ".self::ALLOWED_TARGET_FIELD_TYPES_MESSAGE;
        }

        if ($TargetMdField->type() == MetadataSchema::MDFTYPE_OPTION &&
                !$TargetMdField->allowMultiple()) {
            return "Target option field ".$TargetFieldName.
                    " does not allow multiple values.";
        }
        return null;
    }

    /**
     * Check that a named fully-qualified metadata field exists, and that its
     * type is URL.
     * Return an error message if the field does not exist or if its type is not
     * URL.
     * @param string $FieldName Fully qualified name of field (formatted as
     *         <SchemaName>:<FieldName>, with Schema Name omitted if the schema
     *         is the default Resource schema) to check.
     * @return string|null Error message indicating that the named field does
     *         not exist or if it is not a URL field.
     */
    private function checkUrlFieldForErrors(string $FieldName): ?string
    {
        if (!MetadataSchema::fieldExistsInAnySchema($FieldName)) {
            return "Specified field ".$FieldName." does not exist.";
        }
        $FieldId = MetadataSchema::getCanonicalFieldIdentifier($FieldName);
        $MdField =  MetadataField::getField($FieldId);

        if ($MdField->type() != MetadataSchema::MDFTYPE_URL) {
            return "Field ".$FieldName." is not a URL field.";
        }

        return null;
    }

    /**
     * Remove URLs from the checked URLs table that have not been checked for
     * at least (RECHECK_INTERVALS_TO_KEEP_UNCHECKED_URLS) times the configured
     * recheck interval.
     */
    private function pruneCheckedUrls(): void
    {
        $Interval = $this->getConfigSetting("RecheckInterval");
        $NumberOfWeeks =
                $Interval * self::RECHECK_INTERVALS_TO_KEEP_UNCHECKED_URLS;
        $Duration = strtotime("-" . ( $NumberOfWeeks ) . " weeks");
        if ($Duration === false) {
            throw new Exception("strtotime has failed unexpectedly");
        }

        # purge where the date the URL was last checked is before the calculated
        # back duration in the past
        $CutoffDate = date(StdLib::SQL_DATE_FORMAT, $Duration);
        $this->DB->query(
            "DELETE FROM WhatCMS_CheckedUrls WHERE ".
                "TimeLastChecked < '". $CutoffDate . "'"
        );
    }

    private const SQL_TABLES = [
        "CheckedUrls" => "CREATE TABLE IF NOT EXISTS WhatCMS_CheckedUrls (
                Url TEXT NOT NULL,
                TimeLastChecked TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "RecordCheckQueue" => "CREATE TABLE IF NOT EXISTS
                WhatCMS_RecordCheckQueue (
                RecordId INT NOT NULL,
                UNIQUE UIndex_R (RecordId))",
        "RequestInfo" => "CREATE TABLE IF NOT EXISTS WhatCMS_RequestInfo (
                MostRecentQuery TIMESTAMP,
                RequestsRemaining INT)",
    ];
}
