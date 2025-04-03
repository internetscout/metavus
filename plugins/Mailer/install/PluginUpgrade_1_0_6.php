<?PHP
#
#   FILE:  PluginUpgrade_1_0_6.php (Mailer plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Mailer;

use Metavus\Plugins\Mailer;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Mailer plugin to version 1.0.6.
 */
class PluginUpgrade_1_0_6 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.6.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Mailer::getInstance(true);
        $Plugin->setConfigSetting(
            "EmailTaskPriority",
            ApplicationFramework::PRIORITY_BACKGROUND
        );

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
