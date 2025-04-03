<?PHP
#
#   FILE:  CollectionStats
#
#   Part of the Metavus digital collections platform
#   Copyright 2020-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

/**
 * Encapsulate methods to update collection statistics.
 */
class CollectionStats
{
    /**
     * Callback for updating the collection statistics, usually executed as a
     * background task.
     * @return void
     */
    public static function updateCollectionStats(): void
    {
        $CollectionStats = [];

        $RFactory = new RecordFactory();
        $CFactory = new ClassificationFactory();
        $MSchema = new MetadataSchema();
        $CNFactory = new ControlledNameFactory();
        $SearchEngine = new SearchEngine();

        # query total number of resources from DB
        $CollectionStats["TotalNumberOfResources"] = $RFactory->getItemCount();

        # query number of released resources from DB
        $CollectionStats["NumberOfReleasedResources"] = count(
            $RFactory->filterOutUnviewableRecords(
                $RFactory->getItemIds(),
                User::getAnonymousUser()
            )
        );

        # Count rated resources:
        $CollectionStats["NumberOfRatedResources"] = $RFactory->getRatedRecordCount();

        # Count the classifications:
        $CollectionStats["TotalNumberOfClassifications"] = $CFactory->getItemCount();

        # Count the controlled names:
        $CNCount = 0;
        $Fields = $MSchema->getFields(MetadataSchema::MDFTYPE_CONTROLLEDNAME);
        foreach ($Fields as $Field) {
            $CNCount += $CNFactory->getItemCount("FieldId = ".$Field->id());
        }
        $CollectionStats["TotalNumberOfControlledNames"] = $CNCount ;

        # Count total search terms:
        $CollectionStats["TotalSearchTerms"] = $SearchEngine->searchTermCount();

        # Get the local statistics:
        $LocalStats = [];

        $SignalResult = ApplicationFramework::getInstance()->signalEvent(
            "EVENT_LOCAL_COLLECTION_STATS",
            ["LocalStats" => $LocalStats]
        );

        $CollectionStats["LocalStats"] = $SignalResult["LocalStats"];

        $DB = new Database();
        $DB->query("DELETE FROM CachedValues WHERE NAME='CollectionStats'");

        $DB->query("INSERT INTO CachedValues (Name,Value,Updated) VALUES "
                   ."('CollectionStats','".addslashes(
                       serialize($CollectionStats)
                   )."',NOW())");
    }
}
