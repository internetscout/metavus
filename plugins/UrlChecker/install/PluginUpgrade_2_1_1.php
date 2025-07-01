<?PHP
#
#   FILE:  PluginUpgrade_2_1_1.php (UrlChecker plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the UrlChecker plugin to version 2.1.1.
 */
class PluginUpgrade_2_1_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();

        # remove old garbage data
        if (false === $DB->query("
            DELETE FROM UrlChecker_UrlHistory
            WHERE Url NOT REGEXP '^https?:\/\/'")) {
            return "Could not remove stale data from the URL history";
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
