<?PHP
#
#   FILE:  PluginUpgrade_1_2_15.php (MetricsRecorder plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\MetricsRecorder;

use Metavus\Plugins\MetricsRecorder;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the MetricsRecorder plugin to version 1.2.15.
 */
class PluginUpgrade_1_2_15 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.15.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = MetricsRecorder::getInstance(true);
        ApplicationFramework::getInstance()
            ->queueUniqueTask(
                "\\Metavus\\Plugins\\MetricsRecorder::runDatabaseUpdates",
                [$Plugin->installedVersion()],
                \ScoutLib\ApplicationFramework::PRIORITY_LOW,
                "Perform database updates for MetricsRecorder upgrade."
            );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
