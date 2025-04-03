<?PHP
#
#   FILE:  SearchLog.php (MetricsReporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\InterfaceConfiguration;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MetricsReporter;
use Metavus\PrivilegeSet;
use Metavus\SearchParameterSet;
use Metavus\TransportControlsUI;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

# define constants for the start time options
define("ST_1_DAY", 1);
define("ST_1_WEEK", 2);
define("ST_1_MONTH", 3);
define("ST_3_MONTH", 4);
define("ST_6_MONTH", 5);
define("ST_12_MONTH", 6);
define("ST_24_MONTH", 7);
define("ST_FOREVER", 8);

# define constants for the user type options
define("UT_ALL", 1);
define("UT_ANON", 2);
define("UT_PRIV", 3);
define("UT_UNPRIV", 4);

# define constants for the search type options
define("STY_ALL", 1);
define("STY_SUCCESSFUL", 2);
define("STY_FAILED", 3);

/**
 * Determine if a search should be filtered.
 * @param array $SearchDataRow Array of search data containing at least
 *   Results, UserId, and IsPrivileged elements.
 * @param int $SearchType One of the STY_ constants describing search types.
 * @param int $UserType One of the UT_ constants describing a user type.
 * @return bool true for searches that should be filtered out.
 */
function searchIsFiltered($SearchDataRow, $SearchType, $UserType)
{
    # if looking for 'successful' searches, but this one produced no results, skip it
    if ($SearchType == STY_SUCCESSFUL && $SearchDataRow["Results"] == 0) {
        return true;
    }

    # if looking for 'failed' searches but this one did produce results, skip it
    if ($SearchType == STY_FAILED && $SearchDataRow["Results"] != 0) {
        return true;
    }

    # if we're looking for anon users, but this one isn't anon, skip it
    if ($UserType == UT_ANON && strlen($SearchDataRow["UserId"])) {
        return true;
    }

    # else if we're looking for authed users, but this one is anon, skip it
    if (($UserType == UT_PRIV || $UserType == UT_UNPRIV) &&
        $SearchDataRow["UserId"] == "") {
        return true;
    }

    # else if we're looking for privileged users, but this one isn't priv'd, skip it
    if ($UserType == UT_PRIV && !$SearchDataRow["IsPrivileged"]) {
        return true;
    }

    # else if we're looking for unpriv'd users, but this one is privileged, skip it
    if ($UserType == UT_UNPRIV && $SearchDataRow["IsPrivileged"]) {
        return true;
    }

    return false;
}

/**
 * Convert an array of UserIds to an alphabetical list of user names.
 * @param array $UserIds UserIds to convert.
 * @return array List of user names
 */
function userIdsToUserNames(array $UserIds)
{
    static $UFactory;
    static $UserMap;

    if (!isset($UFactory)) {
        $UFactory = new UserFactory();
    }

    $UserNames = [];
    foreach ($UserIds as $UID) {
        if (is_null($UID)) {
            continue;
        }

        if (!isset($UserMap[$UID])) {
            $UserMap[$UID] = $UFactory->userExists($UID) ?
                (new User($UID))->get("UserName") : "(deleted user ".$UID.")";
        }
        $UserNames[] = $UserMap[$UID];
    }

    sort($UserNames);

    return $UserNames;
}

/**
 * Get a compact opaque string to uniquely identify a search that can be used
 * as an array key.
 * @param string $SearchData Opaque SearchData string
 * @return string Search identifier.
 */
function getSearchKey($SearchData)
{
    return base64_encode(md5($SearchData, true));
}

/**
 * Convert an opaque SearchData string to a search description.
 * @param string $SearchData Search data to convert.
 * @return string text description of search.
 */
function getSearchDescription($SearchData)
{
    static $SearchMap;

    $Key = getSearchKey($SearchData);
    if (!isset($SearchMap[$Key])) {
        $Params = new SearchParameterSet($SearchData);
        try {
            $SearchMap[$Key] = $Params->textDescription();
        } catch (Exception $e) {
            $SearchMap[$Key] = "(invalid parameters ".$Key.")";
        }
    }

    return $SearchMap[$Key];
}

/**
 * Group identical searches that happened within 5 minutes of each other.
 * @param array &$H_ListData Data for ItemListUI to display.
 * @param array &$SearchData Two dimensional array of searches grouped by
 *   search identifier. Rows will be deleted from this array as they are
 *   processed to conserve memory.
 * @param array $SearchKeys Array keyed by compact search identifier with
 *   values giving the corresponding opaque search data that can be passed to
 *   'new SearchParameterSet()'.
 * @return void
 */
