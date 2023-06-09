<?PHP
#
#   FILE:  AutoFetch.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2017-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Exception;
use Metavus\File;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\SearchEngine;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

class AutoFetch extends Plugin
{
    /**
     * Register information about this plugin.
     */
    public function register()
    {
        $this->Name = "Auto Fetch";
        $this->Version = "1.0.3";
        $this->Description =
            "Automatic downloading of files referenced in URL fields.";
        $this->Author = "Internet Scout";
        $this->Url = "http://scout.wisc.edu/cwis/";
        $this->Email = "scout@scout.wisc.edu";
        $this->Requires = ["MetavusCore" => "1.0.0"];
        $this->EnabledByDefault = false;

        $this->CfgSetup["TaskPriority"] = [
            "Type" => "Option",
            "Label" => "Task Priority",
            "Help" => "Priority of the Auto Fetch tasks in the task queue.",
            "AllowMultiple" => false,
            "Options" => [
                ApplicationFramework::PRIORITY_BACKGROUND => "Background",
                ApplicationFramework::PRIORITY_LOW => "Low",
                ApplicationFramework::PRIORITY_MEDIUM => "Medium",
                ApplicationFramework::PRIORITY_HIGH => "High"
            ],
            "Default" => ApplicationFramework::PRIORITY_BACKGROUND,
        ];

        $this->CfgSetup["UrlSrcField"] = [
            "Type" => "MetadataField",
            "Label" => "Source Field",
            "Help" => "Url field giving links to files that should be "
                    ."downloaded when they change.",
            "FieldTypes" => MetadataSchema::MDFTYPE_URL,
            "Required" => true,
        ];

        $this->CfgSetup["FileExtensions"] = [
            "Type" => "Paragraph",
            "Label" => "Extensions To Check",
            "Help" => "File extensions to download, separated by spaces, "
                    ."without a leading period.",
            "Default" => "zip pdf doc docx ppt pptx",
        ];

        # we use a paragraph field here because Url fields do not allow
        # multiple entries
        $this->CfgSetup["SrcField"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "Label" => "Additional Sources Field",
            "Help" => "Paragraph field containing any additional URLs that should be "
                    ."downloaded, one per line.  These will be downloaded regardless "
                    ."of file extension. This field is primarily intended to be used "
                    ."when the Source Field above contains a link to a webpage rather "
                    ."than a direct link to a file, but it can also be used to "
                    ."download multiple files.",
            "FieldTypes" => MetadataSchema::MDFTYPE_PARAGRAPH,
        ];
        $this->CfgSetup["DstField"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "Label" => "Destination Field",
            "Help" => "Destination Field into which downloaded files should be placed.",
            "FieldTypes" => MetadataSchema::MDFTYPE_FILE,
        ];
        $this->CfgSetup["NotificationField"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "Label" => "Notification Field",
            "Help" => "Flag Field to toggle when new files are downloaded.",
            "FieldTypes" => MetadataSchema::MDFTYPE_FLAG,
        ];
        $this->CfgSetup["SearchParams"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "Label" => "Search Parameters",
            "Help" => "Search parameters matching the resources that should "
                    ."be checked for files to retrieve.  When left blank, all "
                    ."resources will be checked.",
        ];
        $this->CfgSetup["EnableFetching"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Enable Fetch",
            "Help" => "Enable automatic retriveal of specified files",
            "Default" => 1,
        ];
        $this->CfgSetup["CheckFrequency"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Check Frequency",
            "Help" => "Number of days to wait between checks.",
            "Default" => 7,
            "Units" => "Days",
        ];
        $this->CfgSetup["ErrorLogLifetime"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Log Retention Period",
            "Help" => "Number of days to retain error message.",
            "Default" => 180,
            "Units" => "Days",
        ];

        $this->addAdminMenuEntry(
            "Status",
            "AutoFetch Status",
            [ PRIV_COLLECTIONADMIN, PRIV_SYSADMIN ]
        );
    }

    /**
     * Handle plugin installation.
     * @return null|string NULL if everything went OK or an error message otherwise
     */
    public function install()
    {
        return $this->createTables($this->SqlTables);
    }

