<?PHP
#
#   FILE:  PurgeSampleDataExecute.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Classification;
use Metavus\Collection;
use Metavus\CollectionFactory;
use Metavus\ControlledName;
use Metavus\Record;
use Metavus\SearchEngine;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized


if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

PageTitle("Purge Sample Data");
$SearchEngine = new SearchEngine();

# check for cancel button
if ($_POST["Submit"] == "Cancel") {
    $AF->SetJumpToPage("index.php?P=SysAdmin");
    return;
}

# get sample record IDs
$SearchParams = new SearchParameterSet();
$SearchParams->addParameter('"[--SAMPLE RECORD--]"', "Description");
$SearchResults = $SearchEngine->search($SearchParams);

# convert search results to simple array of IDs
$ResourceList = array_keys($SearchResults);

$ResourceCount = 0;
$ClassIds = array();
$CNIds = array();

foreach ($ResourceList as $ResourceId) {
    $Resource = new Record($ResourceId);

    # get Classifications associated with this Resource
    $Names = $Resource->Classifications();
    foreach ($Names as $ClassificationType => $ClassificationTypes) {
        foreach ($ClassificationTypes as $ClassId => $Classification) {
            $ClassIds[$ClassId] = $Classification;
        }
    }


     # get ControlledNames associated with this Resource
    $Creators = $Resource->Get("Creator");
    foreach ($Creators as $CNId => $Creator) {
        $CNIds[$CNId] = $Creator;
    }

    $Publishers = $Resource->Get("Publisher");
    foreach ($Publishers as $CNId => $Publisher) {
        $CNIds[$CNId] = $Publisher;
    }

    $Resource->destroy();
    $ResourceCount++;
}

# post-process classification ids
$ClassificationCount = 0;
foreach ($ClassIds as $ClassId => $Classification) {
    # nothing to do if classification has already been deleted
    if (!Classification::itemExists($ClassId)) {
        continue;
    }

    # delete classification if no resources assigned
    $Class = new Classification($ClassId);
    $ClassificationCount += $Class->destroy(true);
}

# post-process controlledname ids
$ControlledNameCount = 0;
foreach ($CNIds as $CNId => $Dummy) {
    # see if any resources are still using this controlled name
    $CN = new ControlledName($CNId);

    # controlled name not in use, so delete it
    if (!$CN->InUse()) {
        $CN->destroy();
        $ControlledNameCount++;
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
