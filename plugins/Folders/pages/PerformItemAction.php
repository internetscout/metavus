<?PHP
#
#   FILE:  PerformItemAction.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Folders;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- SETUP ----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$AF->beginAjaxResponse();
$AF->setBrowserCacheExpirationTime(0);

$User = User::getCurrentUser();
if (!$User->isLoggedIn()) {
    $Result = [
        "Status" => "Error",
        "Message" => "No user logged in."
    ];

    print json_encode($Result);
    return;
}

# get the folders plugin
$FoldersPlugin = Folders::getInstance();

# get parameters
$Action = $_GET["Action"] ?? null;
$FolderId = $_GET["FolderId"] ?? null;
$ItemId = $_GET["ItemId"] ?? null;
$AfterItemId = $_GET["AfterItemId"] ?? null;
$NewFolderId = $_GET["NewFolderId"] ?? null;

if ($FolderId !== null && !Folder::itemExists($FolderId)) {
    $Result = [
        "Status" => "Error",
        "Message" => "No such folder."
    ];

    print json_encode($Result);
    return;
}

if ($ItemId === null || !Record::itemExists($ItemId)) {
    $Result = [
        "Status" => "Error",
        "Message" => "No such item."
    ];

    print json_encode($Result);
    return;
}

$FolderFactory = new FolderFactory($User->id());

# get the currently selected folder if no folder ID is given
if ($FolderId === null) {
    $FolderId = $FolderFactory->getSelectedFolder()->id();
}

$ResourceFolder = $FolderFactory->getResourceFolder();
$Folder = new Folder($FolderId);

# if the 'resource folder' does not contain the target, that tells us that the
# target folder is owned by a different user
if (!$ResourceFolder->containsItem($Folder->id())) {
    $Result = [
        "Status" => "Error",
        "Message" => "Folder is owned by a different user."
    ];

    print json_encode($Result);
    return;
}

if (($Action == "move" || $Action == "move-folder")
    && !$Folder->containsItem($ItemId)) {
    $Result = [
        "Status" => "Error",
        "Message" => "Source folder does not contain the item being moved."
    ];

    print json_encode($Result);
    return;
}


# ----- MAIN -----------------------------------------------------------------

switch ($Action) {
    case "add":
        $Folder->appendItem($ItemId);
        if ($Folder->getSortFieldId() !== false) {
            $Folder->sort();
        }
        $Message = "Item successfully added.";
        break;

    case "remove":
        $Folder->removeItem($ItemId);
        $Message = "Item successfully removed.";
        break;

    case "prepend":
        $Folder->prependItem($ItemId);
        $Folder->setSortFieldId(false);
        $Message = "Item successfully prepended.";
        break;

    case "move":
        if ($AfterItemId === null || !Record::itemExists($AfterItemId)) {
            $Result = [
                "Status" => "Error",
                "Message" => "Invalid AfterItemId provided."
            ];

            print json_encode($Result);
            return;
        }

        if (!$Folder->containsItem($AfterItemId)) {
            $Result = [
                "Status" => "Error",
                "Message" => "Source folder does not contain the AfterItemId provided."
            ];

            print json_encode($Result);
            return;
        }

        $Folder->insertItemAfter($AfterItemId, $ItemId);
        $Folder->setSortFieldId(false);
        $Message = "Item successfully moved.";
        break;

    case "move-folder":
        if ($NewFolderId === null || !Folder::itemExists($NewFolderId)) {
            $Result = [
                "Status" => "Error",
                "Message" => "Invalid destination folder."
            ];

            print json_encode($Result);
            return;
        }

        $NewFolder = new Folder($NewFolderId);
        if (!$ResourceFolder->containsItem($NewFolder->id())) {
            $Result = [
                "Status" => "Error",
                "Message" => "You do not own the destination folder."
            ];

            print json_encode($Result);
            return;
        }

        $Folder->removeItem($ItemId);
        $NewFolder->prependItem($ItemId);
        if ($NewFolder->getSortFieldId() !== false) {
            $NewFolder->sort();
        }
        $Message = "Item successfully moved to new folder.";
        break;

    default:
        $Result = [
            "Status" => "Error",
            "Message" => "Invalid action requested."
        ];

        print json_encode($Result);
        return;
}

# and report success
$Result = [
    "Status" => "Success",
    "Message" => $Message
];
print json_encode($Result);
