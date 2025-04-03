<?PHP
#
#   FILE:  PluginUpgrade_2_0_10.php (Pages plugin)
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
 * Class for upgrading the Pages plugin to version 2.0.10.
 */
class PluginUpgrade_2_0_10 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.0.10.
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

        # rewrite links in Pages content to use updated URLs for preview images
        PageFactory::$PageSchemaId = $Plugin->getConfigSetting("MetadataSchemaId");
        $PFactory = new PageFactory();
        $Ids = $PFactory->getItemIds();
        foreach ($Ids as $Id) {
            $Page = new Page($Id);
            $UpdatedContent = preg_replace(
                '%local/data/images/previews/Preview--([0-9]+)\.([a-z]+)%',
                'local/data/caches/images/scaled/img_\1_300x300.\2',
                $Page->get("Content")
            );
            $Page->set("Content", $UpdatedContent);
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