    /**
     * Handle plugin upgrades.
     * @return null|string NULL on success, error string otherwise.
     */
    public function upgrade(string $PreviousVersion)
    {
        $DB = new Database();
        if (version_compare($PreviousVersion, "1.0.2", "<")) {
            $DB->query(
                "ALTER TABLE AutoFetch_ResourceUrlInts RENAME AutoFetch_RecordUrlInts"
            );
            $DB->query(
                "ALTER TABLE AutoFetch_RecordUrlInts "
                ."CHANGE COLUMN ResourceId RecordId INT NOT NULL"
            );
        }

        if (version_compare($PreviousVersion, "1.0.3", "<")) {
            $DB->query(
                "ALTER TABLE AutoFetch_Urls "
                ."CHANGE COLUMN LastCheck LastCheck TIMESTAMP DEFAULT NOW()"
            );
        }
        return null;
    }

    /**
     * Handle plugin uninstallation.
     * @return null|string NULL on success, otherwise an error string.
     */
    public function uninstall()
    {
        return $this->dropTables($this->SqlTables);
    }

    /**
     * Handle plugin initialization.
     * @return null|string NULL on success, error string on failure.
     */
    public function initialize()
    {
        if (!MetadataSchema::fieldExistsInAnySchema(
            $this->configSetting("UrlSrcField")
        )) {
            return "A URL source field must be configured.";
        }

        $this->DB = new Database();

        return null;
    }

    /**
     * Tell the ApplicationFramework which events we'd like to hook.
     * @return array Events to hook
     */
    public function hookEvents() : array
    {
        $Events = [
            "EVENT_RESOURCE_FILE_DELETE" => "ClearLogForDeletedFiles",
        ];

        if ($this->configSetting("EnableFetching")) {
            $Events["EVENT_HOURLY"] = "CheckFileUrlsAndPotentiallyFetchThem";
        }

        return $Events;
    }

    /**
     * Clear FetchLog entries for files that were deleted.
     * @param MetadataField $Field Field file is being deleted from.
     * @param Record $Resource Resource file is being deleted from.
     * @param File $File File to be deleted.
     */
    public function clearLogForDeletedFiles($Field, $Resource, $File)
    {
        # if a file has been deleted, also delete the log of when we
        # last fetched it
        $this->DB->query("DELETE FROM AutoFetch_FetchLog WHERE FileId=".$File->id());
    }

    /**
     * Perform periodic checking of monitored URLs, downloading a new
     * copy of the file they point to if it has changed.
     */
    public function checkFileUrlsAndPotentiallyFetchThem()
    {
        # if fetches are disabled, bail
        if (!$this->configSetting("EnableFetching")) {
            return;
        }

        # if any monitored Urls are still being checked for file updates,
        # don't queue any new update checks yet
        if ($GLOBALS["AF"]->getQueuedTaskCount([$this, "CheckForFileUpdates"])) {
            return;
        }

        # construct the list of Record IDs to check
        $SearchParams = $this->configSetting("SearchParams");
        if ($SearchParams->parameterCount() > 0) {
            $Engine = new SearchEngine();
            $SearchParams->itemTypes(MetadataSchema::SCHEMAID_DEFAULT);
            $SearchResults = $Engine->search($SearchParams);
            $RecordIds = array_keys($SearchResults);
        } else {
            $RFactory = new RecordFactory(MetadataSchema::SCHEMAID_DEFAULT);
            $RecordIds = $RFactory->getItemIds();
        }

        # pull in the URLs from all our matching resources
        $this->addUrlsFromResources($RecordIds);

        # clean stale data out of our database
        $this->cleanStaleData($RecordIds);

        # identify those URLs that haven't been checked in a while
        # (LastCheck=0 condition is needed because TIMESTAMPDIFF() from
        #  a timestamp of 0 gives back NULL)
        $this->DB->query(
            "SELECT Url FROM AutoFetch_Urls WHERE "
            ."TIMESTAMPDIFF(DAY,LastCheck,NOW()) > "
            .intval($this->configSetting("CheckFrequency"))
            ." OR LastCheck = 0"
        );
        $Urls = $this->DB->fetchColumn("Url");

        # iterate over those URLs that need checking, queueing a task
        # for each of them
        foreach ($Urls as $Url) {
            $GLOBALS["AF"]->queueUniqueTask(
                [$this, "CheckForFileUpdates"],
                [$Url],
                $this->configSetting("TaskPriority"),
                "Check file URL, downloading a copy of the file "
                ."if it has changed since the last download."
            );
        }
    }

