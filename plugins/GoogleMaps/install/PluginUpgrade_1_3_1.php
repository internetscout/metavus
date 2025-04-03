<?PHP
#
#   FILE:  PluginUpgrade_1_3_1.php (GoogleMaps plugin)
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
 * Class for upgrading the GoogleMaps plugin to version 1.3.1.
 */
class PluginUpgrade_1_3_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.3.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $DB->createMissingTables(
            CallbackManager::$SqlTables
        );
        return $this->createMissingTables();
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
