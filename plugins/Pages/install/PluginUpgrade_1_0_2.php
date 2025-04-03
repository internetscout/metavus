<?PHP
#
#   FILE:  PluginUpgrade_1_0_2.php (Pages plugin)
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
 * Class for upgrading the Pages plugin to version 1.0.2.
 */
class PluginUpgrade_1_0_2 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.2.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        if (!$DB->tableExists("Records")) {
            return "Records table does not exist";
        }

        # add clean URL column to database
        $Result = $DB->query("ALTER TABLE Pages_Pages"
                ." ADD COLUMN CleanUrl TEXT");
        if ($Result === false) {
            return "Upgrade failed adding"
                ." \"CleanUrl\" column to database.";
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
