<?PHP
#
#   FILE:  Developer.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\UrlChecker\InvalidUrl;
use Metavus\Plugins\UrlChecker\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- MAIN -----------------------------------------------------------------

global $G_Info;
global $G_NextResourcesToBeChecked;
global $G_NextUrlsToBeChecked;

PageTitle("URL Checker Developer Page");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$MyPlugin = PluginManager::getInstance()->getPluginForCurrentPage();

$G_Info = $MyPlugin->getInformation();
$G_NextResourcesToBeChecked = $MyPlugin->getNextResourcesToBeChecked();
$G_NextUrlsToBeChecked = $MyPlugin->getNextUrlsToBechecked();

if (isset($_GET["QueueNow"])) {
    $MyPlugin->queueResourceCheckTasks();
    ApplicationFramework::getInstance()->setJumpToPage("P_UrlChecker_Developer");
}
