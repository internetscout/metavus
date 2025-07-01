<?PHP
#
#   FILE:  RebuildRecommender.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\RecordFactory;
use Metavus\Recommender;
use Metavus\User;
use ScoutLib\ApplicationFramework;

if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

if ($_GET["AC"] == "Background") {
    $Recommender = new Recommender();
    $RFactory = new RecordFactory();
    $Ids = $RFactory->getItemIds();
    foreach ($Ids as $Id) {
        $Recommender->queueUpdateForItem(
            (int)$Id,
            ApplicationFramework::PRIORITY_BACKGROUND
        );
    }
    ApplicationFramework::getInstance()->queueUniqueTask(
        array($Recommender, "PruneCorrelations"),
        array(),
        ApplicationFramework::PRIORITY_BACKGROUND
    );
    $GLOBALS["ResourceCount"] = count($Ids);
}
