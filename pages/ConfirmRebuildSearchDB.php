<?PHP
#
#   FILE:  ConfirmRebuildSearchDB.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Confirm Search Database Rebuild");

if (!User::requirePrivilege(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}
