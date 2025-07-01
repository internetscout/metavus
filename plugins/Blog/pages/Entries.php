<?PHP
#
#   FILE:  Entries.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\Plugins\Blog\EntryFactory;
use Metavus\TransportControlsUI;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$H_Blog = Blog::getInstance();
$AF = ApplicationFramework::getInstance();

# retrieve user currently logged in
$User = User::getCurrentUser();

if (isset($_GET["BlogId"])) {
    $BlogId = intval($_GET["BlogId"]);
} else {
    # if the blog id is not given, just use the first available blog from the
    # list
    $BlogId = current(array_keys($H_Blog->getAvailableBlogs()));
    if ($BlogId === false) {
        throw new Exception("No available blog found.");
    }
}

$H_Blog->setCurrentBlog($BlogId);

# set the page title to the blog name
$AF->setPageTitle($H_Blog->blogName());

# set up some basic values
$H_SchemaId = $H_Blog->getSchemaId();
$H_Entries = [];

# get the blog entries
$Factory = new EntryFactory((int)$BlogId);

$AllEntryIds = $Factory->getRecordIdsSortedBy(
    Blog::PUBLICATION_DATE_FIELD_NAME,
    false
);

$EntryIds = $Factory->filterOutUnviewableRecords(
    $AllEntryIds,
    $User
);

$CacheExpirationDate = $Factory->getViewCacheExpirationDate(
    $AllEntryIds,
    $User
);

if ($CacheExpirationDate !== false) {
    $AF->expirationDateForCurrentPage(
        $CacheExpirationDate
    );
}

$H_TransportControls = new TransportControlsUI();
$H_TransportControls->itemCount(
    count($EntryIds)
);
$H_TransportControls->itemsPerPage(
    $H_Blog->EntriesPerPage()
);

# calculate ID array checksum and reset paging if list has changed
$ListChecksum = md5(serialize($EntryIds));
if ($ListChecksum != StdLib::getFormValue("CK")) {
    $H_TransportControls->startingIndex(0);
}

$H_TransportControls->baseLink(
    $H_Blog->BlogUrl(["CK" => $ListChecksum])
);

# prune entry IDs down to just currently-selected segment
$EntryIds = array_slice(
    $EntryIds,
    $H_TransportControls->startingIndex(),
    $H_TransportControls->itemsPerPage()
);

# load blog entries from IDs
foreach ($EntryIds as $Id) {
    $H_Entries[$Id] = new Entry($Id);
}

# additional variables
$H_BlogName = $H_Blog->blogName();

# tag page so it will be cleared when events are edited
$AF->addPageCacheTag("ResourceList".$H_SchemaId);
