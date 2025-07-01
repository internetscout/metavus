<?PHP
#
#   FILE:  RemoveAllItems.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

$FolderId = $_GET["FolderId"] ?? null;

if (isset($_SERVER["HTTP_REFERER"])) {
    # else jump back to the page which this page is being directed from
    $AF->setJumpToPage($_SERVER["HTTP_REFERER"]);
} else {
    # jump to ViewFolder page if nothing is set
    $AF->setJumpToPage(
        ApplicationFramework::baseUrl()
            ."index.php?P=P_Folders_ViewFolder&FolderId=".$FolderId
    );
}

# retrieve user currently logged in
$User = User::getCurrentUser();

# check if user is logged in
if (!$User->isLoggedIn()) {
    # we cannot proceed if the user is not logged in
    return;
}

$FolderFactory = new FolderFactory($User->id());
$ResourceFolder = $FolderFactory->getResourceFolder();
$Folder = new Folder($FolderId);

# check if the user owns the folder
if ($User->id() != $Folder->ownerId()) {
    # we cannot proceed if the user currently logged in is not this folder's owner
    return;
}

$ItemsInFolder = $Folder->getItemIds();
foreach ($ItemsInFolder as $ItemId) {
    $Folder->removeItem($ItemId);
}
