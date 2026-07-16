<?PHP
#
#   FILE:  BlogReports.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MetricsReporter;
use Metavus\Plugins\SocialMedia;
use ScoutLib\ApplicationFramework;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Helper to create and deal with summary data
* @param array $Array Summary aray.
* @param mixed $Key Key to create or increment
*/
function createOrIncrement(&$Array, $Key): void
{
    if (!isset($Array[$Key])) {
        $Array[$Key] = 1;
    } else {
        $Array[$Key]++;
    }
}


# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Blog Usage Metrics");

# make sure user has sufficient permission to view report
if (!User::requirePrivilege(PRIV_COLLECTIONADMIN)) {
    return;
}

# grab ahold of the relevant metrics objects
$MetricsRecorderPlugin = MetricsRecorder::getInstance();
$MetricsReporterPlugin = MetricsReporter::getInstance();

$Blog = Blog::getInstance();

$Now = time();

$Past = [
    "Week"  => $Now -   7 * 86400,
    "Month" => $Now -  30 * 86400,
    "Year" => $Now - 365 * 86400
];

$H_StartDates = [
    "Week" => date('Y-m-d', $Past["Week"]),
    "Month" => date('Y-m-d', $Past["Month"]),
    "Year" => date('Y-m-d', $Past["Year"])
];

#
# Blog Views per day
#
$ViewsPerDay = [];
$H_ViewData = [
    "Week" => [],
    "Month" => [],
    "Year" => []
];


$Events = $MetricsRecorderPlugin->getEventData(
    "Blog",
    "ViewEntry",
    null,
    null,
    null,
    null,
    null,
    $MetricsReporterPlugin->getConfigSetting("PrivsToExcludeFromCounts")
);
foreach ($Events as $Event) {
    $TS = strtotime(date('Y-m-d', strtotime($Event["EventDate"])));

    if (!isset($ViewsPerDay[$TS])) {
        $ViewsPerDay[$TS] = [1];
    } else {
        $ViewsPerDay[$TS][0] += 1;
    }

    foreach (["Week","Month","Year"] as $Period) {
        if ($Past[$Period] < $TS) {
            createOrIncrement($H_ViewData[$Period], $Event["DataOne"]);
        }
    }
}

# default graphs are square; set a height to adjust to an 1.33 aspect ratio
$GraphHeight = 450;

$H_ViewsPerDay = new MultiDateChart();
$H_ViewsPerDay->data($ViewsPerDay);
$H_ViewsPerDay->height($GraphHeight);
$H_ViewsPerDay->makeAutosizing();

#
# Blog shares per day
#

# Get a list of all the Resources that are also events
# Use that to filter the shares data

$BlogFactory = new RecordFactory($Blog->getSchemaId());
$PostIds = array_flip($BlogFactory->getItemIds());

$SharesData = [];
$H_ShareData = [
    "Week" => [],
    "Month" => [],
    "Year" => []
];

$ShareTypeMap = [
    SocialMedia::SITE_EMAIL => 0,
    SocialMedia::SITE_FACEBOOK => 1,
    SocialMedia::SITE_TWITTER => 2,
    SocialMedia::SITE_LINKEDIN => 3,
    "gp" => 4 # old data covering shares on Google+
];

foreach ($MetricsRecorderPlugin->getEventData(
    "SocialMedia",
    "ShareResource",
    null,
    null,
    null,
    null,
    null,
    $MetricsReporterPlugin->getConfigSetting("PrivsToExcludeFromCounts"),
    0,
    null
) as $Event) {
    # Skip non-event shares:
    if (!isset($PostIds[$Event["DataOne"]])) {
        continue;
    }

    $TS =  strtotime($Event["EventDate"]);

    $SharesData[$TS] = [0, 0, 0, 0, 0];
    $SharesData[$TS][$ShareTypeMap[$Event["DataTwo"]]] = 1;

    foreach (["Week", "Month", "Year"] as $Period) {
        if ($Past[$Period] < $TS) {
            createOrIncrement($H_ShareData[$Period], $Event["DataOne"]);
        }
    }
}

$H_SharesPerDay = new MultiDateChart();
$H_SharesPerDay->data($SharesData);
$H_SharesPerDay->height($GraphHeight);
$H_SharesPerDay->makeAutosizing();
$H_SharesPerDay->labels(["Email", "Facebook", "Twitter", "LinkedIn", "Google+"]);
$H_SharesPerDay->colors(["C5C53B", "2E4588", "2EC1FD", "007000", "A01E1A"]);

#
# Most viewed and shared blog posts
#
foreach (["Week", "Month", "Year"] as $Period) {
    arsort($H_ViewData[$Period]);
    arsort($H_ShareData[$Period]);
}
