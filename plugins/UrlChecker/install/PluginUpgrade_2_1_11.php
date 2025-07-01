<?PHP
#
#   FILE:  PluginUpgrade_2_1_11.php (UrlChecker plugin)
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
 * Class for upgrading the UrlChecker plugin to version 2.1.11.
 */
class PluginUpgrade_2_1_11 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.11.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = UrlChecker::getInstance();
        $DB = new Database();

        # make the upgrade process fault tolerant
        $DB->setQueryErrorsToIgnore([
            '/ALTER\s+.+/i'
                => '/Duplicate\s+column\s+name/i'
        ]);

        # add the Time column if possible
        $DB->query("
            ALTER TABLE UrlChecker_ResourceHistory
            ADD Time INT DEFAULT ".intval($Plugin::CONNECTION_TIMEOUT));

        # reset the check times (invalid less than normal to make sure an
        # invalid check is performed first)
        $Plugin->setConfigSetting("NextNormalUrlCheck", 1);
        $Plugin->setConfigSetting("NextInvalidUrlCheck", 0);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
