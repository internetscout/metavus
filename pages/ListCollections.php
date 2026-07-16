<?PHP
#
#   FILE:  ListCollections.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_Collections - Collections visible to the current user (array of
#       Records, with Record IDs for the index).
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\Date;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Normalize a value from a metadata field to a string intended to be suitable
 * for sort comparisons.
 * @param mixed $Value Value to normalize.
 * @return string Normalized value for comparisons.
 */
function normalizeFieldValue($Value): string
{
    if (is_array($Value)) {
        # sort array values so that fields containing the same elements in a
        # different order will get the same normalized value
        sort($Value);
        return implode("-", $Value);
    }

    if (is_bool($Value)) {
        return $Value ? "1" : "0";
    }

    if (is_int($Value) || is_float($Value)) {
        return (string)$Value;
    }

    if (is_scalar($Value)) {
        return trim(strtolower((string)$Value));
    }

    throw new Exception(
        "Unable to normalize value (should be impossible)."
    );
}

/**
 * Get a normalized sort value for a collection item.
 * @param Record $Item Item to retrieve sort value from.
 * @return string Normalized value for comparisons.
 */
function getCollectionSortValue(Record $Item): ?string
{
    static $ValueCache = [];
    if (array_key_exists($Item->id(), $ValueCache)) {
        return $ValueCache[$Item->id()];
    }

    static $SortField = null;
    if ($SortField === null) {
        $SortFieldId = InterfaceConfiguration::getInstance()
            ->getInt("CollectionsSortingField");

        $CollectionSchema = (new CollectionFactory())->schema();
        $SortField = $CollectionSchema->fieldExists($SortFieldId) ?
            $CollectionSchema->getField($SortFieldId) :
            $CollectionSchema->getFieldByMappedName("Title");
    }

    if ($SortField->type() == MetadataSchema::MDFTYPE_DATE) {
        $Value = $Item->get($SortField, true);
        if ($Value !== null) {
            if (!($Value instanceof Date)) {
                throw new Exception(
                    "Called get() on a DATE field, result was not a Date object or NULL."
                    ." (Should be impossible)."
                );
            }
            $Value = $Value->beginDate();
        }
    } else {
        $Value = $Item->get($SortField);
        if ($Value !== null) {
            $Value = normalizeFieldValue($Value);
        }
    }

    $ValueCache[$Item->id()] = $Value;
    return $Value;
}

# ----- MAIN -----------------------------------------------------------------

# load visible collections
$CFactory = new CollectionFactory();
$AllCollections = $CFactory->getItems();
$H_Collections = [];
$User = User::getCurrentUser();
foreach ($AllCollections as $CollectionId => $Collection) {
    if ($Collection->userCanView($User)) {
        $H_Collections[$CollectionId] = $Collection;
    }
}

# sort collections
$SortDescending = InterfaceConfiguration::getInstance()
    ->getBool("CollectionsSortDescending");
uasort(
    $H_Collections,
    function (Record $ItemA, Record $ItemB) use ($SortDescending): int {
        $ValueA = getCollectionSortValue($ItemA);
        $ValueB = getCollectionSortValue($ItemB);

        # always put items with no value for a given field at the end,
        # regardless of sort order
        if ($ValueA === null && $ValueB !== null) {
            return 1;
        }
        if ($ValueB === null && $ValueA !== null) {
            return -1;
        }

        # otherwise, compare values (`null <=> null` gives 0)
        $Compare = $ValueA <=> $ValueB;
        if ($Compare !== 0) {
            return ($SortDescending ? -1 : 1 ) * $Compare;
        }

        # break ties using ItemId
        $Compare = $ItemA->id() <=> $ItemB->id();
        return ($SortDescending ? -1 : 1 ) * $Compare;
    }
);
