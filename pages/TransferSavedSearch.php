<?PHP
#
#   FILE:  TransferSavedSearch.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\SavedSearch;
use ScoutLib\UserFactory;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

if (!CheckAuthorization(PRIV_USERADMIN)) {
    return;
}

if (isset($_GET["ID"])) {
    $H_SearchId = $_GET["ID"];
} elseif (isset($_POST["ID"])) {
    $H_SearchId = $_POST["ID"];
} else {
    $H_Error = "No Search ID provided for transfer";
    return;
}

$H_OriginalSearch = new SavedSearch($H_SearchId);

$H_MailingsEnabled = $GLOBALS["G_PluginManager"]->pluginEnabled(
    "SavedSearchMailings"
);

$UFactory = new UserFactory();
if (isset($_POST["Submit"]) && $_POST["Submit"] == "Transfer") {
    # get all selected users from POST parameters
    foreach ($_POST["F_TargetUser"] as $Value) {
        if (isset($Value) && strlen($Value) > 0
                && is_numeric($Value) && $UFactory->userExists(intval($Value))) {
            $UserId = intval($Value);

            # call constructor with null Search ID to add new search to db
            new SavedSearch(
                null,
                $_POST["F_SearchName"],
                $UserId,
                $H_MailingsEnabled ? $_POST["F_Email"] : null,
                $H_OriginalSearch->searchParameters()
            );
        }
    }
    # after transferring to all targets, delete from original user
    $H_OriginalSearch->delete();

    $AF = ApplicationFramework::getInstance();
    $AF->setJumpToPage(
        "ListSavedSearches"
    );
}
