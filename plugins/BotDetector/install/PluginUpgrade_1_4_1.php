<?PHP
#
#   FILE:  PluginUpgrade_1_4_1.php (BotDetector plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2025-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\BotDetector;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the BotDetector plugin to version 1.4.1.
 */
class PluginUpgrade_1_4_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.4.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $Result = $DB->query(
            "DROP TABLE IF EXISTS BotDetector_HostnameCache"
        );
        if ($Result === false) {
            return "Error running database query: ".$DB->queryErrMsg();
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
