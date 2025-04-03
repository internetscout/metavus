<?PHP
#
#   FILE:  RebuildRecommenderDB.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\RecordFactory;
use Metavus\Recommender;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

PageTitle("Rebuilding Recommender Database");

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

/**
* Process segment of recommender DB rebuild.
* @param int|null $StartingResourceId ID of first resource in chunk.
* @return int ID of first resource in next chunk.
*/
function ProcessResourceChunk($StartingResourceId)
{
    # number of resources to update in each pass
    $RebuildChunkSize = 2;

    # if starting resource ID is set
    $Recommender = new Recommender();
    if (isset($StartingResourceId)) {
        # process (update recommender data for) current chunk of resources
        $EndResourceId = $Recommender->UpdateForItems(
            $StartingResourceId,
            $RebuildChunkSize
        );
    } else {
        # initialize starting resource ID
        $NewStartingResourceId = 0;
        $EndResourceId = -1;
    }

    # if we have processed all resources
    $RFactory = new RecordFactory();
    $LastResourceId = $RFactory->GetHighestItemId();
    if ($EndResourceId >= $LastResourceId) {
        # set flag to indicate we're done
        $NewStartingResourceId = -1;

        # do end maintenance to recommender DB
        $Recommender->PruneCorrelations();
    } else {
        # set new starting resource ID
        $NewStartingResourceId = $EndResourceId + 1;
    }

    # return new starting resource ID to caller
    return $NewStartingResourceId;
}


# save page generation timestamp
if (isset($_GET["RSDStartingResourceId"])) {
    $StartingId = $_GET["RSDStartingResourceId"];
} else {
    $StartingId = null;
}

# process rebuild chunk
$StartingResourceId = ProcessResourceChunk($StartingId);

# check if rebuild is complete
$H_RebuildIsComplete = ($StartingResourceId == -1) ? true : false;

# calculate percent complete
$RFactory = new RecordFactory();
$TotalResources = $RFactory->getItemCount();
$ResourcesProcessed = $RFactory->getItemCount("RecordId < ".$StartingResourceId);
$H_PercentRebuildComplete = sprintf(
    "%.1f",
    ($ResourcesProcessed * 100) / max(1, $TotalResources)
);

# calculate estimated time of completion
$Elapsed = StdLib::getFormValue("RSDElapsed", 0);
if ($H_PercentRebuildComplete < 2) {
    $H_EstimatedTimeOfCompletion = "-:--";
    $H_EstimatedTimeRemaining = "-:--";
} else {
    # calculate estimated time remaining on rebuild
    $EstimatedTotalTime = ($Elapsed * 100) / $H_PercentRebuildComplete;
    # (adjust for rebuild speeding up toward end)
    $EstimatedTotalTime = $EstimatedTotalTime
            * (1 - ((100 - $H_PercentRebuildComplete) / 300));
    $EstimatedTimeRemainingInSeconds = $EstimatedTotalTime - $Elapsed;

    if ($EstimatedTimeRemainingInSeconds > (12 * 60 * 60)) {
        $H_EstimatedTimeOfCompletion = date(
            "D g:ia",
            (int)microtime(true) + $EstimatedTimeRemainingInSeconds
        );
    } else {
        $H_EstimatedTimeOfCompletion = date(
            "g:ia",
            (int)microtime(true) + $EstimatedTimeRemainingInSeconds
        );
    }
    $H_EstimatedTimeRemaining = sprintf(
        "%d:%02d:%02d",
        intval($EstimatedTimeRemainingInSeconds / 3600),
        intval(($EstimatedTimeRemainingInSeconds / 60) % 60),
        ($EstimatedTimeRemainingInSeconds % 60)
    );
}

# set up page auto-refresh
if ($StartingResourceId != -1) {
    $AF = ApplicationFramework::getInstance();
    $ElapsedTime = intval($Elapsed
            + $AF->GetElapsedExecutionTime());
    $AF->SlowPageLoadThreshold(60);
    $AF->SetJumpToPage(
        "index.php?P=RebuildRecommenderDB"
            ."&RSDStartingResourceId=".$StartingResourceId
        ."&RSDElapsed=".$ElapsedTime,
        1
    );
}
