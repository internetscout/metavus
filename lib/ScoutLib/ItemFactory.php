<?PHP
#
#   FILE:  ItemFactory.php
#
#   Part of the ScoutLib application support library
#   Copyright 2007-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use InvalidArgumentException;

/**
 * Common factory class for item manipulation.  Not intended to be used
 * directly, but rather as a parent for factory classes for specific
 * item types.  For a derived class to use the temp methods the item
 * record in the database must include a "DateLastModified" column, and
 * the item object must include a "destroy()" method.
 */
abstract class ItemFactory
{
    use ErrorLoggingTrait;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param string $ItemClassName Class of items to be manipulated by factory.
     * @param string $ItemTableName Name of database table used to store info
     *      about items.
     * @param string $ItemIdColumnName Name of field in database table used to
     *      store item IDs.
     * @param string $ItemNameColumnName Name of field in database table used to
     *      store item names.  (OPTIONAL)
     * @param bool $OrderOpsAllowed If TRUE, ordering operations are allowed
     *      with items, and the database table must contain "Previous" and
     *      "Next" fields as described by the PersistentDoublyLinkedList class.
     * @param string $SqlCondition SQL condition clause (without "WHERE") to
     *      include when retrieving items from database.  (OPTIONAL)
     * @param callable|null $ItemInstFunc If supplied, an item ID will be passed to
     *      this function or method to instantiate items, rather than using the
     *      item class constructor.  (OPTIONAL)
     */
    public function __construct(
        string $ItemClassName,
        string $ItemTableName,
        string $ItemIdColumnName,
        ?string $ItemNameColumnName = null,
        bool $OrderOpsAllowed = false,
        ?string $SqlCondition = null,
        ?callable $ItemInstFunc = null
    ) {
        # save item access names
        $this->ItemClassName = $ItemClassName;
        $this->ItemTableName = $ItemTableName;
        $this->ItemIdColumnName = $ItemIdColumnName;
        $this->ItemNameColumnName = $ItemNameColumnName;
        $this->ItemInstFunc = $ItemInstFunc;

        # save database operation conditional
        $this->SqlCondition = $SqlCondition;

        # save flag indicating whether item type allows ordering operations
        $this->OrderOpsAllowed = $OrderOpsAllowed;
        if ($OrderOpsAllowed) {
            $this->OrderList = new PersistentDoublyLinkedList(
                $ItemTableName,
                $ItemIdColumnName
            );
            $this->setOrderOpsCondition(null);
        }

        # grab our own database handle
        $this->DB = new Database();
    }

    /**
     * Get class name of items manipulated by factory.
     * @return string Class name.
     */
    public function getItemClassName(): string
    {
        return $this->ItemClassName;
    }

    /**
     * Clear out (call the destroy() method) for any temp items more than specified
     *       number of minutes old.
     * @param int $MinutesUntilStale Number of minutes before items are considered stale.
     *       (OPTIONAL - defaults to 7 days)
     * @return int Number of stale items deleted.
     */
    public function cleanOutStaleTempItems(int $MinutesUntilStale = 10080): int
    {
        # load array of stale items
        $MinutesUntilStale = max($MinutesUntilStale, 1);
        $this->DB->query("SELECT " . $this->ItemIdColumnName . " FROM " . $this->ItemTableName
            . " WHERE " . $this->ItemIdColumnName . " < 0"
            . " AND DateLastModified < DATE_SUB(NOW(), "
            . " INTERVAL " . intval($MinutesUntilStale) . " MINUTE)"
            . ($this->SqlCondition ? " AND " . $this->SqlCondition : ""));
        $ItemIds = $this->DB->fetchColumn($this->ItemIdColumnName);

        # delete stale items
        foreach ($ItemIds as $ItemId) {
            $Item = $this->getItem($ItemId);
            $Item->destroy();
        }

        # report number of items deleted to caller
        return count($ItemIds);
    }

    /**
     * Retrieve next available (non-temp) item ID.  If there are currently no
     * items, an ID of 1 will be returned.
     * @return int Item ID.
     */
    public function getNextItemId(): int
    {
        # if no highest item ID found
        $HighestItemId = $this->getHighestItemId(true);
        if ($HighestItemId <= 0) {
            # start with item ID 1
            $ItemId = 1;
        } else {
            # else use next ID available after highest
            $ItemId = $HighestItemId + 1;
        }

        # return next ID to caller
        return $ItemId;
    }

    /**
     * Retrieve highest item ID in use.
     * @param bool $IgnoreSqlCondition If TRUE, any SQL condition set via the
     *       constructor is ignored.  (OPTIONAL, defaults to FALSE)
     * @return int Item ID.
     * @see ItemFactory::ItemFactory()
     */
    public function getHighestItemId(bool $IgnoreSqlCondition = false): int
    {
        # use class-wide condition if set
        $ConditionString = ($this->SqlCondition && !$IgnoreSqlCondition)
            ? " WHERE " . $this->SqlCondition : "";

        # return highest item ID to caller
        return $this->DB->query(
            "SELECT " . $this->ItemIdColumnName
            . " FROM " . $this->ItemTableName
            . $ConditionString
            . " ORDER BY " . $this->ItemIdColumnName
            . " DESC LIMIT 1",
            $this->ItemIdColumnName
        );
    }

