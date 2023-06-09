<?PHP
#
#   FILE:  PurgeSampleData.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

# ----- LOCAL FUNCTIONS ------------------------------------------------------

# ----- MAIN -----------------------------------------------------------------

PageTitle("Purge Sample Data");

# check if current user is authorized
CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN);
