<?PHP
#
#   FILE:  ViewFolder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Date;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
 * Print each folder item.This function also passes the previous and subsequent
 * items to the folder item printing function to facilitate item bubbling.
 * @param Folder $Folder Folder the items belong to
 * @param array $Items Folder items to print
 * @return void
 */
function PrintFolderItems($Folder, array $Items)
{
    # we want to be able to get next and previous values, so use numeric indices
    $Items = array_values($Items);
    $NumberItems = count($Items);

    for ($i = 0; $i < $NumberItems; $i++) {
        $Previous = isset($Items[$i - 1]) ? $Items[$i - 1] : null;
        $Next = isset($Items[$i + 1]) ? $Items[$i + 1] : null;

        PrintFolderItem($Folder, $Items[$i], $Previous, $Next);
    }
}

/**
 * Given a list of resource IDs, filter out the resources that are non-viewable
 * to the current user.The resources IDs can from multiple schema
 * @param array $ItemIds List of resource IDs to filter
 * @return array the list of filtered resources IDs
 */
function FilterNonviewableResources(array $ItemIds)
{
    # retrieve user currently logged in
    $User = User::getCurrentUser();

    $OrderedIds = $ItemIds;
    $FilteringIds = [];
    $ResourceFactories = [];

    # divide $ItemIds by schema and construct ResourceFactory accordingly
    # to filter out resources
    foreach ($ItemIds as $ItemId) {
        $CurrentResource = new Record($ItemId);
        $CurrentSchemaId = $CurrentResource->getSchemaId();
        if (!array_key_exists($CurrentSchemaId, $FilteringIds)) {
            $FilteringIds[$CurrentSchemaId] = [];
            $ResourceFactories[$CurrentSchemaId] = new RecordFactory($CurrentSchemaId);
        }
        $FilteringIds[$CurrentSchemaId][] = $ItemId;
    }
    $ItemIds = [];
    # filter out the resources that the current user doesn't have permission to see
    foreach ($FilteringIds as $SchemaId => $FilteringId) {
        $ResourceFactory = $ResourceFactories[$SchemaId];
        $FilterResult = $ResourceFactory->filterOutUnviewableRecords(
            $FilteringId,
            $User
        );
        $ItemIds = array_merge($ItemIds, $FilterResult);
    }

    return array_intersect($OrderedIds, $ItemIds);
}

# ----- MAIN -----------------------------------------------------------------

global $Folder;
global $Id;
global $Name;
global $NormalizedName;
global $TruncatedName;
global $IsShared;
global $IsSelected;
global $OwnerId;
global $Note;
global $ItemIds;
global $Items;
global $ItemCount;
global $HasItems;
global $ShareUrl;

# redirect if no folder ID is given
$AF = ApplicationFramework::getInstance();
if (!isset($_GET["FolderId"])) {
    $AF->SetJumpToPage("P_Folders_ManageFolders");
    return;
}

# retrieve user currently logged in
$User = User::getCurrentUser();

# retrieve whether the user is currently logged in
$IsLoggedIn = $User->isLoggedIn();

# retrieve user currently logged in Id
$UserId = $User->id();

$Offset = intval(StdLib::getArrayValue($_GET, "Offset", 0));
$Length = intval(StdLib::getArrayValue($_GET, "Length", 25));
$SortFieldId = StdLib::getArrayValue($_GET, "F_SortFieldId");
$H_CurrentSortField = is_null($SortFieldId) ? "Placeholder" : $SortFieldId;
$OverrideDefaultSortingOrder = false;
$H_AscSortingOrder = true;
if (isset($_GET["AO"])) {
    $OverrideDefaultSortingOrder = true;
    $H_AscSortingOrder = boolval($_GET["AO"]);
}

# set the length to a sensible value if it is not already one
if (!$Length) {
    $Length = 25;
}

