<?PHP
#
#   FILE:  ListEntries.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\Plugins\Blog\EntryFactory;
use Metavus\TransportControlsUI;
use Metavus\User;
use ScoutLib\PluginManager;

# ----- MAIN -----------------------------------------------------------------

# non-standard global variables
global $H_Schema;

use ScoutLib\StdLib;

$Blog = Blog::getInstance();
$H_Schema = new MetadataSchema($Blog->GetSchemaId());

# don't allow unauthorized access
if (!$H_Schema->UserCanEdit(User::getCurrentUser())) {
    User::handleUnauthorizedAccess();
    return;
}

# determine the current blog
$H_BlogSelectVarName = "BlogId";
$H_CurrentBlogId = array_key_exists($H_BlogSelectVarName, $_GET) ?
    intval($_GET[$H_BlogSelectVarName]) :
    current(array_keys($Blog->GetAvailableBlogs())) ;
$Blog->SetCurrentBlog($H_CurrentBlogId);

# get the sorting parameters
$H_SortField = StdLib::getFormValue(
    TransportControlsUI::PNAME_SORTFIELD,
    Blog::MODIFICATION_DATE_FIELD_NAME
);
$H_ReverseSort = StdLib::getFormValue(TransportControlsUI::PNAME_REVERSESORT, false);

# get all blog entries' IDs
$Factory = new EntryFactory($H_CurrentBlogId);
$EntryIds = $Factory->getRecordIdsSortedBy(
    $H_Schema->getFieldIdByName($H_SortField),
    $H_ReverseSort
);

# pagination page size, page offset, and total number of entries
$H_PageSize = 25;
$H_PageOffset = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);
$H_EntryCount = count($EntryIds);

# verify checksum, if the list has been modified, display the first page
$H_Checksum = md5(serialize($EntryIds));
if ($H_Checksum != StdLib::getFormValue("CK")) {
    $H_PageOffset = 0;
}

# pick the currently-selected page
$EntryIds = array_slice($EntryIds, $H_PageOffset, $H_PageSize);

# convert entry ID to entry object
$H_BlogEntries = [];
foreach ($EntryIds as $Id) {
    $H_BlogEntries[$Id] = new Entry($Id);
}
