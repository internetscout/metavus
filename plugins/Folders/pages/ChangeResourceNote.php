<?PHP
#
#   FILE:  ChangeResourceNote.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\Record;
use Metavus\User;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

global $Folder;
global $FolderId;
global $ItemId;
global $Note;
global $ReturnTo;

PageTitle("Change Resource Note");

# make sure the user is logged in
if (!CheckAuthorization()) {
    return;
}

$FolderId = StdLib::getFormValue("FolderId");
$ItemId = StdLib::getFormValue("ItemId");

# redirect if no IDs are given
if (!strlen($FolderId) || !strlen($ItemId)) {
    $AF->SetJumpToPage("P_Folders_ManageFolders");
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();
try {
    $Folder = new Folder($FolderId);
} catch (Exception $Exception) {
    # redirect if given a bad folder ID
    $AF->SetJumpToPage("P_Folders_ManageFolders");
    return;
}

# redirect if the user should not see the folder
if (!$ResourceFolder->ContainsItem($Folder->Id())) {
    $AF->SetJumpToPage("P_Folders_ManageFolders");
    return;
}

# make sure the resource is valid and belongs to the folder
if (!Record::ItemExists($ItemId) || !$Folder->ContainsItem($ItemId)) {
    $AF->SetJumpToPage("P_Folders_ManageFolders");
    return;
}

$Note = $Folder->NoteForItem($ItemId);
$ReturnTo = defaulthtmlentities(StdLib::getFormValue("ReturnTo"));
