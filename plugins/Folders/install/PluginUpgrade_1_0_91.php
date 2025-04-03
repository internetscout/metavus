<?PHP
#
#   FILE:  PluginUpgrade_1_0_91.php (Folders plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Folders;
use Metavus\Plugins\Folders;
use Metavus\PrivilegeSet;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Folders plugin to version 1.0.91.
 * NOTE: The "91" part of the version number was due to an error where
 *      the version was incorrectly incremented from 1.0.9 to 1.0.91.
 */
class PluginUpgrade_1_0_91 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.91.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = Folders::getInstance(true);
        # PrivsToTransferFolders config setting should be PrivilegeSet, update if not
        if (is_array($Plugin->getConfigSetting("PrivsToTransferFolders"))) {
            $Plugin->setConfigSetting(
                "PrivsToTransferFolders",
                new PrivilegeSet($Plugin->getConfigSetting("PrivsToTransferFolders"))
            );
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
