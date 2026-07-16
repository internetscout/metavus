<?PHP
#
#   FILE:  PluginUpgrade_1_1_4.php (EduLink plugin)
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
 * Class for upgrading the EduLink plugin to version 1.1.4.
 */
class PluginUpgrade_1_1_4 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.4.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $DB = new Database();

        # add InstitutionName column
        $DB->query(
            "ALTER TABLE EduLink_Registrations ADD COLUMN InstitutionName TEXT"
        );

        # and populate it from contact email
        $DB->query(
            "UPDATE EduLink_Registrations"
                ." SET InstitutionName = REGEXP_REPLACE(ContactEmail, '[^@]*@', '')"
        );

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
