<?PHP
#
#   FILE:  Unsubscribe.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

# ----- MAIN -----------------------------------------------------------------

PageTitle("Unsubscribe from blog entry notifications");

# get the blog plugin
$Blog = PluginManager::getInstance()->getPluginForCurrentPage();
$Blog->SetCurrentBlog($Blog->ConfigSetting("EmailNotificationBlog"));

# change the subscription for the user
$Blog->ChangeNotificationSubscription(User::getCurrentUser(), false);

# go back to the blog landing page
ApplicationFramework::getInstance()->setJumpToPage($Blog->blogUrl());
