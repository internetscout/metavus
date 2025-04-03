<?PHP
#
#   FILE:  PluginUpgrade_1_2_10.php (MetricsRecorder plugin)
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
 * Class for upgrading the MetricsRecorder plugin to version 1.2.10.
 */
class PluginUpgrade_1_2_10 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.10.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $DB->query(
            "ALTER TABLE MetricsRecorder_EventData "
            ."ADD INDEX Index_IpD (IPAddress,EventDate)"
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
