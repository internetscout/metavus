<?PHP
#
#   FILE:  DeleteFolder.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
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

# canceled deletion
if (StdLib::getArrayValue($_GET, "Submit") !== "Delete") {
    return;
}

$FolderId = StdLib::getArrayValue($_GET, "FolderId");

# can't do anything if there isn't a folder ID to work with
if (!strlen($FolderId)) {
    return;
}


$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();
$Folder = new Folder($FolderId);

# delete the folder only if the resource folder contains this folder, which
# implies that the user owns the folder and it's a valid folder of resources
if ($ResourceFolder->ContainsItem($Folder->Id())) {
    $CurrentFolder = $FolderFactory->GetSelectedFolder();
    $TgtIsCurrent = $CurrentFolder->Id() == $Folder->Id();

    $ResourceFolder->RemoveItem($Folder->Id());
    $Folder->Delete();

    # if we just deleted the current folder
    if ($TgtIsCurrent) {
        $FolderIds = $ResourceFolder->GetItemIds();
        if (count($FolderIds)) {
            # select the next folder if available
            $NewCurrentFolder = new Folder(array_shift($FolderIds));
        } else {
            # or create a new default folder if we just deleted the last folder
            $NewCurrentFolder = $FolderFactory->createDefaultFolder();
        }

        $FolderFactory->SelectFolder($NewCurrentFolder);
    }
}
