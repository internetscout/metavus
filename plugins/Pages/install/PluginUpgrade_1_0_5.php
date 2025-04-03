<?PHP
#
#   FILE:  PluginUpgrade_1_0_5.php (Pages plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Pages;

use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Pages plugin to version 1.0.5.
 */
class PluginUpgrade_1_0_5 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.5.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        if (!$DB->tableExists("Records")) {
            return "Records table does not exist";
        }

        # convert content column in database to larger type
        $Result = $DB->query("ALTER TABLE Pages_Pages"
                ." MODIFY COLUMN PageContent MEDIUMTEXT");
        if ($Result === false) {
            return "Upgrade failed converting"
                ." \"PageContent\" column to MEDIUMTEXT.";
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
