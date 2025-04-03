<?PHP
#
#   FILE:  Hide.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\Plugins\UrlChecker\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

PageTitle("Hiding a URL in the URL Checker...");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$MyPlugin = \Metavus\Plugins\UrlChecker::getInstance();

$Id = StdLib::getFormValue("Id");
$UrlInfo = $MyPlugin->decodeUrlIdentifier($Id);
$ResourceId = $UrlInfo["RecordId"];
$FieldId = $UrlInfo["FieldId"];

if (Record::itemExists($ResourceId) &&
    MetadataSchema::fieldExistsInAnySchema($FieldId)) {
    $MyPlugin->hideUrl($Id);
}

ApplicationFramework::getInstance()->setJumpToPage("P_UrlChecker_Results");