    /**
     * Return next available temporary item ID.
     * @return int Temporary item ID.
     */
    public function getNextTempItemId(): int
    {
        $LowestItemId = $this->DB->query(
            "SELECT " . $this->ItemIdColumnName
            . " FROM " . $this->ItemTableName
            . " ORDER BY " . $this->ItemIdColumnName
            . " ASC LIMIT 1",
            $this->ItemIdColumnName
        );
        if ($LowestItemId > 0) {
            $ItemId = -1;
        } else {
            $ItemId = $LowestItemId - 1;
        }
        return $ItemId;
    }

    /**
     * Get count of items.
     * @param string $Condition SQL condition to include in query to retrieve
     *       item count.  The condition should not include "WHERE".  (OPTIONAL)
     * @param bool $IncludeTempItems Whether to include temporary items in
     *       count.  (OPTIONAL, defaults to FALSE)
     * @param array $Exclusions Array of item IDs that should be excluded from
     *       the count (OPTIONAL)
     * @return int Item count
     * @throws Exception when so many exclusions are provided that they cannot be
     *   sent in an SQL query
     */
    public function getItemCount(
        ?string $Condition = null,
        bool $IncludeTempItems = false,
        array $Exclusions = []
    ): int {
        # use condition if supplied
        $ConditionString = ($Condition != null) ? " WHERE " . $Condition : "";

        # if temp items are to be excluded
        if (!$IncludeTempItems) {
            # if a condition was previously set
            if (strlen($ConditionString)) {
                # add in condition to exclude temp items
                $ConditionString .= " AND (" . $this->ItemIdColumnName . " >= 0)";
            } else {
                # use condition to exclude temp items
                $ConditionString = " WHERE " . $this->ItemIdColumnName . " >= 0";
            }
        }

        # add class-wide condition if set
        if ($this->SqlCondition) {
            $ConditionString .= (strlen($ConditionString) ? " AND " : " WHERE ")
                .$this->SqlCondition;
        }

        # add exclusions
        if (count($Exclusions)) {
            $ConditionString .= (strlen($ConditionString) ? " AND " : " WHERE ")
                .$this->ItemIdColumnName." NOT IN "
                ."(".implode(",", $Exclusions).")";
        }

        # construct the query
        $Query = "SELECT COUNT(*) AS RecordCount"
            . " FROM " . $this->ItemTableName
            . $ConditionString;

        # verify that it isn't too long
        if (strlen($Query) > $this->DB->getMaxQueryLength()) {
            # not lot of good options in this case; attempt to handle the
            # exclusions on the frontend and hope that we don't pop the memory
            # cap rather than just giving up
            $Exclusions = array_flip($Exclusions);
            $Names = array_filter(
                $this->getItemNames($Condition),
                function ($ItemId) use ($Exclusions, $IncludeTempItems) {
                    if (!$IncludeTempItems && $ItemId < 0) {
                        return false;
                    }
                    return !isset($Exclusions[$ItemId]);
                },
                ARRAY_FILTER_USE_KEY
            );

            return count($Names);
        }

        # retrieve item count
        $Count = $this->DB->query(
            $Query,
            "RecordCount"
        );

        # return count to caller
        return intval($Count);
    }

    /**
     * Return array of item IDs.
     * @param string $Condition SQL condition clause to restrict selection
     *       of items (should not include "WHERE").
     * @param bool $IncludeTempItems Whether to include temporary items
     *       in returned set.  (OPTIONAL, defaults to FALSE)
     * @param string $SortField Database column to use to sort results.
     *       (OPTIONAL)
     * @param bool $SortAscending If TRUE, sort items in ascending order,
     *       otherwise sort items in descending order.  (OPTIONAL, and
     *       only meaningful if a sort field is specified.)
     * @return array Item IDs.
     */
    public function getItemIds(
        ?string $Condition = null,
        bool $IncludeTempItems = false,
        ?string $SortField = null,
        bool $SortAscending = true
    ): array {
        return $this->getItemIdsForCondition(
            $Condition,
            $IncludeTempItems,
            $SortField,
            $SortAscending
        );
    }

    /**
     * Get newest modification date (based on values in "DateLastModified"
     * column in database table).
     * @param string $Condition SQL condition clause to restrict selection
     *       of items (should not include "WHERE").
     * @return string|null Lastest modification date in SQL date/time format,
     *       or null when no item is found.
     */
    public function getLatestModificationDate(?string $Condition = null)
    {
        # set up SQL condition if supplied
        $ConditionString = ($Condition == null) ? "" : " WHERE " . $Condition;

        # add class-wide condition if set
        if ($this->SqlCondition) {
            if (strlen($ConditionString)) {
                $ConditionString .= " AND " . $this->SqlCondition;
            } else {
                $ConditionString = " WHERE " . $this->SqlCondition;
            }
        }

        # return modification date for item most recently changed
        return $this->DB->query(
            "SELECT MAX(DateLastModified) AS LastChangeDate"
            . " FROM " . $this->ItemTableName . $ConditionString,
            "LastChangeDate"
        );
    }

