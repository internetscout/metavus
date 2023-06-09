<?PHP
#
#   FILE:  RemoveSearchResults.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\RecordFactory;
use Metavus\SearchEngine;
use Metavus\SearchParameterSet;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- SETUP -------------------------------------------------------

# check authorization
if (!Common::apiPageCompletion("P_Folders_ManageFolders")) {
    return;
}

PageTitle("Folders - Remove Items From Folder");

# get search parameters from url
$SearchParams = new SearchParameterSet();
$SearchParams->urlParameters($_GET);

# retrieve user currently logged in
$User = User::getCurrentUser();

# get the folders plugin
$FoldersPlugin = PluginManager::getInstance()->getPluginForCurrentPage();

# set up variables
$Errors = [];
$FolderFactory = new FolderFactory($User->id());

# get currently selected folder id
$FolderId = $FolderFactory->getSelectedFolder()->id();

# initialize folder from id
$ResourceFolder = $FolderFactory->getResourceFolder();
$Folder = new Folder($FolderId);

# ----- MAIN --------------------------------------------------------

# retrieve user currently logged in
$User = User::getCurrentUser();

# perform search
$SearchEngine = new SearchEngine();

$SearchResults = $SearchEngine->searchAll($SearchParams);


# filter resources the user cannot see
foreach ($SearchResults as $SchemaId => $Results) {
    $RFactory = new RecordFactory($SchemaId);
    $ViewableIds = $RFactory->filterOutUnviewableRecords(
        array_keys($Results),
        $User
    );
    $SearchResults[$SchemaId] = array_intersect_key(
        $Results,
        array_flip($ViewableIds)
    );
}

# add items only if the resource folder contains this folder, which implies
# that the user owns the folder and it's a valid folder of resources
if ($ResourceFolder->containsItem($Folder->id())) {
    foreach ($SearchResults as $SearchesByItem) {
        foreach ($SearchesByItem as $SearchId => $SearchResult) {
            $Folder->removeItem($SearchId);
        }
    }
} else {
    # user doesn't own the folder
    array_push($Errors, 'E_FOLDERS_NOTFOLDEROWNER');
}

# This page does not output any HTML
ApplicationFramework::getInstance()->suppressHTMLOutput();

/** @phpstan-ignore-next-line */
$FoldersPlugin::processPageResponse($Errors);
