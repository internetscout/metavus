<?PHP
#
#   FILE:  ConfirmNotifySubscribers.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
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
$AF->setPageTitle("Notify Blog Subscribers Confirmation");

# get the blog plugin and entry
$H_Blog = Blog::getInstance();
$H_Entry = new Entry(StdLib::getArrayValue($_GET, "ID"));

# don't allow unauthorized access
if (!$H_Entry->UserCanEdit(User::getCurrentUser())) {
    User::handleUnauthorizedAccess();
    return;
}

# don't allow notification if the entry is not from the "email blog"
if ($H_Entry->GetBlogId() != $H_Blog->getConfigSetting("EmailNotificationBlog")) {
    $AF->SetJumpToPage(
        "index.php?P=P_Blog_Entry&EntryId=".$H_Entry->Id()
        . "&Error=ERROR_NOT_EMAIL_BLOG"
    );
    return;
}
