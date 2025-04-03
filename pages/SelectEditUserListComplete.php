<?PHP
#
#   FILE:  SelectEditUserListComplete.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2003-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Confirm remove user(s).
*/
function ConfirmRemoveUsers(): void
{
    $UserRemoveArray = [];

    foreach ($_POST as $Var => $Value) {
        if (preg_match("/userid_([0-9]+)/", $Var)) {
            if (isset($Value)) {
                $UserRemoveArray[] = intval($Value);
            }
        }
    }

    $_SESSION["UserRemoveArray"] = $UserRemoveArray;

    ApplicationFramework::getInstance()->setJumpToPage("ConfirmRemoveUser");
}

/**
* Remove user(s).
*/
function RemoveUsers(): void
{
    $AF = ApplicationFramework::getInstance();
    $UserRemoveArray = StdLib::getArrayValue($_SESSION, "UserRemoveArray", []);

    foreach ($UserRemoveArray as $UserId) {
        # don't let a user delete his or her own account
        if ($UserId == User::getCurrentUser()->id()) {
            continue;
        }

        $RemoveUser = new User(intval($UserId));
        $AF->signalEvent(
            "EVENT_USER_DELETED",
            [
                "UserId" => $RemoveUser->id(),
            ]
        );
        $RemoveUser->delete();
    }

    unset($_SESSION["UserRemoveArray"]);
    unset($_SESSION["OkayToRemoveUsers"]);

    $AF->setJumpToPage("UserList");
}

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
if (!CheckAuthorization(PRIV_USERADMIN, PRIV_SYSADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();

$Submit = $_POST["Submit"];

# check for Cancel button from previous screen
if ($Submit == "Cancel") {
    $AF->setJumpToPage("UserList");
} elseif (substr($Submit, 0, 6) == "Remove") {
    # OK to remove selected user?
    if (isset($_SESSION["OkayToRemoveUsers"])) {
        RemoveUsers();
    } else {
        # build array list of users to remove
        ConfirmRemoveUsers();
    }
}
