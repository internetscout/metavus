<?PHP
#
#   FILE:  PluginUpgrade_1_0_2.php (GoogleMaps plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\GoogleMaps;
use Metavus\Plugins\GoogleMaps;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the GoogleMaps plugin to version 1.0.2.
 */
class PluginUpgrade_1_0_2 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.2.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = GoogleMaps::getInstance(true);
        $DB = new Database();
        $DB->query(
            "CREATE TABLE GoogleMapsGeocodes ("
            ."Id VARCHAR(32) UNIQUE, "
            ."Lat DOUBLE, "
            ."Lng DOUBLE, "
            ."LastUpdate TIMESTAMP, "
            ."LastUsed TIMESTAMP, "
            ."INDEX (Id))"
        );
        $Plugin->setConfigSetting("ExpTime", 604800);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