    /**
     * Retrieve item by item ID.  This method assumes that an item can be
     * loaded by passing an item ID to the appropriate class constructor.
     * @param int $ItemId Item ID.
     * @return mixed Item of appropriate class.
     */
    public function getItem(int $ItemId)
    {
        if (is_callable($this->ItemInstFunc)) {
            $Item = ($this->ItemInstFunc)($ItemId);
        } else {
            $Item = new $this->ItemClassName($ItemId);
        }
        return $Item;
    }

    /**
     * Check that item exists with specified ID.
     * @param int $ItemId ID of item.
     * @param bool $IgnoreSqlCondition If TRUE, any SQL condition set in the
     *       constructor is ignored.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if item exists with specified ID.
     */
    public function itemExists(int $ItemId, bool $IgnoreSqlCondition = false): bool
    {
        $Condition = $IgnoreSqlCondition ? ""
            : ($this->SqlCondition ? " AND " . $this->SqlCondition : "");
        $ItemCount = $this->DB->query("SELECT COUNT(*) AS ItemCount"
            . " FROM " . $this->ItemTableName
            . " WHERE " . $this->ItemIdColumnName . " = " . intval($ItemId)
            . $Condition, "ItemCount");
        return ($ItemCount > 0) ? true : false;
    }

    /**
     * Retrieve item by name.
     * @param string $Name Name to match.
     * @param bool $IgnoreCase If TRUE, ignore case when attempting to match
     *       the item name.  (OPTIONAL, defaults to FALSE)
     * @return mixed Item of appropriate class or NULL if item not found.
     */
    public function getItemByName(string $Name, bool $IgnoreCase = false)
    {
        # get item ID
        $ItemId = $this->getItemIdByName($Name, $IgnoreCase);

        # if item not found
        if ($ItemId === false) {
            # report error to caller
            return null;
        } else {
            # load object and return to caller
            return $this->getItem($ItemId);
        }
    }

    /**
     * Retrieve item ID by name.
     * @param string $Name Name to match.
     * @param bool $IgnoreCase If TRUE, ignore case when attempting to match
     *       the item name.  (OPTIONAL, defaults to FALSE)
     * @return int|false ID or FALSE if name not found. If multiple items have
     *       the same name, the ID of the first matching item is returned.
     * @throws Exception If item type does not have a name field defined.
     */
    public function getItemIdByName(string $Name, bool $IgnoreCase = false)
    {
        # error out if this is an illegal operation for this item type
        if ($this->ItemNameColumnName == null) {
            throw new Exception("Attempt to get item ID by name on item type"
                . "(" . $this->ItemClassName . ") that has no name field specified.");
        }
        $CacheKey = $IgnoreCase ? (strtolower($Name) . "_ci") : ($Name . "_cs");
        # if caching is off or item ID is already loaded
        if (($this->CachingEnabled != true)
            || !isset($this->ItemIdByNameCache[$this->SqlCondition][$CacheKey])) {
            # query database for item ID
            $Comparison = $IgnoreCase
                ? "LOWER(" . $this->ItemNameColumnName . ") = '"
                . addslashes(strtolower($Name)) . "'"
                : $this->ItemNameColumnName . " = '" . addslashes($Name) . "'";
            $ItemId = $this->DB->query(
                "SELECT " . $this->ItemIdColumnName
                . " FROM " . $this->ItemTableName
                . " WHERE " . $Comparison
                . ($this->SqlCondition
                    ? " AND " . $this->SqlCondition
                    : ""),
                $this->ItemIdColumnName
            );
            $this->ItemIdByNameCache[$this->SqlCondition][$CacheKey] =
                ($this->DB->numRowsSelected() == 0) ? false : $ItemId;
        }

        # return ID or error indicator to caller
        return $this->ItemIdByNameCache[$this->SqlCondition][$CacheKey];
    }

    /**
     * Look up IDs for specified names.
     * @param array $Names Names to look up.
     * @return array Item IDs, indexed by name.  Names that are not found
     *       will have a value of FALSE.
     */
    public function getItemIdsByNames($Names): array
    {
        $ItemIds = [];
        foreach ($Names as $Name) {
            $ItemIds[$Name] = $this->getItemIdByName($Name);
        }
        return $ItemIds;
    }

