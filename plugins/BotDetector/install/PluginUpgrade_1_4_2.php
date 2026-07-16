<?PHP
#
#   FILE:  PluginUpgrade_1_4_2.php (BotDetector plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\BotDetector;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the BotDetector plugin to version 1.4.2.
 */
class PluginUpgrade_1_4_2 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.4.2.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        # (versions of the 1.4.1 upgrade shipped prior to Metavus 1.2.2 had a
        # typo in their DROP for this table, repeat the DROP here to ensure
        # the table gets removed)
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
