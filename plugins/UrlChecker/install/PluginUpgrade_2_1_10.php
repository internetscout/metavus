<?PHP
#
#   FILE:  PluginUpgrade_2_1_10.php (UrlChecker plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use Metavus\Plugins\UrlChecker;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;
use ScoutLib\StdLib;

/**
 * Class for upgrading the UrlChecker plugin to version 2.1.10.
 */
class PluginUpgrade_2_1_10 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.10.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = UrlChecker::getInstance();
        $DB = new Database();

        # make the upgrade process fault tolerant
        $DB->setQueryErrorsToIgnore([
            '/DROP\s+.+/i'
                => '/Unknown\s+table/i',
            '/SELECT\s+.+/i'
                => '/doesn\'t\s+exist/i'
        ]);

        # get old settings data if possible
        $Result = $DB->query("SELECT * FROM UrlChecker_Settings");

        $OldSettings = [];

        # if the query succeeded
        if ($Result) {
            # add the old settings to the array
            while (false !== ($Row = $DB->fetchRow())) {
                $OldSettings[$Row["Name"]] = intval($Row["Value"]);
            }
        }

        # migrate the data to the settings for the plugin
        $Plugin->setConfigSetting(
            "EnableDeveloper",
            (bool)StdLib::getArrayValue($OldSettings, "EnableDeveloper", false)
        );
        $Plugin->setConfigSetting(
            "NextNormalUrlCheck",
            StdLib::getArrayValue($OldSettings, "NextNormalUrlCheck", 0)
        );
        $Plugin->setConfigSetting(
            "NextInvalidUrlCheck",
            StdLib::getArrayValue($OldSettings, "NextInvalidUrlCheck", 0)
        );

        # remove the old settings table if possible
        $DB->query("DROP TABLE UrlChecker_Settings;");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
