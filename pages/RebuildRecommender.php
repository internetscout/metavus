<?PHP
#
#   FILE:  RebuildRecommender.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\RecordFactory;
use Metavus\Recommender;
use ScoutLib\ApplicationFramework;

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

if ($_GET["AC"] == "Background") {
    $Recommender = new Recommender();
    $RFactory = new RecordFactory();
    $Ids = $RFactory->GetItemIds();
    foreach ($Ids as $Id) {
        $Recommender->QueueUpdateForItem(
            (int)$Id,
            ApplicationFramework::PRIORITY_BACKGROUND
        );
    }
    ApplicationFramework::getInstance()->QueueUniqueTask(
        array($Recommender, "PruneCorrelations"),
        array(),
        ApplicationFramework::PRIORITY_BACKGROUND
    );
    $GLOBALS["ResourceCount"] = count($Ids);
}
