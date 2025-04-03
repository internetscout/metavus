<?PHP
#
#   FILE:  Login.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

# request that this page not be indexed by search engines
$AF = ApplicationFramework::getInstance();
$AF->addMetaTag(["robots" => "noindex"]);

$AF->DoNotCacheCurrentPage();
