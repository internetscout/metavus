<?PHP
#
#   FILE:  PluginUpgrade_1_1_16.php (Developer plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Developer;
use Metavus\Plugins\Developer;
use Metavus\PrivilegeSet;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Developer plugin to version 1.1.16.
 */
class PluginUpgrade_1_1_16 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.1.16.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Developer::getInstance(true);
        # if privileges are not a PrivilegeSet, make them one
        if (!($Plugin->getConfigSetting("VariableMonitorPrivilege"))) {
            $VariableMonitorPrivs = new PrivilegeSet();
            $VariableMonitorPrivs->addPrivilege(
                $Plugin->getConfigSetting("VariableMonitorPrivilege")
            );
            $Plugin->setConfigSetting("VariableMonitorPrivilege", $VariableMonitorPrivs);
        }

        if (!($Plugin->getConfigSetting("PageLoadInfoPrivilege")) instanceof PrivilegeSet) {
            $PageLoadInfoPrivs = new PrivilegeSet();
            $PageLoadInfoPrivs->addPrivilege(
                $Plugin->getConfigSetting("PageLoadInfoPrivilege")
            );
            $Plugin->setConfigSetting("PageLoadInfoPrivilege", $PageLoadInfoPrivs);
        }

        return null;
    }
    # ---- PRIVATE INTERFACE -------------------------------------------------
}
