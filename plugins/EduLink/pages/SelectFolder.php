<?PHP
#
#   FILE:  SelectFolder.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

# Select a folder to include in an LTI Deep Linking responses. See docstring
# for EduLink plugin for a description of the request flow.

# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_LaunchId - Launch Id for the current request
#   $H_FolderOptionList - HtmlOptionList to display the avaiable folders
#   $H_Folder - Currently selected folder or FALSE if none is selected
#   $H_BaseLink - Base link for this page

namespace Metavus;

use Exception;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\Plugins\EduLink;
use ScoutLib\ApplicationFramework;
use ScoutLib\HtmlOptionList;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$Plugin = EduLink::getInstance();

$H_LaunchId = $_GET["L"];
$H_OwnerId = $_GET["O"];

$AF->suppressStandardPageStartAndEnd();

$H_FolderIds = FolderFactory::getSharedFoldersOwnedByUsers(
    [ $H_OwnerId ]
);

$H_FolderIds = FolderFactory::filterOutFoldersWithNoPublicItems(
    $H_FolderIds
);

$Plugin->recordListPublisherFolders(
    $H_LaunchId,
    $H_OwnerId
);
