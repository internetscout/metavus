<?PHP
#
#   FILE:  PluginUpgrade_1_2_0.php (BotDetector plugin)
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
 * Class for upgrading the BotDetector plugin to version 1.2.0.
 */
class PluginUpgrade_1_2_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $Plugin = BotDetector::getInstance(true);

        if ($DB->columnExists("BotDetector_DNSCache", "IP")) {
            $Result = $DB->query(
                "ALTER TABLE BotDetector_DNSCache "
                ."CHANGE IP IPAddress INT UNSIGNED "
            );
            if ($Result === false) {
                return "Could not update the IP Column";
            }
        }

        $Result = $this->createMissingTables();
        if ($Result !== null) {
            return $Result;
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
