<?PHP
#
#   FILE:  ManageFolders.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Folders\FolderDisplayUI;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\User;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
 * Print each folder.This function also passes the previous and subsequent
 * items to the folder printing function to facilitate item bubbling.
 * @param Folder $ResourceFolderId Root resource folder
 * @param array $Folders Folders to print
 * @param Folder $SelectedFolder Currently selected folder
 * @return void
 */
function PrintFolders($ResourceFolderId, array $Folders, \Metavus\Folder $SelectedFolder)
{
    # we want to be able to get next and previous values, so use numeric indices
    $Folders = array_values($Folders);

    $N_Folders = count($Folders);
    for ($i = 0; $i < $N_Folders; $i++) {
        $Previous = isset($Folders[$i - 1]) ? $Folders[$i - 1] : null;
        $Next = isset($Folders[$i + 1]) ? $Folders[$i + 1] : null;
        $IsSelected = $Folders[$i]->Id() == $SelectedFolder->Id();

        FolderDisplayUI::PrintFolder(
            $ResourceFolderId,
            $Folders[$i],
            $Previous,
            $Next,
            $IsSelected
        );
    }
}

# ----- MAIN -----------------------------------------------------------------

global $ResourceFolder;
global $SelectedFolder;
global $Folders;
global $HasFolders;

PageTitle("Manage Folders");

# make sure the user is logged in
if (!CheckAuthorization()) {
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->Id());
$ResourceFolder = $FolderFactory->GetResourceFolder();
$SelectedFolder = $FolderFactory->GetSelectedFolder();

# these should come after fetching the selected folder since a folder might be
# created by the FolderFactory::GetSelectedFolder method
$FolderIds = $ResourceFolder->GetItemIds();
$Folders = [];
$HasFolders = count($FolderIds);

# transform folder IDs to objects
foreach ($FolderIds as $FolderId) {
    $Folders[$FolderId] = new Folder($FolderId);
}
