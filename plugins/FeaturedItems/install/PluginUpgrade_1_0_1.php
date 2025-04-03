<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (FeaturedItems plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\FeaturedItems;
use Metavus\Plugins\FeaturedItems;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the FeaturedItems plugin to version 1.0.1.
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
        $Plugin = FeaturedItems::getInstance(true);
        $Plugin->setConfigSetting("FeaturedResourceCache", null);
        $Plugin->setConfigSetting("FeaturedResourceCacheUpdated", null);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
