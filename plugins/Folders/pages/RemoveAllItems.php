<?PHP
#
#   FILE:  RemoveAllItems.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

use ScoutLib\StdLib;

$FolderId = StdLib::getArrayValue($_GET, "FolderId");

# if "ReturnTo" is set, then jump back to that address
if (StdLib::getArrayValue($_GET, "ReturnTo", false)) {
    $AF->SetJumpToPage(urldecode($_GET["ReturnTo"]));
} elseif (isset($_SERVER["HTTP_REFERER"])) {
    # else jump back to the page which this page is being directed from
    $AF->SetJumpToPage($_SERVER["HTTP_REFERER"]);
} else {
    # jump to ViewFolder page if nothing is set
    $AF->SetJumpToPage($AF->
        getCleanRelativeUrlForPath("index.php?P=P_Folders_ViewFolder&FolderId=".$FolderId));
}

# retrieve user currently logged in
$User = User::getCurrentUser();

# check if user is logged in
if (!$User->isLoggedIn()) {
    # we cannot proceed if the user is not logged in
    return;
}

$FolderFactory = new FolderFactory($User->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();
$Folder = new Folder($FolderId);
$ItemsInFolder = $Folder->GetItemIds();

# check if the user owns the folder
if ($User->Id() != $Folder->OwnerId()) {
    # we cannot proceed if the user currently logged in is not this folder's owner
    return;
}

foreach ($ItemsInFolder as $ItemId) {
    $Folder->RemoveItem($ItemId);
}