function groupIdenticalSearches(&$H_ListData, &$SearchData, $SearchKeys)
{
    # iterate over all the searches, using key() and array_pop() to delete
    # each row after we are done
    while (end($SearchData) !== false) {
        $Key = key($SearchData);
        $Items = array_pop($SearchData);

        $Index = 0;
        while ($Item = array_pop($Items)) {
            # construct a key to use when indexing into the ListUI data
            $ThisKey = $Key."_".$Index;

            # if we've already got a search at this key, see if it was
            # within 5 minutes of the search we're inspecting now
            if (isset($H_ListData[$ThisKey]) &&
                $Item["Timestamp"] - $H_ListData[$ThisKey]["Date"] < 300) {
                # if so, increment the NumSearches
                $H_ListData[$ThisKey]["NumSearches"] += 1;

                # add our user if they weren't already there
                if (strlen($Item["UserId"]) &&
                    !in_array($Item["UserId"], $H_ListData[$ThisKey]["UserId"])) {
                    $H_ListData[$ThisKey]["UserId"][] = $Item["UserId"];
                }
            } else {
                # otherwise, if we had a search but it is more than 5
                # min later, move to the next index spot
                if (isset($H_ListData[$ThisKey])) {
                    $Index++;
                }

                # and append this search to our list of searches
                $H_ListData[$Key."_".$Index] = [
                    "Date" => $Item["Timestamp"],
                    "NumSearches" => 1,
                    "UserId" => strlen((string)$Item["UserId"]) ? [$Item["UserId"]] : [],
                    "SearchParameters" => $SearchKeys[$Key],
                    "Results" => $Item["Results"],
                ];
            }
        }
    }
}

/**
 * Accumulate total search frequency summary into an array for ItemListUI to
 *   display.
 * @param array $H_ListData Data for ItemListUI to display.
 * @param string $Key Compact search identifier from getSearchKey().
 * @param string $Search Opaque search identifier from metrics data.
 * @param array $SearchDataRow Summary information for search.
 * @return void
 */
function addSearchToFrequencyData(
    array &$H_ListData,
    string $Key,
    string $Search,
    array $SearchDataRow
) {
    # for the search frequency, we can accumulate the data into
    # H_ListData directly
    if (!isset($H_ListData[$Key])) {
        $H_ListData[$Key] = [
            "Date" => 0,
            "NumSearches" => 0,
            "UserId" => [],
            "SearchParameters" => $Search,
            "Results" => 0,
        ];
    }

    $H_ListData[$Key]["NumSearches"] += 1;
    $H_ListData[$Key]["Date"] = max(
        $H_ListData[$Key]["Date"],
        $SearchDataRow["Timestamp"]
    );
    $H_ListData[$Key]["Results"] = max(
        $H_ListData[$Key]["Results"],
        $SearchDataRow["Results"]
    );
    if (strlen($SearchDataRow["UserId"]) &&
        !in_array($SearchDataRow["UserId"], $H_ListData[$Key]["UserId"])) {
        $H_ListData[$Key]["UserId"][] = $SearchDataRow["UserId"];
    }
}

# ----- MAIN -----------------------------------------------------------------

