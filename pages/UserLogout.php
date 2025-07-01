<?PHP
#
#   FILE:  UserLogout.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();

# retrieve user currently logged in
$User = User::getCurrentUser();

# if user is currently logged in
if ($User->isLoggedIn() == true) {
    # signal user logout
    $AF->signalEvent("EVENT_USER_LOGOUT", array("UserId" => $User->id()));

    # log user out
    $User->logout();
}

# return to page where user logged out
$ReturnPage = isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : "Home";

# pass logout return address through any hooked filters via signal
$SignalResult = $AF->signalEvent(
    "EVENT_USER_LOGOUT_RETURN",
    array("ReturnPage" => $ReturnPage)
);
$ReturnPage = $SignalResult["ReturnPage"];

# set destination to return to after logout
$AF->setJumpToPage($ReturnPage);
