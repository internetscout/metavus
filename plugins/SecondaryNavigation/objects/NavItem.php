<?PHP
#
#   FILE:  NavItem.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\SecondaryNavigation;

use ScoutLib\Item;

/**
 * Class used to store link/label pairs for a user's secondary navigation menu
 */
class NavItem extends Item
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Create a new NavItem in the database
     * @param int $OwnerId ID of User owner of this item
     * @param string $Label text to be displayed for this item
     * @param string $Link where this item directs to
     * @param bool $CreatedByUser if the link was site generated or created by user
     */
    public static function create(
        int $OwnerId,
        string $Label,
        string $Link,
        bool $CreatedByUser = true
    ): NavItem {
        # create a new NavItem
        return static::createWithValues(
            [
                "OwnerId" => $OwnerId,
                "Label" => $Label,
                "Link" => $Link,
                "CreatedByUser" => $CreatedByUser,
            ]
        );
    }

    /**
     * Set the database access values (table name, ID column name, name column
     * name) for specified class.  This may be overridden in a child class, if
     * different values are needed.
     * @param string $ClassName Class to set values for.
     */
    protected static function setDatabaseAccessValues(string $ClassName)
    {
        if (!isset(self::$ItemIdColumnNames[$ClassName])) {
            self::$ItemIdColumnNames[$ClassName] = "NavItemId";
            self::$ItemNameColumnNames[$ClassName] = null;
            self::$ItemTableNames[$ClassName] = "SecondaryNavigation_NavItems";
        }
    }

    /**
     * Gets or sets the link on the NavItem.
     * @param string $NewValue The new link on the NavItem.  (OPTIONAL)
     * @return string The link on the NavItem.
     */
    public function link(string $NewValue = null): string
    {
        return $this->DB->updateValue("Link", $NewValue);
    }

    /**
     * Gets or sets the label on the NavItem.
     * @param string $NewValue The new label on the NavItem.  (OPTIONAL)
     * @return string The label on the NavItem.
     */
    public function label(string $NewValue = null): string
    {
        return $this->DB->updateValue("Label", $NewValue);
    }

    /**
     * Gets or sets the owner ID on the NavItem.
     * @param int $NewValue The new owner ID on the NavItem.  (OPTIONAL)
     * @return int The owner ID on the NavItem.
     */
    public function ownerId(int $NewValue = null): int
    {
        return $this->DB->updateValue("OwnerId", $NewValue);
    }

    /**
     * Gets or sets the CreatedByUser status of the NavItem (whether or not the link is editable).
     * @param bool|null $NewValue The new CreatedByUser status of the NavItem.  (OPTIONAL)
     * @return bool one if the link is editable, zero if it is not.
     */
    public function createdByUser(bool $NewValue = null): bool
    {
        return $this->DB->updateValue("CreatedByUser", $NewValue);
    }
}
