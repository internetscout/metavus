<?PHP
#
#   FILE:  PluginUpgrade_1_2_0.php (MetricsRecorder plugin)
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
 * Class for upgrading the MetricsRecorder plugin to version 1.2.0.
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
        # add table for tracking custom event types
        $DB = new Database();
        $DB->query("CREATE TABLE IF NOT EXISTS MetricsRecorder_EventTypeIds (
                OwnerName       TEXT,
                TypeName        TEXT,
                TypeId          SMALLINT NOT NULL,
                INDEX           (TypeId));");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
