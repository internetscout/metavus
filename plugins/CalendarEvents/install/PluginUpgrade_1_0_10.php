<?PHP
#
#   FILE:  PluginUpgrade_1_0_10.php (CalendarEvents plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\CalendarEvents;

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\CalendarEvents;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the CalendarEvents plugin to version 1.0.10.
 */
class PluginUpgrade_1_0_10 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.10.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = CalendarEvents::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());

        $FieldsToRename = [
            "Date of Modification" => "Date Last Modified",
            "Date of Creation" => "Date Of Record Creation",
            "Date of Release" => "Date Of Record Release",
            "Authored By" => "Added By Id",
            "Last Modified By" => "Last Modified By Id",
        ];

        $UpdateMethods = [
            "Date Last Modified" => MetadataField::UPDATEMETHOD_ONRECORDCHANGE,
            "Last Modified By Id" => MetadataField::UPDATEMETHOD_ONRECORDCHANGE,
            "Date Of Record Creation" => MetadataField::UPDATEMETHOD_ONRECORDCREATE,
            "Added By Id" => MetadataField::UPDATEMETHOD_ONRECORDCREATE,
        ];

        $NoCopyOnDup = [
            "Date Last Modified" => true,
            "Last Modified By Id" => true,
        ];

        foreach ($FieldsToRename as $SrcName => $DstName) {
            if ($Schema->fieldExists($SrcName)) {
                $Field = $Schema->getField($SrcName);
                $Field->name($DstName);

                if (isset($UpdateMethods[$DstName])) {
                    $Field->updateMethod($UpdateMethods[$DstName]);
                }

                if (isset($NoCopyOnDup[$DstName])) {
                    $Field->copyOnResourceDuplication(false);
                }
            }
        }

        $RFlag = $Schema->getField("Release Flag");
        $RFlag->copyOnResourceDuplication(false);
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