# check for valid Folder ID
$FolderId = StdLib::getArrayValue($_GET, "FolderId");
if (!is_numeric($FolderId) || !Folder::itemExists((int)$FolderId)) {
    $H_ErrorMessage = "Invalid Folder Id.";
    return;
}

$Folder = new Folder((int)$FolderId);
$IsShared = $Folder->IsShared();
$OwnerId = $Folder->OwnerId();
$IsOwner = $IsLoggedIn && ($UserId == $OwnerId);

# show login box if not shared and user is not logged in
if (!$IsShared && !$IsLoggedIn) {
    DisplayUnauthorizedAccessPage();
    return;
}

# redirect if the user should not see the folder
if (!$IsShared && !$IsOwner) {
    $H_ErrorMessage = "You do not have permission to view this folder.";
    return;
}

# if the no sorting field was specified, default to the title field
if (is_null($SortFieldId)) {
    $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_RESOURCES);
    $SortFieldId = $Schema->getFieldIdByName("Title");
}

# sort resources first
$ValueCache = [];
$SortField = new MetadataField((int)$SortFieldId);
# get the default sorting order for the given sorting field
# if the user doesn't specify a certain sorting order
if (!$OverrideDefaultSortingOrder) {
    switch ($SortField->type()) {
        case MetadataSchema::MDFTYPE_DATE:
        case MetadataSchema::MDFTYPE_TIMESTAMP:
            $H_AscSortingOrder = false;
            break;

        default:
            $H_AscSortingOrder = true;
            break;
    }
}
$Folder->Sort(function ($ItemA, $ItemB) use ($SortField, $ValueCache, $H_AscSortingOrder) {
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
    return ($H_AscSortingOrder ? 1 : -1) * StdLib::SortCompare(
        strtolower($ValA),
        strtolower($ValB)
    );
});

# check what kind of resources are present in the folder currently
$H_FolderSchemas = [];
foreach ($Folder->GetItemIds() as $Id) {
    $H_FolderSchemas[(new Record($Id))->getSchemaId()] = 1;
}

$Id = $Folder->Id();
$Name = $Folder->Name();
$NormalizedName = $Folder->NormalizedName();
$TruncatedName = StdLib::NeatlyTruncateString($Name, 18);
$Note = $Folder->Note();
$ItemIds = $Folder->GetItemIds($Offset, $Length);
# filter out the resources that the current user cannot view
$ItemIds = FilterNonviewableResources($ItemIds);

$Items = [];
$TotalItemCount = $Folder->GetItemCount();
$ItemCount = count($ItemIds);
$HasItems = $ItemCount > 0;
$HasPreviousItems = $Offset > 0;
$HasNextItems = $Offset + $Length < $TotalItemCount;
$PreviousOffset = max(0, $Offset - $Length);
$NextOffset = min($TotalItemCount, $Offset + $Length);

$IsSelected = false;
$ShareUrl = Common::GetShareUrl($Folder);

# move back a page if items exist but there are none to display given the
# offset and length
if (!$HasItems && $TotalItemCount > 0) {
    # compute the new offset
    $Offset = max(0, $TotalItemCount - $Length - ($TotalItemCount % $Length));

    # construct the page to redirect to
    $Redirect = "P_Folders_ViewFolder";
    $Redirect .= "&FolderId=".urlencode((string)$Id);
    $Redirect .= "&Offset=".urlencode((string)$Offset);
    $Redirect .= "&Length=".urlencode($Length);

    $AF->SetJumpToPage($Redirect);
}

# this will only work if the user is logged in, but since the page might be
# shared, only fetch it conditionally
if ($IsOwner) {
    $FolderFactory = new FolderFactory($UserId);
    $IsSelected = $FolderFactory->GetSelectedFolder()->Id() == $Folder->Id();
}

# transform item IDs into item objects
foreach ($ItemIds as $ItemId) {
    $Resource = new Record($ItemId);

    # make sure the user can view the resource
    if ($Resource->UserCanView($User)) {
        $Items[$ItemId] = $Resource;
    }
}

PageTitle(strip_tags($Name));
