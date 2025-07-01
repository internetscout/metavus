<?PHP
#
#   FILE:  PerformFolderAction.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;

use Metavus\Plugins\Folders;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;

# ----- SETUP ----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$User = User::getCurrentUser();

$AF->beginAjaxResponse();
$AF->setBrowserCacheExpirationTime(0);

$FolderId = $_GET["FolderId"] ?? null;
$Action = $_GET["Action"] ?? null;

# ----- MAIN -----------------------------------------------------------------

if (!$User->isLoggedIn()) {
    $Result = [
        "Status" => "Error",
        "Message" => "No user logged in."
    ];

    print json_encode($Result);
    return;
}

$ValidActions = ["withdraw", "share", "select"];
if (!in_array($Action, $ValidActions)) {
    $Result = [
        "Status" => "Error",
        "Message" => "Invalid Action provided."
    ];

    print json_encode($Result);
    return;
}

# can't do anything if there isn't a folder ID to work with
if ($FolderId !== null && !Folder::itemExists($FolderId)) {
    $Result = [
        "Status" => "Error",
        "Message" => "Invalid FolderId."
    ];

    print json_encode($Result);
    return;
}

$FolderFactory = new FolderFactory(User::getCurrentUser()->id());
$ResourceFolder = $FolderFactory->getResourceFolder();
$Folder = new Folder($FolderId);

if (!$ResourceFolder->containsItem($Folder->id())) {
    $Result = [
        "Status" => "Error",
        "Message" => "Target folder is owned by a different user."
    ];

    print json_encode($Result);
    return;
}

switch ($Action) {
    case "withdraw":
        $Folder->isShared(false);
        $Message = "Folder ".$Folder->id()." withdrawn.";
        break;

    case "share":
        $Folder->isShared(true);
        $Message = "Folder ".$Folder->id()."shared.";
        break;

    case "select":
        $FolderFactory->selectFolder($Folder);
        $Message = "Folder ".$Folder->id()." selected.";
        break;

    default:
        throw new \Exception(
            "Invalid action (should be impossible)."
        );
}

$Result = [
    "Status" => "Success",
    "Message" => $Message,
];
print json_encode($Result);
