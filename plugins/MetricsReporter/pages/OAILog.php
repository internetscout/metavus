<?PHP
#
#   FILE:  OAILog.php (MetricsReporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

# define constants for the start time options
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MetricsReporter;
use Metavus\InterfaceConfiguration;
use Metavus\TransportControlsUI;
use ScoutLib\StdLib;

define("ST_1_DAY", 1);
define("ST_1_WEEK", 2);
define("ST_1_MONTH", 3);
define("ST_3_MONTH", 4);
define("ST_6_MONTH", 5);
define("ST_12_MONTH", 6);
define("ST_24_MONTH", 7);
define("ST_FOREVER", 8);

# define constants for the filter type options
define("FT_ALL", 1);
define("FT_CONTACT", 2);
define("FT_SAMPLE", 3);
define("FT_HARVEST", 4);
define("FT_SETS", 5);

# make sure user has sufficient permission to view report
if (!CheckAuthorization(PRIV_COLLECTIONADMIN)) {
    return;
}

# grab ahold of the relevant metrics objects
$Recorder = $GLOBALS["G_PluginManager"]->GetPlugin("MetricsRecorder");
$Reporter = $GLOBALS["G_PluginManager"]->GetPlugin("MetricsReporter");

# extract parameters
$H_ResultsPerPage = intval(StdLib::getFormValue("RP", 50));
$H_StartTime = intval(StdLib::getFormValue("ST", ST_3_MONTH));
$H_FilterType = intval(StdLib::getFormValue("FT", FT_ALL));

$StartIndex = intval(StdLib::getFormValue(
    TransportControlsUI::PNAME_STARTINGINDEX,
    0
));
$SortField = StdLib::getFormValue(
    TransportControlsUI::PNAME_SORTFIELD,
    "EventDate"
);
$RevSort = StdLib::getFormValue(
    TransportControlsUI::PNAME_REVERSESORT,
    true
);

# set up a lookup table of starting times, starting from the top of the hour
$CurrentHour = strtotime(date("Y-m-d H:00:00"));
$TimeLUT = [
    ST_FOREVER => 0,
    ST_24_MONTH => strtotime("-24 months", $CurrentHour),
    ST_12_MONTH => strtotime("-12 months", $CurrentHour),
    ST_6_MONTH => strtotime("-6 months", $CurrentHour),
    ST_3_MONTH => strtotime("-3 months", $CurrentHour),
    ST_1_MONTH => strtotime("-1 month", $CurrentHour),
    ST_1_WEEK => strtotime("-1 week", $CurrentHour),
    ST_1_DAY => strtotime("-1 day", $CurrentHour),
];

# pull out all the harvest data for the specified period
$H_HarvestData = $Recorder->GetEventData(
    "MetricsRecorder",
    MetricsRecorder::ET_OAIREQUEST,
    date("Y-m-d H:00:00", $TimeLUT[$H_StartTime])
);

# make a list of specific requests to filter
$ToFilter = [];

# and list of IPs that made those requests, so we can put repeat
#  offenders in the doghouse more globally
$FilterIPs = [];

# for each entry
foreach ($H_HarvestData as $Key => $Val) {
    # check if this request looks like an sql injection
    if (MetricsReporter::RequestIsSqlInjection($Val["DataTwo"])) {
        # increment this IP's badness score
        $IPAddr = $Val["DataOne"];
        if (!isset($FilterIPs[$IPAddr])) {
            $FilterIPs[$IPAddr] = 0;
        }
        $FilterIPs[$IPAddr]++;

        # mark this entry as one that should be filtered
        $ToFilter[$Key] = true;
    }
}

# loop over entries again looking for repeat-offender IPs
foreach ($H_HarvestData as $Key => $Val) {
    $IPAddr = $Val["DataOne"];
    if (isset($FilterIPs[$IPAddr]) && $FilterIPs[$IPAddr] > 3) {
        $ToFilter[$Key] = true;
    }
}

# remove problematic entries
foreach ($ToFilter as $Key => $Val) {
    unset($H_HarvestData[$Key]);
}

