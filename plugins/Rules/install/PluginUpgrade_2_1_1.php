<?PHP
#
#   FILE:  PluginUpgrade_2_1_1.php (Rules plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Rules;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Rules plugin to version 2.1.1.
 */
class PluginUpgrade_2_1_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $DB->query(
            "ALTER TABLE Rules_Rules MODIFY COLUMN LastMatchingIds MEDIUMBLOB"
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
