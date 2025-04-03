<?PHP
#
#   FILE:  PluginUpgrade_1_1_0.php (BotDetector plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\BotDetector;

use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the BotDetector plugin to version 1.1.0.
 */
class PluginUpgrade_1_1_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Result = $this->createMissingTables();
        if ($Result !== null) {
            return $Result;
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
