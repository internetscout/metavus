<?PHP
#
#   FILE:  PluginUpgrade_1_0_2.php (BrowserCapabilities plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\BrowserCapabilities;

use Metavus\Plugins\BrowserCapabilities;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the BrowserCapabilities plugin to version 1.0.2.
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
        $Plugin = BrowserCapabilities::getInstance(true);
        $Plugin->setConfigSetting("EnableDeveloper", false);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
