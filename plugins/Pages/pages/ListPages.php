<?PHP
#
#   FILE:  ListPages.php (Pages plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\MetadataSchema;
use Metavus\Plugins\Pages;
use Metavus\Plugins\Pages\Page;
use Metavus\Plugins\Pages\PageFactory;
use Metavus\TransportControlsUI;
use Metavus\User;
use ScoutLib\StdLib;

# retrieve user currently logged in
$User = User::getCurrentUser();

# check authorization to see page list
$Plugin = Pages::getInstance();
$H_SchemaId = $Plugin->getConfigSetting("MetadataSchemaId");
$Schema = new MetadataSchema($H_SchemaId);
if (!$Schema->userCanAuthor($User) && !$Schema->userCanEdit($User)) {
    DisplayUnauthorizedAccessPage();
    return;
}

# retrieve sort parameters
$H_DefaultSortFieldName = "Pages: Date Last Modified";
$H_TransportUI = new TransportControlsUI();
$SortFieldName = $H_TransportUI->sortField();
if ($SortFieldName === null) {
    $SortFieldName = $H_DefaultSortFieldName;
}
$ReverseSort = $H_TransportUI->reverseSortFlag();

# determine list sort direction
if ($SortFieldName == $H_DefaultSortFieldName) {
    $SortAscending = $ReverseSort ? true : false;
} else {
    $SortAscending = $ReverseSort ? false : true;
}

# load page IDs
$SortField = $Schema->getField($SortFieldName);
$PFactory = new PageFactory();
$PageIds = $PFactory->getRecordIdsSortedBy($SortField, $SortAscending);

# get total count of pages
$H_PageCount = count($PageIds);

# get where we currently are in page list
$H_StartingIndex = $H_TransportUI->startingIndex();

# calculate ID array checksum and reset paging if list has changed
$H_ListChecksum = md5(serialize($PageIds));
if ($H_ListChecksum != StdLib::getFormValue("CK")) {
    $H_StartingIndex = 0;
}

# prune page IDs down to just currently-selected segment
$H_PagesPerPage = 25;
$PageIds = array_slice($PageIds, $H_StartingIndex, $H_PagesPerPage);

# load pages from page IDs
$H_Pages = [];
foreach ($PageIds as $Id) {
    $H_Pages[$Id] = new Page($Id);
}
