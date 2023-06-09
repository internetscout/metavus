<?PHP
#
#   FILE:  ReportError.php (GoogleMaps plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

# this page allows client-side javascript to report errors back to the server

use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

ApplicationFramework::getInstance()->suppressHTMLOutput();
$Msg = StdLib::getArrayValue($_POST, "msg");

if ($Msg !== null && strlen($Msg) > 0) {
    $GMaps = PluginManager::getInstance()->getPluginForCurrentPage();
    $GMaps->logJavascriptError($Msg);
}
