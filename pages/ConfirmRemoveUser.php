<?PHP
#
#   FILE:  ConfirmRemoveUser.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------

use ScoutLib\StdLib;
use Metavus\User;

PageTitle("Confirm Remove User");

# check if current user is authorized
CheckAuthorization(PRIV_USERADMIN, PRIV_SYSADMIN);

# get the list of users to remove
$H_UsersToRemove = StdLib::getArrayValue($_SESSION, "UserRemoveArray", []);

# check if the user is trying to remove his or her own account
$OwnIdKey = array_search(User::getCurrentUser()->Id(), $H_UsersToRemove);
$H_TriedRemovingOwnAccount = false;

# check whether any of the users should not be removed
$H_UserInUseBy = [];
foreach ($H_UsersToRemove as $Index => $UserId) {
    $SignalResult = $AF->signalEvent(
        "EVENT_PRE_USER_DELETE",
        [
            "UserId" => $UserId,
            "Reasons" => [],
        ]
    );
    if (count($SignalResult["Reasons"])) {
        $H_UserInUseBy[$UserId] = $SignalResult["Reasons"];
        unset($H_UsersToRemove[$Index]);
    }
}

# the user tried to remove his or her own account, so don't allow it
if ($OwnIdKey !== false) {
    $H_TriedRemovingOwnAccount = true;
    unset($H_UsersToRemove[$OwnIdKey]);
}

# pass along the values to the removal page
$_SESSION["OkayToRemoveUsers"] = 1;
$_SESSION["UserRemoveArray"] = $H_UsersToRemove ;
