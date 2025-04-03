<?PHP
#
#   FILE:  SelectPublisher.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

# Select a folder to include in an LTI Deep Linking responses. See docstring
# for EduLink plugin for a description of the request flow.

# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_LaunchId - Launch Id for the current request
#   $H_OwnerList - List of folder owners to display

namespace Metavus;

use Exception;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\Plugins\EduLink;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$User = User::getCurrentUser();
$Plugin = EduLink::getInstance();

$H_LaunchId = $_GET["L"];

$AF->suppressStandardPageStartAndEnd();

$H_OwnerList = [];

$FolderIds = $Plugin->getFolderList();
if ($User->isLoggedIn()) {
    $UserFolderIds = (new FolderFactory($User->id()))
        ->getResourceFolder()
        ->getItemIds();
    $FolderIds = array_merge(
        $UserFolderIds,
        $FolderIds
    );
}

$Options = [];
foreach ($FolderIds as $Id) {
    $Folder = new Folder((int)$Id);

    # get visible items by schema
    $VisibleItemIds = RecordFactory::buildMultiSchemaRecordList(
        $Folder->getVisibleItemIds(
            User::getAnonymousUser()
        )
    );

    # if there are no records, nothing to do
    if (!isset($VisibleItemIds[MetadataSchema::SCHEMAID_DEFAULT])) {
        continue;
    }

    # if no records are suitable for embedding, nothing to do
    $SuitableItemIds = $Plugin->filterOutRecordsWithUnusableUrls(
        $VisibleItemIds[MetadataSchema::SCHEMAID_DEFAULT]
    );
    if (count($SuitableItemIds) == 0) {
        continue;
    }

    $H_OwnerList[] = $Folder->ownerId();
}

$H_OwnerList = array_unique($H_OwnerList);
