<?PHP
#
#   FILE:  SelectEditUserListComplete.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2003-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\User;
use ScoutLib\Database;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
* Confirm remove user(s).
*/
function ConfirmRemoveUsers()
{
    $UserRemoveArray = array();

    reset($_POST);
    foreach ($_POST as $Var => $Value) {
        if (preg_match("/userid_([0-9]+)/", $Var)) {
            if (isset($Value)) {
                $UserRemoveArray[] = intval($Value);
            }
        }
    }

    $_SESSION["UserRemoveArray"] = $UserRemoveArray;

    $GLOBALS["AF"]->SetJumpToPage("ConfirmRemoveUser");
}

/**
* Remove user(s).
*/
function RemoveUsers()
{
    $UserRemoveArray = StdLib::getArrayValue($_SESSION, "UserRemoveArray", array());

    foreach ($UserRemoveArray as $UserId) {
        # don't let a user delete his or her own account
        if ($UserId == User::getCurrentUser()->Id()) {
            continue;
        }

        $RemoveUser = new User(intval($UserId));
        $GLOBALS["AF"]->signalEvent(
            "EVENT_USER_DELETED",
            [
                "UserId" => $RemoveUser->Id(),
            ]
        );
        $RemoveUser->Delete();
    }

    unset($_SESSION["UserRemoveArray"]);
    unset($_SESSION["OkayToRemoveUsers"]);

    $GLOBALS["AF"]->SetJumpToPage("UserList");
}

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
if (!CheckAuthorization(PRIV_USERADMIN, PRIV_SYSADMIN)) {
    return;
}

# grab entry information from database
$DB = new Database();

$Submit = $_POST["Submit"];

# check for Cancel button from previous screen
if ($Submit == "Cancel") {
    $AF->SetJumpToPage("UserList");
} elseif (substr($Submit, 0, 6) == "Remove") {
    # OK to remove selected user?
    if (isset($_SESSION["OkayToRemoveUsers"])) {
        RemoveUsers();
    } else {
        # build array list of users to remove
        ConfirmRemoveUsers();
    }
}
