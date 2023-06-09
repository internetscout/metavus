<?PHP
#
#   FILE:  TrackUserComments.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\Message;
use Metavus\MessageFactory;
use Metavus\TransportControlsUI;
use ScoutLib\StdLib;

# make sure user has needed privileges for user editing

if (!CheckAuthorization(PRIV_SYSADMIN, PRIV_USERADMIN)) {
    return;
}

PageTitle("Track User Comments");
# retrieve list of MessageIds, reversed as to show most recent
$MFactory = new MessageFactory();
$MessageIds = $MFactory->GetItemIds(
    "ParentId > 0 AND ParentType = ".Message::PARENTTYPE_RESOURCE,
    false,
    "MessageId",
    false
);

# get total count of Messages
$H_MessageCount = count($MessageIds);

# get current Message index
$H_StartingIndex = StdLib::getFormValue(TransportControlsUI::PNAME_STARTINGINDEX, 0);

# calculate ID array checksum and reset paging if list has changed
$H_ListChecksum = md5(serialize($MessageIds));
if ($H_ListChecksum != StdLib::getFormValue("CK")) {
    $H_StartingIndex = 0;
}

# prune message IDs down to currently-selected segment
$H_ItemsPerPage = 25;
$MessageIds = array_slice($MessageIds, $H_StartingIndex, $H_ItemsPerPage);

# load messages from IDs
$H_Messages = array();
foreach ($MessageIds as $MessageId) {
    $H_Messages[$MessageId] = new Message($MessageId);
}
