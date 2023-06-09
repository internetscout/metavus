<?PHP
#
#   FILE:  SysAdmin.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized
if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN, PRIV_USERADMIN)) {
    return;
}

# make sure information for current user is up-to-date
User::getCurrentUser()->LastLocation($GLOBALS["AF"]->getPageName());
