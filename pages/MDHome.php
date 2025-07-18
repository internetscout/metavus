<?PHP
#
#   FILE:  MDHome.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

# ----- MAIN -----------------------------------------------------------------

# retrieve user currently logged in
$User = User::getCurrentUser();

if (!User::requirePrivilege(
    PRIV_RESOURCEADMIN,
    PRIV_CLASSADMIN,
    PRIV_NAMEADMIN,
    PRIV_COLLECTIONADMIN,
    PRIV_RELEASEADMIN
)) {
    return;
}

global $G_StatsUpdateTime;

$DB = new Database();

$AF = ApplicationFramework::getInstance();
if (isset($_GET["US"])) {
    CollectionStats::updateCollectionStats();
    $AF->setJumpToPage("MDHome");
} else {
    $G_StatsUpdateTime = $DB->query(
        "SELECT Updated FROM CachedValues WHERE Name='CollectionStats'",
        "Updated"
    );

    $StatsUpdateTimeString = strtotime(
        gettype($G_StatsUpdateTime) === "string" ? $G_StatsUpdateTime : ""
    );

    if (time() - $StatsUpdateTimeString > 3600) {
        global $AF;

        $AF->queueTask(
            ["\\Metavus\\CollectionStats", "updateCollectionStats"]
        );
    }

    $H_CollectionStats = null;
    $QueryValue = $DB->query(
        "SELECT Value FROM CachedValues WHERE Name='CollectionStats'",
        "Value"
    );
    if (gettype($QueryValue) === "string") {
        $H_CollectionStats = unserialize($QueryValue);
    }

    # generate collection stats if they don't exist yet
    if (!$H_CollectionStats) {
        $AF->setJumpToPage("MDHome&US");
    }
}


# retrieve lists of recently modified and recently added resources to display
$RFactory = new RecordFactory(MetadataSchema::SCHEMAID_DEFAULT);

$AllRecentlyAdded = $RFactory->getRecordIdsSortedBy("Date Of Record Creation", false);
$H_RecentlyAdded = [];
foreach ($AllRecentlyAdded as $Id) {
    if ($Id < 0) {
        continue;
    }

    $Resource = new Record($Id);
    if (!$Resource->userCanView($User)) {
        continue;
    }

    $H_RecentlyAdded[$Id] = $Resource;
    if (count($H_RecentlyAdded) > 10) {
        break;
    }
}

# retrieve list of recently modified resources that aren't also new resources
$AllRecentlyModified = $RFactory->getRecordIdsSortedBy("Date Last Modified", false);
$H_RecentlyModified = [];
foreach ($AllRecentlyModified as $Id) {
    if ($Id < 0) {
        continue;
    }

    $Resource = new Record($Id);
    if (!$Resource->userCanView($User)) {
        continue;
    }

    if (in_array($Id, $H_RecentlyAdded) &&
        strtotime($Resource->get("Date Last Modified"))
          <= strtotime($Resource->get("Date Of Record Creation"))) {
        continue;
    }

    $H_RecentlyModified[$Id] = $Resource;
    if (count($H_RecentlyModified) > 10) {
        break;
    }
}
