<?PHP
#
#   FILE:  EventReports.php (MetricsReporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\InterfaceConfiguration;
use Metavus\Plugins\CalendarEvents;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MetricsReporter;
use Metavus\Plugins\SocialMedia;
use Metavus\RecordFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\PluginManager;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Helper function to deal with summary arrays.
* @param array $Array Array to modify.
* @param string $Key Key to increment.
* @return void
*/
function CreateOrIncrement(&$Array, $Key)
{
    if (!isset($Array[$Key])) {
        $Array[$Key] = 1;
    } else {
        $Array[$Key]++;
    }
}


# ----- MAIN -----------------------------------------------------------------

PageTitle("Event Usage Metrics");

# make sure user has sufficient permission to view report
if (!CheckAuthorization(PRIV_COLLECTIONADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();
$PluginMgr = PluginManager::getInstance();

# check to be sure that the CalendarEvents plugin is actually enabled
#  before doing other things
if (!$PluginMgr->pluginReady("CalendarEvents")) {
    CheckAuthorization(-1);
    return;
}

# grab ahold of the relevant metrics objects
$Recorder = MetricsRecorder::getInstance();
$Reporter = MetricsReporter::getInstance();

$CalendarEvents = CalendarEvents::getInstance();

$Now = time();

$Past = [
    "Week"  => $Now -   7 * 86400,
    "Month" => $Now -  30 * 86400,
    "Year" => $Now - 365 * 86400
];

$H_WeekAgo  = date('Y-m-d', $Past["Week"]);
$H_MonthAgo = date('Y-m-d', $Past["Month"]);
$H_YearAgo  = date('Y-m-d', $Past["Year"]);

# new events per day
$DB = new Database();
$DB->query(
    "SELECT DATE(DateOfRecordCreation".$CalendarEvents->getSchemaId().") AS D,"
    ." COUNT(*) AS CNT FROM Records"
    ." WHERE SchemaId = ".$CalendarEvents->getSchemaId()
    ." AND DateOfRecordCreation".$CalendarEvents->getSchemaId()." IS NOT null"
    ." GROUP BY D"
);

$H_EventsAddedPerDay = [];
while ($Row = $DB->fetchRow()) {
    $TS = strtotime($Row["D"]);
    $H_EventsAddedPerDay[$TS] = $Row["CNT"];
}

# event views per day
$H_ViewsByDay = [];
$H_TopViews = [
    "Week" => [],
    "Month" => [],
    "Year" => []
];
$Events =     $Recorder->getEventData(
    "CalendarEvents",
    "ViewEvent",
    null,
    null,
    null,
    null,
    null,
    $Reporter->getConfigSetting("PrivsToExcludeFromCounts"),
    0,
    null
);
foreach ($Events as $Event) {
    $TS = strtotime(date('Y-m-d', strtotime($Event["EventDate"])));
    if (!isset($H_ViewsByDay[$TS])) {
        $H_ViewsByDay[$TS] = 0;
    }
    $H_ViewsByDay[$TS]++;

    foreach (["Week", "Month", "Year"] as $Period) {
        if ($Past[$Period] < $TS) {
            CreateOrIncrement($H_TopViews[$Period], $Event["DataOne"]);
        }
    }
}

# event shares per day

# get a list of the ResourceIds for all events, use it to filter shares
$EventFactory = new RecordFactory($CalendarEvents->getSchemaId());
$EventIds = array_flip($EventFactory->getItemIds());

$H_SharesByDay = [];
$H_TopShares = [
    "Week" => [],
    "Month" => [],
    "Year" => []
];

$ShareTypeMap = [
    SocialMedia::SITE_EMAIL => 0,
    SocialMedia::SITE_FACEBOOK => 1,
    SocialMedia::SITE_TWITTER => 2,
    SocialMedia::SITE_LINKEDIN => 3
];

$H_ShareTypeLabels = [
    "Email",
    "Facebook",
    "Twitter",
    "LinkedIn"
];
$H_ShareTypeColors = [
    "#C5C53B",
    "#2E4588",
    "#2EC1FD",
    "#007000",
];
$LegacyLabels = [
    "gp" => "Google+",
];
$LegacyColors = [
    "gp" => "#A01E1A",
];
$NextShareTypeIndex = 4;

$Events = $Recorder->getEventData(
    "SocialMedia",
    "ShareResource",
    null,
    null,
    null,
    null,
    null,
    $Reporter->getConfigSetting("PrivsToExcludeFromCounts"),
    0,
    null
);
foreach ($Events as $Event) {
    # skip non-event shares
    if (!isset($EventIds[$Event["DataOne"]])) {
        continue;
    }

    $TS =  strtotime(date('Y-m-d', strtotime($Event["EventDate"])));
    if (!isset($H_SharesByDay[$TS])) {
        $H_SharesByDay[$TS] = [0, 0, 0, 0, 0];
    }
    if (!isset($ShareTypeMap[$Event["DataTwo"]])) {
        $ShareTypeMap[$Event["DataTwo"]] = $NextShareTypeIndex;
        if (isset($LegacyLabels[$Event["DataTwo"]])) {
            $H_ShareTypeLabels[] = $LegacyLabels[$Event["DataTwo"]];
            $H_ShareTypeColors[] = $LegacyColors[$Event["DataTwo"]];
        } else {
            $H_ShareTypeLabels[] = "Unknown Channel: ".$Event["DataTwo"];
            $H_ShareTypeColors[] = "#".substr(md5($Event["DataTwo"]), 0, 6);
        }
        $NextShareTypeIndex++;
    }
    $H_SharesByDay[$TS][$ShareTypeMap[$Event["DataTwo"]]]++;

    foreach (["Week","Month","Year"] as $Period) {
        if ($Past[$Period] < $TS) {
            CreateOrIncrement($H_TopShares[$Period], $Event["DataOne"]);
        }
    }
}

# most viewed and shared events
foreach (["Week", "Month", "Year"] as $Period) {
    arsort($H_TopViews[$Period]);
    arsort($H_TopShares[$Period]);
}

if (isset($_GET["JSON"])) {
    $AF->suppressHtmlOutput();
    header("Content-Type: application/json; charset="
           .InterfaceConfiguration::getInstance()->getString("DefaultCharacterSet"), true);

    print json_encode([
        "TopViews" => $H_TopViews,
        "TopShares" => $H_TopShares,
        "EventsAdded" => MetricsReporter::formatDateKeys($H_EventsAddedPerDay),
        "ViewsByDay" => MetricsReporter::formatDateKeys($H_ViewsByDay),
        "SharesByDay" => MetricsReporter::formatDateKeys($H_SharesByDay),
    ]);
    return;
}
