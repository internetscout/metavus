<?PHP
#
#   FILE:  EditPage.php (Pages plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\File;
use Metavus\Image;
use Metavus\MetadataSchema;
use Metavus\Plugins\Pages\Page;
use Metavus\Plugins\Pages\PageFactory;
use Metavus\PrivilegeEditingUI;
use Metavus\PrivilegeSet;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

$AF = ApplicationFramework::getInstance();

# retrieve ID of page to edit
$PageId = isset($_POST["F_Id"]) ? $_POST["F_Id"]
        : (isset($_GET["ID"]) ? $_GET["ID"] : null);

# make sure user has needed privileges
$Plugin = PluginManager::getInstance()->getPluginForCurrentPage();
$H_SchemaId = $Plugin->configSetting("MetadataSchemaId");

# retrieve user currently logged in
$User = User::getCurrentUser();

if (is_null($PageId)) {
    DisplayUnauthorizedAccessPage();
    return;
} elseif ($PageId == "NEW") {
    $Schema = new MetadataSchema($H_SchemaId);
    if (!$Schema->userCanAuthor($User)) {
        DisplayUnauthorizedAccessPage();
        return;
    }
} else {
    $PFactory = new PageFactory();
    if ($PFactory->itemExists($PageId)) {
        $H_Page = new Page($PageId);
        if (!$H_Page->userCanEdit($User)) {
            DisplayUnauthorizedAccessPage();
            return;
        }
    }
}

# save invoking page
$H_ReturnTo = isset($_POST["F_ReturnTo"]) ? $_POST["F_ReturnTo"]
        : (isset($_GET["ReturnTo"]) ? $_GET["ReturnTo"]
        : (isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"]
        : null));

