<?PHP
#
#   FILE:  ErrorLog.php (GoogleMaps plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# check that user should be on this page
use Metavus\TransportControlsUI;
use ScoutLib\Database;
use ScoutLib\StdLib;

CheckAuthorization(PRIV_SYSADMIN);

$H_SortField = StdLib::getFormValue(TransportControlsUI::PNAME_SORTFIELD);

$DB = new Database();
$DB->Query(
    "SELECT Address, ErrorData FROM GoogleMaps_GeocodeErrors "
    ."ORDER BY Address"
);

$H_Items = $DB->FetchRows();

# get starting index, set items per page
$StartIndex = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);
$H_ItemsPerPage = 50;

# set base link
$H_BaseLink = "index.php?P=P_GoogleMaps_ErrorLog";

# subset as necessary
$H_TotalItems = count($H_Items);
$H_Items = array_slice($H_Items, $StartIndex, $H_ItemsPerPage, true);
