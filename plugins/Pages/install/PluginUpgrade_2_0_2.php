<?PHP
#
#   FILE:  PluginUpgrade_2_0_2.php (Pages plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Pages;
use Metavus\MetadataSchema;
use Metavus\Plugins\Pages;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Pages plugin to version 2.0.2.
 */
class PluginUpgrade_2_0_2 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.0.2.
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

        # add Summary metadata field
        $Schema = new MetadataSchema($Plugin->getConfigSetting("MetadataSchemaId"));
        if ($Schema->addFieldsFromXmlFile(Pages::schemaDefinitionFile(), "Pages")
            == false) {
            return "Error Loading Metadata Fields from XML: ".implode(
                " ",
                $Schema->errorMessages("AddFieldsFromXmlFile")
            );
        }

        # populate Summary field for all pages
        PageFactory::$PageSchemaId = $Plugin->getConfigSetting("MetadataSchemaId");
        $PFactory = new PageFactory();
        $Ids = $PFactory->getItemIds();
        foreach ($Ids as $Id) {
            $Page = new Page($Id);
            $Page->set("Summary", $Page->getSummary());
        }

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
