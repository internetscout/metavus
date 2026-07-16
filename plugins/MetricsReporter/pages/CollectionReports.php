<?PHP
#
#   FILE:  CollectionReports.php (MetricsReporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MetricsReporter;
use Metavus\Plugins\SocialMedia;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Helper function that will increment or create a given array key.
* @param array $Array Array to work on
* @param mixed $Key Key to look for
*/
function createOrIncrement(&$Array, $Key) : void
{
    if (!isset($Array[$Key])) {
        $Array[$Key] = 1;
    } else {
        $Array[$Key]++;
    }
}

/**
* Summarize view counts after a specified date
* @param string|int $StartTS Starting date
* @return array View count summary
*/
function getViewCountData($StartTS)
{
    $Recorder = MetricsRecorder::getInstance();
    $Reporter = MetricsReporter::getInstance();

    $StartDate = date('Y-m-d', (int)$StartTS);

    $Data = $Reporter->cacheGet("ViewCounts".$StartDate);
    if (is_null($Data)) {
        $RFactory = new RecordFactory();
        $AllResourceIds = $RFactory->getItemIds();

        $Data = $Recorder->getFullRecordViewCounts(
            MetadataSchema::SCHEMAID_DEFAULT,
            $StartDate,
            null,
            0,
            15,
            $Reporter->getConfigSetting("PrivsToExcludeFromCounts")
        );
        $Data["StartDate"] = $StartDate;
        $Data["EndDate"]   = date('Y-m-d');

        $Data["Total"] = $Recorder->getEventCounts(
            "MetricsRecorder",
            MetricsRecorder::ET_FULLRECORDVIEW,
            null,
            $StartDate,
            null,
            null,
            $AllResourceIds,
            null,
            $Reporter->getConfigSetting("PrivsToExcludeFromCounts")
        );

        $Reporter->cachePut("ViewCounts".$StartDate, $Data);
    }

    return $Data;
}

/**
* Summarize URL clicks after a specified date
* @param string|int $StartTS Starting date
* @param MetadataField $Field MetadataField of interest
* @return array View count summary
*/
function getClickCountData($StartTS, $Field)
{
    $Recorder = MetricsRecorder::getInstance();
    $Reporter = MetricsReporter::getInstance();

    $StartDate = date('Y-m-d', (int)$StartTS);

    $Data = $Reporter->cacheGet("ClickCounts".$StartDate);

    if (is_null($Data)) {
        $Data = $Recorder->getUrlFieldClickCounts(
            $Field->id(),
            $StartDate,
            null,
            0,
            15,
            $Reporter->getConfigSetting("PrivsToExcludeFromCounts")
        );
        $Data["StartDate"] = $StartDate;
        $Data["EndDate"]   = date('Y-m-d');

        $Data["Total"] = $Recorder->getEventCounts(
            "MetricsRecorder",
            MetricsRecorder::ET_URLFIELDCLICK,
            null,
            $StartDate,
            null,
            null,
            null,
            $Field->id(),
            $Reporter->getConfigSetting("PrivsToExcludeFromCounts")
        );

        $Reporter->cachePut("ClickCounts".$StartDate, $Data);
    }

    return $Data;
}

/**
 * Generator function to retrieve event data from MetricsRecorder
 * in chunks to avoid excessive memory usage
 * @param string $Owner owner of events to get data for
 * @param int|string|array $Type Type of event to get search data for
 * @param string|null $StartDate (OPTIONAL) Beginning of date of range
 *     to search (inclusive), in SQL date format
 * @param array|PrivilegeSet $PrivsToExclude (OPTIONAL) Users with these
 *     privileges will be excluded from the results.
 * @return array|\Generator Requested data.
 */
function getChunkOfData($Owner, $Type, $StartDate = null, $PrivsToExclude = [])
{
    $Recorder = MetricsRecorder::getInstance();
    $Count = (int)(($Recorder->getEventCounts(
        $Owner,
        $Type,
        null,
        $StartDate,
        null,
        null,
        null,
        null,
        $PrivsToExclude
    ))[0]);
    if ($Count == 0) {
        return;
    }
    $ChunkSize = 5000;
    $LastChunkIndex = $ChunkSize * floor(($Count - 1) / $ChunkSize);
    for ($Index = 0; $Index <= $LastChunkIndex; $Index += $ChunkSize) {
        $Data = $Recorder->getEventData(
            $Owner,
            $Type,
            $StartDate,
            null,
            null,
            null,
            null,
            $PrivsToExclude,
            $Index,
            $ChunkSize
        );
        yield $Data;
    }
}

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

