<?PHP
#
#   FILE:  ConfirmDeleteFolder.php (Folders plugin)
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

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Delete Folder");

# make sure the user is logged in
if (!User::requireBeingLoggedIn()) {
    return;
}

$FolderId = $_GET["FolderId"] ?? null;
if ($FolderId === null || !Folder::itemExists($FolderId)) {
    $H_Error = "FolderId is invalid or absent.";
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->id());
$ResourceFolder = $FolderFactory->getResourceFolder();
$H_Folder = new Folder($FolderId);

if (!$ResourceFolder->containsItem($H_Folder->id())) {
    $H_Error = "Target folder is owned by a different user.";
    return;
}
