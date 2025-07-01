<?PHP
#
#   FILE:  GetNavContent.php (SecondaryNavigation plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\User;
use Metavus\Plugins\SecondaryNavigation;
use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();

# get the SecondaryNavigation plugin
$SecondaryNavPlugin = SecondaryNavigation::getInstance();

# This page does not output any HTML
$AF->suppressHtmlOutput();

# return updated content if user is logged in (this won't work if a user isn't logged in)
if (User::getCurrentUser()->isLoggedIn()) {
    print($SecondaryNavPlugin->getSidebarContent());
}
