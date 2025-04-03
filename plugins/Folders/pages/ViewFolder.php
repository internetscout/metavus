<?PHP
#
#   FILE:  ViewFolder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Date;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

# redirect if no folder ID is given
$AF = ApplicationFramework::getInstance();
if (!isset($_GET["FolderId"])) {
    $H_ErrorMessage = "No Folder Id provided.";
    return;
}

$H_Length = intval(StdLib::getArrayValue($_GET, "L", 25));

# check for valid Folder ID
$FolderId = StdLib::getArrayValue($_GET, "FolderId");
if (!is_numeric($FolderId) || !Folder::itemExists((int)$FolderId)) {
    $H_ErrorMessage = "Invalid Folder Id.";
    return;
}

$User = User::getCurrentUser();
$H_Folder = new Folder((int)$FolderId);

# show login box if not shared and user is not logged in
if (!$H_Folder->isShared() && !$User->isLoggedIn()) {
    DisplayUnauthorizedAccessPage();
    return;
}

# if not shared and owned by a different user, then it cannot be viewed
if (!$H_Folder->isShared() && ($H_Folder->ownerId() != $User->id())) {
    $H_ErrorMessage = "You do not have permission to view this folder.";
    return;
}

$H_TransportUI = new TransportControlsUI();
$H_TransportUI->itemsPerPage($H_Length);

$H_SortFieldId = $H_TransportUI->sortField();
if ($H_SortFieldId === null) {
    # if the no sorting field was specified, default to the title field
    $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_RESOURCES);
    $H_SortFieldId = $Schema->getFieldIdByMappedName("Title");
}

# sort resources first
$SortField = MetadataField::getField((int)$H_SortFieldId);
$ReverseSort = $H_TransportUI->reverseSortFlag();

$H_Folder->sort(function ($ItemA, $ItemB) use ($SortField, $ReverseSort) {
    static $ValueCache = [];

    # load values of items not present in cache to cache
    # (value will be used later for sorting)
    foreach ([$ItemA, $ItemB] as $ItemId) {
        # first lookup this item in cache,
        # if it's already present, skip it
        if (isset($ValueCache[$ItemId])) {
            continue;
        }

        $Resource = new Record($ItemId);
        $ResourceSchema = $Resource->getSchema();

        # put this item last if its schema doesn't own the sort_field
        if ($ResourceSchema->Id() != $SortField->SchemaId()) {
            $ValueCache[$ItemId] = null;
            continue;
        }

        # for array value, use the smallest element in the array
        $Value = $Resource->Get($SortField->Id());
        if (is_array($Value)) {
            if (count($Value)) {
                sort($Value);
                $Value = current($Value);
            } else {
                $Value = null;
            }
        }

        # empty string is considered the same as NULL
        if (is_string($Value) && !strlen($Value)) {
            $Value = null;
        }

        # special processing based on sorting field's type
        if (!is_null($Value)) {
            # convert Timestamp type value from string to number (Unix timestamp)
            if ($SortField->Type() == MetadataSchema::MDFTYPE_TIMESTAMP) {
                $Value = strtotime($Value);
            }

            # convert Date type value from string to number (Unix timestamp)
            if ($SortField->Type() == MetadataSchema::MDFTYPE_DATE) {
                $Date = new Date($Value);
                $Value = strtotime($Date->BeginDate());
            }
        }

        $ValueCache[$ItemId] = $Value;
    }

    # get values of ItemA and ItemB
    $ValA = $ValueCache[$ItemA];
    $ValB = $ValueCache[$ItemB];

    # resources with NULL as field value is always put last
    if (is_null($ValA) && !is_null($ValB)) {
        return 1;
    }
    if (is_null($ValB) && !is_null($ValA)) {
        return -1;
    }

    # modify the sort comparison result with respect to the required sorting order
    # in case of descending sorting order, we will reverse the sorting order.
    # the sorting should be case-insensitive.
    return ($ReverseSort ? -1 : 1) * StdLib::SortCompare(
        strtolower($ValA ?? ""),
        strtolower($ValB ?? "")
    );
});

$ItemIds = $H_Folder->getVisibleItemIds($User);

$H_TransportUI->itemCount(count($ItemIds));

$ItemIds = array_slice(
    $ItemIds,
    $H_TransportUI->startingIndex(),
    $H_Length
);

$H_TransportUI->baseLink(
    "index.php?P=ViewFolder"
    ."&FolderId=".$H_Folder->id()
    ."&L=".$H_Length
);

$H_FolderSchemas = [];
$H_Items = [];

# transform item IDs into item objects
foreach ($ItemIds as $ItemId) {
    $Resource = Record::getRecord($ItemId);

    $H_Items[$ItemId] = $Resource;
    $H_FolderSchemas[$Resource->getSchemaId()] = 1;
}
