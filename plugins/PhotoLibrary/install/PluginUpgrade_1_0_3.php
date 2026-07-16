<?PHP
#
#   FILE:  PluginUpgrade_1_0_3.php (PhotoLibrary plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\PhotoLibrary;

use Metavus\MetadataSchema;
use Metavus\Plugins\PhotoLibrary;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the PhotoLibrary plugin to version 1.0.3.
 */
class PluginUpgrade_1_0_3 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.3.
     * @return null|string Return NULL if upgrade succeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = PhotoLibrary::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $Schema->setOwnerToPlugin($Plugin);
        return null;
    }
}
