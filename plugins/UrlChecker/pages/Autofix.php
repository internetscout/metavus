<?PHP
#
#   FILE:  Autofix.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\Plugins\UrlChecker\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Automatically fixing URL...");
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$MyPlugin = \Metavus\Plugins\UrlChecker::getInstance();

$H_Id = StdLib::getFormValue("Id");
$UrlInfo = $MyPlugin->decodeUrlIdentifier($H_Id);

$ResourceId = $UrlInfo["RecordId"];
$FieldId = $UrlInfo["FieldId"];

if (Record::itemExists($ResourceId) &&
    MetadataSchema::fieldExistsInAnySchema($FieldId)) {
    $MyPlugin->autofixUrl($H_Id);
}

$AF->suppressHtmlOutput();
$AF->setJumpToPage("index.php?P=P_UrlChecker_Results");
