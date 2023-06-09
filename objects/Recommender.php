<?PHP
#
#   FILE:  Recommender.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

class Recommender extends \ScoutLib\Recommender
{

    /**
     * Recommender object constructor.
     */
    public function __construct()
    {
        # set up recommender configuration values
        $ItemTableName = "Records";
        $ItemIdFieldName = "RecordId";
        $RatingTableName = "RecordRatings";
        $UserIdFieldName = "UserId";
        $RatingFieldName = "Rating";
        $FieldInfo = [];

        # build field info from metadata schema
        $this->Schema = new MetadataSchema();
        $Fields = $this->Schema->getFields();
        foreach ($Fields as $Field) {
            if ($Field->Enabled() && $Field->IncludeInKeywordSearch()) {
                $FieldName = $Field->Name();
                $FieldInfo[$FieldName]["DBFieldName"] = $Field->DBFieldName();
                $FieldInfo[$FieldName]["Weight"] = $Field->SearchWeight();
                switch ($Field->Type()) {
                    case MetadataSchema::MDFTYPE_TEXT:
                    case MetadataSchema::MDFTYPE_PARAGRAPH:
                    case MetadataSchema::MDFTYPE_USER:
                    case MetadataSchema::MDFTYPE_URL:
                        $FieldInfo[$FieldName]["FieldType"] =
                            self::CONTENTFIELDTYPE_TEXT;
                        break;

                    case MetadataSchema::MDFTYPE_TREE:
                    case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                    case MetadataSchema::MDFTYPE_OPTION:
                        $FieldInfo[$FieldName]["FieldType"] =
                            self::CONTENTFIELDTYPE_TEXT;
                        break;

                    case MetadataSchema::MDFTYPE_NUMBER:
                    case MetadataSchema::MDFTYPE_FLAG:
                        $FieldInfo[$FieldName]["FieldType"] =
                            self::CONTENTFIELDTYPE_NUMERIC;
                        break;

                    case MetadataSchema::MDFTYPE_DATE:
                        $FieldInfo[$FieldName]["FieldType"] =
                            self::CONTENTFIELDTYPE_DATERANGE;
                        break;

                    case MetadataSchema::MDFTYPE_TIMESTAMP:
                        $FieldInfo[$FieldName]["FieldType"] =
                            self::CONTENTFIELDTYPE_DATE;
                        break;

                    case MetadataSchema::MDFTYPE_IMAGE:
                        # (for images we use their alt text)
                        $FieldInfo[$FieldName]["FieldType"] =
                            self::CONTENTFIELDTYPE_TEXT;
                        break;

                    case MetadataSchema::MDFTYPE_FILE:
                        # (for files we use the file name)
                        $FieldInfo[$FieldName]["FieldType"] =
                            self::CONTENTFIELDTYPE_TEXT;
                        break;
                }
            }
        }

        # create our own schema object
        $this->Schema = new MetadataSchema();

        # create a database connection for recommender to use
        $DB = new Database();

        # pass configuration info to real recommender object
        parent::__construct(
            $DB,
            $ItemTableName,
            $RatingTableName,
            $ItemIdFieldName,
            $UserIdFieldName,
            $RatingFieldName,
            $FieldInfo
        );
    }

    /**
     * Get value for a given field.
     * @param int $ItemId Item to retreive value from
     * @param string $FieldName Field name to retrieve
     * @return mixed Value for requested field
     */
    public function getFieldValue(int $ItemId, string $FieldName)
    {
        static $Resources;

        # if resource not already loaded
        if (!isset($Resources[$ItemId])) {
            # get resource object
            $Resources[$ItemId] = new Record($ItemId);

            # if cached resource limit exceeded
            if (count($Resources) > 100) {
                # dump oldest resource
                reset($Resources);
                $DumpedItemId = key($Resources);
                unset($Resources[$DumpedItemId]);
            }
        }

        # retrieve field value from resource object and return to caller
        $FieldValue = $Resources[$ItemId]->Get($FieldName);
        return $FieldValue;
    }

