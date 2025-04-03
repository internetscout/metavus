<?PHP
#
#   FILE:  CookiePermission.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#

namespace Metavus;

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

# suppress the full Metavus start/end to favor the smaller, LTI-specific ones
$AF->suppressStandardPageStartAndEnd();

# cannot cache current page because the State var extracted from the launch
# will change each time
$AF->doNotCacheCurrentPage();

$H_State = $_GET["state"] ?? false;
