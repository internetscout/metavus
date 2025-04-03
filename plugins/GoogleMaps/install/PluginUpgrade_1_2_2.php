<?PHP
#
#   FILE:  PluginUpgrade_1_2_2.php (GoogleMaps plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\GoogleMaps;

use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the GoogleMaps plugin to version 1.2.2.
 */
class PluginUpgrade_1_2_2 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.2.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $DB->query(
            "ALTER TABLE GoogleMapsCallbacks "
            ."RENAME TO GoogleMaps_Callbacks"
        );
        $DB->query(
            "ALTER TABLE GoogleMapsGeocodes "
            ."RENAME TO GoogleMaps_Geocodes"
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
