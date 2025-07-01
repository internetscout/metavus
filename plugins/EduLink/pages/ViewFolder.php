<?PHP
#
#   FILE:  ViewFolder.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

# Select a folder to include in an LTI Deep Linking responses. See docstring
# for EduLink plugin for a description of the request flow.

namespace Metavus;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\EduLink;
use Metavus\Plugins\EduLink\ResourceSelectionUI;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$Plugin = EduLink::getInstance();

$H_LaunchId = $_GET["L"] ?? false;
$FolderId = $_GET["F"] ?? false;
if ($H_LaunchId === false || $FolderId === false) {
    $AF->suppressStandardPageStartAndEnd();
    $H_Error = "Required parameters not provided.";
    return;
}

$H_LaunchId = $_GET["L"];
$FolderId = $_GET["F"];

if (filter_var($FolderId, FILTER_VALIDATE_INT) === false
    || !Folder::itemExists((int)$FolderId)) {
    $AF->suppressStandardPageStartAndEnd();

    $H_Error = "Invalid FolderId provided.";
    return;
}

$H_Folder = new Folder((int)$FolderId);

$Launch = $Plugin->getCachedLaunch($H_LaunchId);

# if a folder was selected, send it back to the LMS
$ButtonPushed = StdLib::getFormValue("Submit");
if ($ButtonPushed == "Send Collection to LMS") {
    $DeepLink = $Launch->get_deep_link();

    $LinkUrl = $AF->baseUrl()."lti/dl_f/v1/".$H_Folder->id();

    $Reply = \IMSGlobal\LTI\LTI_Deep_Link_Resource::new()
        ->set_title($H_Folder->name())
        ->set_url($LinkUrl);

    $AF->suppressHtmlOutput();

    // at-prefix to hide a warning from w/in the LTI libraries
    @$DeepLink->output_response_form([$Reply]);
    return;
}

$H_SelectedRecordIds = ResourceSelectionUI::getCurrentSelections();

$RFactory = new RecordFactory(MetadataSchema::SCHEMAID_DEFAULT);

$ItemIds = RecordFactory::buildMultiSchemaRecordList(
    $H_Folder->getItemIds()
);

$H_ResourceIds = $RFactory->filterOutUnviewableRecords(
    $ItemIds[MetadataSchema::SCHEMAID_DEFAULT] ?? [],
    User::getAnonymousUser()
);

if (count($H_ResourceIds) > 0) {
    $H_ResourceIds = $Plugin->filterOutRecordsWithUnusableUrls(
        $H_ResourceIds
    );
}

$Plugin->recordListFolderContents(
    $H_LaunchId,
    $H_Folder->id()
);

$AF->suppressStandardPageStartAndEnd();
