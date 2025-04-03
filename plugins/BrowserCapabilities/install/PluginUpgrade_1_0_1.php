<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (BrowserCapabilities plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\BrowserCapabilities;

use ScoutLib\PluginUpgrade;
use ScoutLib\StdLib;

/**
 * Class for upgrading the BrowserCapabilities plugin to version 1.0.1.
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
        $OldCachePath = getcwd() . "/tmp/caches/BrowserCapabilities";

        # see if the old cache directory still exists
        if (file_exists($OldCachePath)) {
            # remove the old cache directory
            $Result = StdLib::deleteDirectoryTree($OldCachePath);

            # could not remove the old cache directory
            if (!$Result) {
                $Message = "Could not remove the old cache directory (";
                $Message .= $OldCachePath . ").";

                return $Message;
            }
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
