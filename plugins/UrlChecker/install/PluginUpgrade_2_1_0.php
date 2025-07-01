<?PHP
#
#   FILE:  PluginUpgrade_2_1_0.php (UrlChecker plugin)
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
 * Class for upgrading the UrlChecker plugin to version 2.1.0.
 */
class PluginUpgrade_2_1_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();

        // make the upgrade process fault tolerant
        // @codingStandardsIgnoreStart
        $DB->setQueryErrorsToIgnore([
            '/ALTER\s+TABLE\s+[^\s]+\s+ADD\s+.+/i'
                => '/Duplicate\s+column\s+name\s+[^\s]+/i',
            '/ALTER\s+TABLE\s+[^\s]+\s+DROP\s+.+/i'
                => '/Can\'t\s+DROP\s+[^\s;]+;\s+check\s+that\s+column\/key\s+exists/i'
        ]);
        // @codingStandardsIgnoreEnd

        # get old settings data
        if (false === $DB->query("SELECT * FROM UrlChecker_Settings LIMIT 1")) {
            return "Could not get settings data";
        }

        $Row = $DB->fetchRow();
        if (is_array($Row)) {
            $NextNormalUrlCheck = $Row["NextNormalUrlCheck"];
            $NextInvalidUrlCheck = $Row["NextInvalidUrlCheck"];
        } else {
            $NextNormalUrlCheck = 0;
            $NextInvalidUrlCheck = 0;
        }

        # add column
        if (false === $DB->query("ALTER TABLE UrlChecker_Settings ADD Name Text")) {
            return "Could not add the Name column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_Settings ADD Value Text")) {
            return "Could not add the Value column";
        }

        # remove old columns
        if (false === $DB->query("ALTER TABLE UrlChecker_Settings DROP NextNormalUrlCheck")) {
            return "Could not remove the NextNormalUrlCheck Column";
        }
        if (false === $DB->query("ALTER TABLE UrlChecker_Settings DROP NextInvalidUrlCheck")) {
            return "Could not remove the NextInvalidUrlCheck Column";
        }

        # remove any garbage data from the tables
        if (false === $DB->query("DELETE FROM UrlChecker_UrlHistory WHERE ResourceId < 0")) {
            return "Could not remove stale data from the URL history";
        }
        if (false === $DB->query("DELETE FROM UrlChecker_ResourceHistory
            WHERE ResourceId < 0")) {
            return "Could not remove stale data from the resource history";
        }

        # this makes sure that no garbage rows exist
        if (false === $DB->query("DELETE FROM UrlChecker_Settings")) {
            return "Could not remove stale data from the settings table";
        }

        # add settings back into the table
        if (false === $DB->query("
            INSERT INTO UrlChecker_Settings (Name, Value)
            VALUES
            ('NextNormalUrlCheck', '".addslashes($NextNormalUrlCheck)."'),
            ('NextInvalidUrlCheck', '".addslashes($NextInvalidUrlCheck)."'),
            ('EnableDeveloper', '0')")) {
            return "Could not initialize the updated settings";
        }

        # repair and optimize the settings table after the changes
        if (false === $DB->query("REPAIR TABLE UrlChecker_Settings")) {
            return "Could not repair the settings table";
        }
        if (false === $DB->query("OPTIMIZE TABLE UrlChecker_Settings")) {
            return "Could not optimize the settings table";
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
