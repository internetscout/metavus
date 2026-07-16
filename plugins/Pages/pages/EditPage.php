<?PHP
#
#   FILE:  EditPage.php (Pages plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\File;
use Metavus\FormUI;
use Metavus\MetadataSchema;
use Metavus\Plugins\Pages;
use Metavus\Plugins\Pages\Page;
use Metavus\Plugins\Pages\PageFactory;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Get HTML describing a page timestamp and user.
 * @param Page $Page Page to inspect.
 * @param string $DateFieldName Metadata field containing the timestamp.
 * @param string $UserFieldName Metadata field containing the user ID.
 * @return string Timestamp/user HTML.
 */
function getPageEditTimestampHtml(
    Page $Page,
    string $DateFieldName,
    string $UserFieldName
): string {
    $User = current($Page->get($UserFieldName, true));
    $Timestamp = StdLib::getPrettyTimestamp($Page->get($DateFieldName), true);
    $UserName = ($User instanceof User) ? $User->name() : "(unknown)";
    $TimestampInfo = $Timestamp." by <i>".$UserName."</i>";

    return str_replace(" ", "&nbsp;", $TimestampInfo);
}

$AF = ApplicationFramework::getInstance();

# retrieve ID of page to edit
$PageId = isset($_POST["F_Id"]) ? $_POST["F_Id"]
        : (isset($_GET["ID"]) ? $_GET["ID"] : null);

# if page was not specified
if ($PageId === null) {
    # set error message to be displayed
    $H_ErrorMsgs[] = "No page ID was specified.";
    return;
}

$Plugin = Pages::getInstance();
$H_SchemaId = $Plugin->getConfigSetting("MetadataSchemaId");

$User = User::getCurrentUser();
$Schema = new MetadataSchema($H_SchemaId);
$PFactory = new PageFactory();

if ($PageId == "NEW") {
    if (!$Schema->userCanAuthor($User)) {
        $AF->setJumpToPage("UnauthorizedAccess");
        return;
    }

    $Page = Page::create();
} else {
    if (!$PFactory->itemExists($PageId)) {
        $H_DisplayMode = "Error";
        $H_ErrorMsgs[] = "Invalid page ID.";
        return;
    }

    $Page = new Page($PageId);
    if (!$Page->userCanModify($User)) {
        $AF->setJumpToPage("UnauthorizedAccess");
        return;
    }
}

$H_DisplayMode = $Page->isTempRecord() ? "Adding" : "Editing";

$FormValues = [
    "Title" => $Page->get("Title"),
    "Content" => $Page->get("Content"),
    "Summary" => $Page->get("Summary"),
    "Keywords" => $Page->get("Keywords"),
    "CleanUrl" => $Page->get("Clean URL"),
    "Image" => $Page->get("Images", true),
    "File" => $Page->get("Files", true),
    "ViewingPrivs" => $Page->viewingPrivileges(),
];

$AllowedKeywords = $Plugin->getAllowedInsertionKeywords();
$FormFields = [
    "Title" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Title",
        "Size" => 60,
        "MaxLength" => 120,
        "Help" => "Displayed in the browser title bar, and also "
                ."used by search engines who index the page. ",
    ],
    "Content" => [
        "Type" => FormUI::FTYPE_PARAGRAPH,
        "Label" => "Page Content",
        "Rows" => 20,
        "Columns" => 80,
        "UseWYSIWYG" => true,
        "AllowedInsertionKeywords" => $AllowedKeywords,
    ],
    "Summary" => [
        "Type" => FormUI::FTYPE_PARAGRAPH,
        "Label" => "Summary",
        "Rows" => 5,
        "Columns" => 60,
        "AllowedInsertionKeywords" => $AllowedKeywords,
        "Help" => "Displayed in search results, both on the site and by "
                ."external search engines like Google. If left blank, this "
                ."will be auto-generated from the page content.",
    ],
    "Keywords" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Keywords",
        "Size" => 60,
        "MaxLength" => 120,
        "Help" => "Additional keywords that might be used to search "
                ."for this page. (OPTIONAL)",
    ],
    "CleanUrl" => [
        "Type" => FormUI::FTYPE_TEXT,
        "Label" => "Clean URL Path",
        "Size" => 60,
        "MaxLength" => 120,
        "Help" => "If a &quot;clean URL&quot; path (e.g. <i>my/new/page"
                ."</i>) is set, the page will be reachable at that address.",
        "ValidateFunction" => function (
            $FieldName,
            $Value
        ) use (
            $AF,
            $PFactory,
            $Page
        ) : ?string {
            if (strlen($Value) == 0) {
                return null;
            }

            if (preg_match("%[^a-z0-9_/-]+%i", $Value)) {
                return "Invalid characters in Clean URL."
                    ." Only alphanumerics, underscores, dashes,"
                    ." and slash are allowed.";
            }

            if (substr($Value, 0, 1) == "/") {
                return "Clean URL cannot begin with a slash.";
            }

            if (substr($Value, -1) == "/") {
                return "Clean URL cannot end with a slash.";
            }

            # if specified clean URL is already in use (and not by us)
            $CleanUrlList = $PFactory->getCleanUrls();
            if ($AF->cleanUrlIsMapped($Value) &&
                (!array_key_exists($Page->id(), $CleanUrlList) ||
                 !in_array($Value, $CleanUrlList[$Page->id()]))) {
                # set error message to be displayed
                return "The specified clean URL path (<a href=\""
                    .$AF->baseUrl().$Value."\"><i>".$Value
                    ."</i></a>) is already in use.";
            }

            return null;
        }
    ],
    "Image" => [
        "Type" => FormUI::FTYPE_IMAGE,
        "Label" => "Images",
        "AllowMultiple" => true,
        "InsertIntoField" => "Content",
    ],
    "File" => [
        "Type" => FormUI::FTYPE_FILE,
        "Label" => "Files",
        "AllowMultiple" => true,
        "InsertIntoField" => "Content",
    ],
    "ViewingPrivs" => [
        "Type" => FormUI::FTYPE_PRIVILEGES,
        "Label" => "Privileges Required for Viewing Page",
        "Schemas" => $H_SchemaId,
    ],
    "Created" => [
        "Type" => FormUI::FTYPE_CUSTOMCONTENT,
        "Label" => "Created",
        "Content" => getPageEditTimestampHtml(
            $Page,
            "Creation Date",
            "Added By Id"
        )
    ],
    "LastModified" => [
        "Type" => FormUI::FTYPE_CUSTOMCONTENT,
        "Label" => "Last Modified",
        "Content" =>  getPageEditTimestampHtml(
            $Page,
            "Date Last Modified",
            "Last Modified By Id"
        )
    ],
];

