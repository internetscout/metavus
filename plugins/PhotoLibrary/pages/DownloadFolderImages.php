<?PHP
#
#   FILE:  DownloadFolderImages.php (PhotoLibrary plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_ErrorMessage - Error message to display, if one was encountered.
#
# @scout:phpstan

namespace Metavus;

use Exception;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\PhotoLibrary;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$PluginMgr = PluginManager::getInstance();
$Plugin = PhotoLibrary::getInstance();

if (!class_exists("\\ZipArchive")) {
    $H_ErrorMessage = "ZipArchive PHP class is not available.";
    return;
}

if (!$PluginMgr->pluginReady("Folders")) {
    throw new Exception("The Folders plugin is not available.");
}

$FolderId = StdLib::getFormValue("FolderId");
if (!is_numeric($FolderId) || !Folder::itemExists((int)$FolderId)) {
    $H_ErrorMessage = "Invalid Folder ID.";
    return;
}

$User = User::getCurrentUser();
$Folder = new Folder((int)$FolderId);

# enforce the same folder visibility checks used by the Folders ViewFolder page
if (!$Folder->isShared() && !$User->isLoggedIn()) {
    User::handleUnauthorizedAccess();
    return;
}

if (!$Folder->isShared() && ($Folder->ownerId() != $User->id())) {
    $H_ErrorMessage = "You do not have permission to view this folder.";
    return;
}

if (!$Plugin->folderHasViewableImages($Folder, $User)) {
    $H_ErrorMessage = "No downloadable Photo Library images were found in this folder.";
    return;
}

try {
    $ZipPath = $Plugin->prepareZipFileWithImagesFromFolder((int)$FolderId);
} catch (Exception $Exception) {
    $H_ErrorMessage = "Zip preparation failed: ".$Exception->getMessage();
    return;
}

if ($ZipPath === null) {
    $H_ErrorMessage = "No downloadable Photo Library images were found in this folder.";
    return;
}

$FullZipPath = dirname(__DIR__, 3)."/".$ZipPath;
if (!$AF->downloadFile($FullZipPath, basename($ZipPath), "application/zip", true)) {
    $H_ErrorMessage = "Unable to download generated Zip file.";
    return;
}
