<?PHP
#
#   FILE:  DisplayCollection.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_Collection - Collection to be displayed (Record instance).  If
#       unable to determine collection, this will be set to NULL.
#   $H_Items - Items in collection (array of Records, with Record IDs
#       for the index) viewable by the current user, sorted by the standard
#       Title field values.  If there is more than one page worth of items,
#       this will contain just the items for the current page.
#   $H_LinkedCategories - Categories (controlled vocabulary terms) to
#       be displayed with items (2D array, with item IDs for the first
#       index, category (Tree, Controlled Name, or Option) IDs for the
#       second index, and category names surround by <a> tags that link
#       to search results page for the category for the values).
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_TransportUI - Instance of TransportControlsUI, with items per page,
#       total item count, and base link set.  Only set if there is more
#       than one page worth of items.
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Load categories (controlled vocabulary terms) to display for items.
 * @param array $Items Item instances, with item IDs for the index.
 * @return array Two-dimensional array, with item IDs for the first
 *      index, category IDs for the second index, and category names
 *      surrounded by <a> tags linking to the search results page for
 *      the category for the values.
 */
function loadLinkedCategories(array $Items): array
{
    # load fields from which to pull category info
    $Schemas = MetadataSchema::getAllSchemas();
    $SchemaCategoryFields = [];
    foreach ($Schemas as $Schema) {
        $TreeFields = $Schema->getFields(MetadataSchema::MDFTYPE_TREE);
        $SchemaCategoryFields[$Schema->id()] = reset($TreeFields);
    }

    # build linked category entries for each item
    $SearchUrlBase = ApplicationFramework::baseUrl()."index.php?P=SearchResults";
    $LinkedCategories = [];
    $CategoryLinkCache = [];
    foreach ($Items as $ItemId => $Item) {
        $LinkedCategories[$ItemId] = [];
        $CategoryField = $SchemaCategoryFields[$Item->getSchemaId()];
        if ($CategoryField === false) {
            continue;
        }

        $CategoryFieldId = $CategoryField->id();
        $Categories = $Item->get($CategoryField);
        foreach ($Categories as $CategoryId => $CategoryName) {
            if (!isset($CategoryLinkCache[$CategoryFieldId][$CategoryId])) {
                $SearchParams = new SearchParameterSet();
                $SearchParams->addParameter("=".$CategoryName, $CategoryField);
                $CategoryLinkCache[$CategoryFieldId][$CategoryId] =
                        $SearchUrlBase.'&amp;'.$SearchParams->urlParameterString();
            }
            $LinkedCategories[$ItemId][$CategoryId] =
                    '<a href="'.$CategoryLinkCache[$CategoryFieldId][$CategoryId]
                    .'">'.htmlspecialchars($CategoryName).'</a>';
        }
    }
    return $LinkedCategories;
}


# ----- MAIN -----------------------------------------------------------------

# parameters
$MaxItemsPerPage = InterfaceConfiguration::getInstance()->getInt("NumCollectionItems");

# initialize convenience values
$User = User::getCurrentUser();
$AF = ApplicationFramework::getInstance();

# load collection
$CFactory = new CollectionFactory();
$CollectionId = StdLib::getFormValue("ID");
$H_Collection = $CFactory->itemExists($CollectionId)
        ? new Collection($CollectionId) : null;

# retrieve list of all items in collection
$ItemIds = $H_Collection->getItemIds();

# prune out invalid or unviewable items
$ItemIds = RecordFactory::multiSchemaFilterNonViewableRecords($ItemIds, $User);

# sort items by title
usort($ItemIds, function (int $IdA, int $IdB) {
    $ItemA = new Record($IdA);
    $ItemB = new Record($IdB);
    return $ItemA->getMapped("Title") <=> $ItemB->getMapped("Title");
});

# if we have more than one page of items
if (count($ItemIds) > $MaxItemsPerPage) {
    # set up transport controls for pagination
    $H_TransportUI = new TransportControlsUI();
    $H_TransportUI->itemsPerPage($MaxItemsPerPage);
    $H_TransportUI->itemCount(count($ItemIds));
    $H_TransportUI->baseLink("index.php?P=DisplayCollection&ID=".$H_Collection->id());

    # pare down items to just those for this page
    $ItemIds = array_slice($ItemIds, $H_TransportUI->startingIndex(), $MaxItemsPerPage);
}

# instantiate items
$H_Items = [];
$SchemasInUse = [];
foreach ($ItemIds as $ItemId) {
    $Record = Record::getRecord($ItemId);
    $H_Items[$ItemId] = $Record;
    $SchemasInUse[$Record->getSchemaId()] = true;
}

# cache the page records that will be displayed
foreach (array_keys($SchemasInUse) as $SchemaId) {
    $AF->addPageCacheTag("ResourceList".$SchemaId);
}

# load categories to display for items
$H_LinkedCategories = loadLinkedCategories($H_Items);
