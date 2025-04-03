<?PHP
#
#   FILE:  PluginUpgrade_1_2_9.php (MetricsRecorder plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\MetricsRecorder;

use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the MetricsRecorder plugin to version 1.2.9.
 */
class PluginUpgrade_1_2_9 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.9.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $DB->query(
            "CREATE TABLE IF NOT EXISTS MetricsRecorder_SentEmails (
                FromAddr        TEXT,
                ToAddr          TEXT,
                Subject         TEXT,
                LogData         BLOB,
                DateSent        DATETIME);"
        );

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
