<?PHP
#
#   FILE:  NotifySubscribers.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

PageTitle("Notify Blog Subscribers");

# get the blog plugin and entry
$Blog = Blog::getInstance();
$Entry = new Entry(StdLib::getArrayValue($_GET, "ID"));

# don't allow unauthorized access
if (!$Entry->UserCanEdit(User::getCurrentUser())) {
    CheckAuthorization(false);
    return;
}

$AF = ApplicationFramework::getInstance();

# don't notify if the entry is not from the Email Blog
$EntryBlogId = $Entry->getBlogId();
if ($Blog->getConfigSetting("EmailNotificationBlog") != $EntryBlogId) {
    $AF->setJumpToPage("index.php?P=P_Blog_Entry&EntryId="
            . $Entry->id()."&Error=ERROR_NOT_EMAIL_BLOG");
    return;
}

# notify subscribers
$Blog->notifySubscribers($Entry);

# go back to the page for the blog entry
$AF->setJumpToPage($Entry->entryUrl());
