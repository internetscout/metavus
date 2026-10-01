<?PHP
#
#   FILE:  PluginUpgrade_1_0_7.php (PhotoLibrary plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\PhotoLibrary;

use Exception;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\PhotoLibrary;
use Metavus\Record;
use Metavus\RecordFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the PhotoLibrary plugin to version 1.0.7.
 */
class PluginUpgrade_1_0_7 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.7.
     * @return null|string Return NULL if upgrade succeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $Plugin = PhotoLibrary::getInstance(true);
        $Schema = new MetadataSchema($Plugin->getSchemaId());

        # add the new generated file name field if needed
        $Result = $this->ensureImageFileNameField($Plugin, $Schema);
        if ($Result !== null) {
            return $Result;
        }

        $ImageFileNameField = $Schema->getField(
            PhotoLibrary::IMAGE_FILE_NAME_FIELD_NAME
        );
        $this->moveImageFileNameFieldAfterOriginalFileName(
            $Schema,
            $ImageFileNameField
        );
        $this->backfillImageFileNameValues($Plugin, $ImageFileNameField);

        return null;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private const OLD_IMAGE_FILE_NAME_FIELD_NAME = "Image File Name (OLD)";

    /**
     * Ensure that the Image File Name field exists with the correct type.
     * @param PhotoLibrary $Plugin PhotoLibrary plugin.
     * @param MetadataSchema $Schema PhotoLibrary metadata schema.
     * @return string|null Error message, or NULL if no error occurred.
     */
    private function ensureImageFileNameField(
        PhotoLibrary $Plugin,
        MetadataSchema $Schema
    ): ?string {
        if ($Schema->fieldExists(PhotoLibrary::IMAGE_FILE_NAME_FIELD_NAME)) {
            $ExistingField = $Schema->getField(
                PhotoLibrary::IMAGE_FILE_NAME_FIELD_NAME
            );
            if ($ExistingField->type() == MetadataSchema::MDFTYPE_TEXT) {
                return null;
            }

            if ($Schema->fieldExists(self::OLD_IMAGE_FILE_NAME_FIELD_NAME)) {
                return "Cannot add PhotoLibrary Image File Name field because "
                        ."both \"".PhotoLibrary::IMAGE_FILE_NAME_FIELD_NAME
                        ."\" and \"".self::OLD_IMAGE_FILE_NAME_FIELD_NAME
                        ."\" already exist.";
            }

            $ExistingField->name(self::OLD_IMAGE_FILE_NAME_FIELD_NAME);
            if ($ExistingField->name() !== self::OLD_IMAGE_FILE_NAME_FIELD_NAME) {
                return "Unable to rename existing PhotoLibrary Image File Name "
                        ."field to \"".self::OLD_IMAGE_FILE_NAME_FIELD_NAME."\".";
            }
            MetadataSchema::clearStaticCaches();
        }

        $SchemaFile = dirname(__DIR__)."/install/MetadataSchema--"
                .PhotoLibrary::getBaseName().".xml";
        if (!$Schema->addFieldsFromXmlFile($SchemaFile, $Plugin->getName())) {
            return "Error loading PhotoLibrary metadata fields from XML: "
                    .implode(
                        ", ",
                        $Schema->errorMessages("addFieldsFromXmlFile")
                    );
        }

        if (!$Schema->fieldExists(PhotoLibrary::IMAGE_FILE_NAME_FIELD_NAME)) {
            return "Unable to add PhotoLibrary Image File Name field.";
        }

        return null;
    }

    /**
     * Move the Image File Name field after Original File Name in both orders.
     * @param MetadataSchema $Schema PhotoLibrary metadata schema.
     * @param MetadataField $ImageFileNameField Field to move.
     * @return void
     */
    private function moveImageFileNameFieldAfterOriginalFileName(
        MetadataSchema $Schema,
        MetadataField $ImageFileNameField
    ): void {
        if (!$Schema->fieldExists("Original File Name")) {
            return;
        }

        $OriginalFileNameField = $Schema->getField("Original File Name");
        foreach ([$Schema->getDisplayOrder(), $Schema->getEditOrder()] as $Order) {
            $Order->mendIssues();
            $Order->moveItemAfter($OriginalFileNameField, $ImageFileNameField);
        }
    }

    /**
     * Populate stored image file names for existing PhotoLibrary records.
     * @param PhotoLibrary $Plugin PhotoLibrary plugin.
     * @param MetadataField $ImageFileNameField Field to populate.
     * @return void
     */
    private function backfillImageFileNameValues(
        PhotoLibrary $Plugin,
        MetadataField $ImageFileNameField
    ): void {
        $AF = ApplicationFramework::getInstance();
        $RFactory = new RecordFactory($Plugin->getSchemaId());

        foreach ($RFactory->getItemIds() as $RecordId) {
            $Record = Record::getRecord((int)$RecordId);
            try {
                $Plugin->updateImageFileNameForRecord($Record);
            } catch (Exception $Exception) {
                $AF->logMessage(
                    ApplicationFramework::LOGLVL_ERROR,
                    "Unable to populate PhotoLibrary Image File Name for "
                    ."record ID ".$Record->id().": ".$Exception->getMessage()
                );
                $this->clearImageFileNameField($Record, $ImageFileNameField);
            }
        }
    }

    /**
     * Clear Image File Name field for a record after a backfill failure.
     * @param Record $Record Record to update.
     * @param MetadataField $ImageFileNameField Field to clear.
     * @return void
     */
    private function clearImageFileNameField(
        Record $Record,
        MetadataField $ImageFileNameField
    ): void {
        try {
            $Record->set($ImageFileNameField, "");
        } catch (Exception $Exception) {
            ApplicationFramework::getInstance()->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "Unable to clear PhotoLibrary Image File Name for record ID "
                .$Record->id().": ".$Exception->getMessage()
            );
        }
    }
}
