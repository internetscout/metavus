<?PHP
#
#   FILE:  UpdateResourceNote.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\Record;
use Metavus\User;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

# check authorization and setup HTML suppression and page redirection
if (!Common::ApiPageCompletion("P_Folders_ManageFolders")) {
    return;
}

# canceled editing
if (StdLib::getFormValue("Cancel")) {
    return;
}

$FolderId = StdLib::getFormValue("FolderId");
$ItemId = StdLib::getFormValue("ItemId");
$ResourceNote = StdLib::getFormValue("ResourceNote");

# can't do anything if there aren't IDs to work with
if (!strlen($FolderId) || !strlen($ItemId)) {
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();

try {
    $Folder = new Folder($FolderId);

    # withdraw only if the resource folder contains this folder, which implies
    # that the user owns the folder and it's a valid folder of resources
    if ($ResourceFolder->ContainsItem($FolderId)) {
        # make sure the resource is valid and the folder contains it
        if (Record::ItemExists($ItemId) && $Folder->ContainsItem($ItemId)) {
            $Folder->NoteForItem($ItemId, $ResourceNote);
        }
    }
} catch (Exception $Exception) {
    # do nothing if bad folder ID
}