$AF->setPageTitle("Collection Usage Metrics");

# make sure user has sufficient permission to view report
if (!User::requirePrivilege(PRIV_COLLECTIONADMIN)) {
    return;
}

$PluginMgr = PluginManager::getInstance();

# Grab ahold of the relevant metrics objects:
$Recorder = MetricsRecorder::getInstance();
$Reporter = MetricsReporter::getInstance();

if (isset($_GET["US"]) && $_GET["US"] == 1) {
    $Reporter->cacheClear();
    $AF->setJumpToPage("P_MetricsReporter_CollectionReports");
    return;
}

$H_Now = time();
$H_Past = [
    "Day"   => $H_Now -       86400,
    "Week"  => $H_Now -   7 * 86400,
    "Month" => $H_Now -  30 * 86400,
    "Year"  => $H_Now - 365 * 86400
];

# resource view counts (weekly, monthly, yearly)
$H_ViewCountData = [
    "Week" => getViewCountData($H_Past["Week"]),
    "Month" => getViewCountData($H_Past["Month"]),
    "Year" => getViewCountData($H_Past["Year"]),
];

# url click counts (weekly, monthly, yearly)
$Schema = new MetadataSchema();
$H_UrlField = $Schema->getFieldByMappedName("Url");

# initialize the variables with a default value
$H_ClickCountData = [
    "Week" => null,
    "Month" => null,
    "Year" => null,
];


# if $H_UrlField is set, then update these variables
if (isset($H_UrlField)) {
    $H_ClickCountData["Week"] = getClickCountData($H_Past["Week"], $H_UrlField);
    $H_ClickCountData["Month"] = getClickCountData($H_Past["Month"], $H_UrlField);
    $H_ClickCountData["Year"]  = getClickCountData($H_Past["Year"], $H_UrlField);
}

# total resources vs time
$H_TotalResourceCountData = [];
foreach ($Recorder->getSampleData(
    MetricsRecorder::ST_RESOURCECOUNT
) as $SampleDate => $SampleValue) {
    $H_TotalResourceCountData[strtotime($SampleDate)] = $SampleValue;
}
ksort($H_TotalResourceCountData);

# newly created resources per day
$H_NewResourceCountData = [];

$Prev = null;
foreach ($H_TotalResourceCountData as $TS => $Count) {
    if ($Prev !== null) {
        $H_NewResourceCountData[$TS] = max(0, $Count[0] - $Prev);
    }
    $Prev = $Count[0];
}

# url clicks per day
$H_UrlClickData = $Reporter->cacheGet("UrlClickData");
if (is_null($H_UrlClickData)) {
    $H_UrlClickData = [];
    foreach ($Recorder->getEventCounts(
        "MetricsRecorder",
        MetricsRecorder::ET_URLFIELDCLICK,
        "DAY",
        null,
        null,
        null,
        null,
        null,
        $Reporter->getConfigSetting("PrivsToExcludeFromCounts")
    ) as $TS => $Count) {
        if (!isset($H_UrlClickData[$TS])) {
            $H_UrlClickData[$TS] = 0;
        }

        $H_UrlClickData[$TS] += $Count ;
    }
    $Reporter->cachePut("UrlClickData", $H_UrlClickData);
}

