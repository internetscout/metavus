<?PHP
#
#   FILE:  PluginUpgrade_2_0_0.php (Captcha plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Captcha;
use Metavus\Plugins\Captcha;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;
use ScoutLib\StdLib;

/**
 * Class for upgrading the Captcha plugin to version 1.1.0.
 */
class PluginUpgrade_2_0_0 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.0.0.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $CachePath = getcwd() . "/local/data/caches/Captcha";
        if (is_dir($CachePath)) {
            if (file_exists($CachePath."/.htaccess")) {
                unlink($CachePath."/.htaccess");
            }
            StdLib::deleteDirectoryTree($CachePath);
        }

        $DB = new Database();

        $Tables = ["IpLog", "UserLog"];
        foreach ($Tables as $Table) {
            $DB->query(
                "DROP TABLE IF EXISTS Captcha_".$Table
            );
        }

        $Plugin = Captcha::getInstance(true);
        $Plugin->setConfigSetting("Method", "Turnstile");

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
