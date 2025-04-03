<?PHP
#
#   FILE:  PluginUpgrade_2_0_5.php (Pages plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Pages;
use Metavus\MetadataSchema;
use Metavus\Plugins\Pages;
use Metavus\Record;
use ScoutLib\Database;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Pages plugin to version 2.0.5.
 */
class PluginUpgrade_2_0_5 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 2.0.5.
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

        $Schema = new MetadataSchema($Plugin->getConfigSetting("MetadataSchemaId"));
        $Result = $Schema->addFieldsFromXmlFile(
            Pages::schemaDefinitionFile(),
            "Pages"
        );

        if ($Result == false) {
            return "Error Loading Metadata Fields from XML: "
                .implode(" ", $Schema->errorMessages("AddFieldsFromXmlFile"));
        }

        PageFactory::$PageSchemaId = $Schema->id();
        $PFactory = new PageFactory();

        # set hashes for previously loaded pages
        $ContentHashes = [
            "help/collections"
                => "7c2dbd3394a13c743c76c8d893dc95358e4f81c5b12d32a592ceb2d93aec5bb2",
            "help/collections/customizing_metadata_fields"
                => "dd018590a21d89f95205c9012065186d365f079b113e9d760d3303761cde6667",
            "help/collections/metadata_field_editor"
                => "70dbcc9fc8f8956c952c3a64b84a22fd23d0c395adcc19c9f0cda2bf78639d8d",
            "help/collections/permissions"
                => "e0fca33ae31ddf37f6966df9601e52164ea7413e252c29bc8a1f4381f618bbf3",
            "help/metadata/updating_controlled_names"
                => "82e34db618c255eb9a57878fe45cb00ce338eb3879a6733d4e28c28c167c3e4d",
            "help/metadata/updating_option_lists"
                => "c690c83c83168d491432135b6dc750b2cbabee665e839bc787cfbe21a67b365a",
            "help/users/user_access_privilege_flags"
                => "63c612c3a63e28909365eff85f5630b97739b26622f1f665b1aef9a565ad9cb0"
        ];

        foreach ($ContentHashes as $Url => $ContentHash) {
            $Matches = $PFactory->getIdsOfMatchingRecords(
                ["Clean URL" => $Url]
            );

            if (count($Matches) == 0) {
                continue;
            }

            $Page = new Record(array_shift($Matches));
            $Page->set("Initial Content Hash", $ContentHash);
        }

        # (updates will happen in initialize)

        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
