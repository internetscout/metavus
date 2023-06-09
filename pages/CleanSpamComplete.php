<?PHP
#
#   FILE:  CleanSpamComplete.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\Database;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Removal all messages by a specified user, remove their post privs,
 * and disable their account.
 * @param int $PosterId UserId of the target user
 */
function SlayUser(int $PosterId): void
{
    # refuse to slay oneself
    if (User::getCurrentUser()->Id() == $PosterId) {
        return;
    }

    $Poster = new User($PosterId);
    $Poster->RevokePriv(PRIV_POSTCOMMENTS);
    $Poster->GrantPriv(PRIV_USERDISABLED);

    $DB = new Database();
    $DB->Query(
        "DELETE FROM Messages WHERE PosterId = ".$PosterId
    );
}

/**
 * Remove posting privs from a specified user.
 * @param int $PosterId UserId of the target user
 */
function RemovePostPrivilege(int $PosterId): void
{
    if (User::getCurrentUser()->Id() == $PosterId) {
        return;
    }

    $Poster = new User($PosterId);
    $Poster->RevokePriv(PRIV_POSTCOMMENTS);
}

# ----- MAIN -----------------------------------------------------------------

# check that the user has the required Privs
if (!CheckAuthorization(PRIV_COLLECTIONADMIN, PRIV_USERADMIN)) {
    return;
}

# extract values from POST
$F_ResourceId = StdLib::getFormValue("F_ResourceId");
$F_Submit = StdLib::getFormValue("F_Submit");
$F_PosterId = StdLib::getFormValue("F_PosterId");

# bounce them to UnauthorizedAccess if the required POST values weren't present
if (is_null($F_ResourceId) || is_null($F_PosterId) || is_null($F_Submit)) {
    $GLOBALS["AF"]->SetJumpToPage("UnauthorizedAccess");
    return;
}

# check to make sure user ID is valid
if ((new UserFactory())->userExists($F_PosterId) == false) {
    throw new Exception("User with supplied ID (".$F_PosterId.") does not exist.");
}

# Take action based on the button that was clicked:
if ($F_Submit == "Clean Spam") {
    SlayUser($F_PosterId);
} elseif ($F_Submit == "Remove Post Privilege") {
    RemovePostPrivilege($F_PosterId);
}

# and then redirect back to the Resource
$GLOBALS["AF"]->SetJumpToPage(
    "index.php?P=TrackUserComments"
);
