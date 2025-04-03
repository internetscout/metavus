<?PHP
#
#   FILE:  Folder.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\Database;
use ScoutLib\Item;
use ScoutLib\PersistentDoublyLinkedList;

/**
 * Folder object used to create and manage groups of items.  Items are identified
 * and manipulated within folders by a positive integer "ID" value.  For folders
 * intended to contain multiple types of item types, item types are arbitrary
 * string values.
 * \nosubgrouping
 */
class Folder
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** @name Setup/Initialization */ /*@(*/

    /**
     * Object constructor -- load an existing folder.  New folders must be created
     * with FolderFactory::createFolder() or FolderFactory::createMixedFolder().
     * @param int $FolderId ID of folder.
     */
    public function __construct(int $FolderId)
    {
        # create our own DB handle
        $this->DB = new Database();
        $this->DB->setValueUpdateParameters(
            "Folders",
            "FolderId = ".intval($FolderId)
        );

        # store folder ID
        $this->Id = intval($FolderId);

        # attempt to load in folder info
        $this->DB->query("SELECT * FROM Folders WHERE FolderId = ".$this->Id);
        $Record = $this->DB->fetchRow();

        # if folder was not found
        if ($Record === false) {
            # bail out with exception
            throw new Exception("Unknown Folder ID (".$FolderId.").");
        }

        $this->ContentType = $Record["ContentType"];

        # load list of resources in folder from database
        $this->DB->query("SELECT ItemId, ItemTypeId, ItemNote FROM FolderItemInts"
                ." WHERE FolderId = ".$this->Id);

        # create internal cache for item notes
        $this->ItemNoteCache = [];
        while ($Record = $this->DB->fetchRow()) {
            $Index = self::getCacheIndex($Record["ItemId"], $Record["ItemTypeId"]);
            $this->ItemNoteCache[$Index] = $Record["ItemNote"];
        }

        # load item ordering
        if ($this->ContentType == self::MIXEDCONTENT) {
            $this->OrderList = new PersistentDoublyLinkedList(
                "FolderItemInts",
                "ItemId",
                "FolderId = ".$this->Id,
                "ItemTypeId"
            );
        } else {
            $this->OrderList = new PersistentDoublyLinkedList(
                "FolderItemInts",
                "ItemId",
                "FolderId = ".$this->Id
            );
        }

        # be sure that a NormalizedName is set
        $this->normalizedName();
    }

    /**
     * Check if a folder with the given folder id exists.
     * @param int $FolderId ID of the folder to check.
     * @return bool True if the folder exists, false otherwise.
     */
    public static function itemExists(int $FolderId): bool
    {
        $DB = new Database();
        $DB->query("SELECT * FROM Folders WHERE FolderId = "
                . intval($FolderId));
        return $DB->numRowsSelected() ? true : false;
    }

    /**
     * Create new folder.
     * @param int $OwnerId User ID of folder owner.  (OPTIONAL, defaults to no owner)
     * @param string $ItemType Type of item folder will contain, if folder will
     *      contain only one type.  (OPTIONAL, defaults to mixed content folder)
     * @return static New folder.
     */
    public static function create(?int $OwnerId = null, ?string $ItemType = null)
    {
        # get item type
        $ItemTypeId = ($ItemType !== null)
                ? static::getItemTypeId($ItemType)
                : self::MIXEDCONTENT;

        # add new folder to database
        $Query = "INSERT INTO Folders SET ContentType = ".$ItemTypeId;
        if ($OwnerId !== null) {
            $Query .= ", OwnerId = \"".$OwnerId."\"";
        }
        $DB = new Database();
        $DB->query($Query);

        # retrieve ID of new folder
        $Id = $DB->getLastInsertId();

        # create new folder object and return it to caller
        return new static($Id);
    }

    /**
     * Delete folder.  (Object is no longer usable after calling this method.)
     * @return void
     */
    public function delete(): void
    {
        # take folder out of global folder order
        $Factory = new FolderFactory();
        $Factory->removeItemFromOrder($this->Id);

        # remove item listings from DB
        $this->DB->query("DELETE FROM FolderItemInts WHERE FolderId = ".$this->Id);

        # remove folder listing from DB
        $this->DB->query("DELETE FROM Folders WHERE FolderId = ".$this->Id);
    }

    /*@)*/ /* Setup/Initialization */
    # ------------------------------------------------------------------------
    /** @name Attribute Setting/Retrieval */ /*@(*/

    /**
     * Get folder ID.
     * @return int Numerical folder ID.
     */
    public function id(): int
    {
        return $this->Id;
    }

    /**
     * Get/set folder name.  When used to set the folder name this also
     * generates and sets a new normalized folder name.
     * @param string $NewValue New name.  (OPTIONAL)
     * @return String containing folder name.
     */
    public function name(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->normalizedName(self::normalizeFolderName($NewValue));
        }
        return $this->DB->updateValue("FolderName", $NewValue);
    }

    /**
     * Get/set normalized version of folder name.  This method can be used
     * to override the normalized name autogenerated when the folder name is
     * set with Folder::name().
     * @param string $NewValue New normalized version of folder name.  (OPTIONAL)
     * @return string Current normalized version of folder name.
     */
    public function normalizedName(?string $NewValue = null): string
    {
        $Name = $this->DB->updateValue("NormalizedName", $NewValue);
        # attempt to generate and set new normalized name if none found
        if (!strlen($Name)) {
            $Name = $this->DB->updateValue(
                "NormalizedName",
                self::normalizeFolderName($this->name())
            );
        }
        return $Name;
    }

    /**
     * Convert folder name to normalized form (lower-case alphanumeric only).
     * @param string $Name Folder name to normalize.
     * @return string Normalized name.
     */
    public static function normalizeFolderName(string $Name): string
    {
        return preg_replace("/[^a-z0-9]/", "", strtolower($Name));
    }

    /**
     * Get/set whether folder is publically-viewable.  (This flag is not used at
     * all by the Folder object, but rather provided for use in interface code.)
     * @param bool $NewValue Boolean value.  (OPTIONAL)
     * @return bool Boolean flag indicating whether or not folder is publically-viewable
     */
    public function isShared(?bool $NewValue = null): bool
    {
        return $this->DB->updateBoolValue("IsShared", $NewValue);
    }

    /**
     * Get/set user ID of folder owner.
     * @param int $NewValue Numerical user ID.  (OPTIONAL)
     * @return int ID of current folder owner.
     */
    public function ownerId(?int $NewValue = null): int
    {
        return intval($this->DB->updateIntValue("OwnerId", $NewValue));
    }

    /**
     * Get/set note text for folder.
     * @param string $NewValue New note text.  (OPTIONAL)
     * @return String containing current note for folder.
     */
    public function note(?string $NewValue = null): string
    {
        return $this->DB->updateValue("FolderNote", $NewValue);
    }

    /**
     * Get content type of this folder.
     * @return mixed Type of the items this folder contains or
     *      Folder::MIXEDCONTENT if the folder is a mixed-type folder.
     */
    public function contentType()
    {
        return ($this->ContentType == self::MIXEDCONTENT)
                ? $this->ContentType
                : $this->getItemTypeName($this->ContentType);
    }

    /**
     * Get cover image ID for this folder.
     * @return int|false ID of cover image or FALSE when there is none.
     */
    public function getCoverImageId()
    {
        return $this->DB->updateIntValue("CoverImageId");
    }

    /**
     * Set cover image ID for this folder.
     * @param int|false $NewValue New value or FALSE to clear the value.
     */
    public function setCoverImageId($NewValue) : void
    {
        $this->DB->updateIntValue("CoverImageId", $NewValue);
    }

    /*@)*/ /* Attribute Setting/Retrieval */
    # ------------------------------------------------------------------------
    /** @name Item Operations */ /*@(*/

    /**
     * Insert item into folder before specified item.  If the item is already
     * present in the folder, it is moved to the new location.  If the target
     * item is not found in the folder, the new item is added to the folder as
     * the first item.
     * @param mixed $TargetItemOrItemId Item to insert before.
     * @param mixed $NewItemOrItemId Item to insert.
     * @param mixed $TargetItemType Type of item to insert before.  (OPTIONAL,
     *       for mixed-item-type folders)
     * @param mixed $NewItemType Type of item to insert.  (OPTIONAL, for
     *       mixed-item-type folders)
     * @return void
     */
    public function insertItemBefore(
        $TargetItemOrItemId,
        $NewItemOrItemId,
        $TargetItemType = null,
        $NewItemType = null
    ): void {

        $this->addItem($NewItemOrItemId, $NewItemType);
        $this->OrderList->insertBefore(
            $TargetItemOrItemId,
            $NewItemOrItemId,
            self::getItemTypeId($TargetItemType),
            self::getItemTypeId($NewItemType)
        );
    }

    /**
     * Insert item into folder after specified item.  If the item is already
     * present in the folder, it is moved to the new location.  If the target
     * item is not found, the new item is added to the folder as the last item.
     * @param mixed $TargetItemOrItemId Item to insert after.
     * @param mixed $NewItemOrItemId Item to insert.
     * @param mixed $TargetItemType Type of item to insert after.  (OPTIONAL, for
     *       mixed-item-type folders)
     * @param mixed $NewItemType Type of item to insert.  (OPTIONAL, for
     *       mixed-item-type folders)
     * @return void
     */
    public function insertItemAfter(
        $TargetItemOrItemId,
        $NewItemOrItemId,
        $TargetItemType = null,
        $NewItemType = null
    ): void {

        $this->addItem($NewItemOrItemId, $NewItemType);
        $this->OrderList->insertAfter(
            $TargetItemOrItemId,
            $NewItemOrItemId,
            self::getItemTypeId($TargetItemType),
            self::getItemTypeId($NewItemType)
        );
    }

    /**
     * Add item to folder as the first item.  If the item is already present
     * in the folder, it is moved to be the first item.
     * @param mixed $ItemOrItemId Item to add.
     * @param mixed $ItemType Type of item to add.  (OPTIONAL, for
     *       mixed-item-type folders)
     * @return void
     */
    public function prependItem($ItemOrItemId, $ItemType = null): void
    {
        $this->addItem($ItemOrItemId, $ItemType);
        $this->OrderList->prepend($ItemOrItemId, self::getItemTypeId($ItemType));
    }

    /**
     * Add item to folder as the last item.  If the item is already present
     * in the folder, it is moved to be the last item.
     * @param mixed $ItemOrItemId Item to add.
     * @param mixed $ItemType Type of item to add.  (OPTIONAL, for mixed-item-type
     *       folders)
     * @return void
     */
    public function appendItem($ItemOrItemId, $ItemType = null): void
    {
        $this->addItem($ItemOrItemId, $ItemType);
        $this->OrderList->append($ItemOrItemId, self::getItemTypeId($ItemType));
    }

    /**
     * Add multiple items to the folder at the end.
     * @param array $ItemsOrItemIds ItemIds or Item objects to add.
     * @param array|int $ItemTypes Item types corresponding to the Items,
     *    or a single item type that applies to all Items.
     * @return void
     */
    public function appendItems($ItemsOrItemIds, $ItemTypes = null): void
    {
        # convert ItemTypes to an array if it wasn't one
        if (!is_array($ItemTypes)) {
            $NewItemTypes = [];
            foreach ($ItemsOrItemIds as $Id) {
                $NewItemTypes[] = $ItemTypes;
            }
            $ItemTypes = $NewItemTypes;
        }

        # get items ids
        $ItemIds = [];
        foreach ($ItemsOrItemIds as $Index => $ItemOrId) {
            $this->addItem($ItemOrId, $ItemTypes[$Index]);
        }

        # and add them to our ordering list
        if ($this->ContentType == self::MIXEDCONTENT) {
            $ItemTypeIds = [];
            foreach ($ItemTypes as $ItemType) {
                $ItemTypeIds[] = self::getItemTypeId($ItemType);
            }

            $this->OrderList->append($ItemsOrItemIds, $ItemTypeIds);
        } else {
            $this->OrderList->append($ItemsOrItemIds);
        }
    }

    /**
     * Sort items in this folder.
     * Behavior of sorting is specified by input $CompareCallback function.
     * $CompareCallback takes IDs of two items in the folder
     *       and returns a value "usort()" can recognize.
     * @param callable $CompareCallback A function that takes IDs of
     *       2 items to compare and returns a value that is
     *       > 0 if first item > the second; == 0 if equal;
     *       < 0 if first item < the second
     * @return void
     */
    public function sort(callable $CompareCallback): void
    {
        # get resources in current ordering
        $Items = self::getItemIds();

        # pre-process $Items
        foreach ($Items as $Index => $Item) {
            if ($this->ContentType == self::MIXEDCONTENT) {
                $Items[$Index] = [
                    "ID" => $Item["ID"],
                    "Type" => $Item["Type"]
                ];
            } else {
                $Items[$Index] = [
                    "ID" => $Item,
                    "Type" => null
                ];
            }
        }

        # do sorting in memory
        usort($Items, function ($ItemA, $ItemB) use ($CompareCallback) {
            return $CompareCallback($ItemA["ID"], $ItemB["ID"]);
        });

        # reflect the change on db
        foreach ($Items as $Item) {
            $this->OrderList->append($Item["ID"], self::getItemTypeId($Item["Type"]));
        }
    }

    /**
     * Retrieve array of IDs of items in folder, in the order that they appear
     * in the folder.
     * @param int $Offset Offset into results, to request a subset.  If negative,
     *       the subset will start that far from the end.  (OPTIONAL)
     * @param int $Length Number of results to return, when requesting a subset.
     *       If negative, the subset will stop that many elements from the end.
     *       (OPTIONAL - if not specified, all results to end are returned)
     * @return Array of item IDs or (if mixed-item-type list) item IDs and types.
     *       When returning IDs and types, each element in the returned array is
     *       an associative array, with the indexes "ID" and "Type".
     */
    public function getItemIds(?int $Offset = null, ?int $Length = null): array
    {
        # retrieve item ordered list of type IDs
        $ItemIds = $this->OrderList->getIds();

        # if this is a mixed-item-type folder
        if ($this->ContentType == self::MIXEDCONTENT) {
            # convert item type IDs to corresponding type names
            $NewItemIds = [];
            foreach ($ItemIds as $ItemInfo) {
                $NewItemIds[] = [
                    "ID" => $ItemInfo["ID"],
                    "Type" => self::getItemTypeName($ItemInfo["Type"]),
                ];
            }
            $ItemIds = $NewItemIds;
        }

        # prune results to subset if requested
        if ($Offset !== null) {
            if ($Length !== null) {
                $ItemIds = array_slice($ItemIds, $Offset, $Length);
            } else {
                $ItemIds = array_slice($ItemIds, $Offset);
            }
        }

        # return list of item type IDs (and possibly types) to caller
        return $ItemIds;
    }

    /**
     * Get the Item IDs in a folder that are visible to a specified user.
     * @param User $User User for visibility checks.
     * @return array Item IDs for visible records.
     */
    public function getVisibleItemIds(User $User) : array
    {
        return RecordFactory::multiSchemaFilterNonViewableRecords(
            $this->getItemIds(),
            $User
        );
    }

    /**
     * Get number of items in folder.  This does not included nested items,
     * so if a folder contains another folder that contains items, the inner
     * folder is counted as only one item.
     * @return int Number of items.
     */
    public function getItemCount(): int
    {
        return $this->OrderList->getCount();
    }

    /**
     * Get the number of items a folder that are visible to a specified user.
     * @param User $User User for visibility checks.
     * @return int Count of visible records.
     */
    public function getVisibleItemCount(User $User) : int
    {
        return count($this->getVisibleItemIds($User));
    }

    /**
     * Remove item from folder, if present.
     * @param int $ItemId ID of item to remove.
     * @param mixed $ItemType Type of item to be removed.  (OPTIONAL, for
     *       mixed-item-type folders)
     * @return void
     */
    public function removeItem(int $ItemId, $ItemType = null): void
    {
        # if resource is in folder
        if ($this->containsItem($ItemId, $ItemType)) {
            # remove item from item order
            $ItemTypeId = self::getItemTypeId($ItemType);
            $this->OrderList->remove($ItemId, $ItemTypeId);

            # remove resource from folder locally
            unset($this->ItemNoteCache[self::getCacheIndex($ItemId, $ItemTypeId)]);

            # remove resource from folder in DB
            $this->DB->query("DELETE FROM FolderItemInts"
                    ." WHERE FolderId = ".intval($this->Id)
                    ." AND ItemId = ".intval($ItemId)
                    ." AND ItemTypeId = "
                            .($ItemTypeId === null ? -1 : intval($ItemTypeId)));
        }
    }

    /**
     * Get/set note text for specific item within folder.
     * @param int $ItemId ID of item.
     * @param string $NewValue New note text for item.  (OPTIONAL)
     * @param mixed $ItemType Type of item.  (OPTIONAL, for mixed-item-type folders)
     * @return string|null Current note text for specified item, or NULL
     *      if no note.
     */
    public function noteForItem(
        int $ItemId,
        ?string $NewValue = null,
        $ItemType = null
    ) {
        $ItemTypeId = self::getItemTypeId($ItemType);
        $Index = self::getCacheIndex($ItemId, $ItemTypeId);

        $Condition = " WHERE FolderId = ".intval($this->Id)
                ." AND ItemId = ".intval($ItemId)
                ." AND ItemTypeId = ".(($ItemTypeId === null)
                        ? -1 : intval($ItemTypeId));

        if ($NewValue !== null) {
            $this->DB->query("UPDATE FolderItemInts SET ItemNote = '"
                    .addslashes($NewValue)."'".$Condition);
            $this->ItemNoteCache[$Index] = $NewValue;
        } elseif (!isset($this->ItemNoteCache[$Index])) {
            $this->ItemNoteCache[$Index] = $this->DB->query(
                "SELECT ItemNote FROM FolderItemInts".$Condition,
                "ItemNote"
            );
        }

        return $this->ItemNoteCache[$Index];
    }

    /**
     * Check whether specified item is contained in folder.
     * @param int $ItemId ID of item.
     * @param mixed $ItemType Type of item.  (OPTIONAL, for use with
     *       mixed-item-type folders)
     * @return bool TRUE if item is in folder, otherwise FALSE.
     */
    public function containsItem(int $ItemId, $ItemType = null): bool
    {
        $ItemTypeId = self::getItemTypeId($ItemType);
        return array_key_exists(
            self::getCacheIndex($ItemId, $ItemTypeId),
            $this->ItemNoteCache
        ) ? true : false;
    }

    /**
     * Create a new folder with the exact same content as this folder.
     * @return Folder Folder object just cloned.
     */
    public function duplicate()
    {
        $Factory = new FolderFactory();
        $NewFolder = $Factory->createFolder(
            $this->contentType(),
            $this->name(),
            $this->ownerId()
        );

        # copy old folder metadata to the the new folder
        foreach (["NormalizedName", "Note", "IsShared"] as $Method) {
            $NewFolder->$Method($this->$Method());
        }

        # copy items in the old folder and their notes to the new folder
        if ($this->contentType() == Folder::MIXEDCONTENT) {
            foreach ($this->getItemIds() as $Item) {
                $ItemId = $Item["ID"];
                $ItemType = $Item["Type"];
                $ItemNote = $this->noteForItem($ItemId, null, $ItemType);

                $NewFolder->appendItem($ItemId, $ItemType);
                $NewFolder->noteForItem($ItemId, $ItemNote, $ItemType);
            }
        } else {
            foreach ($this->getItemIds() as $ItemId) {
                $NewFolder->appendItem($ItemId);
                $NewFolder->noteForItem($ItemId, $this->noteForItem($ItemId));
            }
        }

        return $NewFolder;
    }

    /*@)*/ /* Item Operations */

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $DB;
    protected $Id;

    private $ContentType;

    private $ItemNoteCache;
    private $OrderList;

    # item type IDs (indexed by normalized type name)
    private static $ItemTypeIds;
    # item type names (indexed by type ID)
    private static $ItemTypeNames;

    # content type that indicates folder contains mixed content types
    const MIXEDCONTENT = -1;

    /** @cond */
    /**
     * Map item type string to numerical value.  (This method is not private
     * because FolderFactory needs it.)
     * @param string|null $TypeName Item type name.
     * @return int|null Item type ID or NULL if TypeName was NULL.
     */
    public static function getItemTypeId($TypeName)
    {
        # return NULL if TypeName is NULL.
        if ($TypeName === null) {
            return null;
        }

        # make sure item type map is loaded
        self::loadItemTypeMap();

        # normalize item type name
        $NormalizedTypeName = strtoupper(
            preg_replace("/[^a-zA-Z0-9]/", "", $TypeName)
        );

        # if name not already mapped
        if (!array_key_exists($NormalizedTypeName, self::$ItemTypeIds)) {
            # add name to database
            static $DB;
            if (!isset($DB)) {
                $DB = new Database();
            }
            $DB->query("INSERT INTO FolderContentTypes SET"
                    ." TypeName = '".addslashes($TypeName)."',"
                    ." NormalizedTypeName = '".addslashes($NormalizedTypeName)."'");

            # add name to cached mappings
            $NewTypeId = $DB->getLastInsertId();
            self::$ItemTypeIds[$NormalizedTypeName] = $NewTypeId;
            self::$ItemTypeNames[$NewTypeId] = $TypeName;
        }

        # return item type ID to caller
        return self::$ItemTypeIds[$NormalizedTypeName];
    }
    /** @endcond */

    /**
     * Map item type ID value to item type name.
     * @param int $TypeId Item type ID.
     * @return string|null Item type name.
     */
    private static function getItemTypeName(int $TypeId)
    {
        # make sure item type map is loaded
        self::loadItemTypeMap();

        # if ID not present in mappings
        if (!array_key_exists($TypeId, self::$ItemTypeNames)) {
            # return null value
            return null;
        } else {
            # return item type name to caller
            return self::$ItemTypeNames[$TypeId];
        }
    }

    /**
     * Load item type map (self::$ItemTypeIds) from database.  May be called
     * repeatedly with no additional significant overhead.
     * @return void
     */
    private static function loadItemTypeMap(): void
    {
        # if name-to-number item type map not already loaded
        if (!isset(self::$ItemTypeIds)) {
            # load item type map from database
            $DB = new Database();
            $DB->query("SELECT * FROM FolderContentTypes");
            self::$ItemTypeIds = [];
            self::$ItemTypeNames = [];
            while ($Row = $DB->fetchRow()) {
                self::$ItemTypeIds[$Row["NormalizedTypeName"]] = $Row["TypeId"];
                self::$ItemTypeNames[$Row["TypeId"]] = $Row["TypeName"];
            }
        }
    }

    /**
     * Add resource to folder (does not add to ordered list).
     * @param object|int $ItemOrItemId Item object or item ID.
     * @param string $ItemType Item type name.
     * @return void
     */
    private function addItem($ItemOrItemId, $ItemType): void
    {
        # convert item to ID if necessary
        if (is_object($ItemOrItemId)) {
            if (!method_exists($ItemOrItemId, "id")) {
                throw new Exception("Object passed to addItem does not have id function.");
            }
            $ItemId = $ItemOrItemId->id();
        } else {
            $ItemId = $ItemOrItemId;
        }

        # if resource is not already in folder
        if (!$this->containsItem($ItemId, $ItemType)) {
            # convert item type to item type ID
            $ItemTypeId = self::getItemTypeId($ItemType);

            # convert null item type to "no type" value used in database
            if ($ItemTypeId === null) {
                $ItemTypeId = -1;
            }

            # add resource to folder locally
            $this->ItemNoteCache[self::getCacheIndex($ItemId, $ItemTypeId)] = null;

            # add resource to folder in DB
            $this->DB->query("INSERT INTO FolderItemInts SET"
                    ." FolderId = ".intval($this->Id)
                    .", ItemId = ".intval($ItemId)
                    .", ItemTypeId = ".intval($ItemTypeId));
        }
    }

    /**
     * Generate index to be used with item note cache array.
     * @param int $ItemId Numerical item ID.
     * @param int|null $ItemTypeId Numerical item type ID.
     * @return string Index string to be used with associative cache array.
     */
    private static function getCacheIndex(int $ItemId, $ItemTypeId): string
    {
        $ItemTypeId = ($ItemTypeId === null) ? -1 : $ItemTypeId;
        return intval($ItemTypeId).":".intval($ItemId);
    }
}
