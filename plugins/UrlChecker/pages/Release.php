<?PHP
#
#   FILE:  Release.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Releasing a Resource...");
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$MyPlugin = \Metavus\Plugins\UrlChecker::getInstance();

$ResourceId = StdLib::getFormValue("ResourceId");
if (Record::itemExists($ResourceId)) {
    $Resource = Record::getRecord($ResourceId);
    $MyPlugin->releaseResource($Resource);
}

$AF->suppressHtmlOutput();
$AF->setJumpToPage("index.php?P=P_UrlChecker_Results");
