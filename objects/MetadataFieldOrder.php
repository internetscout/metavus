<?PHP
#
#   FILE:  MetadataFieldOrder.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ScoutLib\Database;

/**
 * Class to build metadata field ordering functionality on top of the foldering
 * functionality.
 */
class MetadataFieldOrder extends Folder
{

    /**
     * The default name given to the folders that are really metadata field
     * orders.
     */
    const DEFAULT_FOLDER_NAME = "FieldOrder";

    /**
     * Load an existing metadata field order.
     * @param int $Id The ID of the metadata field order to load.
     * @throws Exception If the ID is invalid or the order does not exist.
     */
    public function __construct(int $Id)
    {
        # being loading by calling the superclass method
        parent::__construct($Id);

        # query for order associations from the database
        $this->DB->query("
            SELECT * FROM MetadataFieldOrders
            WHERE OrderId = '".$Id."'");

        # the ID is invalid
        if ($this->DB->numRowsSelected() < 1) {
            throw new Exception("Unknown metadata field order ID");
        }

        # fetch the data
        $Row = $this->DB->fetchRow();

        # set the values
        $this->SchemaId = $Row["SchemaId"];
        $this->OrderName = $Row["OrderName"];
    }

    /**
     * Get the ID of the metadata schema with which the metadata field order is
     * associated.
     * @return int Returns the ID of the metadata schema with which the metadata
     *      field order is associated.
     */
    public function schemaId(): int
    {
        return $this->SchemaId;
    }

    /**
     * Get the name of the metadata field order.
     * @return string Returns the name of the metadata field order.
     */
    public function orderName(): string
    {
        return $this->OrderName;
    }

    /**
     * Delete the metadata field order. This removes the association of the order
     * with any schemas and deletes the folder it uses. The object should not be
     * used after calling this method.
     * @return void
     */
    public function delete(): void
    {
        # remove the order from the orders associated with schemas
        $this->DB->query("
            DELETE FROM MetadataFieldOrders
            WHERE OrderId = '".$this->id()."'");

        # remove the folder by calling the superclass method
        parent::delete();
    }

    /**
     * Fix any issues found in case an unfound bug causes something to go awry.
     * @return void
     */
    public function mendIssues(): void
    {
        $Schema = new MetadataSchema($this->SchemaId);

        # get all the fields including disabled fields but excluding temp fields
        $Fields = $Schema->getFields(null, null, true);

        foreach ($Fields as $Field) {
            # add the field if it isn't already in the order
            if (!$this->itemInOrder($Field)) {
                $this->appendItem($Field->id(), "Metavus\\MetadataField");
            }
        }
    }

    /**
     * Transform the item IDs of the metadata field order object into objects.
     * @return array An array of metadata field order object items.
     */
    public function getItems(): array
    {
        $ItemIds = $this->getItemIds();
        $Items = [];

        foreach ($ItemIds as $Info) {
            try {
                if ($Info["Type"] == 'Metavus\MetadataField') {
                    $Items[] = MetadataField::getField($Info["ID"]);
                } else {
                    $Items[] = new $Info["Type"]($Info["ID"]);
                }
            # skip invalid fields
            } catch (InvalidArgumentException $Exception) {
                continue;
            }
        }

        return $Items;
    }

    /**
     * Create a new metadata field group with the given name.
     * @param string $Name Group name.
     * @return MetadataFieldGroup New metadata field group.
     */
    public function createGroup(string $Name): MetadataFieldGroup
    {
        $FolderFactory = new FolderFactory();

        # create the new group
        $Folder = $FolderFactory->createMixedFolder($Name);
        $Group = new MetadataFieldGroup($Folder->id());

        # and add it to this ordering
        $this->appendItem($Group->id(), "Metavus\\MetadataFieldGroup");

        return $Group;
    }

    /**
     * Move the metadata fields out of the given metadata group to the metadata
     * field order and then delete it.
     * @param MetadataFieldGroup $Group Metadata field group.
     * @return void
     */
    public function deleteGroup(MetadataFieldGroup $Group): void
    {
        if ($this->containsItem($Group->id(), "Metavus\\MetadataFieldGroup")) {
            $this->moveFieldsToOrder($Group);
            $this->removeItem($Group->id(), "Metavus\\MetadataFieldGroup");
        }
    }

    /**
     * Get all the fields in this metadata field ordering in order.
     * @return array Array of MetadataField objects.
     */
    public function getFields(): array
    {
        $Fields = [];

        foreach ($this->getItems() as $Item) {
            # add fields to the list
            if ($Item instanceof MetadataField) {
                $Fields[$Item->id()] = $Item;
            # else add fields of groups to the list
            } elseif ($Item instanceof MetadataFieldGroup) {
                foreach ($Item->getFields() as $Field) {
                    $Fields[$Field->id()] = $Field;
                }
            }
        }

        return $Fields;
    }

    /**
     * Get all the groups in this metadata field ordering in order.
     * @return array Array of MetadataFieldGroup objects.
     */
    public function getGroups(): array
    {
        $ItemIds = $this->getItemIds();
        $GroupIds = array_filter($ItemIds, [$this, "GroupFilterCallback"]);

        $Groups = [];

        # transform group info to group objects
        foreach ($GroupIds as $GroupId) {
            try {
                $Groups[$GroupId["ID"]] = new $GroupId["Type"]($GroupId["ID"]);
            } catch (Exception $Exception) {
                # (moving to next item just to avoid empty catch statement)
                continue;
            }
        }

        return $Groups;
    }

    /**
     * Place items in order according to a passed array
     * @param array $Order to place items in, indices representing IDs, values representing parents
     * @param int $GroupIdOffset offset to determine what is a group, subtract to get group ID
     * @return void
     */
    public function reorder(array $Order, int $GroupIdOffset): void
    {
        # since we don't have a move item to bottom, we use a previous variable and moveItemAfter()
        # this has the side effect keeping enabled fields at the beginning of the order
        $Previous = null;
        foreach ($Order as $ItemId => $Parent) {
            if ($ItemId >= $GroupIdOffset) {
                $Item = new MetadataFieldGroup($ItemId - $GroupIdOffset);
            } else {
                $Item = MetadataField::getField($ItemId);
            }
            if (!is_null($Parent) && $Parent != "null" && $Item instanceof MetadataField) {
                $this->moveItemAfter($Previous, $Item);
                $Parent = new MetadataFieldGroup($Parent - $GroupIdOffset);
                $this->moveFieldToGroup($Parent, $Item, "append");
            } elseif ($Previous == null) {
                $this->moveItemToTop($Item);
                $Previous = $Item;
            } else {
                $this->moveItemAfter($Previous, $Item);
                $Previous = $Item;
            }
        }
    }

    /**
     * Move the given item to the top of the order.
     * @param MetadataField|MetadataFieldGroup $Item The item to move.
     * @return void
     * @throws Exception If the item isn't a metadata field or metadata group.
     * @throws Exception If the item isn't in the order.
     */
    public function moveItemToTop($Item): void
    {
        # make sure the item is either a field or group
        if (!$this->isFieldOrGroup($Item)) {
            throw new Exception("Item must be a either field or group");
        }

        # make sure the item is in the order
        if (!$this->itemInOrder($Item)) {
            throw new Exception("Item must exist in the ordering");
        }

        $OrderId = $this->getItemId($this);
        $OrderType = $this->getItemType($this);
        $ItemId = $this->getItemId($Item);
        $ItemType = $this->getItemType($Item);
        $ItemEnclosure = $this->getEnclosure($Item);
        $ItemEnclosureId = $this->getItemId($ItemEnclosure);
        $ItemEnclosureType = $this->getItemType($ItemEnclosure);

        $SameEnclosureId = $OrderId == $ItemEnclosureId;
        $SameEnclosureType = $OrderType == $ItemEnclosureType;

        # remove the item from its enclosure if necessary
        if (!$SameEnclosureId || !$SameEnclosureType) {
            $ItemEnclosure->removeItem($ItemId, $ItemType);
        }

        # move the item to the top of the order
        $this->prependItem($ItemId, $ItemType);
    }

    /**
     * Move the given item to the top of the order.
     * @param MetadataFieldGroup $Group The group within which to move the field.
     * @param MetadataField $Field The field to move.
     * @return void
     * @throws Exception If the group or field aren't in the order.
     */
    public function moveFieldToTopOfGroup(
        MetadataFieldGroup $Group,
        MetadataField $Field
    ): void {

        # make sure the items are in the order
        if (!$this->itemInOrder($Group) || !$this->itemInOrder($Field)) {
            throw new Exception("Item must exist in the ordering");
        }

        $GroupId = $this->getItemId($Group);
        $GroupType = $this->getItemType($Group);
        $FieldId = $this->getItemId($Field);
        $FieldType = $this->getItemType($Field);
        $FieldEnclosure = $this->getEnclosure($Field);
        $FieldEnclosureId = $this->getItemId($FieldEnclosure);
        $FieldEnclosureType = $this->getItemType($FieldEnclosure);

        $SameEnclosureId = $GroupId == $FieldEnclosureId;
        $SameEnclosureType = $GroupType == $FieldEnclosureType;

        # remove the item from its enclosure if necessary
        if (!$SameEnclosureId || !$SameEnclosureType) {
            $FieldEnclosure->removeItem($FieldId, $FieldType);
        }

        # move the item to the top of the group
        $Group->prependItem($FieldId, $FieldType);
    }

    /**
     * Move the given item after the given target item.
     * @param MetadataField|MetadataFieldGroup $Target The item to move after.
     * @param MetadataField|MetadataFieldGroup $Item The item to move.
     * @return void
     * @throws Exception If the items aren't a metadata field or metadata group.
     * @throws Exception If the items aren't in the order.
     * @throws Exception If attempting to put a group into another one.
     */
    public function moveItemAfter($Target, $Item): void
    {
        # make sure the items are either a field or group
        if (!$this->isFieldOrGroup($Target) || !$this->isFieldOrGroup($Item)) {
            throw new Exception("Items must be a either field or group");
        }

        # make sure the items are in the order
        if (!$this->itemInOrder($Target) || !$this->itemInOrder($Item)) {
            throw new Exception("Items must exist in the ordering");
        }

        $TargetId = $this->getItemId($Target);
        $TargetType = $this->getItemType($Target);
        $ItemId = $this->getItemId($Item);
        $ItemType = $this->getItemType($Item);
        $TargetEnclosure = $this->getEnclosure($Target);
        $TargetEnclosureId = $this->getItemId($TargetEnclosure);
        $TargetEnclosureType = $this->getItemType($TargetEnclosure);
        $ItemEnclosure = $this->getEnclosure($Item);
        $ItemEnclosureId = $this->getItemId($ItemEnclosure);
        $ItemEnclosureType = $this->getItemType($ItemEnclosure);

        $TargetInGroup = $TargetEnclosure instanceof MetadataFieldGroup;
        $ItemIsField = $Item instanceof MetadataField;

        # make sure only fields are placed in groups
        if ($TargetInGroup && !$ItemIsField) {
            throw new Exception("Only fields can go into field groups");
        }

        $SameEnclosureId = $TargetEnclosureId == $ItemEnclosureId;
        $SameEnclosureType = $TargetEnclosureType == $ItemEnclosureType;

        # move a field into a group if necessary
        if (!$SameEnclosureId || !$SameEnclosureType) {
            $ItemEnclosure->removeItem($ItemId, $ItemType);
        }

        # move the item after the target
        $TargetEnclosure->insertItemAfter(
            $TargetId,
            $ItemId,
            $TargetType,
            $ItemType
        );
    }

    /**
     * Determine whether the given item is a member of this order.
     * @param MetadataField|MetadataFieldGroup $Item Item.
     * @return bool TRUE if the item belongs to the order or FALSE otherwise.
     */
    public function itemInOrder($Item): bool
    {
        # the item would have to be a field or group to be in the order
        if (!$this->isFieldOrGroup($Item)) {
            return false;
        }

        $ItemId = $this->getItemId($Item);
        $ItemType = $this->getItemType($Item);

        # if the item is in the order, i.e., not in a group
        if ($this->containsItem($ItemId, $ItemType)) {
            return true;
        }

        # the item is in one of the groups, so search each one for it
        foreach ($this->getGroups() as $Group) {
            if ($Group->containsItem($ItemId, $ItemType)) {
                return true;
            }
        }

        # the item was not found
        return false;
    }

    /**
     * Create a new metadata field order, optionally specifying the order of the
     * fields. The array values for the order should be ordered field IDs. This
     * will overwrite any existing orders associated with the schema that have
     * the same name as the one given.
     * @param MetadataSchema $Schema Schema with which to associate the order.
     * @param string $Name Name for the metadata field order.
     * @param array $FieldOrder Optional ordered array of field IDs.
     * @return MetadataFieldOrder Returns a new MetadataFieldOrder object.
     */
    public static function createWithOrder(
        MetadataSchema $Schema,
        string $Name,
        array $FieldOrder = []
    ): self {

        $ExistingOrders = self::getOrdersForSchema($Schema);

        # remove existing orders with the same name
        if (array_key_exists($Name, $ExistingOrders)) {
            $ExistingOrders[$Name]->delete();
        }

        # create the folder
        $FolderFactory = new FolderFactory();
        $Folder = $FolderFactory->createMixedFolder(self::DEFAULT_FOLDER_NAME);

        # get all the fields including disabled fields but excluding temp fields
        $Fields = $Schema->getFields(null, null, true);

        # first, add each field from the given order
        foreach ($FieldOrder as $FieldId) {
            # skip invalid field IDs
            if (!array_key_exists($FieldId, $Fields)) {
                continue;
            }

            # remove the field from the array of fields so that we'll know after
            # looping which fields weren't added
            unset($Fields[$FieldId]);

            # add the metadata field to the folder
            $Folder->appendItem($FieldId, "Metavus\\MetadataField");
        }

        # finally, add any remaining fields that weren't removed in the loop
        # above
        foreach ($Fields as $FieldId => $Field) {
            $Folder->appendItem($FieldId, "Metavus\\MetadataField");
        }

        # associate the order with the schema in the database
        $DB = new Database();
        $DB->query("
            INSERT INTO MetadataFieldOrders
            SET SchemaId = '".$Schema->id()."',
            OrderId = '".$Folder->id()."',
            OrderName = '".addslashes($Name)."'");

        # reconstruct the folder as a metadata schema order object and return
        return new MetadataFieldOrder($Folder->id());
    }

    /**
     * Get a metadata field order with a specific name for a given metadata
     * schema.
     * @param MetadataSchema $Schema Schema of which to get the order.
     * @param string $Name Name of the metadata field order to get.
     * @return MetadataFieldOrder|null Returns a MetadataFieldOrder object
     *       or NULL if it doesn't exist.
     * @see GetOrderForSchemaId()
     */
    public static function getOrderForSchema(MetadataSchema $Schema, string $Name)
    {
        return self::getOrderForSchemaId($Schema->id(), $Name);
    }

    /**
     * Get a metadata field order with a specific name for a given metadata
     * schema ID.
     * @param int $SchemaId Schema ID of which to get the order.
     * @param string $Name Name of the metadata field order to get.
     * @return MetadataFieldOrder|null Returns a MetadataFieldOrder object
     *       or NULL if it doesn't exist.
     * @see GetOrderForSchema()
     */
    public static function getOrderForSchemaId(int $SchemaId, string $Name)
    {
        $Orders = self::getOrdersForSchemaId($SchemaId);

        # return NULL if the order doesn't exist
        if (!array_key_exists($Name, $Orders)) {
            return null;
        }

        # return the order
        return $Orders[$Name];
    }

    /**
     * Get all of the orders associated with a schema.
     * @param MetadataSchema $Schema Schema of which to get the orders.
     * @return array Returns an array of orders associated with the schema.
     * @see GetOrdersForSchemaId()
     */
    public static function getOrdersForSchema(MetadataSchema $Schema): array
    {
        return self::getOrdersForSchemaId($Schema->id());
    }

    /**
     * Get all of the orders associated with a schema ID.
     * @param int $SchemaId ID of the schema of which to get the orders.
     * @return array Returns an array of orders associated with the schema.
     * @see GetOrdersForSchema()
     */
    public static function getOrdersForSchemaId(int $SchemaId): array
    {
        $Orders = [];
        $DB = new Database();

        # query the database for the orders associated with the schema
        $DB->query("
            SELECT * FROM MetadataFieldOrders
            WHERE SchemaId = '".$SchemaId."'");

        # loop through each found record
        foreach ($DB->fetchRows() as $Row) {
            try {
                # construct an object using the ID and add it to the array
                $Orders[$Row["OrderName"]] = new MetadataFieldOrder($Row["OrderId"]);
            # remove invalid orders when encountered
            } catch (Exception $Exception) {
                $DB->query("
                    DELETE FROM MetadataFieldOrders
                    WHERE OrderId = '".addslashes($Row["OrderId"])."'");
            }
        }

        return $Orders;
    }

    /**
     * Determine if the given item is a metadata field or metadata field group.
     * @param mixed $Item Item to check.
     * @return bool TRUE if the item is a metadata field or group, FALSE otherwise.
     */
    protected function isFieldOrGroup($Item): bool
    {
        if ($Item instanceof MetadataField) {
            return true;
        }

        if ($Item instanceof MetadataFieldGroup) {
            return true;
        }

        return false;
    }

    /**
     * Get the ID of the given item.
     * @param MetadataField|MetadataFieldGroup|MetadataFieldOrder $Item Item.
     * @return int|null The ID of the item or NULL if the item is invalid.
     */
    protected function getItemId($Item)
    {
        return method_exists($Item, "id") ? $Item->id() : null;
    }

    /**
     * Get the type of the given item.
     * @param mixed $Item Item of type MetadataField, MetadataFieldGroup, or MetadataFieldOrder
     * @return string|null The type of the item or NULL if the item is invalid.
     */
    protected function getItemType($Item)
    {
        return is_object($Item) ? get_class($Item) : null;
    }

    /**
     * Callback for the filter to retrieve groups only from the metadata field
     * order.
     * @param array $Item Array of item info, i.e., item ID and type.
     * @return bool TRUE if the item is a group or FALSE otherwise
     */
    protected function groupFilterCallback(array $Item): bool
    {
        return $Item["Type"] == "Metavus\\MetadataFieldGroup";
    }

    /**
     * Get the metadata field order or metadata field group that encloses the
     * given item.
     * @param MetadataField|MetadataFieldGroup $Item Item.
     * @return MetadataFieldGroup|MetadataFieldOrder|null The metadata field
     *       order or metadata field group that encloses the item, or NULL otherwise.
     */
    protected function getEnclosure($Item)
    {
        $ItemId = $this->getItemId($Item);
        $ItemType = $this->getItemType($Item);

        # the item is in the order, i.e., not in a group
        if ($this->containsItem($ItemId, $ItemType)) {
            return $this;
        }

        # the item is in one of the groups, so search each one for it
        foreach ($this->getGroups() as $Group) {
            if ($Group->containsItem($ItemId, $ItemType)) {
                return $Group;
            }
        }

        # the item was not found
        return null;
    }

    /**
     * Get the item object of the item that is the given distance from the item.
     * @param MetadataField|MetadataFieldGroup $Item Item.
     * @param int $Offset Distance from the item, negative values are allowed.
     * @param callable $Filter Callback to filter out items.
     * @return object|null Item or NULL if not found.
     */
    protected function getSiblingItem($Item, int $Offset, ?callable $Filter = null)
    {
        $Id = $this->getItemId($Item);
        $Type = $this->getItemType($Item);
        $Sibling = null;

        # the sibling is in the order, i.e., not in a group
        if ($this->containsItem($Id, $Type)) {
            return $this->findSiblingItem($this, $Item, $Offset, $Filter);
        }

        # otherwise search for it in the groups
        foreach ($this->getGroups() as $Group) {
            if ($Group->containsItem($Id, $Type)) {
                try {
                    $Sibling = $this->findSiblingItem(
                        $Group,
                        $Item,
                        $Offset,
                        $Filter
                    );

                    if ($Sibling) {
                        return $Sibling;
                    }
                } catch (Exception $Exception) {
                    # (moving to next item just to avoid empty catch statement)
                    continue;
                }

                break;
            }
        }

        return null;
    }

    /**
     * Attempt to find the item that is the given distance from the item within
     * the given enclosure.
     * @param MetadataFieldGroup|MetadataFieldOrder $Enclosure Item enclosure.
     * @param MetadataField|MetadataFieldGroup $Item Item.
     * @param int $Offset Distance from the item, negative values are allowed.
     * @param callable $Filter Callback to filter out items.
     * @return object|null Item or NULL if not found.
     */
    protected function findSiblingItem(
        $Enclosure,
        $Item,
        int $Offset,
        ?callable $Filter = null
    ) {
        $ItemIds = $Enclosure->getItemIds();

        # filter items if necessary
        if (is_callable($Filter)) {
            $ItemIds = array_filter($ItemIds, $Filter);

            # maintain continuous indices
            ksort($ItemIds);
            $ItemIds = array_values($ItemIds);
        }

        $Id = $this->getItemId($Item);
        $Type = $this->getItemType($Item);
        $Index = array_search(["ID" => $Id, "Type" => $Type], $ItemIds);

        if (array_key_exists((int)$Index + $Offset, $ItemIds)) {
            $SiblingInfo = $ItemIds[(int)$Index + $Offset];
            return new $SiblingInfo["Type"]($SiblingInfo["ID"]);
        }

        return null;
    }

    /**
     * Move the field with the given ID to the group with the given ID,
     * optionally specifying the place where the should be placed.
     * @param MetadataFieldGroup $Group Metadata field group.
     * @param MetadataField $Field Metadata field.
     * @param string $Placement Where to place the field ("prepend" or "append").
     * @return void
     */
    protected function moveFieldToGroup(
        MetadataFieldGroup $Group,
        MetadataField $Field,
        string $Placement
    ): void {
        # determine which action to use based on the placement value
        $Action = $Placement == "prepend" ? "prependItem" : "appendItem";

        $GroupId = $this->getItemId($Group);
        $FieldId = $this->getItemId($Field);

        $OrderHasGroup = $this->containsItem($GroupId, "Metavus\\MetadataFieldGroup");
        $OrderHasField = $this->containsItem($FieldId, "Metavus\\MetadataField");

        # make sure the field and group are in the order before editing
        if ($OrderHasGroup && $OrderHasField) {
            $this->removeItem($FieldId, "Metavus\\MetadataField");
            $Group->$Action($FieldId, "Metavus\\MetadataField");
        }
    }

    /**
     * Move the field with the given ID from the group with the given ID to the
     * order, optionally specifying where the field should be placed.
     * @param MetadataFieldGroup $Group Metadata field group.
     * @param MetadataField $Field Metadata field.
     * @param string $Placement Where to place the field ("before" or "after").
     * @return void
     */
    protected function moveFieldToOrder(
        MetadataFieldGroup $Group,
        MetadataField $Field,
        string $Placement
    ): void {

        # determine which action to use based on the placement value
        $Action = $Placement == "before" ? "insertItemBefore" : "insertItemAfter";

        $GroupId = $this->getItemId($Group);
        $FieldId = $this->getItemId($Field);

        $OrderHasGroup = $this->containsItem($GroupId, "Metavus\\MetadataFieldGroup");
        $GroupHasField = $Group->containsItem($FieldId, "Metavus\\MetadataField");

        # make sure the field is in the group and the group is in the order
        if ($OrderHasGroup && $GroupHasField) {
            $Group->removeItem($FieldId, "Metavus\\MetadataField");
            $this->$Action(
                $GroupId,
                $FieldId,
                "Metavus\\MetadataFieldGroup",
                "Metavus\\MetadataField"
            );
        }
    }

    /**
     * Move all the metadata fields out of the given metadata field group and
     * into the main order.
     * @param MetadataFieldGroup $Group Metadata field group.
     * @return void
     */
    protected function moveFieldsToOrder(MetadataFieldGroup $Group): void
    {
        $ItemIds = $Group->getItemIds();
        $PreviousItemId = $Group->id();
        $PreviousItemType = "Metavus\\MetadataFieldGroup";

        foreach ($ItemIds as $ItemInfo) {
            $ItemId = $ItemInfo["ID"];
            $ItemType = $ItemInfo["Type"];

            $this->insertItemAfter(
                $PreviousItemId,
                $ItemId,
                $PreviousItemType,
                $ItemType
            );

            $PreviousItemId = $ItemId;
            $PreviousItemType = $ItemType;
        }
    }

    /**
     * Database object with which to query the database.
     */
    protected $DB;

    /**
     * The ID of the metadata schema this metadata field order is associated
     * with.
     */
    protected $SchemaId;

    /**
     * The name of the metadata field order.
     */
    protected $OrderName;
}