# if we're not going to view all entries
if ($H_FilterType != FT_ALL) {
    switch ($H_FilterType) {
        case FT_HARVEST:
        case FT_SAMPLE:
            # iterate over requests, building up a list of IPs that
            # used a resumptionToken
            $IPsThatResumed = [];
            foreach ($H_HarvestData as $Key => $Val) {
                $Request = urldecode($Val["DataTwo"]);
                if (strpos($Request, "resumptionToken") !== false) {
                    $IPsThatResumed[$Val["DataOne"]] = true;
                }
            }
            break;

        default:
            break;
    }

    # build up a list of which elements to filter
    $ToFilter = [];
    switch ($H_FilterType) {
        case FT_CONTACT:
            # build a summary of the contacts from each IP address
            $ContactSummary = [];
            $ContactCount = [];
            $ContactDates = [];
            foreach ($H_HarvestData as $Key => $Val) {
                $IPAddr = $Val["DataOne"];

                # if we lack a contact summary for this IP, create one
                if (!isset($ContactSummary[$IPAddr])) {
                    $ContactSummary[$IPAddr] = [
                        "ListRecords" => false,
                        "GetRecord" => false,
                        "ListIdentifiers" => false,
                        "ListSets" => false,
                        "Identify" => false,
                        "unknown" => false
                    ];
                }

                # track the number of contacts from each IP
                if (!isset($ContactCount[$IPAddr])) {
                    $ContactCount[$IPAddr] = 0;
                }
                $ContactCount[$IPAddr]++;

                if (!isset($ContactDates[$IPAddr]) ||
                    strtotime($ContactDates[$IPAddr]) < strtotime($Val["EventDate"])) {
                    $ContactDates[$IPAddr] = $Val["EventDate"];
                }

                # parse the request
                parse_str($Val["DataTwo"], $ReqParams);

                # figure out the request type for this request
                if (!isset($ReqParams["verb"]) ||
                    !in_array(
                        $ReqParams["verb"],
                        [
                            "ListRecords", "GetRecord", "ListIdentifiers",
                            "ListSets", "Identify"
                        ]
                    )
                ) {
                    $ReqType = "unknown";
                } else {
                    $ReqType = $ReqParams["verb"];
                }

                # if we don't have a record for this type of contact,
                # create one
                if ($ContactSummary[$IPAddr][$ReqType] == false) {
                    $ContactSummary[$IPAddr][$ReqType] = $Key;
                }
            }

            # iterate over the contact summary, deciding what data
            # to keep
            $ToKeep = [];
            foreach ($ContactSummary as $IPAddr => $Contacts) {
                do {
                    $Key = array_shift($Contacts);
                } while (count($Contacts) > 0 && $Key === false);

                if ($Key !== false) {
                    $ToKeep[$Key] = true;
                }
            }

            # ditch anything not on our keep list
            foreach ($H_HarvestData as $Key => $Val) {
                if (!isset($ToKeep[$Key])) {
                    $ToFilter[] = $Key;
                } else {
                    $IPAddr = $Val["DataOne"];
                    $H_HarvestData[$Key]["Count"] = $ContactCount[$IPAddr];
                    $H_HarvestData[$Key]["EventDate"] = $ContactDates[$IPAddr];
                }
            }

            break;

        case FT_SAMPLE:
            foreach ($H_HarvestData as $Key => $Val) {
                $Request = urldecode($Val["DataTwo"]);
                $IPAddr = $Val["DataOne"];

                # do not filter GetRecord requests
                if (strpos($Request, "verb=GetRecord") !== false) {
                    continue;
                }

                # do not filter ListRecords w/ a resumption token
                if (strpos($Request, "resumptionToken") !== false &&
                    isset($IPsThatResumed[$IPAddr])) {
                    continue;
                }

                $ToFilter[] = $Key;
            }

            break;

        case FT_HARVEST:
            $CurHarvest = null;
            $ContinuationCounts = [];

            foreach ($H_HarvestData as $Key => $Val) {
                $Request = urldecode($Val["DataTwo"]);
                $IPAddr = $Val["DataOne"];

                # do not filter ListRecords from IPs that resumed
                if (strpos($Request, "verb=ListRecords") !== false &&
                    isset($IPsThatResumed[$IPAddr])) {
                    if (strpos($Request, "resumptionToken") === false) {
                        $CurHarvest = $Key;
                        $ContinuationCounts[$CurHarvest] = 0;
                        continue;
                    }

                    if ($CurHarvest !== null) {
                        $ContinuationCounts[$CurHarvest]++;
                    }
                }

                $ToFilter[] = $Key;
            }

            # add continuation counts in to harvest data
            foreach ($ContinuationCounts as $Key => $Count) {
                $H_HarvestData[$Key]["Count"] = $Count;
            }

            break;

        case FT_SETS:
            $CurHarvest = null;
            $ContinuationCounts = [];

            foreach ($H_HarvestData as $Key => $Val) {
                $Request = urldecode($Val["DataTwo"]);
                $IPAddr = $Val["DataOne"];

                if (strpos($Request, "set=") !== false) {
                    if (strpos($Request, "verb=ListSets") !== false) {
                        continue;
                    }

                    if (strpos($Request, "verb=ListRecords") !== false) {
                        if (strpos($Request, "resumptionToken") === false) {
                            $CurHarvest = $Key;
                            $ContinuationCounts[$CurHarvest] = 0;
                            continue;
                        }

                        if ($CurHarvest !== null) {
                            $ContinuationCounts[$CurHarvest]++;
                        }
                    }
                }
                $ToFilter[] = $Key;
            }

            # add continuation counts in to harvest data
            foreach ($ContinuationCounts as $Key => $Count) {
                $H_HarvestData[$Key]["Count"] = $Count;
            }

            break;
    }

    # prune marked records
    foreach ($ToFilter as $Key) {
        unset($H_HarvestData[$Key]);
    }
}