    /**
     * Check an individual url for updates.
     * @param string $Url Url to check
     */
    public function checkForFileUpdates($Url)
    {
        # if fetches are disabled, bail
        if (!$this->configSetting("EnableFetching")) {
            return;
        }

        $Result = $this->checkUrlForChanges($Url);
        if ($Result["Status"] == "Changed") {
            # figure out the desired file name, using
            # Content-Disposition if available and falling back to
            # parsing the URL if not
            $FileName = null;
            if (isset($Result["Headers"]["Content-Disposition"]) &&
                strpos($Result["Headers"]["Content-Disposition"], "filename") !== false) {
                // @codingStandardsIgnoreStart
                # for details on Content-Disposition, see
                # https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
                // @codingStandardsIgnoreEnd

                # break the header up into semicolon-delimited parts
                $HeaderParts = explode(";", $Result["Headers"]["Content-Disposition"]);
                foreach ($HeaderParts as $Chunk) {
                    # remove leading/trailing whitespace
                    $Chunk = trim($Chunk);

                    # look for a filename= field, taking its contents
                    # if found but continuing to look
                    if (preg_match("%^filename=\"(.*)\"$%", $Chunk, $Matches)) {
                        $FileName = basename($Matches[1]);
                    }

                    # also look for filename*= (the MIME-encoded
                    # version of filename=), taking its contents if
                    # found and ceasing the search so as to prefer it
                    # per Mozilla's docs
                    if (preg_match("%^filename\\*=\"(.*)\"$%", $Chunk, $Matches)) {
                        $DecodedName = iconv_mime_decode($Matches[1]);
                        if ($DecodedName !== false) {
                            $FileName = basename($DecodedName);
                        }
                        break;
                    }
                }
            }

            # if no file name was available from the HTTP headers,
            # generate one from our URL
            if ($FileName === null) {
                $UrlPath = parse_url($Url, PHP_URL_PATH);
                if (is_string($UrlPath)) {
                    $FileName = basename(urldecode($UrlPath));
                }
            }

            # get the list of Record IDs associated with this URL
            $this->DB->query(
                "SELECT RecordId FROM AutoFetch_RecordUrlInts "
                ."WHERE UrlId = ".$Result["UrlId"]
            );
            $RecordIds = $this->DB->fetchColumn("RecordId");

            # iterate over the associated resources
            foreach ($RecordIds as $RecordId) {
                $Resource = new Record($RecordId);

                # toggle their notification flag
                $Resource->set($this->configSetting("NotificationField"), 1);

                # create a new file from the response body,
                # associating it with our resource.
                # we leave it to the site admin to inspect the newly
                # download file to determine if they want to keep it, if
                # they want to delete older versions, or if they want to
                # move older versions to a Previous Version field.
                $ThisFile = File::create(
                    $Result["BodyFile"],
                    $FileName,
                    false
                );
                if (is_int($ThisFile)) {
                    throw new Exception("Unable to create File (Src: \""
                            .$Result["BodyFile"]."\"  Dst: \"".$FileName."\""
                            ."  Err: ".$ThisFile.").");
                }
                $ThisFile->resourceId($RecordId);
                $ThisFile->fieldId($this->configSetting("DstField"));

                # add the fetch date as a comment
                $ThisFile->comment(
                    "Retrieved by AutoFetch on ".date("Y-m-d")
                );

                # log successful fetch
                $this->DB->query(
                    "INSERT INTO AutoFetch_FetchLog (Url, FileId, FetchDate) "
                    ."VALUES ('".addslashes($Url)."',".$ThisFile->id().",NOW())"
                );
            }

            # clean up response body
            unlink($Result["BodyFile"]);
        } elseif ($Result["Status"] == "HTTP-Error") {
            $this->DB->query("INSERT INTO AutoFetch_Errors (UrlId, Error) "
                       ."VALUES (".$Result["UrlId"].",".
                       addslashes(
                           $Result["Headers"]["Status-Line"]["StatusCode"].": "
                           .$Result["Headers"]["Status-Line"]["ReasonPhrase"]
                       )."')");
        } elseif ($Result["Status"] == "Failed") {
            $this->DB->query("INSERT INTO AutoFetch_Errors (UrlId, Error) "
                       ."VALUES (".$Result["UrlId"].",".
                       "'Curl Error: ".addslashes(
                           $Result["CurlErrno"].": " .$Result["CurlError"]
                       )."')");
        }
    }

