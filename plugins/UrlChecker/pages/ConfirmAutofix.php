<?PHP
#
#   FILE:  ConfirmAutofix.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\UrlChecker\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Confirm an automatic fix to the URL");
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
    $H_Field = MetadataField::getField($FieldId);
    $H_Resource = new Record($ResourceId);
    $H_InvalidUrl = $MyPlugin->getInvalidUrl(
        $ResourceId,
        $FieldId,
        $UrlInfo["UrlHash"]
    );
    if (is_null($H_InvalidUrl)) {
        $H_Error = "UrlChecker does not have an entry for this record and URL"
            ." combination. It is possible someone else already fixed it."
            ." Do you need to refresh your URL Checker Results page?";
    }
} else {
    $AF->suppressHtmlOutput();
    $AF->setJumpToPage("index.php?P=P_UrlChecker_Results");
}
