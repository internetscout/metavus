<?PHP
#
#   FILE:  Withhold.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\UrlChecker\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

PageTitle("Withholding a Resource...");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$AF = ApplicationFramework::getInstance();
$MyPlugin = \Metavus\Plugins\UrlChecker::getInstance();

$ResourceId = StdLib::getFormValue("ResourceId");

if (Record::itemExists($ResourceId)) {
    $Resource = new Record($ResourceId);
    $MyPlugin->withholdResource($Resource);
}

$AF->suppressHTMLOutput();
$AF->setJumpToPage("index.php?P=P_UrlChecker_Results");
