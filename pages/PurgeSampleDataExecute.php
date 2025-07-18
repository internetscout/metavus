<?PHP
#
#   FILE:  PurgeSampleDataExecute.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized
if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();
$SearchEngine = new SearchEngine();

# check for cancel button
if ($_POST["Submit"] == "Cancel") {
    $AF->setJumpToPage("index.php?P=SysAdmin");
    return;
}

# get sample record IDs
$SearchParams = new SearchParameterSet();
$SearchParams->addParameter('"[--SAMPLE RECORD--]"', "Description");
$SearchResults = $SearchEngine->search($SearchParams);

# convert search results to simple array of IDs
$ResourceList = array_keys($SearchResults);

$H_ResourceCount = 0;
$ClassIds = array();
$CNIds = array();

foreach ($ResourceList as $ResourceId) {
    $Resource = new Record($ResourceId);

    # get Classifications associated with this Resource
    $Names = $Resource->classifications();
    foreach ($Names as $ClassificationType => $ClassificationTypes) {
        foreach ($ClassificationTypes as $ClassId => $Classification) {
            $ClassIds[$ClassId] = $Classification;
        }
    }


     # get ControlledNames associated with this Resource
    $Creators = $Resource->get("Creator");
    foreach ($Creators as $CNId => $Creator) {
        $CNIds[$CNId] = $Creator;
    }

    $Publishers = $Resource->get("Publisher");
    foreach ($Publishers as $CNId => $Publisher) {
        $CNIds[$CNId] = $Publisher;
    }

    $Resource->destroy();
    $H_ResourceCount++;
}

# post-process classification ids
$H_ClassificationCount = 0;
foreach ($ClassIds as $ClassId => $Classification) {
    # nothing to do if classification has already been deleted
    if (!Classification::itemExists((int)$ClassId)) {
        continue;
    }

    # delete classification if no resources assigned
    $Class = new Classification($ClassId);
    $H_ClassificationCount += $Class->destroy(true);
}

# post-process controlled name ids
$H_ControlledNameCount = 0;
foreach ($CNIds as $CNId => $Dummy) {
    # see if any resources are still using this controlled name
    $CN = new ControlledName($CNId);

    # controlled name not in use, so delete it
    if (!$CN->inUse()) {
        $CN->destroy();
        $H_ControlledNameCount++;
    }
}

# also clean out sample collections
$CFactory = new CollectionFactory();
$CollectionIds = $CFactory->getIdsOfMatchingRecords(
    ["Description" => "\[--SAMPLE COLLECTION--\]"],
    true,
    "=~"
);

foreach ($CollectionIds as $Id) {
    $Record = new Collection($Id);
    $Record->destroy();
}
