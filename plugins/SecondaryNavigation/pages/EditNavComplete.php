<?PHP
#
#   FILE:  EditNavComplete.php (SecondaryNavigation plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Plugins\SecondaryNavigation\NavMenu;
use Metavus\User;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();

# retrieve user currently logged in
$User = User::getCurrentUser();

# go home if user isn't logged in
if (!$User->isLoggedIn()) {
    $AF->setJumpToPage("Home");
    return;
}

$NewOrder = StdLib::getArrayValue($_POST, "newOrder");
$NavMenu = new NavMenu($User->id());
parse_str($NewOrder, $NavMenuOrder);
$NavMenuOrder = $NavMenuOrder["NavMenuOrder"];

if (!is_array($NavMenuOrder)) {
    # we don't expect this to be true as there should be at least 2 nav items (i.e., an array)
    # for the reorder operation to be triggered
    throw new Exception("The nav items order is not an array (should be impossible).");
}

$NavMenu->reorder($NavMenuOrder);

# This page does not output any HTML
$AF->SuppressHTMLOutput();

$JsonArray = [
    "data" => [],
    "status" => [
        "state" => "OK",
        "message" => "",
        "numWarnings" => 0,
        "warnings" => []
    ]
];
print(json_encode($JsonArray));
