<?PHP
#
#   FILE: ManualUpload.php (ChatPDF plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

namespace Metavus;

use Metavus\Plugins\ChatPDF;
use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();
$AF->suppressHTMLOutput();
if (ApplicationFramework::reachedViaAjax()) {
    $RecordId = $_POST["RecordId"];
    $FileIds = $_POST["FileIds"];
    $ChatPDFPlugin = ChatPDF::getInstance();
    $Responses = $ChatPDFPlugin->handleManualButtonPress($RecordId, $FileIds);
    echo json_encode($Responses);
}
