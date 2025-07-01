<?PHP
#
#   FILE:  PluginUpgrade_1_1_3.php (MetricsReporter plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\MetricsReporter;

use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the MetricsReporter plugin to version 1.1.3.
 */
class PluginUpgrade_1_1_3 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.3.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $DB->query("DROP TABLE IF EXISTS MetricsReporter_Cache");

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
