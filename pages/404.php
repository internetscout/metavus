<?PHP
#
#   FILE:  404.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Page Not Found");
