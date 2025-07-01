<?PHP
#
#   FILE:  CleanSpamComplete.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
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
    if (User::getCurrentUser()->id() == $PosterId) {
        return;
    }

    $Poster = new User($PosterId);
    $Poster->revokePriv(PRIV_POSTCOMMENTS);
    $Poster->grantPriv(PRIV_USERDISABLED);

    $DB = new Database();
    $DB->query(
        "DELETE FROM Messages WHERE PosterId = ".$PosterId
    );
}

/**
 * Remove posting privs from a specified user.
 * @param int $PosterId UserId of the target user
 */
function RemovePostPrivilege(int $PosterId): void
{
    if (User::getCurrentUser()->id() == $PosterId) {
        return;
    }

    $Poster = new User($PosterId);
    $Poster->revokePriv(PRIV_POSTCOMMENTS);
}

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();

# check that the user has the required Privs
if (!User::requirePrivilege(PRIV_COLLECTIONADMIN, PRIV_USERADMIN)) {
    return;
}

# extract values from POST
$F_ResourceId = StdLib::getFormValue("F_ResourceId");
$F_Submit = StdLib::getFormValue("Submit");
$F_PosterId = StdLib::getFormValue("F_PosterId");

# bounce them to UnauthorizedAccess if the required POST values weren't present
if (is_null($F_ResourceId) || is_null($F_PosterId) || is_null($F_Submit)) {
    $AF->setJumpToPage("UnauthorizedAccess");
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
$AF->setJumpToPage(
    "index.php?P=TrackUserComments"
);
