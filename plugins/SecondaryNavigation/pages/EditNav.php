<?PHP
#
#   FILE:  EditNav.php (SecondaryNavigation plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2020-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Metavus\Plugins\SecondaryNavigation;
use Metavus\Plugins\SecondaryNavigation\NavItem;
use Metavus\Plugins\SecondaryNavigation\NavMenu;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

# ---------- EXPORTED FUNCTIONS ----------------------------------

/**
 * Get Tree with structure to display NavItems (Should be depth = 1 since you can't nest)
 * @param NavMenu $NavMenu containing NavItems to display
 * @return array representing structure in which to display the NavItems
 */
function getTree(NavMenu $NavMenu): array
{
    $ItemIds = $NavMenu->getItemIdsInOrder();
    $Tree = [];
    foreach ($ItemIds as $Index => $ItemId) {
        $NavItem = new NavItem($ItemId);
        $Tree[$Index]["Id"] = $ItemId;
        $Tree[$Index]["Label"] = htmlspecialchars($NavItem->label());
        $Tree[$Index]["Link"] = $NavItem->link();
    }
    return $Tree;
}

# --------- MAIN ------------------------------------------------

# retrieve user currently logged in
$User = User::getCurrentUser();

# go home if user isn't logged in
if (!$User->isLoggedIn()) {
    $AF = ApplicationFramework::getInstance();
    $AF->setJumpToPage("Home");
    return;
}
$H_NavMenu = new NavMenu($User->id());
$H_Errors = [];
$H_ButtonPushed = StdLib::getFormValue("Submit", "");
$SecondaryNav = SecondaryNavigation::getInstance();
$H_OfferedItems = $SecondaryNav->getOfferedNavItems();

# get SecondaryNavigation plugin
$SecondaryNavPlugin = SecondaryNavigation::getInstance();

$H_OfferedItems = $SecondaryNavPlugin->getOfferedNavItems();
# remove any items that are already present/user doesn't have privs for
foreach ($H_OfferedItems as $Link => $Item) {
    if ($H_NavMenu->navItemExists($Link) || !$Item["Privs"]->meetsRequirements($User)) {
        unset($H_OfferedItems[$Link]);
    }
}

if ($H_ButtonPushed == "Add Item") {
    # get values from the user
    $NewLabel = StdLib::getFormValue("F_Label");
    $NewLink = StdLib::getFormValue("F_Link");

    # check for errors and return if link/label are invalid
    $LabelValidation = SecondaryNavigation::validateLabel(null, $NewLabel);
    if (!is_null($LabelValidation)) {
        $H_Errors[] = $LabelValidation;
    }
    if (strlen(trim($NewLabel)) == 0) {
        $H_Errors[] = "New label must not be empty.";
    }
    if (strlen($NewLink) == 0 || !SecondaryNavigation::urlLooksValid($NewLink)) {
        $H_Errors[] = "New link must be a valid URL.";
    }
    if (count($H_Errors)) {
        return;
    }

    # create new NavItem, append to order
    $NavItem = NavItem::create($User->id(), $NewLabel, $NewLink);
    $H_NavMenu->append($NavItem);
} elseif (preg_match("/DeleteItem/", $H_ButtonPushed)) {
    # get ID from button name, index 0 contains "DeleteItem"
    $ToDelete = (explode(",", $H_ButtonPushed))[1];
    # check to avoid issues on refreshes
    if ($H_NavMenu->itemExists(intval($ToDelete))) {
        $H_NavMenu->removeItemFromOrder(intval($ToDelete));
        (new NavItem($ToDelete))->destroy();
    }
    # refresh offered items in case user deleted an offered item
    $H_OfferedItems = $SecondaryNavPlugin->getOfferedNavItems();
    # remove any items that are already present/user doesn't have privs for
    foreach ($H_OfferedItems as $Link => $Item) {
        if ($H_NavMenu->navItemExists($Link) || !$Item["Privs"]->meetsRequirements($User)) {
            unset($H_OfferedItems[$Link]);
        }
    }
} elseif (preg_match("/AddOffered/", $H_ButtonPushed)) {
    # get link from button name, index 0 contains "AddOffered"
    $AddLink = (explode(",", $H_ButtonPushed))[1];
    # in case of refresh
    if (isset($H_OfferedItems[$AddLink])) {
        $NavItem = NavItem::create(
            $User->id(),
            $H_OfferedItems[$AddLink]["Label"],
            $AddLink,
            false
        );
        $H_NavMenu->append($NavItem);
        unset($H_OfferedItems[$AddLink]);
    }
}
