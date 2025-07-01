<?PHP
#
#   FILE:  PluginUpgrade_1_1_1.php (SocialMedia plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\SocialMedia;

use Metavus\Plugins\SocialMedia;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the SocialMedia plugin to version 1.1.1.
 */
class PluginUpgrade_1_1_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = SocialMedia::getInstance();
        # set the maximum description length to 1200 by default
        $Plugin->setConfigSetting("MaxDescriptionLength", 1200);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
