<?PHP
#
#   FILE:  PluginUpgrade_1_1_3.php (EduLink plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\EduLink;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the EduLink plugin to version 1.1.3.
 */
class PluginUpgrade_1_1_3 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.3.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();
        $DB->query(
            "ALTER TABLE EduLink_Registrations ADD COLUMN IsInternal INT DEFAULT 0"
        );

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
