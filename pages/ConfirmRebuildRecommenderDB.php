<?PHP
#
#   FILE:  ConfirmRebuildRocommenderDB.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

PageTitle("Confirm Recommender Database Rebuild");

# ----- MAIN -----------------------------------------------------------------

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}
