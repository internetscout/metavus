<?PHP
#
#   FILE:  ViewMessage.php (Mailer plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2017-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Mailer\StoredEmail;
use ScoutLib\ApplicationFramework;

# check that user should be on this page
CheckAuthorization(PRIV_COLLECTIONADMIN, PRIV_SYSADMIN);

$AF = ApplicationFramework::getInstance();
$H_Errors = [];

if (!isset($_GET["ID"]) || !isset($_GET["A"])) {
    $H_Errors[] = "Required parameters not specified.";
    return;
}

# pull out the provided action
$Action = $_GET["A"];

if (!in_array($Action, ["View", "Send", "Delete"])) {
    $H_Errors[] = "Invalid action.";
    return;
}

$ItemId = intval($_GET["ID"]);
if (!StoredEmail::ItemExists($ItemId)) {
    $H_Errors[] = "Invalid stored message Id.";
    return;
}

$H_StoredEmail = new StoredEmail($ItemId);

if (in_array($Action, ["Send", "Delete"])) {
    # perform the spec'd action
    if ($Action == "Send") {
        $H_StoredEmail->Send();
    } elseif ($Action == "Delete") {
        $H_StoredEmail->Destroy();
    }

    # and then bounce to the mail queue
    $TgtPage = "index.php?P=P_Mailer_ListQueuedEmail";
    foreach (["SS", "TID"] as $Param) {
        if (isset($_GET[$Param])) {
            $TgtPage .= "&".$Param."=".urlencode($_GET[$Param]);
        }
    }

    $AF->setJumpToPage($TgtPage);
    return;
}
