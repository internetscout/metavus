<?PHP
#
#   FILE:  BlogReports.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Graph;
use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Plugins\MetricsReporter;
use Metavus\Plugins\SocialMedia;
use Metavus\RecordFactory;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Helper to create and deal with summary data
* @param array $Array Summary aray.
* @param mixed $Key Key to create or increment
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

PageTitle("Blog Usage Metrics");

# make sure user has sufficient permission to view report
if (!CheckAuthorization(PRIV_COLLECTIONADMIN)) {
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

$H_WeekAgo  = date('Y-m-d', $Past["Week"]);
$H_MonthAgo = date('Y-m-d', $Past["Month"]);
$H_YearAgo  = date('Y-m-d', $Past["Year"]);

#
# Blog Views per day
#
$ViewsPerDay = [];
$H_ViewData = [
    "Week" => [],
    "Month" => [],
    "Year" => []
];

foreach ($MetricsRecorderPlugin->GetEventData(
    "Blog",
    "ViewEntry",
    null,
    null,
    null,
    null,
    null,
    $MetricsReporterPlugin->getConfigSetting("PrivsToExcludeFromCounts"),
    0,
    null
) as $Event) {
    $TS = strtotime(date('Y-m-d', strtotime($Event["EventDate"])));

    if (!isset($ViewsPerDay[$TS])) {
        $ViewsPerDay[$TS] = [1];
    } else {
        $ViewsPerDay[$TS][0] += 1;
    }

    foreach (["Week","Month","Year"] as $Period) {
        if ($Past[$Period] < $TS) {
            CreateOrIncrement($H_ViewData[$Period], $Event["DataOne"]);
        }
    }
}

$H_ViewsPerDay = new Graph(Graph::TYPE_DATE_BAR, $ViewsPerDay);
$H_ViewsPerDay->XLabel("Date");
$H_ViewsPerDay->YLabel("Blog Views");

#
# Blog shares per day
#

# Get a list of all the Resources that are also events
# Use that to filter the shares data

$BlogFactory = new RecordFactory($Blog->GetSchemaId());
$PostIds = array_flip($BlogFactory->GetItemIds());

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

foreach ($MetricsRecorderPlugin->GetEventData(
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
            CreateOrIncrement($H_ShareData[$Period], $Event["DataOne"]);
        }
    }
}

$H_SharesPerDay = new Graph(Graph::TYPE_DATE_BAR, $SharesData);
$H_SharesPerDay->XLabel("Date");
$H_SharesPerDay->YLabel("Blog Shares");
$H_SharesPerDay->Legend(["Email", "Facebook", "Twitter", "LinkedIn", "Google+"]);
$H_SharesPerDay->Scale(Graph::WEEKLY);

#
# Most viewed and shared blog posts
#

foreach (["Week", "Month", "Year"] as $Period) {
    arsort($H_ViewData[$Period]);
    arsort($H_ShareData[$Period]);
}
