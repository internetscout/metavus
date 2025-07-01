<?PHP
#
#   FILE:  PluginUpgrade_2_1_14.php (UrlChecker plugin)
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

/**
 * Class for upgrading the UrlChecker plugin to version 2.1.14.
 */
class PluginUpgrade_2_1_14 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.14.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = UrlChecker::getInstance();
        $DB = new Database();

        $DB->setQueryErrorsToIgnore([
            '/ALTER\s+.+/i'
                => '/check\sthat\scolumn\/key\sexists/i'
        ]);

        $DB->query(
            "ALTER TABLE UrlChecker_ResourceHistory"
            ." DROP COLUMN Time"
        );

            $Plugin->setConfigSetting("CheckDelay", 15);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
