<?PHP
#
#   FILE:  PluginUpgrade_2_1_4.php (UrlChecker plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use Metavus\Plugins\UrlChecker;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the UrlChecker plugin to version 2.1.4.
 */
class PluginUpgrade_2_1_4 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.4.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = UrlChecker::getInstance();
        $Plugin->setConfigSetting(
            "TaskPriority",
            ApplicationFramework::PRIORITY_BACKGROUND
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
