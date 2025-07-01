<?PHP
#
#   FILE:  PluginUpgrade_1_3_9.php (GoogleMaps plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\GoogleMaps;

use Metavus\Plugins\GoogleMaps;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the GoogleMaps plugin to version 1.3.9.
 */
class PluginUpgrade_1_3_9 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.3.9.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();

        # columns have changed such that the old data is now invalid, so drop
        # and re-create the tables
        $DB->query(
            "DROP TABLE GoogleMaps_GeocodeErrors"
        );
        $DB->query(
            "DROP TABLE GoogleMaps_Geocodes"
        );

        $Result = $this->createMissingTables();
        if ($Result !== null) {
            return $Result;
        }

        # if geocode expiration time was less than 1 day, increase it
        $Plugin = GoogleMaps::getInstance(true);
        $ExpirationTime = $Plugin->getConfigSetting(
            "GeocodeFailureCacheExpirationTime"
        );
        $Plugin->setConfigSetting(
            "GeocodeFailureCacheExpirationTime",
            max(86400, $ExpirationTime)
        );

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
