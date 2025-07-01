<?PHP
#
#   FILE:  Check.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Plugins\UrlChecker\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Automatically checking an URL...");
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$MyPlugin = \Metavus\Plugins\UrlChecker::getInstance();

$ResourceId = StdLib::getFormValue("ResourceId");
if (Record::itemExists($ResourceId)) {
    $Resource = new Record($ResourceId);
    $MyPlugin->checkResourceUrls($Resource, null);
}

$AF->suppressHtmlOutput();
$AF->setJumpToPage("index.php?P=P_UrlChecker_Results");
