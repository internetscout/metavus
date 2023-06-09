<?PHP
#
#   FILE:  NavMenu.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\SecondaryNavigation;

use ScoutLib\ItemFactory;

/**
 * class used to order and retrieve NavItems for a user
 */
class NavMenu extends ItemFactory
{
    /**
     * Class constructor.
     * @param int $OwnerId ID of owner to load Secondary Navigation Menu for.
     */
    public function __construct(int $OwnerId)
    {
        # save owner id
        $this->OwnerId = $OwnerId;

        # set up item factory base class
        parent::__construct(
            "Metavus\\Plugins\\SecondaryNavigation\\NavItem",
            "SecondaryNavigation_NavItems",
            "NavItemId",
            null,
            true,
            "OwnerId = ".intval($this->OwnerId)
        );
    }

    /**
     * Return owner ID for this menu
     * @return int ID of User that owns this NavMenu
     */
    public function ownerId()
    {
        return $this->OwnerId;
    }

    /**
     * Reorder the NavMenu according to the passed array
     * @param array $NavOrder array of NavItem IDs
     */
    public function reorder($NavOrder)
    {
        $Previous = null;
        foreach ($NavOrder as $ItemId => $Parent) {
            $Item = new NavItem($ItemId);
            if ($Previous == null) {
                $this->prepend($Item);
            } else {
                $this->insertAfter($Previous, $Item);
            }
            $Previous = $Item;
        }
    }

    /**
     * Check whether or not the given user already has a NavMenu
     * @param int $OwnerId ID of owner to check for
     * @return bool true if there is already a NavMenu for the user, false otherwise
     */
    public static function userNavMenuExists($OwnerId)
    {
        $NavMenu = new NavMenu($OwnerId);

        if ($NavMenu->getItemCount() == 0) {
            return false;
        }
        return true;
    }

    /**
     * Check whether a NavItem with the given link already exists
     * @param string $Link link to check for
     * @return bool true if NavItem with link exists, false otherwise
     */
    public function navItemExists($Link)
    {
        $Items = $this->getItems();
        foreach ($Items as $NavItem) {
            if ($NavItem->link() == $Link) {
                return true;
            }
        }
        return false;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $OwnerId;
}