    /**
     * Get the list of fetch errors.
     * @return array Two dimensional array of error information, with the
     *      inner array containing the following keys "UrlId, Resource,
     *      Url, Error, ErrorDate.
     */
    public function getErrorList(): array
    {
        # extract error information from the database, using the
        # RecordUrlInts to associate specific errors with their
        # corresponding resources
        $this->DB->query(
            "SELECT U.UrlId AS UrlId, RecordId, Url, Error, "
            ."ErrorDate AS Date FROM AutoFetch_Urls U, AutoFetch_Errors E, "
            ."AutoFetch_RecordUrlInts RU WHERE "
            ."U.UrlId = E.UrlId AND U.UrlId = RU.UrlId"
        );
        return $this->DB->fetchRows();
    }

    /**
     * Get the list of fetched files.
     * @return array Two dimensional array of information on fetched files,
     *      with the inner array containing the following keys Url, FileId,
     *      FetchDate, Resource.
     */
    public function getFetchList(): array
    {
        $this->DB->query("SELECT Url, FileId, FetchDate FROM AutoFetch_FetchLog");
        return $this->DB->fetchRows();
    }

    /**
     * Get the number of URLs being monitored.
     * @return int Number of urls being checked.
     */
    public function getUrlCount(): int
    {
        return $this->DB->query(
            "SELECT COUNT(*) AS N FROM AutoFetch_Urls",
            "N"
        );
    }

    /**
     * Get recently checked URLs.
     * @param int $NumToGet Number of urls to retrieve.
     * @return array Two dimensional array of information on checked URLs,
     *      with the inner array containing the following keys: UrlId,
     *      RecordId, Url, LastCheck.
     */
    public function getRecentlyFetchedUrls($NumToGet = 10): array
    {
        # pull information from the db on recently fetched resources,
        # using RecordUrlInts to associate Urls with their
        # corresponding resource and also filtering out fetch errors
        $this->DB->query(
            "SELECT U.UrlId AS UrlId, RecordId, Url, LastCheck FROM "
            ."AutoFetch_Urls U, AutoFetch_RecordUrlInts RU WHERE "
            ."U.UrlId NOT IN (SELECT UrlId FROM AutoFetch_Errors) "
            ."AND U.UrlId = RU.UrlId ORDER BY LastCheck DESC "
            ."LIMIT ".intval($NumToGet)
        );

        return $this->DB->fetchRows();
    }

    # ---- PRIVATE METHODS ---------------------------------------------------

    /**
     * Add URLs to our to-check list from a list of resources.
     * @param array $RecordIds Record IDs to add.
     */
    private function addUrlsFromResources($RecordIds)
    {

        $ParId = $this->configSetting("SrcField");
        if (MetadataSchema::fieldExistsInAnySchema($ParId)) {
            $ParField = new MetadataField($ParId);
        } else {
            $ParField = false;
        }

        $UrlField = new MetadataField(
            $this->configSetting("UrlSrcField")
        );

        $Exts = $this->allowedExtensions();

        foreach ($RecordIds as $RecordId) {
            $Resource = new Record($RecordId);

            # pull out the URLs that live in our paragraph field; these
            # will always need to be checked
            $Urls = ($ParField !== false) ? explode("\n", $Resource->get($ParField)) : [];

            # pull the URL out of our configured Url field
            $Url = $Resource->get($UrlField);

            # get the extension for this URL
            $UrlPath = parse_url($Url, PHP_URL_PATH);
            $ThisExt = is_string($UrlPath) ?
                strtolower(pathinfo($UrlPath, PATHINFO_EXTENSION)) : "";

            # if this url's extension is in the allowed list, we will want to check it
            if (in_array($ThisExt, $Exts)) {
                $Urls[] = $Url;
            }

            # track which UrlIds are reflected in the current metadata for this resource
            $CurrentUrlIds = [];

            # iterate over our URLs, making sure we have database entries for each of them
            foreach ($Urls as $Url) {
                $Url = trim($Url);
                if (filter_var($Url, FILTER_VALIDATE_URL) !== false) {
                    $Row = $this->getUrlInfo($Url, true);
                    $this->DB->query(
                        "INSERT IGNORE INTO AutoFetch_RecordUrlInts "
                        ."(UrlId, RecordId) VALUES "
                        ."(".$Row["UrlId"].",".$Resource->id().")"
                    );
                    $CurrentUrlIds[] = $Row["UrlId"];
                }
            }

            if (count($CurrentUrlIds) > 0) {
                # delete extraneous URLs for this resource
                $this->DB->query(
                    "DELETE FROM AutoFetch_RecordUrlInts"
                    ." WHERE RecordId = ".$Resource->id()
                    ." AND UrlId NOT IN (".implode(",", $CurrentUrlIds).")"
                );
            }
        }
    }

