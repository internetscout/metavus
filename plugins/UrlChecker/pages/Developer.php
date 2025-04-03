<?PHP
#
#   FILE:  Developer.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

global $G_Info;
global $G_NextResourcesToBeChecked;
global $G_NextUrlsToBeChecked;

PageTitle("URL Checker Developer Page");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$MyPlugin = \Metavus\Plugins\UrlChecker::getInstance();

$G_Info = $MyPlugin->getInformation();
$G_NextResourcesToBeChecked = $MyPlugin->getNextResourcesToBeChecked();
$G_NextUrlsToBeChecked = $MyPlugin->getNextUrlsToBechecked();

if (isset($_GET["QueueNow"])) {
    $MyPlugin->queueResourceCheckTasks();
    ApplicationFramework::getInstance()->setJumpToPage("P_UrlChecker_Developer");
}
