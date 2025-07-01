<?PHP
#
#   FILE:  Status.php (AutoFetch plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use Exception;
use Metavus\Plugins\AutoFetch;
use ScoutLib\StdLib;

if (!User::requirePrivilege(PRIV_COLLECTIONADMIN)) {
    return;
}

# set up constants for tab numbers
define("TAB_ERRORS", 0);
define("TAB_FETCHES", 1);

$AutoFetchPlugin = AutoFetch::getInstance();

if (!($AutoFetchPlugin instanceof \Metavus\Plugins\AutoFetch)) {
    throw new Exception("Retrieved plugin is not AutoFetch (should be impossible).");
}

# extract viewing parameters
$H_ResultsPerPage = intval(StdLib::getFormValue("RP", 50));

$H_BaseLink = "index.php?P=P_AutoFetch_Status&amp;RP=".$H_ResultsPerPage;

# get total number of URLs we're watching
$H_NumberMonitored = $AutoFetchPlugin->GetUrlCount();

# get list of errors
$H_ErrorList = $AutoFetchPlugin->GetErrorList();
$H_TotalErrors = count($H_ErrorList);

# get complete fetch log
$H_FetchList = $AutoFetchPlugin->GetFetchList();
$H_TotalFetches = count($H_FetchList);

# pull out the active tab, sort field, rev sort, and start index
$H_ActiveTab = StdLib::getFormValue("AT", TAB_ERRORS);

$Tabs = [ TAB_ERRORS, TAB_FETCHES ];
foreach ($Tabs as $Tab) {
    $H_TransportUIs[$Tab] = new TransportControlsUI($Tab);
    $H_TransportUIs[$Tab]->defaultSortField("R");
    $H_SortFields[$Tab] = $H_TransportUIs[$Tab]->sortField();
    $H_RevSort[$Tab] = $H_TransportUIs[$Tab]->reverseSortFlag();
    $H_StartIndexes[$Tab] = $H_TransportUIs[$Tab]->startingIndex();
}

# sort according to requested fields
switch ($H_SortFields[TAB_ERRORS]) {
    case "R":
    case "Date":
        uasort($H_ErrorList, function ($a, $b) {
            return StdLib::SortCompare($b["Date"], $a["Date"]);
        });
        break;

    case "Error":
        uasort($H_ErrorList, function ($a, $b) {
            return strcmp($a["Error"], $b["Error"]);
        });
        break;

    default:
        throw new Exception("Unsupported sort field: ".$H_SortFields[TAB_ERRORS]);
}

switch ($H_SortFields[TAB_FETCHES]) {
    case "R":
    case "FetchDate":
        uasort($H_FetchList, function ($a, $b) {
            return StdLib::SortCompare($b["FetchDate"], $a["FetchDate"]);
        });
        break;

    default:
        throw new Exception("Unsupported sort field: ".$H_SortFields[TAB_FETCHES]);
}

# handle reverse sort flags
if ($H_RevSort[TAB_ERRORS]) {
    $H_ErrorList = array_reverse($H_ErrorList);
}

if ($H_RevSort[TAB_FETCHES]) {
    $H_FetchList = array_reverse($H_FetchList);
}

# paginate errors
$H_ErrorList = array_slice(
    $H_ErrorList,
    $H_StartIndexes[TAB_ERRORS],
    $H_ResultsPerPage
);

# paginate successful fetches
$H_FetchList = array_slice(
    $H_FetchList,
    $H_StartIndexes[TAB_FETCHES],
    $H_ResultsPerPage
);
