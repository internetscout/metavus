<?PHP
#
#   FILE:  PluginUpgrade_1_1_2.php (MetricsReporter plugin)
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
 * Class for upgrading the MetricsReporter plugin to version 1.1.2.
 */
class PluginUpgrade_1_1_2 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.2.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $DB->query("CREATE TABLE IF NOT EXISTS MetricsReporter_SpamSearches ("
                    ."SearchKey VARCHAR(32), UNIQUE (SearchKey) )");

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
