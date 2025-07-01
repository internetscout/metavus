<?PHP
#
#   FILE:  PluginUpgrade_2_1_21.php (UrlChecker plugin)
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
 * Class for upgrading the UrlChecker plugin to version 2.1.21.
 */
class PluginUpgrade_2_1_21 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.21.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();

        # get a list of the indexes on the UrlHistory table
        $Indexes = [];
        $DB->query("SHOW KEYS FROM UrlChecker_UrlHistory");
        $Rows = $DB->fetchRows();
        foreach ($Rows as $Row) {
            $Indexes[$Row["Key_name"]][] = $Row["Column_name"];
        }

        # if it has a PRIMARY KEY, we'll need to drop it
        if (isset($Indexes["PRIMARY"])) {
            $Result = $DB->query(
                "ALTER TABLE UrlChecker_UrlHistory DROP PRIMARY KEY"
            );
            if ($Result === false) {
                return "Failed to remove primary key from UrlChecker_UrlHistory";
            }
        }

        # prior to r10147, UrlChecker was creating an INDEX (ResourceId, FieldId)
        # that was automatically named ResourceId. Our col renaming will have changed
        # such indexes to cover (RecordId, FieldId). If such an index exists, then we
        # don't need to create anything
        $HaveIndexAlready = isset($Indexes["ResourceId"]) &&
            $Indexes["ResourceId"][0] == "RecordId" &&
            $Indexes["ResourceId"][1] == "FieldId" ? true : false;

        # if we don't already have such an index, create one
        if (!$HaveIndexAlready) {
            $Result = $DB->query(
                "CREATE INDEX Index_RF ON UrlChecker_UrlHistory (RecordId, FieldId)"
            );
            if ($Result === false) {
                return "Failed to create Index_RF for UrlChecker_UrlHistory";
            }
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
