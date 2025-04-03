<?PHP
#
#   FILE:  PluginUpgrade_1_2_2.php (BotDetector plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\BotDetector;

use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the BotDetector plugin to version 1.2.2.
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
        if (!$DB->columnExists("BotDetector_DNSCache", "LastUsed")) {
            $Result = $DB->query(
                "ALTER TABLE BotDetector_DNSCache "
                ."ADD COLUMN LastUsed TIMESTAMP, ADD INDEX (LastUsed)"
            );
            if ($Result === false) {
                return "Could not add LastUsed column to DNSCache";
            }
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
