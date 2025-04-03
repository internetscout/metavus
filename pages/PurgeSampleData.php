<?PHP
#
#   FILE:  PurgeSampleData.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

# ----- MAIN -----------------------------------------------------------------

# check if current user is authorized
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);
