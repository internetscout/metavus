<?PHP
#
#   FILE:  PersistentDoublyLinkedList.php
#
#   Part of the ScoutLib application support library
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use ScoutLib\Database;
use Exception;

/**
 * Persistent doubly-linked-list data structure, with its data stored in a
 * specified database table.  The specified table is usually also being used
 * to store other information about the items being referenced by the list.
 * Items in the list are assumed to have associated unique IDs, and anywhere
 * item objects are passed in as parameters, the objects are assumed to have
 * Id() methods that can be used to retrieve their associated IDs.  More
 * than one type of item can be included in a list, by use of the (optional)
 * item type parameters.  (Item IDs and types are assumed to be positive
 * integers.)
 */
class PersistentDoublyLinkedList
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.  The specified database table must include an INT
     * field containing IDs for the items being managed, and two other INT
     * fields that have the same name with "Previous" and "Next" prepended
     * to it (e.g. "ItemId", "PreviousItemId", and "NextItemId").  These
     * additional INT fields should have a default value of -1.  If mixed
     * item types are to be included in the list, then the table must also
     * include an INT field for the types of the items being managed, and
     * two other INT fields that have the same name with "Previous" and
     * "Next" prepended ("ItemType", "PreviousItemType", "NextItemType").
     * @param string $ItemTableName Database table in which to store list information.
     * @param string $ItemIdFieldName Database field name for item IDs.
     * @param string $SqlCondition SQL condition for referencing items in database.
     *       (OPTIONAL)  This is intended to be used to specify a conditional
     *       clause to be added to any SQL statements that are used to store
     *       or retrieve information about items within the list, usually
     *       with the goal of information about multiple lists being stored in
     *       the same table in the database.  The condition should not include
     *       a leading WHERE or other leading SQL clause joining operator.
     * @param string $ItemTypeFieldName Database field name for item types.  (OPTIONAL)
     * @see PersistentDoublyLinkedList::SqlCondition()
     */
    public function __construct(
        string $ItemTableName,
        string $ItemIdFieldName,
        ?string $SqlCondition = null,
        ?string $ItemTypeFieldName = null
    ) {

        # grab our own database handle
        $this->DB = new Database();

        # save configuration
        $this->ItemTableName = $ItemTableName;
        $this->ItemIdFieldName = $ItemIdFieldName;
        $this->ItemTypeFieldName = $ItemTypeFieldName;
        $this->ItemTypesInUse = ($ItemTypeFieldName === null) ? false : true;
        $this->sqlCondition($SqlCondition);
    }

    /**
     * Get or set/update SQL condition for referencing items in database.  This
     * is intended to be used to specify a conditional clause to be added to
     * any SQL statements that are used to store or retrieve information about
     * items within the list, usually with the goal of information about
     * multiple lists being stored in the same table in the database.  The
     * condition should not include a leading WHERE or other leading SQL
     * clause joining operator.
     * @param string $Condition New SQL condition clause.  (OPTIONAL)
     * @return string|null Current SQL condition clause or NULL if no clause set.
     */
    public function sqlCondition(?string $Condition = null)
    {
        if ($Condition) {
            $this->SqlCondition = $Condition;
        }
        return $this->SqlCondition;
    }

    /**
     * Insert item into list before specified item.  If the item is already
     * present in the list, it is moved to the new location.  If the target
     * item is not found, the new item is added to the beginning of the list.
     * @param mixed $TargetItemOrItemId Item to insert before.
     * @param mixed $NewItemOrItemId Item to insert.
     * @param int $TargetItemType Type of item to insert before.  (OPTIONAL)
     * @param int $NewItemType Type of item to insert.  (OPTIONAL)
     */
    public function insertBefore(
        $TargetItemOrItemId,
        $NewItemOrItemId,
        ?int $TargetItemType = null,
        ?int $NewItemType = null
    ): void {

        # verify item types supplied or omitted as appropriate
        $this->checkItemTypeParameter($TargetItemType);
        $this->checkItemTypeParameter($NewItemType);

        # retrieve item IDs
        $NewItemId = (is_object($NewItemOrItemId)
                        && method_exists($NewItemOrItemId, "id"))
                ? $NewItemOrItemId->id() : $NewItemOrItemId;
        $TargetItemId = (is_object($TargetItemOrItemId)
                        && method_exists($TargetItemOrItemId, "id"))
                ? $TargetItemOrItemId->id() : $TargetItemOrItemId;

        # remove source item from current position if necessary
        $this->remove($NewItemId, $NewItemType);

        # retrieve current previous item pointer for target item
        $TargetItemCurrentPreviousId = $this->getPreviousItemId(
            $TargetItemId,
            $TargetItemType
        );

        # if target item not found
        if ($TargetItemCurrentPreviousId === false) {
            # add new item to beginning of list
            $this->prepend($NewItemId, $NewItemType);
        } else {
            # retrieve target item type if available
            if (is_array($TargetItemCurrentPreviousId)) {
                $TargetItemCurrentPreviousType = $TargetItemCurrentPreviousId["Type"];
                $TargetItemCurrentPreviousId = $TargetItemCurrentPreviousId["ID"];
            } else {
                $TargetItemCurrentPreviousType = null;
            }

            # update IDs to link in new item
            $this->setPreviousItemId(
                $TargetItemId,
                $TargetItemType,
                $NewItemId,
                $NewItemType
            );
            if ($TargetItemCurrentPreviousId != self::LISTEND_ID) {
                $this->setNextItemId(
                    $TargetItemCurrentPreviousId,
                    $TargetItemCurrentPreviousType,
                    $NewItemId,
                    $NewItemType
                );
            }
            $this->setPreviousAndNextItemIds(
                $NewItemId,
                $NewItemType,
                $TargetItemCurrentPreviousId,
                $TargetItemCurrentPreviousType,
                $TargetItemId,
                $TargetItemType
            );
        }
    }

    /**
     * Insert item into list after specified item.  If the item is already
     * present in the list, it is moved to the new location.  If the target
     * item is not found, the new item is added to the end of the list.
     * @param mixed $TargetItemOrItemId Item to insert after.
     * @param mixed $NewItemOrItemId Item to insert.
     * @param int $TargetItemType Type of item to insert after.  (OPTIONAL)
     * @param int $NewItemType Type of item to insert.  (OPTIONAL)
     */
    public function insertAfter(
        $TargetItemOrItemId,
        $NewItemOrItemId,
        ?int $TargetItemType = null,
        ?int $NewItemType = null
    ): void {

        # verify item types supplied or omitted as appropriate
        $this->checkItemTypeParameter($TargetItemType);
        $this->checkItemTypeParameter($NewItemType);

        # retrieve item IDs
        $NewItemId = (is_object($NewItemOrItemId)
                        && method_exists($NewItemOrItemId, "id"))
                ? $NewItemOrItemId->id() : $NewItemOrItemId;
        $TargetItemId = (is_object($TargetItemOrItemId)
                        && method_exists($TargetItemOrItemId, "id"))
                ? $TargetItemOrItemId->id() : $TargetItemOrItemId;

        # remove new item from existing position (if necessary)
        $this->remove($NewItemId, $NewItemType);

        # retrieve current next item pointer for target item
        $TargetItemCurrentNextId = $this->getNextItemId(
            $TargetItemId,
            $TargetItemType
        );

        # if target item not found
        if ($TargetItemCurrentNextId === false) {
            # add new item to end of list
            $this->append($NewItemId, $NewItemType);
        } else {
            # retrieve target item type if available
            if (is_array($TargetItemCurrentNextId)) {
                $TargetItemCurrentNextType = $TargetItemCurrentNextId["Type"];
                $TargetItemCurrentNextId = $TargetItemCurrentNextId["ID"];
            } else {
                $TargetItemCurrentNextType = null;
            }

            # update IDs to link in new item
            $this->setNextItemId(
                $TargetItemId,
                $TargetItemType,
                $NewItemId,
                $NewItemType
            );
            if ($TargetItemCurrentNextId != self::LISTEND_ID) {
                $this->setPreviousItemId(
                    $TargetItemCurrentNextId,
                    $TargetItemCurrentNextType,
                    $NewItemId,
                    $NewItemType
                );
            }
            $this->setPreviousAndNextItemIds(
                $NewItemId,
                $NewItemType,
                $TargetItemId,
                $TargetItemType,
                $TargetItemCurrentNextId,
                $TargetItemCurrentNextType
            );
        }
    }

    /**
     * Add item to beginning of list.  If the item is already present in the list,
     * it is moved to the new location.
     * @param mixed $ItemOrItemId Item to add.
     * @param int $ItemType Numerical type of item to add.  (OPTIONAL)
     */
    public function prepend($ItemOrItemId, ?int $ItemType = null): void
    {
        # verify item types supplied or omitted as appropriate
        $this->checkItemTypeParameter($ItemType);

        # get item ID
        $ItemId = (is_object($ItemOrItemId) && method_exists($ItemOrItemId, "id"))
                ? $ItemOrItemId->id() : $ItemOrItemId;

        # remove new item from current position if necessary
        $this->remove($ItemId, $ItemType);

        # if there are items currently in list
        $ItemIds = $this->getIds();
        if (count($ItemIds)) {
            # link first item to source item
            if ($this->ItemTypesInUse) {
                $Row = array_shift($ItemIds);
                $FirstItemId = $Row["ID"];
                $FirstItemType = $Row["Type"];
            } else {
                $FirstItemId = array_shift($ItemIds);
                $FirstItemType = null;
            }

            $this->setPreviousItemId($FirstItemId, $FirstItemType, $ItemId, $ItemType);
            $this->setPreviousAndNextItemIds(
                $ItemId,
                $ItemType,
                self::LISTEND_ID,
                self::LISTEND_ID,
                $FirstItemId,
                $FirstItemType
            );
        } else {
            # add item to list as only item
            $this->setPreviousAndNextItemIds(
                $ItemId,
                $ItemType,
                self::LISTEND_ID,
                self::LISTEND_ID,
                self::LISTEND_ID,
                self::LISTEND_ID
            );
        }
    }

    /**
     * Add item(s) to end of list.  If an item is already present in the list,
     * it is moved to the end of the list.
     * @param int|array $ItemsOrItemIds Item or array of items to add.
     * @param int|array $ItemTypes Numerical type of item or array of types of items
     *       to add.  If supplied, the type(s) must either match whatever is
     *       supplied for the item ID(s)/object(s), or a single item type may
     *       be specified, which will be assumed for all items.  (OPTIONAL)
     */
    public function append($ItemsOrItemIds, $ItemTypes = null): void
    {
        # verify item types supplied or omitted as appropriate
        $this->checkItemTypeParameter($ItemTypes);

        # make incoming values into arrays if they aren't already
        if (!is_array($ItemsOrItemIds)) {
            $ItemsOrItemIds = array($ItemsOrItemIds);
        }
        if (!is_array($ItemTypes)) {
            $NewItemTypes = array();
            foreach ($ItemsOrItemIds as $Id) {
                $NewItemTypes[] = $ItemTypes;
            }
            $ItemTypes = $NewItemTypes;
        }

        # get item IDs
        $ItemIds = array();
        foreach ($ItemsOrItemIds as $ItemOrId) {
            $ItemIds[] = (is_object($ItemOrId) && method_exists($ItemOrId, "id"))
                    ? $ItemOrId->id() : $ItemOrId;
        }

        # lock database to prevent anyone from mucking up our changes
        $this->DB->query("LOCK TABLES `".$this->ItemTableName."` WRITE");

        # for each item
        $ItemIdList = $this->getIds();
        foreach ($ItemIds as $Index => $ItemId) {
            # retrieve item type
            $ItemType = $ItemTypes[$Index];

            # if there are items currently in list
            if (count($ItemIdList)) {
                # remove item from current position if necessary
                $ItemWasRemoved = $this->remove($ItemId, $ItemType);

                # reload item ID list if necessary
                if ($ItemWasRemoved) {
                    $ItemIdList = $this->getIds();
                }
            }

            # if there are still items currently in list
            if (count($ItemIdList)) {
                # find ID and type of last item in list
                if ($this->ItemTypesInUse) {
                    $Row = array_pop($ItemIdList);
                    $LastItemId = $Row["ID"];
                    $LastItemType = $Row["Type"];
                    array_push($ItemIdList, $Row);
                } else {
                    $LastItemId = array_pop($ItemIdList);
                    $LastItemType = null;
                    array_push($ItemIdList, $LastItemId);
                }

                # link last item to source item
                $this->setNextItemId($LastItemId, $LastItemType, $ItemId, $ItemType);
                $this->setPreviousAndNextItemIds(
                    $ItemId,
                    $ItemType,
                    $LastItemId,
                    $LastItemType,
                    self::LISTEND_ID,
                    self::LISTEND_ID
                );
            } else {
                # add item to list as only item
                $this->setPreviousAndNextItemIds(
                    $ItemId,
                    $ItemType,
                    self::LISTEND_ID,
                    self::LISTEND_ID,
                    self::LISTEND_ID,
                    self::LISTEND_ID
                );
            }

            # add item to our local ID list
            array_push(
                $ItemIdList,
                $this->ItemTypesInUse
                        ? array("ID" => $ItemId, "Type" => $ItemType)
                        : $ItemId
            );
        }

        # unlock database
        $this->DB->query("UNLOCK TABLES");
    }

    /**
     * Retrieve array of IDs of items in list, in the order that they appear
     * in the list.
     * @return Array of item IDs or (if mixed-item-type list) item IDs and types.
     *       When returning IDs and types, each element in the returned array is
     *       an associative array, with the indexes "ID" and "Type".
     */
    public function getIds(): array
    {
        # assume no items will be found in folder
        $ItemIds = array();

        # if item types are in use
        if ($this->ItemTypesInUse) {
            # retrieve IDs and types of all items in list and links between items
            $this->DB->Query("SELECT * FROM ".$this->ItemTableName
                    .($this->SqlCondition ? " WHERE ".$this->SqlCondition : "")
                    ." ORDER BY ".$this->ItemIdFieldName." ASC");

            # build up lists of next and previous item pointers
            $KnownItemTypes = [];
            $KnownItemIds = [];
            $PreviousItemIds = [];
            $NextItemIds = [];
            while ($Record = $this->DB->FetchRow()) {
                $Index = $Record[$this->ItemTypeFieldName]
                        .":".$Record[$this->ItemIdFieldName];
                $KnownItemTypes[$Index] = intval($Record[$this->ItemTypeFieldName]);
                $KnownItemIds[$Index] = intval($Record[$this->ItemIdFieldName]);
                $PreviousItemIds[$Index] = $Record["Previous".$this->ItemTypeFieldName]
                        .":".$Record["Previous".$this->ItemIdFieldName];
                $NextItemIds[$Index] = $Record["Next".$this->ItemTypeFieldName]
                        .":".$Record["Next".$this->ItemIdFieldName];
            }

            # find ID of first item in list
            $ListEndIndex = self::LISTEND_ID.":".self::LISTEND_ID;
            $Index = array_search($ListEndIndex, $PreviousItemIds);

            # if first item was found
            if ($Index !== false) {
                # traverse linked list to build list of item types and IDs
                do {
                    $ItemIds[$Index] = array(
                        "Type" => $KnownItemTypes[$Index],
                        "ID" => $KnownItemIds[$Index]
                    );
                    $Index = $NextItemIds[$Index];
                    # (stop if we've reached the end of the list)
                } while (($Index != $ListEndIndex)
                        # (stop if link points to item not in list)
                        && array_key_exists($Index, $NextItemIds)
                        # (stop if link is circular)
                        && !array_key_exists($Index, $ItemIds));
            }
        } else {
            # retrieve IDs of all items in list and links between items
            $this->DB->Query("SELECT ".$this->ItemIdFieldName
                    .", Previous".$this->ItemIdFieldName
                    .", Next".$this->ItemIdFieldName
                    ." FROM ".$this->ItemTableName
                    .($this->SqlCondition ? " WHERE ".$this->SqlCondition : "")
                    ." ORDER BY ".$this->ItemIdFieldName." ASC");

            # build up lists of next item pointers
            $PreviousItemIds = array();
            $NextItemIds = array();
            while ($Record = $this->DB->FetchRow()) {
                $Index = intval($Record[$this->ItemIdFieldName]);
                $PreviousItemIds[$Index] =
                        intval($Record["Previous".$this->ItemIdFieldName]);
                $NextItemIds[$Index] =
                        intval($Record["Next".$this->ItemIdFieldName]);
            }

            # find ID of first item in list
            $ItemId = array_search(self::LISTEND_ID, $PreviousItemIds);

            # if first item was found
            if ($ItemId !== false) {
                # traverse linked list to build list of item IDs
                do {
                    $ItemIds[] = $ItemId;
                    $ItemId = $NextItemIds[$ItemId];
                    # (stop if we've reached the end of the list)
                } while (($ItemId != self::LISTEND_ID)
                        # (stop if link points to item not in list)
                        && array_key_exists($ItemId, $NextItemIds)
                        # (stop if link is circular)
                        && !in_array($ItemId, $ItemIds));
            }
        }

        # return list of item IDs to caller
        return $ItemIds;
    }

    /**
     * Get number of items in list.
     * @return int Count of items in list.
     */
    public function getCount(): int
    {
        # retrieve count of items
        return count($this->getIds());
    }

    /**
     * Remove item from list.  If the item is not currently present in the
     * list, then the call has no effect.
     * @param int $ItemId ID of item to be removed.
     * @param int $ItemType Numerical type of item to be removed.
     * @return bool TRUE if item was removed or FALSE if item was not found.
     */
    public function remove(int $ItemId, ?int $ItemType = null): bool
    {
        # verify item types supplied or omitted as appropriate
        $this->checkItemTypeParameter($ItemType);

        # retrieve item's "previous" pointer
        $CurrentItemPreviousId = $this->getPreviousItemId($ItemId, $ItemType);

        # bail out if item was not found
        if ($CurrentItemPreviousId === false) {
            return false;
        }

        # retrieve item's "next" pointer
        $CurrentItemNextId = $this->getNextItemId($ItemId, $ItemType);
        if (is_array($CurrentItemPreviousId) && is_array($CurrentItemNextId)) {
            $CurrentItemPreviousType = $CurrentItemPreviousId["Type"];
            $CurrentItemPreviousId = $CurrentItemPreviousId["ID"];
            $CurrentItemNextType = $CurrentItemNextId["Type"];
            $CurrentItemNextId = $CurrentItemNextId["ID"];
        } else {
            $CurrentItemPreviousType = null;
            $CurrentItemNextType = null;
        }

        # if item was not first in list
        if ($CurrentItemPreviousId >= 0) {
            # link previous item to item's current next item
            $this->setNextItemId(
                $CurrentItemPreviousId,
                $CurrentItemPreviousType,
                $CurrentItemNextId,
                $CurrentItemNextType
            );
        }

        # if item was not last in list
        if ($CurrentItemNextId >= 0) {
            # link next item to item's current previous item
            $this->setPreviousItemId(
                $CurrentItemNextId,
                $CurrentItemNextType,
                $CurrentItemPreviousId,
                $CurrentItemPreviousType
            );
        }

        # set items pointers to indicate it is not part of a list
        $this->setPreviousAndNextItemIds(
            $ItemId,
            $ItemType,
            self::UNINITIALIZED_ID,
            self::UNINITIALIZED_ID,
            self::UNINITIALIZED_ID,
            self::UNINITIALIZED_ID
        );

        # report that item was removed
        return true;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $DB;
    /** Name of database table containing item list info. */
    private $ItemTableName;
    /** Suffix to use for item ID field names in database. */
    private $ItemIdFieldName;
    /** Suffix to use for item type field names in database. */
    private $ItemTypeFieldName;
    /** Whether item types are in use for this list. */
    private $ItemTypesInUse;
    /** SQL conditional clause to add when retrieving list info from database. */
    private $SqlCondition = null;

    const UNINITIALIZED_ID = -1;
    const LISTEND_ID = -2;

    /**
     * Get ID for item before specified item in list.
     * @param int $ItemId ID of item to look before.
     * @param int|null $ItemType Type of item (numerical value).
     * @return int|bool|array ID of previous item, LISTEND_ID if no previous item,
     *      or FALSE if specified item not found.  Or, if item types are in
     *      use, an associative array with "Type" and "ID" elements.
     */
    private function getPreviousItemId(int $ItemId, $ItemType)
    {
        if ($this->ItemTypesInUse) {
            $this->DB->Query("SELECT Previous".$this->ItemIdFieldName
                    .", Previous".$this->ItemTypeFieldName
                    ." FROM ".$this->ItemTableName
                    ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                    ." AND ".$this->ItemTypeFieldName." = ".intval($ItemType)
                    .($this->SqlCondition ? " AND ".$this->SqlCondition : ""));
            if (!$this->DB->NumRowsSelected()) {
                return false;
            }
            $Row = $this->DB->FetchRow();
            if ($Row["Previous".$this->ItemIdFieldName] == self::UNINITIALIZED_ID) {
                return false;
            }
            $ReturnValue["Type"] = $Row["Previous".$this->ItemTypeFieldName];
            $ReturnValue["ID"] = $Row["Previous".$this->ItemIdFieldName];
            return $ReturnValue;
        } else {
            $ReturnVal = $this->DB->Query(
                "SELECT Previous".$this->ItemIdFieldName
                        ." FROM ".$this->ItemTableName
                        ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                        .($this->SqlCondition ? " AND ".$this->SqlCondition : ""),
                "Previous".$this->ItemIdFieldName
            );
            return (($ReturnVal === null)
                    || ($ReturnVal == self::UNINITIALIZED_ID))
                    ? false : $ReturnVal;
        }
    }

    /**
     * Get ID for item after specified item in list.
     * @param int $ItemId ID of item to look after.
     * @param int|null $ItemType Type of item (numerical value).
     * @return int|bool|array ID of next item, LISTEND_ID if no next item,
     *      or FALSE if specified item not found.  Or, if item types are in
     *      use, an associative array with "Type" and "ID" elements.
     */
    private function getNextItemId(int $ItemId, $ItemType)
    {
        if ($this->ItemTypesInUse) {
            $this->DB->Query("SELECT Next".$this->ItemIdFieldName
                    .", Next".$this->ItemTypeFieldName
                    ." FROM ".$this->ItemTableName
                    ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                    ." AND ".$this->ItemTypeFieldName." = ".intval($ItemType)
                    .($this->SqlCondition ? " AND ".$this->SqlCondition : ""));
            if (!$this->DB->NumRowsSelected()) {
                return false;
            }
            $Row = $this->DB->FetchRow();
            if ($Row["Next".$this->ItemIdFieldName] == self::UNINITIALIZED_ID) {
                return false;
            }
            $ReturnValue["Type"] = $Row["Next".$this->ItemTypeFieldName];
            $ReturnValue["ID"] = $Row["Next".$this->ItemIdFieldName];
            return $ReturnValue;
        } else {
            $ReturnVal = $this->DB->Query(
                "SELECT Next".$this->ItemIdFieldName
                        ." FROM ".$this->ItemTableName
                        ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                        .($this->SqlCondition ? " AND ".$this->SqlCondition : ""),
                "Next".$this->ItemIdFieldName
            );
            return (($ReturnVal === null)
                    || ($ReturnVal == self::UNINITIALIZED_ID))
                    ? false : $ReturnVal;
        }
    }

    /**
     * Insert item before specified item in list.
     * @param int $ItemId ID of item to put item before.
     * @param int|null $ItemType Type of item (numerical value).
     * @param int $NewId ID of item to insert.
     * @param int|null $NewType Type of item (numerical value) to insert.
     */
    private function setPreviousItemId(int $ItemId, $ItemType, int $NewId, $NewType): void
    {
        if ($this->ItemTypesInUse) {
            $this->DB->Query("UPDATE ".$this->ItemTableName
                    ." SET Previous".$this->ItemIdFieldName." = ".intval($NewId)
                    .", Previous".$this->ItemTypeFieldName." = ".intval($NewType)
                    ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                    ." AND ".$this->ItemTypeFieldName." = ".intval($ItemType)
                    .($this->SqlCondition ? " AND ".$this->SqlCondition : ""));
        } else {
            $this->DB->Query("UPDATE ".$this->ItemTableName
                    ." SET Previous".$this->ItemIdFieldName." = ".intval($NewId)
                    ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                    .($this->SqlCondition ? " AND ".$this->SqlCondition : ""));
        }
    }

    /**
     * Insert item after specified item in list.
     * @param int $ItemId ID of item to put item after.
     * @param int|null $ItemType Type of item (numerical value).
     * @param int $NewId ID of item to insert.
     * @param int|null $NewType Type of item (numerical value) to insert.
     */
    private function setNextItemId(int $ItemId, $ItemType, int $NewId, $NewType): void
    {
        if ($this->ItemTypesInUse) {
            $this->DB->Query("UPDATE ".$this->ItemTableName
                    ." SET Next".$this->ItemIdFieldName." = ".intval($NewId)
                    .", Next".$this->ItemTypeFieldName." = ".intval($NewType)
                    ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                    ." AND ".$this->ItemTypeFieldName." = ".intval($ItemType)
                    .($this->SqlCondition ? " AND ".$this->SqlCondition : ""));
        } else {
            $this->DB->Query("UPDATE ".$this->ItemTableName
                    ." SET Next".$this->ItemIdFieldName." = ".intval($NewId)
                    ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                    .($this->SqlCondition ? " AND ".$this->SqlCondition : ""));
        }
    }

    /**
     * Insert items before and after specified item in list.
     * @param int $ItemId ID of item to put item after.
     * @param int|null $ItemType Type of item (numerical value).
     * @param int $NewPreviousId ID of item to insert before.
     * @param int|null $NewPreviousType Type of item to insert before.
     * @param int $NewNextId ID of item to insert after.
     * @param int|null $NewNextType Type of item to insert after.
     */
    private function setPreviousAndNextItemIds(
        int $ItemId,
        $ItemType,
        int $NewPreviousId,
        $NewPreviousType,
        int $NewNextId,
        $NewNextType
    ): void {

        if ($this->ItemTypesInUse) {
            $this->DB->Query("UPDATE ".$this->ItemTableName
                    ." SET Previous".$this->ItemIdFieldName." = ".intval($NewPreviousId)
                    .", Previous".$this->ItemTypeFieldName." = "
                    .intval($NewPreviousType)
                    .", Next".$this->ItemIdFieldName." = ".intval($NewNextId)
                    .", Next".$this->ItemTypeFieldName." = ".intval($NewNextType)
                    ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                    ." AND ".$this->ItemTypeFieldName." = ".intval($ItemType)
                    .($this->SqlCondition ? " AND ".$this->SqlCondition : ""));
        } else {
            $this->DB->Query("UPDATE ".$this->ItemTableName
                    ." SET Previous".$this->ItemIdFieldName." = ".intval($NewPreviousId)
                    .", Next".$this->ItemIdFieldName." = ".intval($NewNextId)
                    ." WHERE ".$this->ItemIdFieldName." = ".intval($ItemId)
                    .($this->SqlCondition ? " AND ".$this->SqlCondition : ""));
        }
    }

    /**
     * Verify that item types are supplied when required and not supplied otherwise.
     * @param mixed $ItemType ItemType value to check
     */
    private function checkItemTypeParameter($ItemType): void
    {
        if ($this->ItemTypesInUse) {
            if ($ItemType === null) {
                throw new Exception("Item type(s) not supplied.");
            }
        } else {
            if ($ItemType !== null) {
                throw new Exception("Item type(s) supplied when not in use.");
            }
        }
    }
}
