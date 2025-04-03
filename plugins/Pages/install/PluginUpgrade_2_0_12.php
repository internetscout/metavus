<?PHP
#
#   FILE:  PluginUpgrade_2_0_12.php (Pages plugin)
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
 * Class for upgrading the Pages plugin to version 2.0.12.
 */
class PluginUpgrade_2_0_12 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.0.12.
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

        # rewrite links in Pages content to use the IMAGEURL keyword
        # (the set() will rewrite urls)
        PageFactory::$PageSchemaId = $Plugin->getConfigSetting("MetadataSchemaId");
        $Ids = (new PageFactory())->getItemIds();
        foreach ($Ids as $Id) {
            $Page = new Page($Id);
            $Page->set("Content", $Page->get("Content"));
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
