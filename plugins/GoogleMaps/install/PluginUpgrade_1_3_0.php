<?PHP
#
#   FILE:  PluginUpgrade_1_3_0.php (GoogleMaps plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\GoogleMaps;
use Metavus\Plugins\GoogleMaps;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the GoogleMaps plugin to version 1.3.0.
 */
class PluginUpgrade_1_3_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.3.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = GoogleMaps::getInstance(true);
        $DB = new Database();
        $DB->query(
            "ALTER TABLE GoogleMaps_Geocodes "
            ."ADD COLUMN LastUsed TIMESTAMP"
        );

        $DB->query(
            "DELETE FROM GoogleMaps_Geocodes WHERE Lat IS NULL"
        );

        $DB->query(
            "UPDATE GoogleMaps_Geocodes SET LastUsed=NOW()"
        );

        $Plugin->setConfigSetting("GeocodeLastSuccessful", time());

        $Plugin->setConfigSetting(
            "GeocodeErrorEmailTemplate",
            $Plugin->getMailerTemplateId()
        );
        return $this->createMissingTables();
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
