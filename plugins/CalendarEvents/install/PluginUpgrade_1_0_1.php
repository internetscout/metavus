<?PHP
#
#   FILE:  PluginUpgrade_1_0_1.php (CalendarEvents plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;

use Metavus\MetadataSchema;
use Metavus\Plugins\CalendarEvents;
use Metavus\PrivilegeSet;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the CalendarEvents plugin to version 1.0.1.
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
        $Plugin = CalendarEvents::getInstance(true);
        # fix the viewing privileges
        $Schema = new MetadataSchema($Plugin->getSchemaId());
        $ViewingPrivs = new PrivilegeSet();
        $ViewingPrivs->addPrivilege(PRIV_NEWSADMIN);
        $ViewingPrivs->addPrivilege(PRIV_SYSADMIN);
        $ViewingPrivs->addCondition($Schema->getField("Release Flag"), 1);
        $Subgroup = new PrivilegeSet();
        $ViewingPrivs->addCondition($Schema->getField("Added By Id"));
        $Schema->viewingPrivileges($ViewingPrivs);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