# take action based on which button was pushed or which action was requested
$Action = isset($_POST["Submit"]) ? $_POST["Submit"] : "Edit";
switch ($Action) {
    case "Edit":
        # if new page was requested
        if ($PageId == "NEW") {
            # create new page
            $H_Page = Page::create();

            # set display mode to adding new page
            $H_DisplayMode = "Adding";
        } else {
            # if page was not specified
            if ($PageId === null) {
                # set error message to be displayed
                $H_ErrorMsgs[] = "No page ID was specified.";
                $H_DisplayMode = "Error";
            } else {
                # if page does not exist
                if (!$PFactory->itemExists($PageId)) {
                    # set error message to be displayed
                    $H_ErrorMsgs[] = "The specified page (ID=<i>"
                            .$PageId."</i>) does not exist.";
                    $H_DisplayMode = "Error";
                } else {
                    # set display mode based on page status
                    $H_DisplayMode = $H_Page->isTempRecord()
                            ? "Adding" : "Editing";
                }
            }
        }

        # load values for editing
        if ($H_DisplayMode != "Error") {
            $H_Title = $H_Page->get("Title");
            $H_Content = $H_Page->get("Content");
            if ($H_Content === null) {
                $H_Content = "";
            }
            $H_Summary = $H_Page->get("Summary");
            if ($H_Summary === null) {
                $H_Summary = "";
            }
            $H_Keywords = $H_Page->get("Keywords");
            if ($H_Keywords === null) {
                $H_Keywords = "";
            }
            $H_CleanUrl = $H_Page->get("Clean URL");
            $H_AltTexts = [];
            $Images = $H_Page->get("Images", true);
            foreach ($Images as $Image) {
                $H_AltTexts[$Image->id()] = $Image->altText();
            }
            $H_Privileges = $H_Page->viewingPrivileges();
            if ($H_Privileges == null) {
                $H_Privileges = new PrivilegeSet();
            }
        }
        break;

    case "Delete":
        # if image was specified to delete
        $Schema = new MetadataSchema($H_SchemaId);
        if (strlen($_POST["F_ImageToDelete"])) {
            # dissociate image from page and delete image
            $ImageId = $_POST["F_ImageToDelete"];
            if (Image::itemExists($ImageId)) {
                $Image = new Image($ImageId);
                $Field = $Schema->getField("Images");
                $H_Page->clear($Field, $Image);
            }
        } elseif (strlen($_POST["F_FileToDelete"])) {
            # if file was specified to delete
            # dissociate file from page and delete file
            $FileId = $_POST["F_FileToDelete"];
            if (File::itemExists($FileId)) {
                $Field = $Schema->getField("Files");
                $File = new File($FileId);
                $H_Page->clear($Field, $File);
            }
        }
        break;

    case "Upload":
        # if image uploaded
        if (isset($_FILES["F_Image"]["tmp_name"]) &&
            is_uploaded_file($_FILES["F_Image"]["tmp_name"])) {
            # create temp copy of file with correct name
            $TempFile = "tmp/".$_FILES["F_Image"]["name"];
            copy($_FILES["F_Image"]["tmp_name"], $TempFile);

            # create new Image object from uploaded file
            $Schema = new MetadataSchema($H_SchemaId);
            $Field = $Schema->getField("Images");

            try {
                $Image = Image::create($TempFile);
            } catch (Exception $Ex) {
                $ImageName = $_FILES["F_Image"]["name"];
                $H_ErrorMsgs[] = "A problem was encountered uploading"
                    ." the image file <i>".$ImageName."</i>."
                    ."(".$Ex->getMessage().")";
                break;
            }

            # attach image to resource
            $H_Page->set($Field, $Image->id());

            # set the image's alternate text
            $AltText = StdLib::getArrayValue($_POST, "F_ImageAltText");
            if (strlen($AltText)) {
                $Image->altText($AltText);
            }
        }

        # if file uploaded
        if (isset($_FILES["F_File"]["tmp_name"]) &&
            is_uploaded_file($_FILES["F_File"]["tmp_name"])) {
            # create temp copy of file with correct name
            $TempFile = "tmp/".$_FILES["F_File"]["name"];
            copy($_FILES["F_File"]["tmp_name"], $TempFile);

            # create new File object from uploaded file
            $Schema = new MetadataSchema($H_SchemaId);
            $Field = $Schema->getField("Files");
            $FileName = $_FILES["F_File"]["name"];
            $File = File::create($TempFile, $FileName);

            # if file save was succeessful
            if (is_object($File)) {
                # set additional file attributes
                $File->resourceId($H_Page->id());
                $File->fieldId($Field->id());
            } else {
                # set error message and error out
                switch ($File) {
                    case File::FILESTAT_ZEROLENGTH:
                        $H_ErrorMsgs[] = "The file <i>".$FileName
                                ."</i> uploaded was empty (zero length).";
                        break;

                    default:
                        $H_ErrorMsgs[] = "A problem was encountered uploading"
                                ." the file <i>".$FileName."</i>.(".$File.")";
                        break;
                }
            }
        }
        break;

    case "Add":
    case "Save":
        # tidy up clean URL if specified
        $CleanUrl = "";
        if (array_key_exists("F_CleanUrl", $_POST) && strlen(trim($_POST["F_CleanUrl"]))) {
            $CleanUrl = $_POST["F_CleanUrl"];
            $CleanUrl = trim($CleanUrl, "/");
            $CleanUrl = str_replace($AF->baseUrl(), "", $CleanUrl);
            $CleanUrl = preg_replace("%[^a-z0-9_/-]+%i", "", $CleanUrl);
        }

        # if specified clean URL is already in use (and not by us)
        $CleanUrlList = $PFactory->getCleanUrls();
        if (strlen($CleanUrl) &&
            $AF->cleanUrlIsMapped($CleanUrl) &&
            (!array_key_exists($H_Page->id(), $CleanUrlList) ||
             !in_array($CleanUrl, $CleanUrlList[$H_Page->id()]))) {
            # set error message to be displayed
            $H_ErrorMsgs[] = "The specified clean URL path (<a href=\""
                    .$AF->baseUrl().$CleanUrl."\"><i>".$CleanUrl
                    ."</i></a>) is already in use.";

            # reload values for editing
            $H_Title = $_POST["F_Title"];
            $H_Content = $_POST["F_Content"];
            $H_Summary = $_POST["F_Summary"];
            $H_Keywords = $_POST["F_Keywords"];
            $H_CleanUrl = $CleanUrl;
            $PrivUI = new PrivilegeEditingUI($H_SchemaId);
            $PrivSets = $PrivUI->getPrivilegeSetsFromForm();
            $H_Privileges = $PrivSets["ViewingPrivs"];

            # set display mode appropriately
            $H_DisplayMode = $H_Page->isTempRecord() ? "Adding" : "Editing";
        } else {
            # if summary was not edited or is empty
            if (!strlen(trim($_POST["F_Summary"])) ||
                ($_POST["F_Summary"]
                == $H_Page->getSummary($Plugin->configSetting("SummaryLength")))) {
                # update content and regenerate summary from content
                $H_Page->set("Content", $_POST["F_Content"]);
                $H_Page->set("Summary", $H_Page->getSummary(
                    $Plugin->configSetting("SummaryLength")
                ));
            } else {
                # save edited summary
                $H_Page->set("Summary", $_POST["F_Summary"]);
            }

            # update page content
            $H_Page->set("Title", $_POST["F_Title"]);
            $H_Page->set("Content", $_POST["F_Content"]);
            $H_Page->set("Clean URL", $CleanUrl);
            $H_Page->set("Keywords", $_POST["F_Keywords"]);
            foreach ($_POST as $Name => $Value) {
                if (preg_match("/^F_ImageAltText_[0-9]+/", $Name)) {
                    $ImageId = preg_replace("/F_ImageAltText_/", "", $Name);
                    $Image = new Image($ImageId);
                    $Image->altText($Value);
                }
            }

            # update page modification times
            $H_Page->set("Last Modified By Id", $User->id());
            $H_Page->set("Date Last Modified", date("Y-m-d H:i:s"));

            # update viewing privileges for page
            $PrivUI = new PrivilegeEditingUI($H_SchemaId);
            $PrivSets = $PrivUI->getPrivilegeSetsFromForm();
            $H_Page->viewingPrivileges($PrivSets["ViewingPrivs"]);

            # if new page
            if ($Action == "Add") {
                # set author and mark page no longer temporary
                $H_Page->set("Added By Id", $User->id());
                $H_Page->isTempRecord(false);
            } else {
                # signal modified page
                $AF->signalEvent(
                    "EVENT_RESOURCE_MODIFY",
                    ["Resource" => $H_Page]
                );
            }

            # go to display saved page
            if (strlen($CleanUrl)) {
                $AF->setJumpToPage($CleanUrl, 0, true);
            } else {
                $AF->setJumpToPage(
                    "index.php?P=P_Pages_DisplayPage&ID=".$H_Page->id()
                );
            }

            # update search indices
            $H_Page->queueSearchAndRecommenderUpdate();
        }
        break;

    case "Cancel":
        # discard page if temporary
        if (isset($H_Page) && $H_Page->isTempRecord()) {
            $H_Page->destroy();
        }

        # return to invoking page
        $AF->setJumpToPage($H_ReturnTo);
        break;
}

