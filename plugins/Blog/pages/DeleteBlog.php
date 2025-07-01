<?PHP
#
#   FILE:  EditBlog.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\EntryFactory;
use Metavus\User;
use ScoutLib\PluginManager;

if (!User::requirePrivilege(PRIV_SYSADMIN)) {
    return;
}

# don't allow unauthorized access
$BlogPlugin = Blog::getInstance();

$H_BlogId = intval($_GET["BI"]);
$EntryFactory = new EntryFactory($H_BlogId);

$H_BlogName = $BlogPlugin->BlogSetting($H_BlogId, "BlogName");
$H_BlogEntryCount = count($EntryFactory->GetItemIds());
