<?PHP
#
#   FILE:  PluginUpgrade_2_1_24.php (UrlChecker plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the UrlChecker plugin to version 2.1.24.
 */
class PluginUpgrade_2_1_24 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.1.24.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        # Remove URLs that contain an IMAGEURL keyword pulled in from a
        # paragraph field
        $DB = new Database();
        $DB->query(
            "DELETE FROM UrlChecker_UrlHistory "
            ."WHERE Url LIKE '%{{IMAGEURL|Id:%|Size:%}}'"
        );
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
