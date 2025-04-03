<?PHP
#
#   FILE:  UserList.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\TransportControlsUI;
use Metavus\User;
use ScoutLib\Database;
use ScoutLib\Date;
use ScoutLib\StdLib;

# make sure user has needed privileges for user editing

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

$H_ItemsPerPage = 25;
$H_TransportUI = new TransportControlsUI();
$H_TransportUI->itemsPerPage($H_ItemsPerPage);

# retrieve current offset into list
$H_StartingIndex = $H_TransportUI->startingIndex();

# determine result ordering SQL clause
$G_SortField = StdLib::getFormValue("SF", "UserName");
$G_SortAscending = StdLib::getFormValue("SA", 1);
$SortClause = " ORDER BY `".addslashes($G_SortField)."` "
        .($G_SortAscending ? "ASC" : "DESC")
        .", UserId "
        .($G_SortAscending ? "ASC" : "DESC");

# load IDs of users with specified privilege
$DB = new Database();
$Privilege = (isset($_POST["F_Privilege"]) && ($_POST["F_Privilege"] != -1))
        ? $_POST["F_Privilege"]
        : ((isset($_GET["F_Privilege"]) && ($_GET["F_Privilege"] != -1))
        ? $_GET["F_Privilege"]
        : null);
if ($Privilege !== null) {
    $Query = "SELECT DISTINCT AU.UserId, AU.`".addslashes($G_SortField)."`"
            ." FROM APUsers AS AU, APUserPrivileges AS AP"
            ." WHERE AP.Privilege = '".addslashes($Privilege)."'"
            ." AND AU.UserId = AP.UserId";
} else {
    $Query = "SELECT DISTINCT UserId, `".addslashes($G_SortField)."`"
        ." FROM APUsers";
}
$DB->Query($Query.$SortClause);
$PrivUserIds = $DB->FetchColumn("UserId");
$H_ExtraParams = (!is_null($Privilege) && strlen($Privilege)) ?
    "&F_Privilege=".$Privilege : "";
$H_ExtraParams .= "&SA=".$G_SortAscending;
# load IDs of users that meet specified search criteria
if (StdLib::getFormValue("F_Field") && StdLib::getFormValue("F_Condition")
        && strlen(StdLib::getFormValue("F_SearchText"))) {
    $ConditionMap = array(
        "contains" => "contains",
        "equals" => "=",
        "is before" => "<",
        "is after" => ">",
    );
    $Condition = $ConditionMap[StdLib::getFormValue("F_Condition")];
    $H_ExtraParams .= "&F_Condition=".$Condition;
    $SearchText = StdLib::getFormValue("F_SearchText");
    $H_ExtraParams .= "&F_SearchText=".$SearchText;
    if ($Condition == "contains") {
        $Target = "LIKE '%".addslashes($SearchText)."%'";
    } else {
        $Target = $Condition." '".addslashes($SearchText)."'";
    }
    $H_ExtraParams .= "&F_Field=".StdLib::getFormValue("F_Field");
    if (StdLib::getFormValue("F_Field") == "ALL") {
        $AllFields = array(
            "UserName",
            "EMail",
            "RealName",
            "AddressLineOne",
            "AddressLineTwo",
            "State",
            "ZipCode",
            "Country",
            "LastLoginDate",
            "CreationDate",
        );
        foreach ($AllFields as $Field) {
            if (($Condition == "contains") && preg_match("/Date/", $Field)) {
                $Field = "DATE_FORMAT(".$Field.", '%M %D %Y %l:%i%p')";
            }
            if (isset($WhereClause)) {
                $WhereClause .= " OR ".$Field." ".$Target;
            } else {
                $WhereClause = " ".$Field." ".$Target;
            }
        }
    } elseif (StdLib::getFormValue("F_Field") == "Address") {
        $WhereClause = " AddressLineOne ".$Target." OR AddressLineTwo ".$Target;
    } elseif ((StdLib::getFormValue("F_Field") == "LastLoginDate")
            || (StdLib::getFormValue("F_Field") == "CreationDate")) {
        if ($Condition == "contains") {
            $WhereClause = "DATE_FORMAT(`".addslashes(StdLib::getFormValue("F_Field"))
                    ."`, '%M %D %Y %l:%i%p') ".$Target;
        } else {
            $SearchDate = new Date($SearchText);
            if (strlen($SearchDate->Formatted())) {
                $WhereClause = $SearchDate->SqlCondition(
                    StdLib::getFormValue("F_Field"),
                    null,
                    $Condition
                );
            } else {
                $WhereClause = "1 = 0";
            }
        }
    } else {
        $WhereClause = "`".addslashes(StdLib::getFormValue("F_Field"))."` ".$Target;
    }
    $DB->Query("SELECT UserId FROM APUsers WHERE ".$WhereClause.$SortClause);
    $SearchUserIds = $DB->FetchColumn("UserId");
} else {
    $SearchUserIds = $PrivUserIds;
}

# combine user ID lists to those that met all criteria
$UserIds = array_intersect($PrivUserIds, $SearchUserIds);

# calculate user ID list checksum to use to know when to reset paging
$G_UserIdChecksum = md5(serialize($UserIds));
if ($G_UserIdChecksum != StdLib::getFormValue("F_UserIdChecksum")) {
    $H_StartingIndex = 0;
}
$H_ExtraParams .= "&F_UserIdChecksum=".$G_UserIdChecksum;

# pare list of user IDs down to segment to be displayed
$G_TotalItems = count($UserIds);
$H_TransportUI->itemCount($G_TotalItems);
$UserIds = array_slice($UserIds, $H_StartingIndex, $H_ItemsPerPage);

# load users
$G_Users = array();
foreach ($UserIds as $Id) {
    $G_Users[$Id] = new User($Id);
}

# if ConfirmRemoveUser page was accessed previously but then user clicked
#       elseswhere instead of continuing the removal, these won't be cleared
foreach (array("UserRemoveArray", "OkayToRemoveUsers") as $Val) {
    if (isset($_SESSION[$Val])) {
        unset($_SESSION[$Val]);
    }
}
