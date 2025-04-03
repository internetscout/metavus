<?PHP
#
#   FILE:  PluginUpgrade_1_0_3.php (Captcha plugin)
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
 * Class for upgrading the Captcha plugin to version 1.0.3.
 */
class PluginUpgrade_1_0_3 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.3.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Captcha::getInstance(true);
        $Plugin->setConfigSetting("CaptchaCommentPost", true);
        $Plugin->setConfigSetting("CaptchaMessagePost", true);
        $Plugin->setConfigSetting("CaptchaFeedback", true);
        $Plugin->setConfigSetting("CaptchaSignup", true);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
