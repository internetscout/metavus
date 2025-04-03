<?PHP
#
#   FILE:  EditPrivilegeNamesComplete.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use ScoutLib\ApplicationFramework;

if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

# ----- CONFIGURATION  -------------------------------------------------------

# ----- EXPORTED FUNCTIONS ---------------------------------------------------
# (functions intended for use in corresponding HTML file)

# ----- LOCAL FUNCTIONS ------------------------------------------------------
# (functions intended for use only within this file)

# ----- MAIN -----------------------------------------------------------------

PageTitle("Privilege Editing Complete");

# Check if the privileges have already been updated
if (isset($_POST["AlreadyLoaded"])) {
    # check if required variable AF is set
    if (!isset($AF)) {
        $AF = ApplicationFramework::getInstance();
    }

    # Go to all privileges  (needs the "?ID=" portion, fixes refresh bug)
    $AF->SetJumpToPage("EditPrivilegeNames&ID=");
    return;
}

$_POST["AlreadyLoaded"] = "TRUE";
