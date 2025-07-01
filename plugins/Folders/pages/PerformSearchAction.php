<?PHP
#
#   FILE:  PerformSearchAction.php (Folders plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Folders;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use ScoutLib\ApplicationFramework;

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
    $SortFieldId = $_GET["SF"] ?? null;
    $SortDescending = $_GET["SD"] ?? null;

    # use specified sort field
    if (!is_null($SortFieldId) && MetadataSchema::fieldExistsInAnySchema($SortFieldId)) {
        $SortField = MetadataField::getField($SortFieldId);
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

    $SortFieldName = $SortField instanceof MetadataField
        ? $SortField->name() : $SortField;

    return [$SortFieldName, $SortDescending];
}

/**
* Perform a search using the given search groups and sorting info.
* @param SearchParameterSet $SearchParams Search group parameters for the search engine.
* @param string|null $SortFieldName Name of the field to sort by.
* @param bool $SortDescending TRUE to sort the results in descending order.
* @return array Returns an array of search results.
*/
function performSearch(
    SearchParameterSet $SearchParams,
    ?string $SortFieldName,
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

# set up variables
$Errors = [];
$FolderFactory = new FolderFactory(User::getCurrentUser()->id());

$FolderId = $FolderFactory->getSelectedFolder()->id();
$ResourceFolder = $FolderFactory->getResourceFolder();
$Folder = new Folder($FolderId);

$ItemType = $_GET["ItemType"] ?? null;

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Folders - Add Items to Folder");

$Action = $_GET["Action"] ?? null;

# ----- MAIN -----------------------------------------------------------------
# add items only if the resource folder contains this folder, which implies
# that the user owns the folder and it's a valid folder of resources
if (!$ResourceFolder->containsItem($Folder->id())) {
    $Result = [
        "Status" => "Error",
        "Message" => "Target folder is owned by a different user."
    ];
    print json_encode($Result);
    return;
}

# check that the provided action is one we know how to do
if (!in_array($Action, ["add", "remove"])) {
    $Result = [
        "Status" => "Error",
        "Message" => "Invalid action provided."
    ];
    print json_encode($Result);
    return;
}

# extract search params
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

    if ($Action == "add") {
        $Folder->appendItems(array_keys($Results));
    } else {
        foreach (array_keys($Results) as $ItemId) {
            $Folder->removeItem((int)$ItemId);
        }
    }
}

$Result = [
    "Status" => "Success",
    "Message" => "Items successfully "
        .(($Action == "add") ? "added" : "removed")."."
];
print json_encode($Result);
