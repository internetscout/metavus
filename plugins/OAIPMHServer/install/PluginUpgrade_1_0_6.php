<?PHP
#
#   FILE:  PluginUpgrade_1_0_6.php (OAIPMHServer plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\OAIPMHServer;
use Metavus\MetadataSchema;
use Metavus\Plugins\OAIPMHServer;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the OAIPMHServer plugin to version 1.0.6.
 */
class PluginUpgrade_1_0_6 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.6.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = OAIPMHServer::getInstance(true);
        $Formats = $Plugin->getConfigSetting("Formats");
        if (!isset($Formats["oai_dc"]["Elements"]["dc:title"])) {
            $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
            $Formats["oai_dc"]["Elements"]["dc:title"] =
                $Schema->getFieldIdByMappedName("Title");
            $Plugin->setConfigSetting("Formats", $Formats);
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
