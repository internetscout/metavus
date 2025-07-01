<?PHP
#
#   FILE:  Withhold.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\UrlChecker\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Withholding a Resource...");
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$MyPlugin = \Metavus\Plugins\UrlChecker::getInstance();

$ResourceId = StdLib::getFormValue("ResourceId");

if (Record::itemExists($ResourceId)) {
    $Resource = new Record($ResourceId);
    $MyPlugin->withholdResource($Resource);
}

$AF->suppressHTMLOutput();
$AF->setJumpToPage("index.php?P=P_UrlChecker_Results");
