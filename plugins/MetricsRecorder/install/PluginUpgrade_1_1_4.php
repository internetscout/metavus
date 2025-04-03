<?PHP
#
#   FILE:  PluginUpgrade_1_1_4.php (MetricsRecorder plugin)
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
 * Class for upgrading the MetricsRecorder plugin to version 1.1.4.
 */
class PluginUpgrade_1_1_4 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.4.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        # replace old Type/User index with Type/User/Date index for event data
        $DB = new Database();
        $DB->setQueryErrorsToIgnore([
            '/DROP\s+INDEX\s+[^\s]+\s+\([^)]+\)/i'
                => '/Can\'t\s+DROP\s+[^\s]+\s+check\s+that/i'
        ]);
        $DB->query("DROP INDEX EventType_2 ON MetricsRecorder_EventData");
        $DB->query("CREATE INDEX Index_TUD ON MetricsRecorder_EventData"
                ." (EventType,UserId,EventDate)");

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
