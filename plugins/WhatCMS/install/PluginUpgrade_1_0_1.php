<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (WhatCMS plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\WhatCMS;

use Metavus\Plugins\WhatCMS;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the WhatCMS plugin to version 1.0.1.
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
        $DB = new Database();
        $Plugin = WhatCMS::getInstance();

        $Result = $this->createMissingTables();
        if ($Result !== null) {
            return $Result;
        }

        # populate request info row
        $MostRecentQuery = $Plugin->getConfigSetting("MostRecentQuery");
        $RequestsRemaining = $Plugin->getConfigSetting("RequestsRemaining");

        $DB->query(
            "INSERT INTO WhatCMS_RequestInfo"
            ." (MostRecentQuery, RequestsRemaining)"
            ." VALUES ('".$MostRecentQuery."', ".$RequestsRemaining.")"
        );

        $Plugin->setConfigSetting("MostRecentQuery", null);
        $Plugin->setConfigSetting("RequestsRemaining", null);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
