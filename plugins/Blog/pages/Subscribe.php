<?PHP
#
#   FILE:  Subscribe.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Blog;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Subscribe to Blog Entry Notifications");

# retrieve user currently logged in
$User = User::getCurrentUser();

# get the blog plugin
$Blog = Blog::getInstance();
$Blog->setCurrentBlog($Blog->getConfigSetting("EmailNotificationBlog"));

# if user is logged in
if ($User->isLoggedIn()) {
    # change the subscription for the user
    $Blog->changeNotificationSubscription($User, true);

    # go back to the blog landing page
    $AF->setJumpToPage($Blog->blogUrl());
} else {
    # send user to login page with appropriate prompt
    $_SESSION["LoginPrompt"] = $Blog->getConfigSetting("NotificationLoginPrompt");
    $AF->setJumpToPage("Login");
}
