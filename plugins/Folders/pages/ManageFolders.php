<?PHP
#
#   FILE:  ManageFolders.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Folders\FolderDisplayUI;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

/**
 * Print each folder.This function also passes the previous and subsequent
 * items to the folder printing function to facilitate item bubbling.
 * @param int $ResourceFolderId Root resource folder id
 * @param array $Folders Folders to print
 * @param Folder $SelectedFolder Currently selected folder
 * @return void
 */
function PrintFolders(int $ResourceFolderId, array $Folders, \Metavus\Folder $SelectedFolder)
{
    # we want to be able to get next and previous values, so use numeric indices
    $Folders = array_values($Folders);

    $N_Folders = count($Folders);
    for ($i = 0; $i < $N_Folders; $i++) {
        $Previous = isset($Folders[$i - 1]) ? $Folders[$i - 1] : null;
        $Next = isset($Folders[$i + 1]) ? $Folders[$i + 1] : null;
        $IsSelected = $Folders[$i]->id() == $SelectedFolder->id();

        FolderDisplayUI::printFolder(
            $ResourceFolderId,
            $Folders[$i],
            $Previous,
            $Next,
            $IsSelected
        );
    }
}

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Manage Folders");

# make sure the user is logged in
if (!User::requireBeingLoggedIn()) {
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->id());
$H_ResourceFolder = $FolderFactory->getResourceFolder();
$H_SelectedFolder = $FolderFactory->getSelectedFolder();

# these should come after fetching the selected folder since a folder might be
# created by the FolderFactory::GetSelectedFolder method
$FolderIds = $H_ResourceFolder->getItemIds();
$H_Folders = [];

# transform folder IDs to objects
foreach ($FolderIds as $FolderId) {
    $H_Folders[$FolderId] = new Folder($FolderId);
}
