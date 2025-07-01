<?PHP
#
#   FILE:  Developer.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

global $G_Info;
global $G_NextResourcesToBeChecked;
global $G_NextUrlsToBeChecked;

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("URL Checker Developer Page");
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$MyPlugin = \Metavus\Plugins\UrlChecker::getInstance();

$G_Info = $MyPlugin->getInformation();
$G_NextResourcesToBeChecked = $MyPlugin->getNextResourcesToBeChecked();
$G_NextUrlsToBeChecked = $MyPlugin->getNextUrlsToBeChecked();

if (isset($_GET["QueueNow"])) {
    $MyPlugin->queueResourceCheckTasks();
    $AF->setJumpToPage("P_UrlChecker_Developer");
}
