<?PHP
#
#   FILE:  AddSearchResults.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\RecordFactory;
use Metavus\SearchEngine;
use Metavus\SearchParameterSet;
use Metavus\SystemConfiguration;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Get sorting information from GET parameters.
* @return array Returns an array containing the sort field name and sort
*       descending value.
*/
function getSortInfo(): array
{
    $Schema = new MetadataSchema();
    $SortField = null;
    $SortFieldId = StdLib::getArrayValue($_GET, "SF");
    $SortDescending = StdLib::getArrayValue($_GET, "SD");

    # use specified sort field
    if (!is_null($SortFieldId) && MetadataSchema::fieldExistsInAnySchema($SortFieldId)) {
        $SortField = new MetadataField($SortFieldId);
    }

    # use the sort order specified
    if (!is_null($SortDescending)) {
        $SortDescending = (bool)$SortDescending;
    } elseif (!is_null($SortField)) {
        # use the sort order defaults for the given sort field
        switch ($SortField->type()) {
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_DATE:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
                $SortDescending = true;
                break;
            default:
                $SortDescending = false;
                break;
        }
    } else {
        # otherwise sort descending by default
        $SortDescending = true;
    }

    $SortFieldName = $SortField instanceof Metavus\MetadataField
        ? $SortField->name() : $SortField;

    return [$SortFieldName, $SortDescending];
}

/**
* Perform a search using the given search groups and sorting info.
* @param SearchParameterSet $SearchParams Search group parameters for the search engine.
* @param string $SortFieldName Name of the field to sort by.
* @param bool $SortDescending TRUE to sort the results in descending order.
* @return array Returns an array of search results.
*/
function performSearch(
    SearchParameterSet $SearchParams,
    string $SortFieldName = null,
    bool $SortDescending
): array {
    # retrieve user currently logged in
    $User = User::getCurrentUser();

    $SearchEngine = new SearchEngine();

    # perform search
    if (!is_null($SortFieldName)) {
        $SearchParams->sortBy($SortFieldName);
    }
    $SearchParams->sortDescending($SortDescending);
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

    return $SearchResults;
}

/**
* Callback function used with search engine objects to filter out temporary
* resources.
* @param int $Id Resource ID to check.
* @return bool Returns TRUE if the resource ID is less than zero.
*/
function Folders_FilterOutTempResources($Id): bool
{
    return $Id < 0;
}

# ----- SETUP ----------------------------------------------------------------
# check authorization
if (!Common::apiPageCompletion("P_Folders_ManageFolders")) {
    return;
}

# get the folders plugin
$FoldersPlugin = PluginManager::getInstance()->getPluginForCurrentPage();

# set up variables
$Errors = [];
$FolderFactory = new FolderFactory(User::getCurrentUser()->id());
$FolderId = StdLib::getArrayValue($_GET, "FolderId");

# get the currently selected folder if no folder ID is given
if ($FolderId === null) {
    $FolderId = $FolderFactory->getSelectedFolder()->id();
}

$ResourceFolder = $FolderFactory->getResourceFolder();
$Folder = new Folder($FolderId);

$FolderId = StdLib::getArrayValue($_GET, "FolderId");

$ItemType = StdLib::getArrayValue($_GET, "ItemType");

PageTitle("Folders - Add Items to Folder");

# ----- MAIN -----------------------------------------------------------------
# add items only if the resource folder contains this folder, which implies
# that the user owns the folder and it's a valid folder of resources
if ($ResourceFolder->containsItem($Folder->id())) {
    $SearchParams = new SearchParameterSet();
    $SearchParams->urlParameters($_GET);

    list($SortFieldName, $SortDescending) = getSortInfo();

    $SearchResults = performSearch(
        $SearchParams,
        $SortFieldName,
        $SortDescending
    );

    foreach ($SearchResults as $SchemaId => $Results) {
        # filter out the resources that do not belong to the target $ItemType
        if (($ItemType !== null) && ($SchemaId != $ItemType)) {
            continue;
        }
        $Folder->appendItems(array_keys($Results));
    }
} else {
    # user doesn't own the folder
    array_push($Errors, 'E_FOLDERS_NOTFOLDEROWNER');
}
# ----- PAGE ROUTING  -----------------------------------------------------------------
# handle page routing based on the success/failure above.

# This page does not output any HTML
ApplicationFramework::getInstance()->suppressHTMLOutput();

/** @phpstan-ignore-next-line */
$FoldersPlugin::processPageResponse($Errors);
