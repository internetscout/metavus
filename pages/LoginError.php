<?PHP
#
#   FILE:  LoginError.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Login Error");

$User = User::getCurrentUser();
if ($User->isLoggedIn()) {
    $AF->setJumpToPage("Home");
}
