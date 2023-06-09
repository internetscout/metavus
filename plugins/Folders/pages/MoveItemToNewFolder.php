<?PHP
#
#   FILE:  MoveItemToNewFolder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

# check authorization and setup HTML suppression and page redirection
use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\User;
use ScoutLib\StdLib;

if (!Common::ApiPageCompletion("P_Folders_ManageFolders")) {
    return;
}

$OldFolderId = StdLib::getArrayValue($_GET, "OldFolderId");
$NewFolderId = StdLib::getArrayValue($_GET, "NewFolderId");
$ItemId = StdLib::getArrayValue($_GET, "ItemId");

# can't do anything if there aren't any valid IDs to work with
if (!strlen($OldFolderId) || !strlen($NewFolderId) || !strlen($ItemId)) {
    return;
}


$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();
$OldFolder = new Folder($OldFolderId);
$NewFolder = new Folder($NewFolderId);

$HasOldFolder = $ResourceFolder->ContainsItem($OldFolder->Id());
$HasNewFolder = $ResourceFolder->ContainsItem($NewFolder->Id());

# move the item only if the resource folder
# contains this folder, which implies that the user owns the folder and it's
# a valid folder of resources
if ($HasOldFolder && $HasNewFolder) {
    # make sure the item ID is valid
    if ($OldFolder->ContainsItem($ItemId)) {
        $OldFolder->RemoveItem($ItemId);
        $NewFolder->PrependItem($ItemId);
    }
}
