<?PHP
#
#   FILE:  ListBlogs.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Blog;
use Metavus\TransportControlsUI;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

if (!CheckAuthorization(PRIV_SYSADMIN)) {
    return;
}

$BlogPlugin = Blog::getInstance();

# blog list field definition
$H_BlogFields = [
    "BlogName" => [
        "Heading" => "Blog Name",
        "MaxLength" => 80,
    ],
    "BlogDescription" => [
        "Heading" => "Blog Description",
        "MaxLength" => 320,
    ]
];

# blog list field values
$H_Blogs = [];
foreach ($BlogPlugin->GetAvailableBlogs() as $BlogId => $BlogName) {
    $H_Blogs[$BlogId] = [
        "BlogName" => $BlogName,
        "BlogDescription" => $BlogPlugin->BlogSetting($BlogId, "BlogDescription"),
    ];
}

# blog list total size
$H_BlogsListSize = count($H_Blogs);

# pagination page size and offset
$H_PageSize = 25;
$H_PageOffset = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);

# generate a hash code using the list and check if the list has been modified
$H_Checksum = md5(serialize($H_Blogs));
if ($H_Checksum != StdLib::getFormValue("CK")) {
    $H_PageOffset = 0;
}

# pick the currently-selected page to display
$H_Blogs = array_slice($H_Blogs, $H_PageOffset, $H_PageSize, true);
