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

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $ErrorMessages;

# verify that user is logged in and authorized
if (!User::requirePrivilege(PRIV_SYSADMIN)) {
    return;
}

$PrivilegeFactory = new PrivilegeFactory();
$H_Privileges = $PrivilegeFactory->getPrivileges();

$H_ErrorMessages = $ErrorMessages;

$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Edit Per-Field User Permission Names");
