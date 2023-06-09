<?PHP
#
#   FILE:  GetKML.php (GoogleMaps plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

$AF = ApplicationFramework::getInstance();
$AF->suppressHTMLOutput();

$PointProvider = StdLib::getArrayValue($_GET, "PP");
$DetailProvider = StdLib::getArrayValue($_GET, "DP");

# only continue if given the parameters
if ($PointProvider && $DetailProvider) {
    $GMaps = PluginManager::getInstance()->getPluginForCurrentPage();

    $Path = $GMaps->GetKml($PointProvider, $DetailProvider);

    if (file_exists($Path)) {
        # send the file but unbuffered to avoid memory issues
        header('Content-type: application/vnd.google-earth.kml+xml');
        $AF->AddUnbufferedCallback("readfile", [$Path]);
    } else {
        # if kml could not be generated, return an error
        header($_SERVER["SERVER_PROTOCOL"]." 500 Internal Server Error");
        print "ERROR: Unable to generate KML. "
            ."Server admin can check cwis.log for details.";
    }
}
