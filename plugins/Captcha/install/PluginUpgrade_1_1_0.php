<?PHP
#
#   FILE:  PluginUpgrade_1_1_0.php (Captcha plugin)
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
 * Class for upgrading the Captcha plugin to version 1.1.0.
 */
class PluginUpgrade_1_1_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Captcha::getInstance(true);
        $Result = $Plugin->checkCacheDirectory();
        if (!is_null($Result)) {
            return $Result;
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