    /**
     * Get the list of allowed extensions.
     * @return array of allowed file extensions (lower case, without leading dots).
     */
    private function allowedExtensions()
    {
        $Exts = [];

        $Tmp =  preg_split('%\s+%', $this->configSetting("FileExtensions"));
        if ($Tmp === false) {
            return [];
        }

        foreach ($Tmp as $Ext) {
            # convert to lower case
            $TmpExt = strtolower(trim($Ext));
            # trim leading dots
            if (strpos($TmpExt, '.') === 0) {
                $TmpExt = substr($Ext, 1);
            }
            $Exts[] = $TmpExt;
        }

        return $Exts;
    }

    /**
     * Check a URL for changes.
     * @param string $Url Url to check
     * @return array describing the result.  Will have a "Status" key
     *     saying either "Changed", "Unchanged", "HTTP-Error", or
     *     "Failed".  The first three statuses will have a "Headers"
     *     key giving the HTTP response headers.  The "Changed" status
     *     will also have a "Body" key giving the body.  The "Failed"
     *     status will have CurlErrno and CurlError keys describing the
     *     failure.
     */
    private function checkUrlForChanges($Url)
    {
        # pull out what we know about this url
        $UrlInfo = $this->getUrlInfo($Url);

        # set up a curl context to use for this fetch
        $Context = curl_init();

        # fetch the data
        $ResultFile = $this->fetchFromUrl($Context, $UrlInfo);

        # start constructing the data we shall return
        $Data = [
            "UrlId" => $UrlInfo["UrlId"]
        ];

        # if the fetch was successful, determine if the content was updated
        if ($ResultFile !== false) {
            # parse the headers out of the response file
            $HeaderSize = curl_getinfo($Context, CURLINFO_HEADER_SIZE);
            $HeaderString = fread($ResultFile, $HeaderSize);
            if ($HeaderString === false) {
                throw new Exception(
                    "Error reading from downloaded file."
                );
            }

            $Data["Headers"] = self::headerStringToArray($HeaderString);

            switch ($Data["Headers"]["Status-Line"]["StatusCode"]) {
                case "304":
                    $Data["Status"] = "Unchanged";
                    break;

                case "200":
                    # copy the body of the response into a separate file
                    $BodyFileName = tempnam("tmp", "AutoFetch");
                    if ($BodyFileName === false) {
                        throw new Exception("Error creating temp file.");
                    }

                    $BodyFile = fopen($BodyFileName, "w");
                    if ($BodyFile === false) {
                        throw new Exception("Error opening temp file.");
                    }

                    do {
                        $Chunk = fread($ResultFile, 8192);
                        if ($Chunk !== false) {
                            $Result = fwrite($BodyFile, $Chunk);
                            if ($Result === false) {
                                throw new Exception("Unable to write to temp file.");
                            }
                        }
                    } while (!feof($ResultFile));
                    $Result = fclose($BodyFile);
                    if ($Result === false) {
                        throw new Exception("Error closing temp file.");
                    }

                    # compute as hash and the length of what we got
                    $Data["Hash"] = hash_file("sha512", $BodyFileName);
                    $Data["Length"] = filesize($BodyFileName);

                    # see if the hash and length of the content we were given
                    # match the previous values we had
                    if ($UrlInfo["Hash"] == $Data["Hash"] &&
                        $UrlInfo["Length"] == $Data["Length"]) {
                        # if so, report that there was no change
                        $Data["Status"] = "Unchanged";
                        unlink($BodyFileName);
                    } else {
                        # otherwise, return the filename that contains
                        # updated data
                        $Data["Status"] = "Changed";
                        $Data["BodyFile"] = $BodyFileName;
                    }
                    break;

                default:
                    $Data["Status"] = "HTTP-Error";
                    break;
            }
        } else {
            # if the fetch failed, return the curl errors
            $Data["Status"] = "Failed";
            $Data["CurlErrno"] = curl_errno($Context);
            $Data["CurlError"] = curl_error($Context);
            $Data["Headers"] = [];
        }

        # update stored UrlInfo
        $this->updateUrlInfo($UrlInfo["UrlId"], $Data);

        # give the results of our check back to the caller
        return $Data;
    }

