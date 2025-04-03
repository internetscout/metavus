<?PHP
#
#   FILE:  PluginUpgrade_1_2_8.php (GoogleMaps plugin)
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
 * Class for upgrading the GoogleMaps plugin to version 1.2.8.
 */
class PluginUpgrade_1_2_8 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.8.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $DB->query(
            "ALTER TABLE GoogleMaps_Geocodes "
            ."ADD COLUMN AddrData MEDIUMBLOB"
        );
        $DB->query("DELETE FROM GoogleMaps_Geocodes");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
