<?PHP
#
#   FILE:  FullRecord.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();

$RecordId = $_GET["ID"] ?? null;
$H_Record = Record::itemExists($RecordId) ? new Record($RecordId) : false;

# if provided ID was invalid, tell AF not to cache page
if ($H_Record === false) {
    $AF->doNotCacheCurrentPage();
    return;
}

# if we have a record
$CurrentUser = User::getCurrentUser();

# check to make sure that current user has permission to view record
if ($H_Record->userCanView($CurrentUser) == false) {
    $H_Record = false;
    $AF->doNotCacheCurrentPage();
    return;
}

# redirect to correct URL if this is not the right viewing page for record
$ViewUrl = $H_Record->getSchema()->getViewPage();
$ViewUrlArgString = parse_url($ViewUrl, PHP_URL_QUERY);
$ViewUrl = str_replace('$ID', (string)$H_Record->id(), $ViewUrl);
if ($ViewUrlArgString !== false) {
    parse_str($ViewUrlArgString, $ViewUrlArgs);
    if (isset($ViewUrlArgs["P"]) && ($ViewUrlArgs["P"] != "FullRecord")) {
        if ($AF->cleanUrlSupportAvailable()) {
            $ViewUrl = $AF->getCleanRelativeUrlForPath($ViewUrl);
        }
        $AF->setJumpToPage(ApplicationFramework::baseUrl().$ViewUrl);
    }
}

# set expiration date for cached version of page if viewing permission
#       depends on a Timestamp field
$ExpDate = $H_Record->getViewCacheExpirationDate();
if ($ExpDate !== false) {
    $AF->expirationDateForCurrentPage($ExpDate);
}

# only record view when not jumping to another page
if (!$AF->jumpToPageIsSet()) {
    # signal full record page view
    $AF->signalEvent("EVENT_FULL_RECORD_VIEW", ["ResourceId" => $H_Record->id()]);
}
