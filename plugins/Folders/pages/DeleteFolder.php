<?PHP
#
#   FILE:  DeleteFolder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

namespace Metavus;

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();
$Submit = $_POST["Submit"] ?? null;
$FolderId = $_POST["FolderId"] ?? null;

# make sure the user is logged in
if (!User::requireBeingLoggedIn()) {
    return;
}

# back to ManageFolders when we are done
$AF->setJumpToPage("P_Folders_ManageFolders");

# nothing to do if FolderId is absent or invalid
if ($FolderId === null || !Folder::itemExists($FolderId)) {
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->id());
$ResourceFolder = $FolderFactory->getResourceFolder();
$Folder = new Folder($FolderId);

# nothing to do if current user does not own the target folder
if (!$ResourceFolder->containsItem($Folder->id())) {
    return;
}

# determine if target folder is currently selected folder
$CurrentFolder = $FolderFactory->getSelectedFolder();
$TgtIsCurrent = $CurrentFolder->id() == $Folder->id();

# delete target folder
$ResourceFolder->removeItem($Folder->id());
$Folder->delete();

# if target was not the currently selected folder, nothing else to do
if (!$TgtIsCurrent) {
    return;
}

$FolderIds = $ResourceFolder->getItemIds();
if (count($FolderIds)) {
    # select the next folder if available
    $NewCurrentFolder = new Folder(reset($FolderIds));
} else {
    # or create a new default folder if we just deleted the last folder
    $NewCurrentFolder = $FolderFactory->createDefaultFolder();
}

$FolderFactory->selectFolder($NewCurrentFolder);
