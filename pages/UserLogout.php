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
use ScoutLib\StdLib;

$AF = ApplicationFramework::getInstance();

# a list of pages that user should not be returned to on logout
$DoNotReturnTo = [
    "404", "ActivateAccount", "Login", "LoginError", "RequestAccount",
    "ResendAccountActivation", "ResetPassword", "UserLogin"
];

# retrieve user currently logged in
$User = User::getCurrentUser();

# if user is currently logged in
if ($User->isLoggedIn() == true) {
    # signal user logout
    $AF->signalEvent("EVENT_USER_LOGOUT", array("UserId" => $User->id()));

    # log user out
    $User->logout();
}

# return to page where user logged out if it's not in blacklist; otherwise home
$ReturnPage = "Home";
if (isset($_SERVER["HTTP_REFERER"])
        && strpos($_SERVER["HTTP_REFERER"], ApplicationFramework::baseUrl()) === 0) {
    $Referer = $AF->getUncleanRelativeUrlWithParamsForPath($_SERVER["HTTP_REFERER"]);
    $Page = StdLib::getQueryParamFromUrl("P", $Referer);
    if (!in_array($Page, $DoNotReturnTo)) {
        $ReturnPage = $_SERVER["HTTP_REFERER"];
    }
}

# pass logout return address through any hooked filters via signal
$SignalResult = $AF->signalEvent(
    "EVENT_USER_LOGOUT_RETURN",
    array("ReturnPage" => $ReturnPage)
);
$ReturnPage = $SignalResult["ReturnPage"];

# set destination to return to after logout
$AF->setJumpToPage($ReturnPage);
