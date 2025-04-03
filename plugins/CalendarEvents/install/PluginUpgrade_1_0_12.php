<?PHP
#
#   FILE:  PluginUpgrade_1_0_12.php (CalendarEvents plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;

use Metavus\Plugins\CalendarEvents;
use Metavus\PrivilegeSet;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the CalendarEvents plugin to version 1.0.12.
 */
class PluginUpgrade_1_0_12 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.12.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = CalendarEvents::getInstance(true);
        if (is_array($Plugin->getConfigSetting("ViewMetricsPrivs"))) {
            $Plugin->setConfigSetting(
                "ViewMetricsPrivs",
                new PrivilegeSet($Plugin->getConfigSetting("ViewMetricsPrivs"))
            );
        }
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
