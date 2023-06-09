<?PHP
#
#   FILE:  EditNavComplete.php (SecondaryNavigation plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\SecondaryNavigation\NavMenu;
use Metavus\User;
use ScoutLib\StdLib;

# retrieve user currently logged in
$User = User::getCurrentUser();

# go home if user isn't logged in
if (!$User->isLoggedIn()) {
    $GLOBALS["AF"]->setJumpToPage("Home");
    return;
}

$NewOrder = StdLib::getArrayValue($_POST, "newOrder");
$NavMenu = new NavMenu($User->id());
parse_str($NewOrder, $NavMenuOrder);
$NavMenu->reorder($NavMenuOrder["NavMenuOrder"]);

# This page does not output any HTML
$GLOBALS["AF"]->SuppressHTMLOutput();

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
