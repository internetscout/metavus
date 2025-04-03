<?PHP
#
#   FILE:  RebuildSearch.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

use Metavus\SearchEngine;

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_COLLECTIONADMIN)) {
    return;
}

if ($_GET["AC"] == "Background") {
    # set relatively high PHP timeout in case of large collection
    set_time_limit(600);

    # queue rebuild for all schemas
    $H_ResourcesQueued = SearchEngine::queueDBRebuildForAllSchemas();
}