    /**
     * Fetch a specified Url.
     * @param \CurlHandle $Context Curl context to use for the fetch (as a
     *      PHP resource, not a Resource).
     * @param array $UrlInfo Information about the URL to be fetched in
     *      the format provided by AutoFetch::getUrlInfo().
     * @return resource|false On success, a PHP resource referring to a temp
     *      file that contains the full response (Headers+Body) that the
     *      remote server gave. These files are created with tmpfile(), so PHP
     *      will automatically clean them up.  On failure, FALSE.
     */
    private function fetchFromUrl($Context, $UrlInfo)
    {
        # create a temp file to store responses
        $TempFile = tmpfile();
        if ($TempFile === false) {
            throw new Exception("Error creating temp file.");
        }

        curl_setopt_array($Context, [
            CURLOPT_COOKIEFILE => "",
            CURLOPT_FILE => $TempFile,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_URL => $UrlInfo["Url"],
        ]);

        # if we had either an ETag or a LastModified from a previous
        # fetch, use those to perform a conditional get
        $HeadersToSet = [];

        if (strlen($UrlInfo["ETag"])) {
            $HeadersToSet[] = "If-None-Match: ".$UrlInfo["ETag"];
        }

        if (strlen($UrlInfo["LastModified"])) {
            $HeadersToSet[] = "If-Modified-Since: ".$UrlInfo["LastModified"];
        }

        if (count($HeadersToSet)) {
            curl_setopt($Context, CURLOPT_HTTPHEADER, $HeadersToSet);
        }

        # perform the fetch
        $Result = curl_exec($Context);

        if ($Result !== false) {
            rewind($TempFile);
            return $TempFile;
        } else {
            return false;
        }
    }

    /**
     * Convert a string containing HTTP response headers to an array.
     * @param string $HeaderString String of headers.
     * @return array Array where keys are header names and values are
     * header content
     */
    private static function headerStringToArray($HeaderString)
    {
        # convert the headers into an array
        $Headers = [];
        foreach (explode("\r\n", $HeaderString) as $Line) {
            # skip the blank line at the end of the headers
            if (strlen(trim($Line)) == 0) {
                continue;
            }

            # check if this is a named header, extract it if so
            if (strpos($Line, ":") !== false) {
                list($Key, $Val) = explode(":", $Line, 2);
                $Headers[$Key] = $Val;
            # otherwise, this is the status-line
            } else {
                list($HttpVer, $Status, $Reason) = explode(" ", $Line, 3);

                $Headers["Status-Line"] = [
                    "HttpVersion" => $HttpVer,
                    "StatusCode" => $Status,
                    "ReasonPhrase" => $Reason
                ];
            }
        }

        return $Headers;
    }

    /**
     * Get information for a specified Url, optionally adding unknown
     * Urls to our database and assigning them a UrlId.
     * @param string $Url Url to look for.
     * @param bool $AddIfMissing Add the Url if it was not found
     *     (OPTIONAL, default FALSE).
     * @return Array of data about the given Url having keys: UrlId,
     * Url, LastModified, ETag, Hash, Length, LastCheck.  Urls that
     * were never checked will have a LastCheck data of 0000-00-00.
     */
    private function getUrlInfo($Url, $AddIfMissing = false)
    {
        if ($AddIfMissing) {
            $this->DB->query(
                "LOCK TABLES AutoFetch_Urls WRITE"
            );
        }

        $this->DB->query(
            "SELECT * FROM AutoFetch_Urls WHERE Url='".addslashes($Url)."'"
        );

        if ($this->DB->numRowsSelected() == 0) {
            if ($AddIfMissing) {
                $this->DB->query(
                    "INSERT INTO AutoFetch_Urls (Url) VALUES "
                    ."('".addslashes($Url)."')"
                );
                $this->DB->query(
                    "SELECT * From AutoFetch_Urls WHERE Url='".addslashes($Url)."'"
                );
            } else {
                throw new Exception(
                    "No information stored for Url: ".$Url
                );
            }
        }

        $Row = $this->DB->fetchRow();

        if ($AddIfMissing) {
            $this->DB->query("UNLOCK TABLES");
        }

        return $Row;
    }

