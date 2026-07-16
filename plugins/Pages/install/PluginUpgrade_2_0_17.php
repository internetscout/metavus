<?PHP
#
#   FILE:  PluginUpgrade_2_0_17.php (Pages plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Pages;

use Metavus\MetadataSchema;
use Metavus\Plugins\Pages;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Pages plugin to version 2.0.17.
 */
class PluginUpgrade_2_0_17 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.0.17.
     * @return null|string Return NULL if upgrade succeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Pages::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $Schema->setOwnerToPlugin($Plugin);
        return null;
    }
}
