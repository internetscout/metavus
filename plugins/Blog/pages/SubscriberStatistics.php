<?PHP
#
#   FILE:  SubscriberStatistics.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Metavus\Plugins\Blog;
use Metavus\Plugins\MetricsRecorder;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();
$PluginMgr = PluginManager::getInstance();
$MyPlugin = Blog::getInstance();

if (!$PluginMgr->pluginReady("MetricsRecorder")) {
    throw new \Exception(
        "MetricsRecorder not ready (should be impossible)."
    );
}

# set up pagination
$AF->setPageTitle("Blog Subscription Information");
$H_ItemsPerPage = 30;

# get current Message index
$H_StartingIndex = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);

# pull out our current list of subscribers
$H_Subscribers = $MyPlugin->getSubscribers();
$H_SubscriberCount = count($H_Subscribers);

# cut down subscriber list to subsection currently being displayed
$H_Subscribers = array_slice($H_Subscribers, $H_StartingIndex, $H_ItemsPerPage);

# if we have subscriber metrics, make a plot of them

# pull our data out of metrics recorder
$MetricsRecorder = MetricsRecorder::getInstance();
$Data = $MetricsRecorder->getEventData(
    "Blog",
    "NumberOfSubscribers",
    date("Y-m-d", strtotime('-24 months'))
);

# convert into the format that Graph wants
$GraphData = [];
foreach ($Data as $Item) {
    if ($Item["DataTwo"] > 0) {
        $GraphData[strtotime($Item["EventDate"])] = [$Item["DataTwo"]];
    }
}

# generate and label our graph
$H_Graph = new LineChart();
$H_Graph->axisType(LineChart::AXIS_DATE);
$H_Graph->data($GraphData);
$H_Graph->height(450);
$H_Graph->makeAutosizing();
$H_Graph->yLabel("Subscribers");