    /**
     * Queue a background update for a specified item.
     * @param mixed $ItemOrItemId Item or an int item id to update
     * @param mixed $TaskPriority Priority to use for this task, if the default
     *    is not suitable
     */
    public function queueUpdateForItem($ItemOrItemId, $TaskPriority = null)
    {
        if (is_numeric($ItemOrItemId)) {
            $ItemId = (int)$ItemOrItemId;
            $Item = new Record($ItemId);
        } else {
            $Item = $ItemOrItemId;
            $ItemId = $Item->Id();
        }

        # if no proirity was provided, use the default
        if ($TaskPriority === null) {
            $TaskPriority = self::$TaskPriority;
        }

        $TaskDescription = "Update recommender data for"
            ." <a href=\"r".$ItemId."\"><i>"
            .$Item->GetMapped("Title")."</i></a>";
        $GLOBALS["AF"]->QueueUniqueTask(
            [__CLASS__, "RunUpdateForItem"],
            [intval($ItemId), 0],
            $TaskPriority,
            $TaskDescription
        );
    }

    /**
     * Perform recommender db updates for a specified item (usually in the background)
     * @param int $SourceItemId ItemId for the source item in this update
     * @param int $StartingIndex Starting index of the destination items
     */
    public static function runUpdateForItem($SourceItemId, $StartingIndex)
    {
        # check that resource still exists
        $RFactory = new RecordFactory();
        if (!$RFactory->itemExists($SourceItemId)) {
            return;
        }

        # load recommender engine
        static $Recommender;
        if (!isset($Recommender)) {
            $Recommender = new Recommender();
        }

        # if starting update for source item
        if ($StartingIndex == 0) {
            # clear data for item
            $Recommender->DropItem($SourceItemId);
        }

        # load array of item IDs and pare down to those in same schema as source item
        $TargetItemIds = $Recommender->GetItemIds();
        $SourceSchemaIds = $RFactory->getItemIds();
        $TargetItemIds = array_values(array_intersect(
            $TargetItemIds,
            $SourceSchemaIds
        ));
        $TargetCount = count($TargetItemIds);

        # while not last item ID and not out of time
        for ($Index = $StartingIndex; $Index < $TargetCount; $Index++) {
            # if target ID points to non-temporary entry
            if ($TargetItemIds[$Index] >= 0) {
                # update correlation for source item and current item
                $StartTime = microtime(true);
                $Recommender->UpdateContentCorrelation(
                    $SourceItemId,
                    $TargetItemIds[$Index]
                );
                $ExecutionTime = microtime(true) - $StartTime;

                # clear all caches if memory has run low
                if ($GLOBALS["AF"]->GetFreeMemory() < 8000000) {
                    Database::caching(false);
                    Database::caching(true);
                    self::clearCaches();
                    if (function_exists("gc_collect_cycles")) {
                        gc_collect_cycles();
                    }
                }

                # bail out if out of memory or not enough time for another update
                if (($GLOBALS["AF"]->GetSecondsBeforeTimeout() < ($ExecutionTime * 2))
                    || ($GLOBALS["AF"]->GetFreeMemory() < 8000000)) {
                    break;
                }
            }
        }

        # if all correlations completed for source item
        if ($Index >= $TargetCount) {
            # periodically prune correlations if enough time remaining
            if (($GLOBALS["AF"]->GetSecondsBeforeTimeout() > 20)
                && (rand(1, 10) == 1)) {
                $Recommender->PruneCorrelations();
            }
        } else {
            # requeue updates for remaining items
            $Item = new Record($SourceItemId);
            $TaskDescription = "Update recommender data for"
                ." <a href=\"r".$SourceItemId."\"><i>"
                .$Item->getMapped("Title")."</i></a>";
            $GLOBALS["AF"]->QueueUniqueTask(
                [__CLASS__, "RunUpdateForItem"],
                [(int)$SourceItemId, $Index],
                ApplicationFramework::PRIORITY_LOW
            );
        }
    }

    /**
     * Set the default priority for background tasks.
     * @param mixed $NewPriority New task priority (one of
     *     ApplicationFramework::PRIORITY_*)
     */
    public static function setUpdatePriority($NewPriority)
    {
        self::$TaskPriority = $NewPriority;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $Schema;
    private static $TaskPriority = ApplicationFramework::PRIORITY_BACKGROUND;

    /**
     * Load internal item ID cache (if not already loaded).
     */
    protected function loadItemIds()
    {
        # if item IDs not already loaded
        if (!isset($this->ItemIds)) {
            # load item IDs from DB
            $RFactory = new RecordFactory();
            $this->ItemIds = $RFactory->getItemIds();
        }
    }
}
