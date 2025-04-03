<?PHP
#
#   FILE:  ManuallyActivateAccount.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

ApplicationFramework::getInstance()->setJumpToPage("ActivateAccount");
