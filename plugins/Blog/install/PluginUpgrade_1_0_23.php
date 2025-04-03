<?PHP
#
#   FILE:  PluginUpgrade_1_0_23.php (Blog plugin)
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
 * Class for upgrading the Blog plugin to version 1.0.23.
 */
class PluginUpgrade_1_0_23 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.23.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Blog::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $Schema->setEditPage("index.php?P=EditResource&ID=\$ID");
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
