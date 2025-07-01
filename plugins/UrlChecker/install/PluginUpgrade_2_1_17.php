<?PHP
#
#   FILE:  PluginUpgrade_2_1_17.php (UrlChecker plugin)
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
 * Class for upgrading the UrlChecker plugin to version 2.1.17.
 */
class PluginUpgrade_2_1_17 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.17.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();

        if (false === $DB->query("
            ALTER TABLE UrlChecker_UrlHistory
            ADD CheckDuration INT DEFAULT NULL
            AFTER CheckDate")) {
            return "Could not add the CheckDuration column";
        }

        # set a default duration of 25 seconds
        $DB->query(
            "UPDATE UrlChecker_UrlHistory SET CheckDuration=25"
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
