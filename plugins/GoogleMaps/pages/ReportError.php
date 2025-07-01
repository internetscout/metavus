<?PHP
#
#   FILE:  ReportError.php (GoogleMaps plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

# ----- MAIN -----------------------------------------------------------------

# this page allows client-side javascript to report errors back to the server

use Metavus\Plugins\GoogleMaps;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

ApplicationFramework::getInstance()->suppressHtmlOutput();
$Msg = StdLib::getArrayValue($_POST, "msg");

if ($Msg !== null && strlen($Msg) > 0) {
    $GMaps = GoogleMaps::getInstance();

    $GMaps->logJavascriptError($Msg);
}
