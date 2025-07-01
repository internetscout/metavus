<?PHP
#
#   FILE:  SubscriberStatistics.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Blog;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Graph;
use Metavus\TransportControlsUI;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Blog Subscription Information");

$PluginMgr = PluginManager::getInstance();
$MyPlugin = Blog::getInstance();

# set up pagination
$H_ItemsPerPage = 30;

# get current Message index
$H_StartingIndex = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);

# pull out our current list of subscribers
$H_Subscribers = $MyPlugin->GetSubscribers();
$H_SubscriberCount = count($H_Subscribers);

# cut down subscriber list to subsection currently being displayed
$H_Subscribers = array_slice($H_Subscribers, $H_StartingIndex, $H_ItemsPerPage);

# if we have subscriber metrics, make a plot of them
if ($PluginMgr->pluginReady("MetricsRecorder")) {
    # pull our data out of metrics recorder
    $MetricsRecorder = MetricsRecorder::getInstance();
    $Data = $MetricsRecorder->GetEventData(
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
    $H_Graph = new Graph(Graph::TYPE_DATE, $GraphData);
    $H_Graph->Title("");
    $H_Graph->XLabel("Date");
    $H_Graph->YLabel("Subscribers");
}
