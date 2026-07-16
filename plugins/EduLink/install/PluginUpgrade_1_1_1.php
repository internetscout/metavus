<?PHP
#
#   FILE:  PluginUpgrade_1_1_1.php (EduLink plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\EduLink;

use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the EduLink plugin to version 1.1.1.
 */
class PluginUpgrade_1_1_1 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.1.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $Tables = [
            "HtmlCache",
            "SearchResultsCache",
        ];
        foreach ($Tables as $Table) {
            $DB->query("DROP TABLE IF EXISTS EduLink_".$Table);
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