$H_FormUI = new FormUI($FormFields, $FormValues);

$H_FormUI->addHiddenField("F_Id", (string)$Page->id());

$ReturnTo = $_POST["F_ReturnTo"] ??
    $_SERVER["HTTP_REFERER"] ??
    "index.php?P=P_Pages_ListPages";
$H_FormUI->addHiddenField("F_ReturnTo", (string)$ReturnTo);

$Action = $H_FormUI->getSubmitButtonValue();
switch ($Action) {
    case "Upload":
        $H_FormUI->handleUploads();
        break;

    case "Delete":
        $H_FormUI->handleDeletes();
        break;

    case "Add":
    case "Save":
        # stop processing on errors
        if ($H_FormUI->validateFieldInput() > 0) {
            return;
        }

        # get submitted values
        $NewValues = $H_FormUI->getNewValuesFromForm();
        $CleanUrl = $NewValues["CleanUrl"];

        # update page data
        $Page->set("Title", $NewValues["Title"]);

        $OldSummary = $Page->getSummary($Plugin->getConfigSetting("SummaryLength"));
        $Page->set("Content", $NewValues["Content"]);

        # if summary was not edited or is empty
        if (strlen(trim($NewValues["Summary"])) == 0 ||
            ($NewValues["Summary"] == $OldSummary)) {
            # generate from content
            $Page->set("Summary", $Page->getSummary(
                $Plugin->getConfigSetting("SummaryLength")
            ));
        } else {
            # otherwise, use provided value
            $Page->set("Summary", $NewValues["Summary"]);
        }

        $Page->set("Clean URL", $CleanUrl);
        $Page->set("Keywords", $NewValues["Keywords"]);
        $Page->set("Images", $NewValues["Image"], true);
        $Page->set("Files", $NewValues["File"], true);


        # update page modification times
        $Page->set("Last Modified By Id", $User->id());
        $Page->set("Date Last Modified", date("Y-m-d H:i:s"));

        # update viewing privileges for page
        $Page->viewingPrivileges($NewValues["ViewingPrivs"]);

        # clean up uploaded files/images not associated with the record
        # (Record::set() makes a copy of the file, this handles the one from
        # the upload. It also handles images/files that were uploaded but then
        # deleted before the record was saved.)
        $H_FormUI->deleteUploads();

        # if new page
        if ($Action == "Add") {
            # set author and mark page no longer temporary
            $Page->set("Added By Id", $User->id());
            $Page->isTempRecord(false);
        }

        # go to display saved page
        if (strlen($CleanUrl) !== 0) {
            $AF->setJumpToPage($CleanUrl, 0, true);
        } else {
            $AF->setJumpToPage(
                "index.php?P=P_Pages_DisplayPage&ID=".$Page->id()
            );
        }
        break;

    case "Cancel":
        $H_FormUI->deleteUploads();

        # discard page if temporary
        if ($Page->isTempRecord()) {
            $Page->destroy();
        }

        # return to invoking page
        $AF->setJumpToPage($ReturnTo);
        break;
}
