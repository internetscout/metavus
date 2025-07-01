<?PHP
#
#   FILE:  ErrorLog.php (GoogleMaps plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\GoogleMaps;
use Metavus\TransportControlsUI;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

# check that user should be on this page
User::requirePrivilege(PRIV_SYSADMIN);

# set base link
$H_BaseLink = "index.php?P=P_GoogleMaps_ErrorLog";


if (isset($_GET["A"])) {
    switch ($_GET["A"]) {
        case "DELETE":
            if (!isset($_GET["ID"])) {
                $H_Error = "Required ID parameter not provided.";
                return;
            }

            GoogleMaps::getInstance()
                ->deleteAllDataForId(
                    $_GET["ID"]
                );

            ApplicationFramework::getInstance()
                ->setJumpToPage($H_BaseLink);
            return;

        default:
            $H_Error = "Unsupported action provided.";
            return;
    }
}

$H_SortField = StdLib::getFormValue(TransportControlsUI::PNAME_SORTFIELD);

$DB = new Database();
$DB->query(
    "SELECT * FROM GoogleMaps_Geocodes WHERE ErrorCount > 0"
    ." ORDER BY Address"
);

$H_Items = $DB->fetchRows();

# get starting index, set items per page
$StartIndex = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);
$H_ItemsPerPage = 50;

# subset as necessary
$H_TotalItems = count($H_Items);
$H_Items = array_slice($H_Items, $StartIndex, $H_ItemsPerPage, true);