# make sure user has sufficient permission to view report
if (!CheckAuthorization(PRIV_COLLECTIONADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

# grab ahold of the relevant metrics objects
$Recorder = MetricsRecorder::getInstance();
$Reporter = MetricsReporter::getInstance();

# extract page parameters
$H_SearchType = intval(StdLib::getFormValue("STY", STY_ALL));
$UserType = intval(StdLib::getFormValue("UT", UT_ALL));
$H_ResultsPerPage = StdLib::getFormValue("RP", 50);
$H_View = StdLib::getFormValue("V", "Log");

$H_StartTime = ($H_View == "Log") ? ST_24_MONTH :
           intval(StdLib::getFormValue("ST", ST_3_MONTH));

$SpamSearch = StdLib::getFormValue("Spam");
$StartIndex = intval(StdLib::getFormValue(
    TransportControlsUI::PNAME_STARTINGINDEX,
    0
));
$SortField = StdLib::getFormValue(TransportControlsUI::PNAME_SORTFIELD);
$RevSort = StdLib::getFormValue(TransportControlsUI::PNAME_REVERSESORT, false);

# process spam searches
if ($SpamSearch !== null) {
    $SpamSearch = preg_replace('/_[0-9]+$/', '', $SpamSearch);

    # add this search to the ignore list
    (new Database())->query(
        "INSERT IGNORE INTO MetricsReporter_SpamSearches "
        ."(SearchKey) VALUES ('".addslashes($SpamSearch)."')"
    );

    # redirect to our search listing
    $AF->setJumpToPage(
        "index.php?P=P_MetricsReporter_SearchLog"
        ."&V=".$H_View
        ."&ST=".$H_StartTime
        ."&UT=".$UserType
        ."&STY=".$H_SearchType
        ."&RP=".$H_ResultsPerPage
        ."&".TransportControlsUI::PNAME_STARTINGINDEX."=".$StartIndex
    );
    return;
}

# set up a lookup table of starting times
$CurrentTime = time();
$TimeLUT = [
    ST_FOREVER => 0,
    ST_24_MONTH => strtotime("-24 months", $CurrentTime),
    ST_12_MONTH => strtotime("-12 months", $CurrentTime),
    ST_6_MONTH => strtotime("-6 months", $CurrentTime),
    ST_3_MONTH => strtotime("-3 months", $CurrentTime),
    ST_1_MONTH => strtotime("-1 month", $CurrentTime),
    ST_1_WEEK => strtotime("-1 week", $CurrentTime),
    ST_1_DAY => strtotime("-1 day", $CurrentTime),
];

# define a lookup table of sort functions
$SortFns = [
    "Date" => function ($a, $b) {
        return $b["Date"] <=> $a["Date"];
    },
    "NumSearches" => function ($a, $b) {
        return $b["NumSearches"] <=> $a["NumSearches"];
    },
    "Results" => function ($a, $b) {
        return $b["Results"] <=> $a["Results"];
    },
    "UserId" => function ($a, $b) {
        # if asked to sort by user, we have to do something in the case
        # where we have a list of users.  in this case, we're comparing the
        # alphabetically first element of each list of users to determine
        # the ordering.
        $UsersA = userIdsToUserNames($a["UserId"]);
        $UsersB = userIdsToUserNames($b["UserId"]);

        return strcmp(reset($UsersA), reset($UsersB));
    },
    "SearchParameters" => function ($a, $b) {
        return strcmp(
            getSearchDescription($a["SearchParameters"]),
            getSearchDescription($b["SearchParameters"])
        );
    },
];

# define the ItemListUI fields for our valid view types
$ListFields = [
    "Log" =>  [
        "Date" => [
            "Heading" => "Date",
            "DefaultToDescendingSort" => true,
            "DefaultSortField" => true,
            "ValueFunction" => function ($Item, $FieldId) {
                return StdLib::getPrettyTimestamp($Item[$FieldId]);
            }
        ],
        "Results" => [
            "DefaultToDescendingSort" => true,
            "Heading" => "Results",
        ],
        "UserId" => [
            "Heading" => "Users",
            "ValueFunction" => function ($Item, $FieldId) {
                return implode(", ", userIdsToUserNames($Item["UserId"]));
            },
        ],
        "SearchParameters" => [
            "Heading" => "Parameters",
            "ValueFunction" => function ($Item, $FieldId) {
                return getSearchDescription($Item["SearchParameters"])
                    .(($Item["NumSearches"] > 1) ?
                      "<br/>(repeated ".$Item["NumSearches"]." times)" :
                      "");
            },
            "AllowHTML" => true,
        ],
    ],
    "Frequency" => [
        "NumSearches" => [
            "Heading" => "Count",
            "DefaultToDescendingSort" => true,
            "DefaultSortField" => true,
        ],
        "Results" => [
            "Heading" => "Results",
            "DefaultToDescendingSort" => true,
        ],
        "UserId" => [
            "Heading" => "Users",
            "ValueFunction" => function ($Item, $FieldId) {
                return implode(", ", userIdsToUserNames($Item["UserId"]));
            },
        ],
        "SearchParameters" => [
            "Heading" => "Parameters",
            "ValueFunction" => function ($Item, $FieldId) {
                return getSearchDescription($Item["SearchParameters"]);
            },
            "AllowHTML" => true,
        ],
    ],
];

# if the provided view type was invalid, error out
if (!isset($ListFields[$H_View])) {
    throw new Exception("Unsupported view type: ".$H_View);
}

# if no SortField was provided, set a default based on the provided view type
if ($SortField === null) {
    $SortField = ($H_View == "Log") ? "Date" : "NumSearches";
}

# error out on invalid sort field
if (!isset($SortFns[$SortField])) {
    throw new Exception("Invalid sort field: ".$SortField);
}

# get the list of spammy searches that we should exclude
$DB = new Database();
$DB->query("SELECT SearchKey FROM MetricsReporter_SpamSearches");
$SpamSearches = $DB->fetchColumn("SearchKey");
$SpamSearches = array_flip($SpamSearches);

# get the list of users considered privileged
$PrivsToExclude = $Reporter->getConfigSetting("PrivsToExcludeFromCounts") ?? [];
if ($PrivsToExclude instanceof PrivilegeSet) {
    $PrivsToExclude = $PrivsToExclude->getPrivileges();
}
$PrivilegedUsers = (new UserFactory())->getUsersWithPrivileges($PrivsToExclude);

# create arrays to accumulate our result data
$H_ListData = [];
$SearchKeys = [];
$SearchData = [];

# extract metrics data in chunks up to 6 months large
$EndDate = $CurrentTime;
do {
    $StartDate = max(strtotime("-6 months", $EndDate), $TimeLUT[$H_StartTime]);

    $AllSearches = $Recorder->getEventData(
        "MetricsRecorder",
        [MetricsRecorder::ET_SEARCH, MetricsRecorder::ET_ADVANCEDSEARCH],
        date(StdLib::SQL_DATE_FORMAT, $StartDate),
        date(StdLib::SQL_DATE_FORMAT, $EndDate)
    );

    # iterate over data using array_pop() to discard processed rows
    while ($Row = array_pop($AllSearches)) {
        # if there was a search string recorded
        #  (Note that the Metrics Data is sometimes missing a search string,
        #   and the cause for that has not yet been determined.)
        if (!strlen($Row["DataOne"])) {
            continue;
        }

        # if this search was marked as spam, continue on
        $Key = getSearchKey($Row["DataOne"]);
        if (isset($SpamSearches[$Key])) {
            continue;
        }

        # construct a data row describing this search
        $SearchDataRow = [
            "UserId" => $Row["UserId"],
            "IsPrivileged" => isset($PrivilegedUsers[$Row["UserId"]]),
            "Timestamp" => strtotime($Row["EventDate"]),
            "Results" => $Row["DataTwo"],
        ];

        # see if this row should be filtered out, discarding it if so
        if (searchIsFiltered($SearchDataRow, $H_SearchType, $UserType)) {
            continue;
        }

        # otherwise, include this search in our results
        if ($H_View == "Log") {
            # for the search log, we need to group searches into bins so that
            # we can de-duplicate them to construct the log
            $SearchKeys[$Key] = $Row["DataOne"];
            $SearchData[$Key][] = $SearchDataRow;
        } else {
            # for search frequency, we can accumulate totals in H_ListData
            addSearchToFrequencyData($H_ListData, $Key, $Row["DataOne"], $SearchDataRow);
        }
    }

    $EndDate = $StartDate;
} while ($StartDate > $TimeLUT[$H_StartTime]);

# if the view type was 'Log', we need to do some additional processing to
# group identical searches that happened within 5 minutes of each other
if ($H_View == "Log") {
    groupIdenticalSearches($H_ListData, $SearchData, $SearchKeys);
}

# sort the data
uasort($H_ListData, $SortFns[$SortField]);

# reverse order if necessary
if ($RevSort) {
    $H_ListData = array_reverse($H_ListData, true);
}

# construct the TransportControls
$H_TotalResults = count($H_ListData);

$H_TransportUI = new TransportControlsUI();
$H_TransportUI->itemsPerPage($H_ResultsPerPage);
$H_TransportUI->itemCount($H_TotalResults);

# subset the ListData according to the TransportControls
$H_ListData = array_slice(
    $H_ListData,
    $H_TransportUI->startingIndex(),
    $H_ResultsPerPage,
    true
);

# construct the BaseLink for TranportControlsUI and ItemListUI
$H_BaseLink = "index.php?P=P_MetricsReporter_SearchLog"
    ."&V=".$H_View
    ."&ST=".$H_StartTime
    ."&UT=".$UserType
    ."&STY=".$H_SearchType
    ."&RP=".$H_ResultsPerPage;

# define the fields that should appear in the ListUI based on our view type
$H_ListFields = $ListFields[$H_View];

# if the user requested JSON data, produce that as output
if (isset($_GET["JSON"])) {
    $AF->suppressHtmlOutput();
    header("Content-Type: application/json; charset="
           .InterfaceConfiguration::getInstance()->getString("DefaultCharacterSet"), true);

    foreach ($H_ListData as $Index => $DataRow) {
        $H_ListData[$Index]["SearchParameters"] = getSearchDescription(
            $DataRow["SearchParameters"]
        );

        $H_ListData[$Index]["UserId"] = implode(
            ", ",
            userIdsToUserNames($DataRow["UserId"])
        );
    }

    print json_encode($H_ListData);
    return;
}