    /**
     * Retrieve item names.
     * @param string $SqlCondition SQL condition (w/o "WHERE") for name
     *      retrieval. (OPTIONAL)
     * @param int $Limit Number of results to retrieve. (OPTIONAL)
     * @param int $Offset Beginning offset into results.  (OPTIONAL, defaults
     *       to 0, which is the first element)
     * @param array $Exclusions Item IDs to exclude. (OPTIONAL)
     * @return array Array with item names as values and item IDs as indexes.
     */
    public function getItemNames(
        ?string $SqlCondition = null,
        ?int $Limit = null,
        int $Offset = 0,
        array $Exclusions = []
    ): array {
        # error out if this is an illegal operation for this item type
        if ($this->ItemNameColumnName == null) {
            throw new Exception("Attempt to get array of item names"
                . " on item type (" . $this->ItemClassName . ") that has no"
                . " name field specified.");
        }

        # query database for item names
        $Condition = "";
        if ($SqlCondition) {
            $Condition = "WHERE " . $SqlCondition;
        }

        if ($this->SqlCondition) {
            $Condition .= (strlen($Condition) ? " AND " : " WHERE ")
                .$this->SqlCondition;
        }

        if (count($Exclusions)) {
            $Condition .= (strlen($Condition) ? " AND " : " WHERE ")
                .$this->ItemIdColumnName." NOT IN "
                ."(".implode(",", $Exclusions).")";
        }

        $Query = "SELECT " . $this->ItemIdColumnName
            . ", " . $this->ItemNameColumnName
            . " FROM " . $this->ItemTableName . " "
            . $Condition
            . " ORDER BY " . $this->ItemNameColumnName
            . (!is_null($Limit) ? " LIMIT ".$Limit : "" )
            . (($Offset > 0) ? " OFFSET ".$Offset : "" );

        # verify that our query isn't too long
        if (strlen($Query) > $this->DB->getMaxQueryLength()) {
            # not lot of good options in this case; attempt to handle the
            # exclusions on the frontend and hope that we don't pop the memory
            # cap rather than just giving up

            $Exclusions = array_flip($Exclusions);
            $Names = array_filter(
                $this->getItemNames($SqlCondition),
                function ($ItemId) use ($Exclusions) {
                    return !isset($Exclusions[$ItemId]);
                },
                ARRAY_FILTER_USE_KEY
            );

            return array_slice($Names, $Offset, $Limit);
        }

        $this->DB->query($Query);
        $Names = $this->DB->fetchColumn(
            $this->ItemNameColumnName,
            $this->ItemIdColumnName
        );

        # return item names to caller
        return $Names;
    }

    /**
     * Retrieve items.
     * @param string $SqlCondition SQL condition (w/o "WHERE") for name
     *       retrieval. (OPTIONAL)
     * @return array Array with item objects as values and item IDs as indexes.
     */
    public function getItems(?string $SqlCondition = null): array
    {
        $Items = array();
        $Ids = $this->getItemIds($SqlCondition);
        foreach ($Ids as $Id) {
            $Items[$Id] = $this->getItem($Id);
        }
        return $Items;
    }

    /**
     * Check whether item name is currently in use.
     * @param string $Name Name to check.
     * @param bool $IgnoreCase If TRUE, ignore case when checking.  (Defaults to FALSE)
     * @return bool TRUE if name is in use, otherwise FALSE.
     */
    public function nameIsInUse(string $Name, bool $IgnoreCase = false): bool
    {
        # specify BINARY to make string comparison case-sensitive
        $Condition = $IgnoreCase
            ? "LOWER(" . $this->ItemNameColumnName . ")"
            . " = '" . addslashes(strtolower($Name)) . "'"
            : $this->ItemNameColumnName . " = BINARY '" . addslashes($Name) . "'";
        if ($this->SqlCondition) {
            $Condition .= " AND " . $this->SqlCondition;
        }
        $NameCount = $this->DB->query("SELECT COUNT(*) AS RecordCount FROM "
            . $this->ItemTableName . " WHERE " . $Condition, "RecordCount");
        return ($NameCount > 0) ? true : false;
    }