$H_ListFields = [
    "EventDate" => [
        "Heading" => "Request Date",
        "DefaultSortField" => true,
    ],
    "DataOne" => [
        "Heading" => "Remote host",
        "Sortable" => false,
    ],
    "DataTwo" => [
        "Heading" => "Request",
    ],
];

if ($H_FilterType == FT_CONTACT) {
    $H_ListFields["DataTwo"]["Heading"] = "Sample Request";
    $H_ListFields["EventDate"]["Heading"] = "Most Recent Request";

    $H_ListFields["Count"] = ["Heading" => "Request Count"];
} elseif ($H_FilterType == FT_HARVEST ||
        $H_FilterType == FT_SETS) {
    $H_ListFields["Count"] = ["Heading" => "Continuation Count"];
}

# sort the data
$SortFunctions = [
    "EventDate" => function ($V1, $V2) {
        return StdLib::SortCompare(
            strtotime($V1["EventDate"]),
            strtotime($V2["EventDate"])
        );
    },
    "R" => function ($V1, $V2) {
        return StdLib::SortCompare(
            strtotime($V1["EventDate"]),
            strtotime($V2["EventDate"])
        );
    },
    "DataTwo" => function ($V1, $V2) {
        return strcmp($V1["DataTwo"], $V2["DataTwo"]);
    },
    "Count" => function ($V1, $V2) {
        return StdLib::SortCompare($V1["Count"], $V2["Count"]);
    }
];
uasort($H_HarvestData, $SortFunctions[$SortField]);

# reverse if requested
if ($RevSort) {
    $H_HarvestData = array_reverse($H_HarvestData);
}

$H_BaseLink = "index.php?P=P_MetricsReporter_OAILog"
        ."&ST=".$H_StartTime
        ."&RP=".$H_ResultsPerPage
        ."&FT=".$H_FilterType;


# construct the TransportControls
$H_TransportUI = new TransportControlsUI();
$H_TransportUI->itemsPerPage($H_ResultsPerPage);

$H_TotalResults = count($H_HarvestData);
$H_TransportUI->ItemCount($H_TotalResults);

$H_HarvestData = array_slice(
    $H_HarvestData,
    $H_TransportUI->StartingIndex(),
    $H_ResultsPerPage,
    true
);

# if the user requested JSON data, produce that as output
if (isset($_GET["JSON"])) {
    $GLOBALS["AF"]->SuppressHTMLOutput();
    header("Content-Type: application/json; charset="
           .InterfaceConfiguration::getInstance()->getString("DefaultCharacterSet"), true);

    print json_encode($H_HarvestData);
    return;
}
