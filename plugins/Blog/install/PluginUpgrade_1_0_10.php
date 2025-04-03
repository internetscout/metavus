<?PHP
#
#   FILE:  PluginUpgrade_1_0_10.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\Plugins\Blog;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.10.
 */
class PluginUpgrade_1_0_10 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.10.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        # set the notification login prompt
        $Plugin->getConfigSetting("Please log in to subscribe to notifications"
                ." of new blog posts.");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
