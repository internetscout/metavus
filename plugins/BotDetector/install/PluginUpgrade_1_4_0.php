<?PHP
#
#   FILE:  PluginUpgrade_1_4_0.php (BotDetector plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\BotDetector;

use Metavus\Plugins\BotDetector;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the BotDetector plugin to version 1.4.0.
 */
class PluginUpgrade_1_4_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.4.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = BotDetector::getInstance(true);
        $DB = new Database();
        $DB->query(
            "RENAME TABLE BotDetector_DNSCache TO BotDetector_HttpBLCache"
        );

        $Result = $this->createMissingTables();
        if ($Result !== null) {
            return $Result;
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
