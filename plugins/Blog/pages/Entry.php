<?PHP
#
#   FILE:  Entry.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2013-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$H_Blog = Blog::getInstance();

# assume that a generic error will occur
$H_State = "Error";

# get objet parameters
$EntryId = StdLib::getArrayValue($_GET, "ID");

# if the entry ID is invalid
if (!is_numeric($EntryId) || !Record::ItemExists((int)$EntryId)) {
    $AF->doNotCacheCurrentPage();
    $H_State = "Invalid ID";
    return;
}

# if the entry is some other type of resource
if (Record::getSchemaForRecord((int)$EntryId) != $H_Blog->getSchemaId()) {
    $H_State = "Not Blog Entry";
    return;
}

$H_Entry = new Entry((int)$EntryId);
$H_Blog->SetCurrentBlog($H_Entry->GetBlogId());

# if the entry hasn't been published yet and the user can't view unpublished
# entries
$CanView = $H_Entry->UserCanView(User::getCurrentUser());

# set cache expiration time (if any) for cached version of page
$ExpDate = $H_Entry->getViewCacheExpirationDate();
if ($ExpDate !== false) {
    $AF->expirationDateForCurrentPage($ExpDate);
}

if (!$CanView) {
    $H_State = "Entry Not Viewable";
    return;
}

# get the blog entry's metrics
$H_Metrics = $H_Blog->GetBlogEntryMetrics($H_Entry);

# record an event
$H_Blog->RecordBlogEntryView($H_Entry);

# if this entry is not from an "Email Blog" and someone tries to notify, jump
# back to this page with error
if (isset($_GET["Error"])) {
    if (StdLib::getArrayValue($_GET, "Error") == "ERROR_NOT_EMAIL_BLOG") {
        $H_State = "Not Email Blog";
        return;
    }
}

# everything is fine
$H_State = "OK";

# signal view of full blog entry info
$AF->SignalEvent(
    "EVENT_FULL_RECORD_VIEW",
    ["ResourceId" => $H_Entry->Id()]
);