    /**
     * Update UrlInfo for a given UrlId after a fetch.
     * @param int $UrlId UrlId to update.
     * @param array $Data Fetch result in the format returned by
     *     AutoFetch::checkUrlForChanges().
     */
    private function updateUrlInfo($UrlId, $Data)
    {
        # build up an UPDATE
        $Query = "UPDATE AutoFetch_Urls SET ";

        # include the ETag if present
        if (isset($Data["Headers"]["ETag"])) {
            $Query .= "ETag = '".addslashes($Data["Headers"]["ETag"])."', ";
        }

        # include Last-Modified if present
        if (isset($Data["Headers"]["Last-Modified"])) {
            $Query .= "LastModified = '".addslashes(
                $Data["Headers"]["Last-Modified"]
            )."', " ;
        }

        # include content hash if present
        if (isset($Data["Hash"])) {
            $Query .= "Hash = '".addslashes($Data["Hash"])."', " ;
        }

        # include content length if present
        if (isset($Data["Length"])) {
            $Query .= "Length = ".intval($Data["Length"]).", " ;
        }

        # update for the given UrlId
        $Query .= "LastCheck = NOW() WHERE UrlId=".intval($UrlId) ;

        $this->DB->query($Query);
    }

    /**
     * Clean stale data out of plugin tables.
     * @param array $RecordIds Array of Record IDs currently being
     *   monitored.
     */
    private function cleanStaleData($RecordIds)
    {
        # remove Record IDs from our intersection table that don't appear in our
        # search results
        $this->DB->query(
            "DELETE FROM AutoFetch_RecordUrlInts "
            ."WHERE RecordId NOT IN (".implode(",", $RecordIds).")"
        );

        # clean out URLs that are no longer associated with any
        # resources
        $this->DB->query(
            "DELETE FROM AutoFetch_Urls WHERE UrlId NOT IN "
            ."(SELECT UrlId FROM AutoFetch_RecordUrlInts)"
        );

        # clean errors related to URLs no longer associated with any resources
        $this->DB->query(
            "DELETE FROM AutoFetch_Errors WHERE UrlId NOT IN "
            ."(SELECT UrlId FROM AutoFetch_RecordUrlInts)"
        );

        # clean errors that are older than the configured lifetime
        $this->DB->query(
            "DELETE FROM AutoFetch_Errors WHERE "
            ."TIMESTAMPDIFF(DAY,ErrorDate,NOW()) > "
            .$this->configSetting("ErrorLogLifetime")
        );
    }

    private $DB;
    private $SqlTables = [
        "Urls" => "CREATE TABLE IF NOT EXISTS AutoFetch_Urls (
            UrlId INT AUTO_INCREMENT,
            Url TEXT,
            LastModified TEXT,
            ETag TEXT,
            Hash TEXT,
            Length INT,
            LastCheck TIMESTAMP DEFAULT NOW(),
            UNIQUE UIndex_I (UrlId),
            INDEX Index_C (LastCheck),
            INDEX Index_U (Url(128)) )",
        "RecordUrlInts" => "CREATE TABLE IF NOT EXISTS AutoFetch_RecordUrlInts (
            UrlId INT NOT NULL,
            RecordId INT NOT NULL,
            UNIQUE UIndex_UR (UrlId, RecordId),
            INDEX Index_R (RecordId) )",
        "Errors" => "CREATE TABLE IF NOT EXISTS AutoFetch_Errors (
            UrlId INT NOT NULL,
            Error TEXT,
            ErrorDate TIMESTAMP,
            UNIQUE UIndex_U (UrlId) )",
        "FetchLog" => "CREATE TABLE IF NOT EXISTS AutoFetch_FetchLog (
            FetchDate TIMESTAMP,
            Url TEXT,
            FileId INT NOT NULL)",
    ];
}
