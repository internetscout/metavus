<?PHP
#
#   FILE:  PluginUpgrade_2_0_14.php (Pages plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Pages;
use Metavus\MetadataSchema;
use Metavus\Plugins\Pages;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Pages plugin to version 2.0.14.
 */
class PluginUpgrade_2_0_14 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.0.14.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Pages::getInstance(true);
        $DB = new Database();
        if (!$DB->tableExists("Records")) {
            return "Records table does not exist";
        }

        # set our item class name
        $Schema = new MetadataSchema($Plugin->getConfigSetting("MetadataSchemaId"));
        $Schema->setItemClassName("Metavus\\Plugins\\Pages\\Page");

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
