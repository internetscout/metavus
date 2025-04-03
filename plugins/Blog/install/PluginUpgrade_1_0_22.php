<?PHP
#
#   FILE:  PluginUpgrade_1_0_22.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.22.
 */
class PluginUpgrade_1_0_22 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.22.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        # set our item class name
        $Schema = new MetadataSchema($Plugin->getConfigSetting("MetadataSchemaId"));
        $Schema->setItemClassName("Metavus\\Plugins\\Blog\\Entry");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
