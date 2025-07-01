<?PHP
#
#   FILE:  ListQueuedEmail.php (Mailer plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

# check that user should be on this page
use Metavus\Plugins\Mailer;
use Metavus\TransportControlsUI;
use Metavus\User;
use ScoutLib\Database;
use ScoutLib\StdLib;

User::requirePrivilege(PRIV_COLLECTIONADMIN, PRIV_SYSADMIN);

# extract parameters from Url
$H_SearchString = StdLib::getFormValue("SS", "");
$H_SelectedTemplate = StdLib::getFormValue("TID", -1);

$MailerPlugin = Mailer::getInstance();

$DB = new Database();

# start building up a WHERE clause
$WhereClause = "";

# add in SQL conditions for the given search
if (strlen($H_SearchString)) {
    $Vars = ["FromAddr", "ToAddr", "Mailer_StoredEmailName" ];
    $Conditions = [];
    foreach ($Vars as $Var) {
        $Conditions[] = $Var.' LIKE "%'.addslashes($H_SearchString).'%"';
    }
    $WhereClause .= "(".implode(" OR ", $Conditions).")";
}

# add in SQL conditions for the template
if ($H_SelectedTemplate >= 0) {
    if (strlen($WhereClause)) {
        $WhereClause .= " AND ";
    }
    $WhereClause .= "TemplateId = ".intval($H_SelectedTemplate);
}

# prepend the WHERE if we have any conditions
if (strlen($WhereClause)) {
    $WhereClause = " WHERE ".$WhereClause;
}

# get starting index, set items per page
$StartIndex = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);
$H_ItemsPerPage = 50;

# build the list of template
$H_Templates = [-1 => "(all)"];
$H_Templates += $MailerPlugin->getTemplateList();

# extract sort field
$SortField = StdLib::getFormValue(TransportControlsUI::PNAME_SORTFIELD, "DateCreated");
if ($SortField == "Subject") {
    $SortField = "Mailer_StoredEmailName";
}

# die if specified sort field was not valid
if (!in_array($SortField, ["Subject", "FromAddr", "ToAddr",
    "NumResources", "DateCreated"
])) {
    throw new Exception("Invalid sort field");
}

# determine sort direction
$SortDir = StdLib::getFormValue(TransportControlsUI::PNAME_REVERSESORT, 0) == 1 ?
    "DESC" : "ASC";

# extract matching messages
$DB->query(
    "SELECT Mailer_StoredEmailId as ItemId, Mailer_StoredEmailName as Subject, "
    ."FromAddr, ToAddr, TemplateId, NumResources, DateCreated FROM Mailer_StoredEmails"
    .$WhereClause." ORDER BY ".$SortField." ".$SortDir
);

$H_EmailList = [];
while ($Row = $DB->fetchRow()) {
    $H_EmailList[$Row["ItemId"]] = $Row;
}

$H_TotalItems = count($H_EmailList);

# subset as necessary
$H_EmailList = array_slice($H_EmailList, $StartIndex, $H_ItemsPerPage, true);

# provide base link for pagination
$H_BaseLink = "index.php?P=P_Mailer_ListQueuedEmail"
    ."&amp;SS=".urlencode($H_SearchString)
    ."&amp;TID=".urlencode($H_SelectedTemplate);
