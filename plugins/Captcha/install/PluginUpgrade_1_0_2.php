<?PHP
#
#   FILE:  PluginUpgrade_1_0_2.php (Captcha plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Captcha;

use Metavus\Plugins\Captcha;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Captcha plugin to version 1.0.2.
 */
class PluginUpgrade_1_0_2 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.2.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Captcha::getInstance(true);
        $Method = $Plugin->getConfigSetting("Method");

        # disabling from the preferences is no longer an option since it's
        # assumed when enabling/disabling the plugin
        if (is_null($Method) || $Method == "None" || $Method == "Disabled") {
            $Plugin->setConfigSetting("Method", "Securimage");
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
