<?PHP
#
#   FILE:  Recommender.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

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
        # if resource not already loaded
        if (!isset(self::$RecordCache[$ItemId])) {
            # get resource object
            self::$RecordCache[$ItemId] = new Record($ItemId);

            # if cached resource limit exceeded
            if (count(self::$RecordCache) > 100) {
                # dump oldest resource
                reset(self::$RecordCache);
                $DumpedItemId = key(self::$RecordCache);
                unset(self::$RecordCache[$DumpedItemId]);
            }
        }

        # retrieve field value from resource object and return to caller
        $FieldValue = self::$RecordCache[$ItemId]->get($FieldName);

        return $FieldValue;
    }

    /**
     * Queue a background update for a specified item.
     * @param mixed $ItemOrItemId Item or an int item id to update
     * @param mixed $TaskPriority Priority to use for this task, if the default
     *    is not suitable
     * @return void
     */
    public function queueUpdateForItem($ItemOrItemId, $TaskPriority = null): void
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

        $AF = ApplicationFramework::getInstance();

        $TaskDescription = "Update recommender data for"
            ." <a href=\"r".$ItemId."\"><i>"
            .$Item->GetMapped("Title")."</i></a>";
        $AF->queueUniqueTask(
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
     * @return void
     */
    public static function runUpdateForItem($SourceItemId, $StartingIndex): void
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

        $AF = ApplicationFramework::getInstance();

        # determine a percent memory threshold for when we want to clear caches,
        # set higher than what Database does automatically so that this will
        # trigger first and give us deterministic behavior
        $PercentMemThreshold =
            100 * (Database::getThresholdForCacheClearing() /
                   StdLib::getPhpMemoryLimit()) + 10;

        # if starting update for source item
        if ($StartingIndex == 0) {
            # clear data for item
            $Recommender->dropItem($SourceItemId);
        }

        # load array of item IDs and pare down to those in same schema as source item
        $TargetItemIds = $Recommender->getItemIds();
        $SourceSchemaIds = $RFactory->getItemIds();
        $TargetItemIds = array_values(array_intersect(
            $TargetItemIds,
            $SourceSchemaIds
        ));
        $TargetCount = count($TargetItemIds);

        # start with an initial estimate of 90s to update an item
        # (this is 1.5x typical times for our production sites in 2023)
        $ExecutionTime = 90;

        # while not last item ID and not out of time
        for ($Index = $StartingIndex; $Index < $TargetCount; $Index++) {
            # if target ID points to non-temporary entry
            if ($TargetItemIds[$Index] >= 0) {
                # if running low on memory, clear caches (Database caches are
                # also cleared so we don't end up in a situation where we're
                # repeatedly clearing Recommender's caches just to have Database
                # sitting there on a fat stack of memory and laughing at our
                # efforts)
                if (StdLib::getPercentFreeMemory() < $PercentMemThreshold) {
                    Database::clearCaches();
                    static::clearCaches();
                }

                # bail out if out of memory or not enough time for another update
                if (($AF->getSecondsBeforeTimeout() < ($ExecutionTime * 2))
                    || (StdLib::getFreeMemory() < 8000000)) {
                    break;
                }

                # update correlation for source item and current item
                $StartTime = microtime(true);
                $Recommender->updateContentCorrelation(
                    $SourceItemId,
                    $TargetItemIds[$Index]
                );
                $ExecutionTime = microtime(true) - $StartTime;
            }
        }

        # if all correlations completed for source item
        if ($Index >= $TargetCount) {
            # periodically prune correlations if enough time remaining
            if (($AF->getSecondsBeforeTimeout() > 20)
                && (rand(1, 10) == 1)) {
                $Recommender->pruneCorrelations();
            }
        } else {
            # requeue updates for remaining items
            $Item = new Record($SourceItemId);
            $TaskDescription = "Update recommender data for"
                ." <a href=\"r".$SourceItemId."\"><i>"
                .$Item->getMapped("Title")."</i></a>";
            $AF->queueUniqueTask(
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
     * @return void
     */
    public static function setUpdatePriority($NewPriority): void
    {
        self::$TaskPriority = $NewPriority;
    }

    /**
     * Clear internal caches.
     * @return void
     */
    public static function clearCaches(): void
    {
        parent::clearCaches();
        self::$RecordCache = [];
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $Schema;
    private static $TaskPriority = ApplicationFramework::PRIORITY_BACKGROUND;
    private static $RecordCache = [];

    /**
     * Load internal item ID cache (if not already loaded).
     * @return void
     */
    protected function loadItemIds(): void
    {
        # if item IDs not already loaded
        if (!isset($this->ItemIds)) {
            # load item IDs from DB
            $RFactory = new RecordFactory();
            $this->ItemIds = $RFactory->getItemIds();
        }
    }
}
