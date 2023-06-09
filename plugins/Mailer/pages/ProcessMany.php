<?PHP
#
#   FILE:  ProcessMany.php (Mailer plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Mailer\StoredEmail;
use ScoutLib\ApplicationFramework;

# check that user should be on this page
CheckAuthorization(PRIV_COLLECTIONADMIN, PRIV_SYSADMIN);

$H_Errors = [];

# make sure MessageIds and Action were provided
if (!isset($_GET["IDs"]) || !isset($_GET["A"])) {
    $H_Errors[] = "Required parameters not specified.";
    return;
}

$Action = $_GET["A"];

# make sure that action was valid
if (!in_array($Action, ["Send", "Destroy"])) {
    $H_Errors[] = "Specified action is not valid.";
    return;
}

# extract MessageIds, check them all for validity
$IDs = explode("-", $_GET["IDs"]);
foreach ($IDs as $Id) {
    if (!StoredEmail::ItemExists($Id)) {
        $H_Errors[] = "Invalid stored message Id: ".$Id.".";
    }
}

# if any Ids were invalid, bail
if (count($H_Errors)) {
    return;
}

# otherwise, iterate over all the provided IDs and send them all
foreach ($IDs as $Id) {
    $StoredEmail = new StoredEmail($Id);
    $StoredEmail->$Action();
}

# and then bounce to the mail queue
$TgtPage = "index.php?P=P_Mailer_ListQueuedEmail";
foreach (["SS", "TID"] as $Param) {
    if (isset($_GET[$Param])) {
        $TgtPage .= "&".$Param."=".urlencode($_GET[$Param]);
    }
}
ApplicationFramework::getInstance()->setJumpToPage($TgtPage);
