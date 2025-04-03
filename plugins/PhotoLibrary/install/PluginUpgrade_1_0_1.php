<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (PhotoLibrary plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\PhotoLibrary;
use Metavus\MetadataSchema;
use Metavus\Plugins\PhotoLibrary;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the PhotoLibrary plugin to version 1.0.1.
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
        $Plugin = PhotoLibrary::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getConfigSetting("MetadataSchemaId"));
        $Schema->setViewPage(PhotoLibrary::VIEW_PAGE_LINK);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
