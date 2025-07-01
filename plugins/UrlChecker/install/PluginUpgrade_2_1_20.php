<?PHP
#
#   FILE:  PluginUpgrade_2_1_20.php (UrlChecker plugin)
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
 * Class for upgrading the UrlChecker plugin to version 2.1.20.
 */
class PluginUpgrade_2_1_20 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.20.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();

        $Result = $DB->query(
            "ALTER TABLE UrlChecker_ResourceHistory"
            ." RENAME UrlChecker_RecordHistory"
        );
        if ($Result === false) {
            return "Failed to rename UrlChecker_ResourceHistory";
        }

        $Tables = ["RecordHistory", "UrlHistory"];
        foreach ($Tables as $Table) {
            $Result = $DB->query(
                "ALTER TABLE UrlChecker_".$Table
                ." CHANGE COLUMN ResourceId RecordId INT"
            );
            if ($Result === false) {
                return "Failed to rename ResourceId column in UrlChecker_".$Table;
            }
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