    /**
     * Retrieve items with names matching search string.
     * @param string $SearchString String to search for.
     * @param int $NumberOfResults Number of results to return.  (OPTIONAL,
     *       defaults to 100)
     * @param bool $IncludeVariants Should Variants be included?
     *       (NOT YET IMPLEMENTED)  (OPTIONAL)
     * @param bool $UseBooleanMode If TRUE, perform search using MySQL
     *       "Boolean Mode", which among other things supports inclusion and
     *       exclusion operators on terms in the search string.  (OPTIONAL,
     *       defaults to TRUE)
     * @param int $Offset Beginning offset into results.  (OPTIONAL, defaults
     *       to 0, which is the first element)
     * @param array $IdExclusions List of IDs of items to exclude.
     * @param array $NameExclusions List of names of items to exclude.
     * @return array List of item names, with item IDs for index.
     */
    public function searchForItemNames(
        string $SearchString,
        int $NumberOfResults = 100,
        bool $IncludeVariants = false,
        bool $UseBooleanMode = true,
        int $Offset = 0,
        array $IdExclusions = array(),
        array $NameExclusions = array()
    ): array {
        # error out if this is an illegal operation for this item type
        if ($this->ItemNameColumnName == null) {
            throw new Exception("Attempt to search for item names on item type"
                . "(" . $this->ItemClassName . ") that has no name field specified.");
        }

        # return no results if empty search string passed in
        if (!strlen(trim($SearchString))) {
            return array();
        }

        # construct SQL query
        $DB = new Database();
        $QueryString = "SELECT " . $this->ItemIdColumnName . "," . $this->ItemNameColumnName
            . " FROM " . $this->ItemTableName . " WHERE "
            . $this->constructSqlConditionsForSearch(
                $SearchString,
                $IncludeVariants,
                $UseBooleanMode,
                $IdExclusions,
                $NameExclusions
            );

        # sort by item name
        $QueryString .= " ORDER BY ".$this->ItemNameColumnName." ASC";

        # limit response set
        $QueryString .= " LIMIT " . intval($NumberOfResults) . " OFFSET "
            . intval($Offset);

        # perform query and retrieve names and IDs of items found by query
        $DB->query($QueryString);
        $Names = $DB->fetchColumn($this->ItemNameColumnName, $this->ItemIdColumnName);

        # remove excluded words that were shorter than the MinWordLength
        # (these will always be returned as mysql effectively ignores them)
        $MinWordLen = $DB->query(
            "SHOW VARIABLES WHERE variable_name='ft_min_word_len'",
            "Value"
        );

        # explode the search string into whitespace delimited tokens,
        # iterate over each token
        $Words = preg_split("/[\s]+/", trim($SearchString));
        if ($Words === false) {
            throw new Exception("Unable to split search string \""
                    .trim($SearchString)."\".");
        }
        foreach ($Words as $Word) {
            # if this token was an exclusion
            if ($Word[0] == "-") {
                # remove the - prefix to get the TgtWord
                $TgtWord = substr($Word, 1);

                # if this token was an exclusion shorter than MindWordLen
                if (strlen($TgtWord) < $MinWordLen) {
                    # filter names that match this exclusion from results
                    $NewNames = array();
                    foreach ($Names as $Id => $Name) {
                        if (!preg_match('/\b' . $TgtWord . '/i', $Name)) {
                            $NewNames[$Id] = $Name;
                        }
                    }
                    $Names = $NewNames;
                }
            }
        }

        # return names to caller
        return $Names;
    }

    /**
     * Retrieve count of items with names matching search string.
     * @param string $SearchString String to search for.
     * @param bool $IncludeVariants Include Variants ?
     *       (NOT YET IMPLEMENTED)  (OPTIONAL)
     * @param bool $UseBooleanMode If TRUE, perform search using MySQL
     *       "Boolean Mode", which among other things supports inclusion and
     *       exclusion operators on terms in the search string.  (OPTIONAL,
     *       defaults to TRUE)
     * @param array $IdExclusions List of IDs of items to exclude.
     * @param array $NameExclusions List of names of items to exclude.
     * @return int Count of matching items.
     */
    public function getCountForItemNames(
        string $SearchString,
        bool $IncludeVariants = false,
        bool $UseBooleanMode = true,
        array $IdExclusions = array(),
        array $NameExclusions = array()
    ): int {
        # return no results if empty search string passed in
        if (!strlen(trim($SearchString))) {
            return 0;
        }

        # construct SQL query
        $DB = new Database();
        $QueryString = "SELECT COUNT(*) as ItemCount FROM "
            . $this->ItemTableName . " WHERE "
            . $this->constructSqlConditionsForSearch(
                $SearchString,
                $IncludeVariants,
                $UseBooleanMode,
                $IdExclusions,
                $NameExclusions
            );

        # perform query and retrieve names and IDs of items found by query
        $DB->query($QueryString);
        return intval($DB->fetchField("ItemCount"));
    }

    /**
     * Reindex supplied associative array, by replacing item names with item IDs.
     * @param array $Array Array to reindex.
     * @return array Reindexed array.
     * @throws InvalidArgumentException If key found that does not match item name.
     */
    public function reindexByItemIds($Array): array
    {
        $NewArray = [];
        foreach ($Array as $Key => $Value) {
            $Id = $this->getItemIdByName($Key);
            if ($Id == false) {
                throw new InvalidArgumentException(
                    "Unknown name found (\"" . $Key . "\")."
                );
            }
            $NewArray[$Id] = $Value;
        }
        return $NewArray;
    }

