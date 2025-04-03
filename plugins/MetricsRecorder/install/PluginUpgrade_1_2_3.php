<?PHP
#
#   FILE:  PluginUpgrade_1_2_3.php (MetricsRecorder plugin)
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
 * Class for upgrading the MetricsRecorder plugin to version 1.2.3.
 */
class PluginUpgrade_1_2_3 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.3.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        # set the errors that can be safely ignored
        $DB = new Database();
        $DB->setQueryErrorsToIgnore([
            "/ALTER TABLE [a-z]+ ADD INDEX/i" => "/Duplicate key name/i"
        ]);

        # add indexes to speed view and click count retrieval
        $DB->query("ALTER TABLE MetricsRecorder_EventData"
                ." ADD INDEX Index_TO (EventType,DataOne(8))");
        $DB->query("ALTER TABLE MetricsRecorder_EventData"
                ." ADD INDEX Index_TOW (EventType,DataOne(8),DataTwo(8))");

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
