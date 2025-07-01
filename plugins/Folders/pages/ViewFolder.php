<?PHP
#
#   FILE:  ViewFolder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

# redirect if no folder ID is given
$AF = ApplicationFramework::getInstance();
if (!isset($_GET["FolderId"])) {
    $H_ErrorMessage = "No Folder Id provided.";
    return;
}

$H_Length = intval($_GET["L"] ?? 25);

# check for valid Folder ID
$FolderId = $_GET["FolderId"] ?? null;
if (!is_numeric($FolderId) || !Folder::itemExists((int)$FolderId)) {
    $H_ErrorMessage = "Invalid Folder Id.";
    return;
}

$User = User::getCurrentUser();
$H_Folder = new Folder((int)$FolderId);

# show login box if not shared and user is not logged in
if (!$H_Folder->isShared() && !$User->isLoggedIn()) {
    User::handleUnauthorizedAccess();
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
if ($H_SortFieldId !== null) {
    if ($H_SortFieldId == -1) {
        $H_Folder->setSortFieldId(false);
    } else {
        $H_Folder->setSortFieldId((int)$H_SortFieldId);
        $H_Folder->setReverseSortFlag($H_TransportUI->reverseSortFlag());
        $H_Folder->sort();
    }
} else {
    $H_SortFieldId = $H_Folder->getSortFieldId();
    if ($H_SortFieldId !== null) {
        $H_TransportUI->sortField((string)$H_SortFieldId);
        $H_TransportUI->reverseSortFlag(
            $H_Folder->getReverseSortFlag()
        );
    } else {
        $H_SortFieldId = -1;
    }
}

$ItemIds = $H_Folder->getVisibleItemIds($User);

$H_TransportUI->itemCount(count($ItemIds));

$ItemIds = array_slice(
    $ItemIds,
    $H_TransportUI->startingIndex(),
    $H_Length
);

$H_TransportUI->baseLink(
    "index.php?P=P_Folders_ViewFolder"
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