    /**
     * Enable/disable caching of item information.
     * @param bool $NewValue TRUE to enable caching, or FALSE to disable. (OPTIONAL)
     * @return bool TRUE if caching is enabled, otherwise FALSE.
     * @see clearCaches()
     */
    public function cachingEnabled(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            $this->CachingEnabled = $NewValue ? true : false;
        }
        return $this->CachingEnabled;
    }

    /**
     * Clear item information caches.
     * @see cachingEnabled()
     */
    public function clearCaches(): void
    {
        unset($this->ItemIdByNameCache);
    }


    # ---- Ordering Operations -----------------------------------------------

    /**
     * Set SQL condition (added to WHERE clause) used to select items for
     * ordering operations.  NULL may be passed in to clear any existing
     * condition.
     * @param string|null $Condition SQL condition (should not include "WHERE").
     */
    public function setOrderOpsCondition($Condition): void
    {
        # condition is non-negative IDs (non-temp items) plus supplied condition
        $NewCondition = $this->ItemIdColumnName . " >= 0"
            . ($Condition ? " AND " . $Condition : "")
            . ($this->SqlCondition ? " AND " . $this->SqlCondition : "");
        $this->OrderList->sqlCondition($NewCondition);
    }

    /**
     * Insert item into order before specified item.  If the item is already
     * present in the order, it is moved to the new location.  If the target
     * item is not found, the new item is added to the beginning of the order.
     * @param mixed $TargetItem Item (object or ID) to insert before.
     * @param mixed $NewItem Item (object or ID) to insert.
     */
    public function insertBefore($TargetItem, $NewItem): void
    {
        # error out if ordering operations are not allowed for this item type
        if (!$this->OrderOpsAllowed) {
            throw new Exception("Attempt to perform order operation on item"
                . " type (" . $this->ItemClassName . ") that does not support"
                . " ordering.");
        }

        # insert/move item
        $this->OrderList->insertBefore($TargetItem, $NewItem);
    }

    /**
     * Insert item into order after specified item.  If the item is already
     * present in the order, it is moved to the new location.  If the target
     * item is not found, the new item is added to the end of the order.
     * @param mixed $TargetItem Item (object or ID) to insert after.
     * @param mixed $NewItem Item to insert.
     */
    public function insertAfter($TargetItem, $NewItem): void
    {
        # error out if ordering operations are not allowed for this item type
        if (!$this->OrderOpsAllowed) {
            throw new Exception("Attempt to perform order operation on item"
                . " type (" . $this->ItemClassName . ") that does not support"
                . " ordering.");
        }

        # insert/move item
        $this->OrderList->insertAfter($TargetItem, $NewItem);
    }

    /**
     * Add item to beginning of order.  If the item is already present in the order,
     * it is moved to the beginning.
     * @param mixed $Item Item (object or ID) to add.
     */
    public function prepend($Item): void
    {
        # error out if ordering operations are not allowed for this item type
        if (!$this->OrderOpsAllowed) {
            throw new Exception("Attempt to perform order operation on item"
                . " type (" . $this->ItemClassName . ") that does not support"
                . " ordering.");
        }

        # prepend item
        $this->OrderList->prepend($Item);
    }

    /**
     * Add item to end of order.  If the item is already present in the order,
     * it is moved to the end.
     * @param mixed $Item Item (object or ID) to add.
     */
    public function append($Item): void
    {
        # error out if ordering operations are not allowed for this item type
        if (!$this->OrderOpsAllowed) {
            throw new Exception("Attempt to perform order operation on item"
                . " type (" . $this->ItemClassName . ") that does not support"
                . " ordering.");
        }

        # add/move item
        $this->OrderList->append($Item);
    }

    /**
     * Retrieve list of item IDs in order.
     * @return array List of item IDs.
     */
    public function getItemIdsInOrder(): array
    {
        # error out if ordering operations are not allowed for this item type
        if (!$this->OrderOpsAllowed) {
            throw new Exception("Attempt to perform order operation on item"
                . " type (" . $this->ItemClassName . ") that does not support"
                . " ordering.");
        }

        # retrieve list of IDs
        return $this->OrderList->getIds();
    }

    /**
     * Remove item from existing order.  If the item is not currently in the
     * existing order, then the call has no effect.  This does not delete or
     * otherwise remove the item from the database.
     * @param int $ItemId ID of item to be removed from order.
     */
    public function removeItemFromOrder(int $ItemId): void
    {
        # error out if ordering operations are not allowed for this item type
        if (!$this->OrderOpsAllowed) {
            throw new Exception("Attempt to perform order operation on item"
                . " type (" . $this->ItemClassName . ") that does not support"
                . " ordering.");
        }

        # remove item
        $this->OrderList->remove($ItemId);
    }

    /**
     * Get error messages (if any) from recent calls.  If no method name is
     * specified, then an array is returned with method names for the index
     * and arrays of error messages for the values.
     * @param string $Method Name of method.  (OPTIONAL)
     * @return array Array of arrays of error message strings.
     */
    public function errorMessages(?string $Method = null): array
    {
        if ($Method === null) {
            return $this->getAllErrorMessages();
        }

        return $this->getErrorMessages($Method);
    }


    # ---- PROTECTED INTERFACE -----------------------------------------------

    /**
     * Get IDs for all items that match stated condition.
     * @param string $Condition SQL condition clause to restrict selection
     *       of items (should not include "WHERE").
     * @param bool $IncludeTempItems Whether to include temporary items
     *       in returned set.  (OPTIONAL, defaults to FALSE)
     * @param string $SortField Database column to use to sort results.
     *       (OPTIONAL)
     * @param bool $SortAscending If TRUE, sort items in ascending order,
     *       otherwise sort items in descending order.  (OPTIONAL, and
     *       only meaningful if a sort field is specified.)
     */
    protected function getItemIdsForCondition(
        ?string $Condition = null,
        bool $IncludeTempItems = false,
        ?string $SortField = null,
        bool $SortAscending = true
    ): array {
        # if temp items are supposed to be included
        if ($IncludeTempItems) {
            # condition is only as supplied
            $ConditionString = ($Condition == null) ? "" : " WHERE " . $Condition;
        } else {
            # condition is non-negative IDs plus supplied condition
            $ConditionString = " WHERE " . $this->ItemIdColumnName . " >= 0"
                . (($Condition == null) ? "" : " AND " . $Condition);
        }

        # add class-wide condition if set
        if ($this->SqlCondition) {
            if (strlen($ConditionString)) {
                $ConditionString .= " AND " . $this->SqlCondition;
            } else {
                $ConditionString = " WHERE " . $this->SqlCondition;
            }
        }

        # add sorting if specified
        if ($SortField !== null) {
            $ConditionString .= " ORDER BY `" . addslashes($SortField) . "` "
                . ($SortAscending ? "ASC" : "DESC");
        }

        # get item IDs
        $this->DB->query("SELECT " . $this->ItemIdColumnName
            . " FROM " . $this->ItemTableName
            . $ConditionString);
        $ItemIds = $this->DB->fetchColumn($this->ItemIdColumnName);

        # return IDs to caller
        return $ItemIds;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Construct an SQL query string to search against item names.
     * @param string $SearchString String to search for.
     * @param bool $IncludeVariants Include Variants ?
     *       (NOT YET IMPLEMENTED)  (OPTIONAL)
     * @param bool $UseBooleanMode If TRUE, perform search using MySQL
     *       "Boolean Mode", which among other things supports inclusion and
     *       exclusion operators on terms in the search string.  (OPTIONAL,
     *       defaults to TRUE)
     * @param array $IdExclusions List of IDs of items to exclude.
     * @param array $NameExclusions List of names of items to exclude.
     * @return string SQL conditions.
     * @see ItemFactory::getCountForItemNames()
     * @see ItemFactory::searchForItemNames()
     */
    private function constructSqlConditionsForSearch(
        string $SearchString,
        bool $IncludeVariants = false,
        bool $UseBooleanMode = true,
        $IdExclusions = array(),
        $NameExclusions = array()
    ): string {
        $MinWordLen = Database::getFullTextSearchMinWordLength();

        if ($UseBooleanMode) {
            $QueryString = $this->constructBooleanSqlConditions(
                $SearchString
            );
        } else {
            # if we weren't in boolean mode, just include the search
            # string verbatim as a match condition
            $QueryString = "MATCH (" . $this->ItemNameColumnName . ")"
                . " AGAINST ('" . addslashes(trim($SearchString)) . "')";
        }

        # add each ID exclusion
        foreach ($IdExclusions as $IdExclusion) {
            $QueryString .= " AND " . $this->ItemIdColumnName . " != '"
                . addslashes($IdExclusion) . "' ";
        }

        # add each value exclusion
        foreach ($NameExclusions as $NameExclusion) {
            $QueryString .= " AND " . $this->ItemNameColumnName . " != '"
                . addslashes($NameExclusion) . "' ";
        }

        # add class-wide condition if set
        if ($this->SqlCondition) {
            $QueryString .= " AND " . $this->SqlCondition;
        }

        return $QueryString;
    }

    /**
     * Construct MATCH and REGEXP conditions needed for a BOOLEAN mode SQL
     * search. The resulting condition will:
     *   1) include quited strings verbatim
     *   2) make sure that each non-quoted word is prefixed with +/-
     *      so that it will be explicitly included or excluded, and
     *   3) will include REGEXP queries to match or exclude any stopwords that
     *      occurred outside of quoted strings.
     * @param string $SearchString Terms to search for.
     * @return string SQL condition.
     */
    private function constructBooleanSqlConditions(string $SearchString): string
    {
        $Condition = "";

        $StopWordList = Database::getStopWordList();
        $MinWordLen = Database::getFullTextSearchMinWordLength();

        $Tokens = $this->tokenizeSearchStringForFTS($SearchString);

        # required/excluded words that won't work in a boolean condition
        # either because they are stopwords or because they are too short
        $RequiredWords = [];
        $ExcludedWords = [];

        $NewSearchString = "";
        $InQuotedString = false;
        foreach ($Tokens as $Token) {
            # if this is the beginning of a quoted string
            #   " -> quoted string implicitly required
            #  +" -> quoted string explicitly required
            #  -" -> quoted string explicitly forbidden
            $InQuotedString |= preg_match('/^[+-]?"/', $Token);
            if ($InQuotedString) {
                $NewSearchString .= $Token . " ";
                # we're still in a quoted string when our token
                # doesn't end with a quote
                $InQuotedString &= (substr($Token, -1) != '"');
            } else {
                # extract just the 'word' part of the token to
                # check against our stopword list (alphabetic
                # characters, apostrophes, and underscores)
                $Word = preg_replace("/[^a-zA-Z_']/", "", $Token);

                # default stopword lists are all lowercase
                # comparisons against the list respect the case sensitivity of
                #   the server collation, which is case insensitive by default
                # include a case sensitive check just in case the server admin has
                #   configured a case sensitive collation and also added upper-case
                #   stopwords to a custom list
                # see https://dev.mysql.com/doc/refman/5.6/en/fulltext-stopwords.html
                if (in_array(strtolower($Word), $StopWordList) ||
                    in_array($Word, $StopWordList) ||
                    strlen($Word) < $MinWordLen) {
                    if ($Token[0] == "-") {
                        $ExcludedWords[] = $Word;
                    } else {
                        $RequiredWords[] = $Word;
                    }
                } else {
                    # if our token isn't explicitly required or
                    # excluded, mark it required
                    if ($Token[0] != "+" && $Token[0] != "-") {
                        $Token = "+" . $Token;
                    }

                    $NewSearchString .= $Token . " ";
                }
            }
        }

        # trim trailing whitespace, close any open quotes
        $NewSearchString = trim($NewSearchString);
        if ($InQuotedString) {
            $NewSearchString .= '"';
        }

        if (strlen($NewSearchString)) {
            # build onto our query string by appending the boolean search
            # conditions
            $Condition .= "MATCH (" . $this->ItemNameColumnName . ")"
                . " AGAINST ('" . addslashes(trim($NewSearchString)) . "'"
                . " IN BOOLEAN MODE)";
        }

        # build conditions for stopwords and short words
        $OtherConditions = [];

        foreach ($RequiredWords as $Word) {
            $OtherConditions[] = $this->ItemNameColumnName
                . " REGEXP '(^| )" . addslashes(preg_quote($Word)) . "'";
        }
        foreach ($ExcludedWords as $Word) {
            $OtherConditions[] = $this->ItemNameColumnName
                . " NOT REGEXP '(^| )" . addslashes(preg_quote($Word)) . "'";
        }

        if (count($OtherConditions)) {
            if (strlen($Condition)) {
                $Condition .= " AND ";
            }
            $Condition .= implode(" AND ", $OtherConditions);
        }

        return $Condition;
    }

    /**
     * Split a string of search terms into "words" suitable for use in an SQL
     * Boolean MATCH condition. Splits based on whitespace and hyphens and
     * strips out characters with special meaning in a MATCH() AGAINST()
     * (i.e. () and <> characters)
     * @param string $SearchString Search string.
     * @return array Tokens to search for.
     */
    private function tokenizeSearchStringForFTS(string $SearchString) : array
    {
        $Result = [];

        $SearchString = trim(preg_replace("/[)\(><]+/", "", $SearchString));
        $Tokens = preg_split('/\s+/', $SearchString);
        if ($Tokens === false) {
            throw new Exception("Unable to split search string \"".$SearchString."\".");
        }

        # MySQL's docs are annoyingly coy about mentioning this, but their
        # default full text indexer treats hyphens as a word separator. See
        # https://dev.mysql.com/doc/refman/5.7/en/fulltext-fine-tuning.html
        # where they describe different ways to reconfigure MySQL to fix this behavior.
        # See also https://bugs.mysql.com/bug.php?id=2095

        # iterate over all the tokens
        foreach ($Tokens as $Token) {
            # if there is no hyphen in the token at all
            # or if the only hyphen is a prefix character,
            # include this token and move to the next
            if ((strpos($Token, "-") === false) ||
                (($Token[0] == "-") && (strpos($Token, "-", 1) === false))) {
                $Result[] = $Token;
                continue;
            }

            # otherwise, split hyphenated words
            # into two words and maintain their required/excluded state as
            # given by a +/- prefix
            $Prefix = "";
            if ($Token[0] == "-" || $Token[0] == "+") {
                $Prefix = $Token[0];
                $Token = substr($Token, 1);
            }

            $SubTokens = explode("-", $Token);
            foreach ($SubTokens as $SubToken) {
                $Result[] = $Prefix.$SubToken;
            }
        }

        return $Result;
    }

    protected $DB;
    protected $ItemClassName;
    protected $ItemIdColumnName;
    protected $ItemInstFunc;
    protected $ItemNameColumnName;
    protected $ItemTableName;

    private $CachingEnabled = true;
    private $ItemIdByNameCache;
    private $OrderOpsAllowed;
    private $OrderList;
    private $SqlCondition;
}
