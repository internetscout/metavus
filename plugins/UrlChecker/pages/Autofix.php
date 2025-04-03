<?PHP
#
#   FILE:  Autofix.php (UrlChecker plugin)
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

# ----- MAIN -----------------------------------------------------------------

PageTitle("Automatically fixing URL...");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$AF = ApplicationFramework::getInstance();
$MyPlugin = \Metavus\Plugins\UrlChecker::getInstance();

$H_Id = StdLib::getFormValue("Id");
$UrlInfo = $MyPlugin->decodeUrlIdentifier($H_Id);

$ResourceId = $UrlInfo["RecordId"];
$FieldId = $UrlInfo["FieldId"];

if (Record::itemExists($ResourceId) &&
    MetadataSchema::fieldExistsInAnySchema($FieldId)) {
    $MyPlugin->autofixUrl($H_Id);
}

$AF->suppressHTMLOutput();
$AF->setJumpToPage("index.php?P=P_UrlChecker_Results");
