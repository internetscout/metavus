<?PHP
#
#   FILE:  FollowupTasks.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;

/**
 * Class encapsulating followup tasks that must be performed after new installs or upgrades.
 */
class FollowupTasks
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform any follow-up tasks after a new installation.  Tasks that go here are
     *   typically those that can be performed after the site is in use.
     */
    public static function performNewInstallFollowUp()
    {
        # load default search synonym list
        $SearchEngine = new SearchEngine();
        $Synonyms = $SearchEngine->parseSynonymsFromFile(
            "lib/ScoutLib/SearchEngine--DefaultSynonymList.txt"
        );
        $SearchEngine->setAllSynonyms($Synonyms);

        # queue complete recommender DB rebuild
        $Recommender = new Recommender();
        $RFactory = new RecordFactory();
        $Ids = $RFactory->getItemIds();
        foreach ($Ids as $Id) {
            $Recommender->queueUpdateForItem(
                (int)$Id,
                ApplicationFramework::PRIORITY_BACKGROUND
            );
        }
        $GLOBALS["AF"]->queueUniqueTask(
            [$Recommender, "PruneCorrelations"],
            [],
            ApplicationFramework::PRIORITY_BACKGROUND
        );

        $ClassificationFactory = new ClassificationFactory();
        $ClassificationFactory->recalculateAllResourceCounts();
    }

    /**
     * Perform any follow-up tasks after an upgrade.  Tasks that go here are
     *   typically those that can be performed after the site is in use.
     * @param string $OldVersion Version being upgraded from.
     */
    public static function performUpgradeFollowUp(string $OldVersion)
    {
        # queue complete recommender DB rebuild
        $Recommender = new Recommender();
        $RFactory = new RecordFactory();
        $Ids = $RFactory->GetItemIds();
        foreach ($Ids as $Id) {
            $Recommender->queueUpdateForItem(
                (int)$Id,
                ApplicationFramework::PRIORITY_BACKGROUND
            );
        }

        $GLOBALS["AF"]->QueueUniqueTask(
            [$Recommender, "PruneCorrelations"],
            [],
            ApplicationFramework::PRIORITY_BACKGROUND
        );

        # recalculate all resource counts
        $ClassificationFactory = new ClassificationFactory();
        $ClassificationFactory->RecalculateAllResourceCounts();
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
