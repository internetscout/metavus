<?PHP
#
#   FILE:  Launch.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan
#
# Handle LTI Launch requests. See docstring for EduLink plugin for a
# description of the request flow.
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_RecordIds - List of Record IDs that should be displayed

namespace Metavus;

use Exception;
use Metavus\Plugins\EduLink;
use Metavus\Plugins\EduLink\LTIDatabase;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\MetricsRecorder;
use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$H_Plugin = EduLink::getInstance();

# we never want Metavus framing on this page
$AF->suppressStandardPageStartAndEnd();

# get and validate the incoming message launch
$Launch = $H_Plugin->getNewLaunch();
try {
    $Launch->validate();
} catch (Exception $Ex) {
    # clear 'enabled' cookie so that the next time through the Login flow will
    # use the JS-based redirect that also hits the Storage Access API to gain
    # permission to set third-party cookies like the ones used to store LTI
    # states (see P_EduLink_Login.js for more details)

    # (cookie needs to be cleared using Set-Cookie via header() rather than
    # setcookie() to match the Secure and SameSite flags used in the JS)
    header(
        'Set-Cookie: lti1p3_enabled=0; Secure; SameSite=none; '
        .'Expires=Thu, 01 Jan 1970 00:00:00 GMT'
    );

    # tell the user what happened
    $H_Error = "ERROR_VALIDATE_LAUNCH";
    $H_DebuggingInfo = "Could not validate LTI launch."
        ." Error from LTI library was: ".$Ex->getMessage()."\n"
        .$Ex->getTraceAsString();

    # and log an error about it with some diagnostic info
    $AF->logMessage(
        ApplicationFramework::LOGLVL_INFO,
        "Could not validate LTI launch."
        ." Error: '".$Ex->getMessage()."'"
        ." IP: " . ($_SERVER["REMOTE_ADDR"] ?? "(unknown)")
        ." User-Agent: '".($_SERVER["HTTP_USER_AGENT"] ?? "(unknown)")."'"
        ." Cookie: '".($_SERVER["HTTP_COOKIE"] ?? "{NULL}")."'"
    );
    return;
}

# if this is a deep linking request, bounce to resource selection page
if ($Launch->is_deep_link_launch()) {
    $AF->setJumpToPage(
        $AF->baseUrl()."index.php"
            ."?P=P_EduLink_LTIHome"
            ."&L=".$Launch->get_launch_id()
    );
    return;
}

# if this is NOT a request for a previously linked resource, then we don't
# know how to handle it
if (!$Launch->is_resource_launch()) {
    throw new Exception(
        "Unsupported or unknown launch type."
    );
}

$UriKey = "https://purl.imsglobal.org/spec/lti/claim/target_link_uri";
$Data = @$Launch->get_launch_data();
if (!is_array($Data) || !isset($Data[$UriKey])) {
    throw new Exception(
        "No target_link_uri provided (should be impossible)."
    );
}

# determine which resource was requested
$TargetLinkUri = str_replace($AF->baseUrl(), "", $Data[$UriKey]);
$Result = preg_match("%^lti/dl_([rlf])/(.*)$%", $TargetLinkUri, $Matches);
if (!$Result) {
    throw new Exception(
        "Invalid target_link_uri format. Cannot determine what record(s) are requested. "
            ."Link was: ".$TargetLinkUri
    );
}

# allow browser to try https for http links when page was loaded via https
# (often Just Works rather than failing to load iframes while producing
# a 'mixed content' security warning that most users don't even know how to
# look for)
header('Content-Security-Policy: upgrade-insecure-requests');

$LinkType = $Matches[1];
$LinkData = $Matches[2];

switch ($LinkType) {
    case "r":
        if (preg_match('%^v1/([0-9]+)$%', $LinkData, $Matches)) {
            $RecordId = $Matches[1];

            $H_RecordIds = [ $RecordId ];
        } else {
            throw new Exception("Unsupported URL format.");
        }
        break;

    case "f":
        if (preg_match('%^v1/([0-9]+)$%', $LinkData, $Matches)) {
            $Folder = new Folder((int)$Matches[1]);

            $ItemIds = RecordFactory::buildMultiSchemaRecordList($Folder->getItemIds());
            $H_RecordIds = $ItemIds[MetadataSchema::SCHEMAID_DEFAULT] ?? [];
        } else {
            throw new Exception("Unsupported URL format.");
        }
        break;

    case "l":
        # version prefix is handled by decodeRecordList
        $H_RecordIds = $H_Plugin->decodeRecordList($Matches[2]);
        break;

    default:
        throw new Exception(
            "Unknown linking type (should be impossible)."
        );
}

$H_RecordIds = RecordFactory::multiSchemaFilterNonViewableRecords(
    $H_RecordIds,
    User::getAnonymousUser()
);

$H_Plugin->recordRecordViewing($Launch->get_launch_id(), $H_RecordIds);
