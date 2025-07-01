<?PHP
#
#   FILE:  UpdateResourceNote.php (Folders plugin)
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

# make sure the user is logged in
if (!CheckAuthorization()) {
    return;
}

$AF = ApplicationFramework::getInstance();

$FolderId = StdLib::getFormValue("FolderId");
$ItemId = StdLib::getFormValue("ItemId");
$ResourceNote = StdLib::getFormValue("ResourceNote");

# if no valid folder id, go back to ManageFolders
if ($FolderId === null || !Folder::itemExists($FolderId)) {
    $AF->setJumpToPage("P_Folders_ManageFolders");
    return;
}

# otherwise, back to the ViewFolder page for this folder
$AF->setJumpToPage(
    "index.php?P=P_Folders_ViewFolder&FolderId=".$FolderId
);

# if we weren't saving a new note, nothing to do
if (StdLib::getFormValue("Submit") != "Change Resource Note") {
    return;
}

# can't do anything if there aren't valid IDs to work with
if ($ItemId === null || !Record::itemExists($ItemId)) {
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->id());
$ResourceFolder = $FolderFactory->getResourceFolder();
$Folder = new Folder($FolderId);

# if resource folder does not contain the target FolderId, that implies that a
# different user owns the folder
if (!$ResourceFolder->containsItem($FolderId)) {
    return;
}

# update folder note
$Folder->noteForItem($ItemId, $ResourceNote);
