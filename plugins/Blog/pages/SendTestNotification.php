<?PHP
#
#   FILE:  SendTestNotification.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------
$AF = ApplicationFramework::getInstance();
$AF->setPageTitle("Send Test Notification");

# get the blog plugin and entry
$Blog = Blog::getInstance();
$Entry = new Entry(StdLib::getArrayValue($_GET, "ID"));

# don't allow unauthorized access
if (!$Entry->UserCanEdit(User::getCurrentUser())) {
    User::handleUnauthorizedAccess();
    return;
}

# don't notify if the entry is not from the Email Blog
$EntryBlogId = $Entry->getBlogId();
if ($Blog->getConfigSetting("EmailNotificationBlog") != $EntryBlogId) {
    $AF->setJumpToPage("index.php?P=P_Blog_Entry&EntryId="
            . $Entry->id()."&Error=ERROR_NOT_EMAIL_BLOG");
    return;
}

# send test email
$Blog->sendTestNotification($Entry);

# go back to the page for the blog entry
$AF->setJumpToPage($Entry->entryUrl());
