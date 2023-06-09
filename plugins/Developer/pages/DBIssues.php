<?PHP
#
#   FILE:  DBIssues.php (Developer plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2019-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use ScoutLib\Database;
use ScoutLib\StdLib;

CheckAuthorization(PRIV_SYSADMIN);

$H_Action = StdLib::getFormValue("Submit");
switch ($H_Action) {
    case "Clean":
        switch (StdLib::getFormValue("F_IssueName")) {
            default:
                break;
        }
        break;
}

$H_Issues = [];
$H_StatusMsgs = [
    "No issues found."
];
