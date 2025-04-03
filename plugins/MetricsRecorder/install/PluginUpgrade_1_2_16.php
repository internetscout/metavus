<?PHP
#
#   FILE:  PluginUpgrade_1_2_16.php (MetricsRecorder plugin)
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
 * Class for upgrading the MetricsRecorder plugin to version 1.2.16.
 */
class PluginUpgrade_1_2_16 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.4.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        # update ownership of count metadata fields if they exist
        $Schema = new MetadataSchema();

        $Fields = [
            "Full Record View Count",
            "URL Field Click Count",
        ];
        foreach ($Fields as $Field) {
            if ($Schema->fieldExists($Field)) {
                $Field = $Schema->getField($Field);
                $Field->triggersAutoUpdates(false);
            }
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
