<?PHP
#
#   FILE:  InlineEdit.php (Pages plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2019-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Plugins\Pages\Page;
use Metavus\Plugins\Pages\PageFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

$AF = ApplicationFramework::getInstance();

# retrieve ID of page to edit
$PageId = (isset($_GET["ID"]) ? $_GET["ID"] : null);

$AF->beginAjaxResponse();

$Plugin = PluginManager::getInstance()->getPluginForCurrentPage();

# make sure provided page is valid
$PFactory = new PageFactory();
if (!$PFactory->itemExists($PageId)) {
    $Result = [
        "status" => "error",
        "message" => "Invalid PageId (".$PageId.") provided.",
    ];

    print json_encode($Result);
    return;
}

# retrieve user currently logged in
$User = User::getCurrentUser();

# make sure user can edit provided page
$Page = new Page($PageId);
if (!$Page->userCanEdit($User)) {
    $Result = [
        "status" => "error",
        "message" => "You are not authorized to edit this page.",
    ];

    print json_encode($Result);
    return;
}

# and make sure the user actually gave us some content
if (!isset($_POST["Content"])) {
    $Result = [
        "status" => "error",
        "message" => "No content provided.",
    ];

    print json_encode($Result);
    return;
}

# get page content
$PageContent = $Page->get("Content");

# if anything has been changed
if ($_POST["Content"] != $PageContent) {
    # save new content
    $Page->set("Content", $_POST["Content"]);
    $Page->set("Summary", $Page->getSummary(
        $Plugin->configSetting("SummaryLength")
    ));

    # update page modification times
    $Page->set("Last Modified By Id", $User->id());
    $Page->set("Date Last Modified", date("Y-m-d H:i:s"));

    # signal resource modification event
    $AF->signalEvent(
        "EVENT_RESOURCE_MODIFY",
        ["Resource" => $Page]
    );

    # generate updated HTML for display
    $PageContent = $Page->get("Content");
}

# if page contains tabs, add tab markup
if ($Page->containsTabs()) {
    $PageContent = Page::processTabMarkup($PageContent);
}

# make sure only allowed insertion keywords are expanded
$PageContent = $AF->escapeInsertionKeywords(
    $PageContent,
    $Plugin->getAllowedInsertionKeywords()
);

$DisplayUser = $User->name();
$DisplayTime = StdLib::getPrettyTimestamp(
    $Page->get("Date Last Modified"),
    true
);

$Result = [
    "status" => "OK",
    "content" => $PageContent,
    "updates" => [
        ["selector" => ".cw-pages-modifiedby" , "html" => $DisplayUser ],
        ["selector" => ".cw-pages-modifiedtime", "html" => $DisplayTime ],
    ]
];
print json_encode($Result);