# analysis of search data
$H_SearchSummary = $Reporter->cacheGet("SearchData");
if (is_null($H_SearchSummary)) {
    $UserCache = [];

    $H_SearchSummary = [
        "Priv" => [
            "Day" => [],
            "Week" => [],
            "Month" => [],
        ],
        "Unpriv" => [
            "Day" => [],
            "Week" => [],
            "Month" => [],
        ]
    ];

    # pull out privs that should be excluded from metrics
    $ExcludedPrivs = $Reporter->getConfigSetting("PrivsToExcludeFromCounts");
    if ($ExcludedPrivs instanceof PrivilegeSet) {
        $ExcludedPrivs = $ExcludedPrivs->getPrivileges();
    }

    $StartDate = date('Y-m-d', $H_Past["Month"]);
    $SearchTypes = [MetricsRecorder::ET_SEARCH, MetricsRecorder::ET_ADVANCEDSEARCH];
    foreach (getChunkOfData("MetricsRecorder", $SearchTypes, $StartDate) as $Searches) {
        foreach ($Searches as $Row) {
            # if there was a search string recorded
            #  (Note that the Metrics Data is sometimes missing a search string,
            #   and the cause for that has not yet been determined...)
            if (strlen($Row["DataOne"]) !== 0) {
                # if we had a logged in user
                if (!is_null($Row["UserId"]) && strlen($Row["UserId"])) {
                    # determine if we've already checked their permissions against the
                    #  list of exclusions from the Metrics Reporter.
                    if (!isset($UserCache[$Row["UserId"]])) {
                        # if we haven't check their perms and cache the result
                        $ThisUser = new User($Row["UserId"]);

                        $UserCache[$Row["UserId"]] = $ThisUser->hasPriv(
                            $ExcludedPrivs
                        );
                    }

                    # pull cached perms data out
                    $Privileged = $UserCache[$Row["UserId"]];
                } else {
                        # if there was no user logged in, count them as non-priv
                    $Privileged = false;
                }

                $TS = strtotime($Row["EventDate"]);
                $SearchParams = new SearchParameterSet($Row["DataOne"]);
                $SearchUrl = $SearchParams->urlParameterString();

                $Index = $Privileged ? "Priv" : "Unpriv";
                foreach (["Month", "Week", "Day"] as $Interval) {
                    if ($H_Past[$Interval] < $TS) {
                        createOrIncrement(
                            $H_SearchSummary[$Index][$Interval],
                            $SearchUrl
                        );
                    }
                }
            }
        }
    }

    # sort summaries in descending order and limit to the top 15, with
    # Other and Total rows for the rest
    foreach ($H_SearchSummary as $SearchType => $TypeSummary) {
        foreach ($TypeSummary as $Interval => $Data) {
            arsort($Data);
            $Total = array_sum($Data);
            $Data = array_slice($Data, 0, 15, true);
            $H_SearchSummary[$SearchType][$Interval] = $Data;
            $H_SearchSummary[$SearchType][$Interval]["Other"] = $Total - array_sum($Data);
            $H_SearchSummary[$SearchType][$Interval]["Total"] = $Total;
        }
    }

    $Reporter->cachePut("SearchData", $H_SearchSummary);
}

# daily summary of search counts
$H_SearchDataByDay = $Reporter->cacheGet("SearchDataByDay");
if (is_null($H_SearchDataByDay)) {
    # pull out totals for regular + advanced searches
    $AllSearches = $Recorder->getEventCounts(
        "MetricsRecorder",
        [MetricsRecorder::ET_SEARCH, MetricsRecorder::ET_ADVANCEDSEARCH],
        "DAY"
    );

    # pull out the searches by unprivileged users
    $UnprivSearches = $Recorder->getEventCounts(
        "MetricsRecorder",
        [MetricsRecorder::ET_SEARCH, MetricsRecorder::ET_ADVANCEDSEARCH],
        "DAY",
        null,
        null,
        null,
        null,
        null,
        $Reporter->getConfigSetting("PrivsToExcludeFromCounts")
    );

    # massage the data into the format that the Graph object wants
    $H_SearchDataByDay = [];
    foreach ($AllSearches as $TS => $Count) {
        $ThisUnpriv = array_key_exists($TS, $UnprivSearches) ?
            $UnprivSearches[$TS] : 0 ;

        $H_SearchDataByDay[$TS] =
            [$ThisUnpriv, $Count - $ThisUnpriv];
    }

    $Reporter->cachePut("SearchDataByDay", $H_SearchDataByDay);
}

# OAI data
$H_OaiDataByDay = $Reporter->cacheGet("OaiDataByDay");
if (is_null($H_OaiDataByDay)) {
    $H_OaiDataByDay = [];
    foreach (getChunkOfData("MetricsRecorder", MetricsRecorder::ET_OAIREQUEST) as $Rows) {
        foreach ($Rows as $Row) {
            $TS = strtotime(date('Y-m-d', strtotime($Row["EventDate"])));

            if (!isset($H_OaiDataByDay[$TS])) {
                $H_OaiDataByDay[$TS] = 0;
            }
            $H_OaiDataByDay[$TS]++;
        }
    }

    $Reporter->cachePut("OaiDataByDay", $H_OaiDataByDay);
}

