<?PHP
#
#   FILE:  PluginUpgrade_1_3_3.php (GoogleMaps plugin)
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
 * Class for upgrading the GoogleMaps plugin to version 1.3.3.
 */
class PluginUpgrade_1_3_3 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.3.3.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        foreach (["Params", "Payload"] as $Col) {
            $DB->query(
                "ALTER TABLE GoogleMaps_Callbacks "
                ."CHANGE COLUMN ".$Col." ".$Col." BLOB"
            );
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
