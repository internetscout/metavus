<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (Captcha plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Captcha;

use Metavus\Plugins\Captcha;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Captcha plugin to version 1.0.1.
 */
class PluginUpgrade_1_0_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Captcha::getInstance(true);

        if (is_null($Plugin->DB)) {
            $Plugin->DB = new Database();
        }
        $Method = $Plugin->DB->queryValue("
            SELECT V FROM CaptchaPrefs
            WHERE K='Method'", "Method");

        # migrate existing plugin configuration from the database
        $Plugin->setConfigSetting("Method", $Method);

        # new image dimensions configuration
        $Plugin->setConfigSetting("Width", 115);
        $Plugin->setConfigSetting("Height", 40);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