# if just processed delete or upload request
if (($Action == "Delete") || ($Action == "Upload")) {
    # retrieve display mode
    $H_DisplayMode = StdLib::getArrayValue($_POST, "F_DisplayMode", "Editing");

    # transfer existing values from form
    $H_Title = StdLib::getArrayValue($_POST, "F_Title");
    $H_Content = StdLib::getArrayValue($_POST, "F_Content");
    $H_Summary = StdLib::getArrayValue($_POST, "F_Summary");
    $H_Keywords = StdLib::getArrayValue($_POST, "F_Keywords");
    $H_CleanUrl = StdLib::getArrayValue($_POST, "F_CleanUrl");
    $H_AltTexts = [];
    $Images = $H_Page->get("Images", true);
    foreach ($Images as $Image) {
        if (isset($_POST["F_ImageAltText_".$Image->id()])) {
            $H_AltTexts[$Image->id()] = $_POST["F_ImageAltText_".$Image->id()];
        } else {
            $H_AltTexts[$Image->id()] = $Image->altText();
        }
    }

    # transfer viewing privileges for page
    $PrivUI = new PrivilegeEditingUI($H_SchemaId);
    $PrivSets = $PrivUI->getPrivilegeSetsFromForm();
    $H_Privileges = $PrivSets["ViewingPrivs"];
}
