<?PHP
#
#   FILE:  Hide.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataSchema;
use Metavus\Plugins\UrlChecker\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

PageTitle("Hiding a URL in the URL Checker...");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$MyPlugin = PluginManager::getInstance()->getPluginForCurrentPage();

$Id = StdLib::getFormValue("Id");
$UrlInfo = $MyPlugin->decodeUrlIdentifier($Id);
$ResourceId = $UrlInfo["RecordId"];
$FieldId = $UrlInfo["FieldId"];

if (Record::itemExists($ResourceId) &&
    MetadataSchema::fieldExistsInAnySchema($FieldId)) {
    $MyPlugin->hideUrl($Id);
}

ApplicationFramework::getInstance()->setJumpToPage("P_UrlChecker_Results");
