<?PHP
#
#   FILE:  UserLogout.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\User;

# retrieve user currently logged in
$User = User::getCurrentUser();

# if user is currently logged in
if ($User->IsLoggedIn() == true) {
    # signal user logout
    $AF->SignalEvent("EVENT_USER_LOGOUT", array("UserId" => $User->Id()));

    # log user out
    $User->Logout();
}

# return to page where user logged out
$ReturnPage = isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : "Home";

# pass logout return address through any hooked filters via signal
$SignalResult = $AF->SignalEvent(
    "EVENT_USER_LOGOUT_RETURN",
    array("ReturnPage" => $ReturnPage)
);
$ReturnPage = $SignalResult["ReturnPage"];

# set destination to return to after logout
$AF->SetJumpToPage($ReturnPage);