if ($PluginMgr->pluginReady("SocialMedia")) {
    # resource shares
    $H_SharesByDay = $Reporter->cacheGet("Shares");
    # we must have access to the SocialMedia plugin to gather sharing data
    if (is_null($H_SharesByDay)) {
        $H_SharesByDay = [];
        /**
         * @var array<string, array> $H_TopShares
         */
        $H_TopShares = [];

        $ShareTypeMap = [
            SocialMedia::SITE_EMAIL => 0,
            SocialMedia::SITE_FACEBOOK => 1,
            SocialMedia::SITE_TWITTER => 2,
            SocialMedia::SITE_LINKEDIN => 3
        ];
        $ValidDataTwoValues = [
            SocialMedia::SITE_FACEBOOK,
            SocialMedia::SITE_LINKEDIN,
            SocialMedia::SITE_TWITTER
        ];

        foreach (getChunkOfData(
            "SocialMedia",
            "ShareResource",
            null,
            $Reporter->getConfigSetting("PrivsToExcludeFromCounts")
        ) as $Events) {
            foreach ($Events as $Event) {
                if (in_array($Event["DataTwo"], $ValidDataTwoValues)) {
                    $TS = strtotime(date('Y-m-d', strtotime($Event["EventDate"])));
                    if (!isset($H_SharesByDay[$TS])) {
                        $H_SharesByDay[$TS] = [0, 0, 0, 0, 0];
                    }

                    $H_SharesByDay[$TS][$ShareTypeMap[$Event["DataTwo"]]]++;

                    foreach (["Week", "Month", "Year"] as $Period) {
                        if ($H_Past[$Period] < $TS) {
                            createOrIncrement($H_TopShares[$Period], $Event["DataOne"]);
                        }
                    }
                }
            }
        }

        # sort top shares
        foreach (["Week", "Month", "Year"] as $Period) {
            if (!isset($H_TopShares[$Period])) {
                $H_TopShares[$Period] = [];
            }

            arsort($H_TopShares[$Period]);

            $Total = array_sum($H_TopShares[$Period]);

            $H_TopShares[$Period] = array_slice(
                $H_TopShares[$Period],
                0,
                15,
                true
            );

            $H_TopShares[$Period]["Other"] = $Total - array_sum($H_TopShares[$Period]);
            $H_TopShares[$Period]["Total"] = $Total;
        }

        $Reporter->cachePut("Shares", $H_SharesByDay);
        $Reporter->cachePut("TopShares", $H_TopShares);
    } else {
        $H_TopShares = $Reporter->cacheGet("TopShares");
    }
} else {
    $H_SharesByDay = [];
    $H_TopShares = null;
}

# if the user requested JSON data, provide it and cease processing
if (isset($_GET["JSON"])) {
    $AF->suppressHtmlOutput();
    header("Content-Type: application/json; charset="
           .InterfaceConfiguration::getInstance()->getString("DefaultCharacterSet"), true);

    print json_encode([
        "TopViews" => [
            "Weekly" => $H_ViewCountData["Week"],
            "Monthly" => $H_ViewCountData["Month"],
            "Yearly" => $H_ViewCountData["Year"]
        ],
        "TopClicks" => [
            "Weekly" => $H_ClickCountData["Week"],
            "Monthly" => $H_ClickCountData["Month"],
            "Yearly" => $H_ClickCountData["Year"]
        ],
        "TopSearches" => $H_SearchSummary,
        "ResourceCount" => MetricsReporter::formatDateKeys($H_TotalResourceCountData),
        "NewResources" => MetricsReporter::formatDateKeys($H_NewResourceCountData),
        "UrlClicks" => MetricsReporter::formatDateKeys($H_UrlClickData),
        "SearchData" => MetricsReporter::formatDateKeys($H_SearchDataByDay),
        "OaiData" => MetricsReporter::formatDateKeys($H_OaiDataByDay),
        "SharesData" => MetricsReporter::formatDateKeys($H_SharesByDay),
    ]);
    return;
}
