<?PHP
#
#   FILE:  DeleteBlogComplete.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

CheckAuthorization(PRIV_SYSADMIN);

if ($_POST["Submit"] == "Delete") {
    $BlogPlugin = PluginManager::getInstance()->getPluginForCurrentPage();
    $BlogId = intval($_POST["F_BlogId"]);
    $BlogPlugin->DeleteBlog($BlogId);
}

$AF = ApplicationFramework::getInstance();
$AF->SetJumpToPage("index.php?P=P_Blog_ListBlogs");
