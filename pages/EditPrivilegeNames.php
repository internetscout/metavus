<?PHP
#
#   FILE:  EditPrivilegeNames.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2001-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

namespace Metavus;

if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $ErrorMessages;
global $Privileges;

if (User::getCurrentUser()->IsLoggedIn()) {
    $PrivilegeFactory = new PrivilegeFactory();
    $Privileges = $PrivilegeFactory->GetPrivileges();
}

$H_ErrorMessages = $ErrorMessages;

PageTitle("Edit Per-Field User Permission Names");
