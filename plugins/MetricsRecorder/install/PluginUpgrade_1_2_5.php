<?PHP
#
#   FILE:  PluginUpgrade_1_2_5.php (MetricsRecorder plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\MetricsRecorder;

use Metavus\MetadataSchema;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the MetricsRecorder plugin to version 1.2.5.
 */
class PluginUpgrade_1_2_5 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.2.5.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        # update ownership of count metadata fields if they exist
        $Schema = new MetadataSchema();
        if ($Schema->fieldExists("Full Record View Count")) {
            $Field = $Schema->getField("Full Record View Count");
            $Field->owner("MetricsRecorder");
            $Field->description(str_replace(
                "Reporter",
                "Recorder",
                $Field->description()
            ));
        }
        if ($Schema->fieldExists("URL Field Click Count")) {
            $Field = $Schema->getField("URL Field Click Count");
            $Field->owner("MetricsRecorder");
            $Field->description(str_replace(
                "Reporter",
                "Recorder",
                $Field->description()
            ));
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
