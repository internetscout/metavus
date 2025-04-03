<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (Pages plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Pages;
use Metavus\Plugins\Pages;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Pages plugin to version 1.0.1.
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
        $Plugin = Pages::getInstance(true);
        $DB = new Database();
        if (!$DB->tableExists("Records")) {
            return "Records table does not exist";
        }

        $PrivilegeToAuthor = $Plugin->getConfigSetting("PrivilegeToAuthor");
        $PrivilegeToEdit = $Plugin->getConfigSetting("PrivilegeToEdit");

        # if authoring privilege is an array because it hasn't been edited
        # since the plugin was installed
        if (is_array($PrivilegeToAuthor)) {
            $Plugin->setConfigSetting(
                "PrivilegeToAuthor",
                array_shift($PrivilegeToAuthor)
            );
        }

        # if editing privilege is an array because it hasn't been edited
        # since the plugin was installed
        if (is_array($PrivilegeToEdit)) {
            $Plugin->setConfigSetting("PrivilegeToEdit", array_shift($PrivilegeToEdit));
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
