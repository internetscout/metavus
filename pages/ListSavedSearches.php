<?PHP
#
#   FILE:  ListSavedSearches.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\SavedSearch;
use Metavus\SavedSearchFactory;
use Metavus\TransportControlsUI;
use Metavus\User;
use ScoutLib\StdLib;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------

if (!CheckAuthorization()) {
    return;
}

# retrieve list of Saved Searches reversed to be in order from most recent to least recent
$SSFactory = new SavedSearchFactory();
$H_SavedSearches = array_reverse($SSFactory->GetSearchesForUser(User::getCurrentUser()->Id()), true);

# get total count of Saved Search Ids
$H_SearchCount = count($H_SavedSearches);

# get current Saved Search index, using "Index" if passed from edit or delete, otherwise standard TCUI
$H_StartingIndex = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);

# set items per page
$H_ItemsPerPage = 25;

# check if starting index is inbounds, go to last page if out of bounds
# $H_StartingIndex will be negative $H_ItemsPerPage when the array is empty, does not cause issues so not worth checking
if ($H_StartingIndex >= $H_SearchCount) {
    $ExtraItems = $H_SearchCount % $H_ItemsPerPage;
    $ItemsOnLastPage = ($ExtraItems == 0) ? $H_ItemsPerPage : $ExtraItems;
    $H_StartingIndex = max(0, $H_SearchCount - $ItemsOnLastPage);
}

# prune search Ids down to currently selected segement
$H_SavedSearches = array_slice($H_SavedSearches, $H_StartingIndex, $H_ItemsPerPage, true);

# if user asked to delete search
if (isset($_GET["AC"]) && ($_GET["AC"] == "Delete")) {
    # delete specified search
    $Search = new SavedSearch($_GET["ID"]);
    $Search->Delete();

    if (isset($_SERVER["HTTP_REFERER"]) &&
        strpos($_SERVER["HTTP_REFERER"], "EditUser")) {
        $GLOBALS["AF"]->setJumpToPage(
            $_SERVER["HTTP_REFERER"]
        );
    } else {
        $GLOBALS["AF"]->SetJumpToPage(
            "index.php?P=ListSavedSearches&"
            .TransportControlsUI::PNAME_STARTINGINDEX."=".$H_StartingIndex
        );
    }
}
