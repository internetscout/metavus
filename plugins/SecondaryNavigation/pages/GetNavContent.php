<?PHP
#
#   FILE:  GetNavContent.php (SecondaryNavigation plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\User;

# get the SecondaryNavigation plugin
$SecondaryNavPlugin = $GLOBALS["G_PluginManager"]->getPluginForCurrentPage();

# This page does not output any HTML
$GLOBALS["AF"]->suppressHTMLOutput();

# return updated content if user is logged in (this won't work if a user isn't logged in)
if (User::getCurrentUser()->isLoggedIn()) {
    print($SecondaryNavPlugin->getSidebarContent());
}
