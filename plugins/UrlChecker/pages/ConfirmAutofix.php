<?PHP
#
#   FILE:  ConfirmAutofix.php (UrlChecker plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\UrlChecker\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

PageTitle("Confirm an automatic fix to the URL");
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);

$AF = ApplicationFramework::getInstance();
$MyPlugin = PluginManager::getInstance()->getPluginForCurrentPage();

$H_Id = StdLib::getFormValue("Id");
$UrlInfo = $MyPlugin->decodeUrlIdentifier($H_Id);

$ResourceId = $UrlInfo["RecordId"];
$FieldId = $UrlInfo["FieldId"];

if (Record::itemExists($ResourceId) &&
    MetadataSchema::fieldExistsInAnySchema($FieldId)) {
    $H_Field = new MetadataField($FieldId);
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
    $AF->suppressHTMLOutput();
    $AF->setJumpToPage("index.php?P=P_UrlChecker_Results");
}
