<?PHP
#
#   FILE:  ListSentEmail.php (MetricsReporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# check that user should be on this page
use Metavus\TransportControlsUI;
use ScoutLib\Database;
use ScoutLib\StdLib;
use ScoutLib\ApplicationFramework;

CheckAuthorization(PRIV_COLLECTIONADMIN, PRIV_SYSADMIN);

$DB = new Database();

# construct SQL conditions from provided search string
$H_SearchString = StdLib::getFormValue("SS", "");
$SqlConditions = [];
if (strlen($H_SearchString)) {
    $Vars = ["FromAddr", "ToAddr", "Subject" ];
    foreach ($Vars as $Var) {
        $SqlConditions[] = $Var.' LIKE "%'.addslashes($H_SearchString).'%"';
    }
}

# if Mailer is enabled, set up UI stuff for selecting a template
if ($GLOBALS["G_PluginManager"]->PluginEnabled("Mailer")) {
    $Mailer = $GLOBALS["G_PluginManager"]->GetPlugin("Mailer");

    $H_SelectedTemplate = StdLib::getFormValue("TID", -1);
    $H_Templates = [-1 => "(all)"];
    $H_Templates += $Mailer->GetTemplateList();
}

# get starting index, set items per page
$StartIndex = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);
$H_ItemsPerPage = 50;

# extract sort field
$SortField = StdLib::getFormValue(TransportControlsUI::PNAME_SORTFIELD, "DateSent");

# die if it was invalid
if (!in_array($SortField, ["Subject", "FromAddr", "ToAddr", "DateSent"])) {
    throw new Exception("Invalid sort field");
}

# determine sort direction
$SortDir = StdLib::getFormValue(TransportControlsUI::PNAME_REVERSESORT, 0) == 1 ?
    "DESC" : "ASC" ;

$DB->Query(
    "SELECT FromAddr, ToAddr, Subject, LogData, DateSent FROM MetricsRecorder_SentEmails"
    .(count($SqlConditions) ? " WHERE ".implode(" OR ", $SqlConditions) : "")
    ." ORDER BY ".$SortField." ".$SortDir
);
$H_EmailList = $DB->FetchRows();

# if we were supposed to subset the list based on a specific template
if ($GLOBALS["G_PluginManager"]->PluginEnabled("Mailer") &&
    $H_SelectedTemplate != -1) {
    $NewList = [];
    foreach ($H_EmailList as $Email) {
        # if no additional data was recorded, then this can't have
        # come from the selected template
        if (strlen($Email["LogData"]) == 0) {
            continue;
        }

        # otherwise, pull out the additional data and check the
        # template
        $LogData = unserialize($Email["LogData"]);
        if ($LogData["TemplateId"] != $H_SelectedTemplate) {
            continue;
        }

        # include messages from our desired template
        $NewList[] = $Email;
    }

    $H_EmailList = $NewList;
}

# subset as necessary
$H_TotalItems = count($H_EmailList);
$H_EmailList = array_slice($H_EmailList, $StartIndex, $H_ItemsPerPage, true);

$H_BaseLink = "index.php?P=P_MetricsReporter_ListSentEmail"
    ."&amp;SS=".urlencode($H_SearchString);

if ($GLOBALS["G_PluginManager"]->PluginEnabled("Mailer")) {
    $H_BaseLink .= "&amp;TID=".urlencode($H_SelectedTemplate);
}
