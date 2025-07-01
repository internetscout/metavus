<?PHP
#
#   FILE:  EditPrivilegeNamesComplete.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\User;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

if (!User::requirePrivilege(PRIV_SYSADMIN)) {
    return;
}

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Privilege Editing Complete");

# Check if the privileges have already been updated
if (isset($_POST["AlreadyLoaded"])) {
    # Go to all privileges  (needs the "?ID=" portion, fixes refresh bug)
    $AF->setJumpToPage("EditPrivilegeNames&ID=");
    return;
}

$_POST["AlreadyLoaded"] = "TRUE";
