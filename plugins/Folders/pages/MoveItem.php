<?PHP
#
#   FILE:  MoveItem.php (Folders plugin)
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

$FolderId = StdLib::getArrayValue($_GET, "FolderId");
$TargetItemId = StdLib::getArrayValue($_GET, "TargetItemId");
$ItemId = StdLib::getArrayValue($_GET, "ItemId");

# can't do anything if there aren't any valid ID to work with
if (!strlen($FolderId) || !strlen($ItemId)) {
    return;
}


$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();
$Folder = new Folder($FolderId);
$IsResourceFolder = $ResourceFolder->Id() == $Folder->Id();

# move the item only if it's the resource folder or the resource folder
# contains this folder, which implies that the user owns the folder and it's
# a valid folder of resources
if ($IsResourceFolder || $ResourceFolder->ContainsItem($Folder->Id())) {
    if ($Folder->ContainsItem($ItemId)) {
        # if given a target ID, move the item after it
        if ($TargetItemId && $Folder->ContainsItem($TargetItemId)) {
            $Folder->InsertItemAfter($TargetItemId, $ItemId);
        } else {
            # otherwise just add it to the beginning of the list
            $Folder->PrependItem($ItemId);
        }
    }
}
