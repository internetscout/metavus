<?PHP
#
#   FILE:  Subscribe.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- MAIN -----------------------------------------------------------------

PageTitle("Subscribe to Blog Entry Notifications");

# retrieve user currently logged in
$User = User::getCurrentUser();

# get the blog plugin
$Blog = PluginManager::getInstance()->getPluginForCurrentPage();
$Blog->setCurrentBlog($Blog->configSetting("EmailNotificationBlog"));

$AF = ApplicationFramework::getInstance();

# if user is logged in
if ($User->isLoggedIn()) {
    # change the subscription for the user
    $Blog->changeNotificationSubscription($User, true);

    # go back to the blog landing page
    $AF->setJumpToPage($Blog->blogUrl());
} else {
    # send user to login page with appropriate prompt
    $_SESSION["LoginPrompt"] = $Blog->configSetting("NotificationLoginPrompt");
    $AF->setJumpToPage("Login");
}
