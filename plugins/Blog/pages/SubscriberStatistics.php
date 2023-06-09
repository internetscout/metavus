<?PHP
#
#   FILE:  SubscriberStatistics.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Graph;
use Metavus\TransportControlsUI;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

PageTitle("Blog Subscription Information");

$PluginMgr = PluginManager::getInstance();
$MyPlugin = $PluginMgr->getPluginForCurrentPage();

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
if ($PluginMgr->pluginEnabled("MetricsRecorder")) {
    # pull our data out of metrics recorder
    $Recorder = $PluginMgr->getPlugin("MetricsRecorder");
    $Data = $Recorder->GetEventData(
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
