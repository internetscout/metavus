<?PHP
#
#   FILE:  GoTo.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus;
use ScoutLib\ApplicationFramework;

# request that this page not be indexed by search engines
$AF = ApplicationFramework::getInstance();
$AF->addMetaTag(["robots" => "noindex"]);

# if resource ID was specified
if (isset($_GET["ID"])) {
    # grab resource ID
    $ResourceId = $_GET["ID"];

    # if resource ID is valid
    if (Record::itemExists($ResourceId)) {
        # if metadata field was specified
        $Resource = new Record($ResourceId);
        if (isset($_GET["MF"])) {
            # if specified metadata field is valid
            if ($Resource->getSchema()->fieldExists($_GET["MF"])) {
                # use specified metadata field
                $FieldId = $_GET["MF"];
            }
        } else {
            # if there is a standard URL field for resource
            $StdUrlFieldId = $Resource->getSchema()->stdNameToFieldMapping("Url");
            if ($StdUrlFieldId !== null) {
                # use standard URL field
                $FieldId = $StdUrlFieldId;
            }
        }

        # if we have a metadata field ID
        if (isset($FieldId)) {
            # if URL field was specified and user has permission to view it
            $Field = $Resource->getSchema()->getField($FieldId);
            if (($Field->type() == MetadataSchema::MDFTYPE_URL)
                    && ($Resource->userCanViewField(User::getCurrentUser(), $Field))) {
                # load URL to go to
                $Url = $Resource->get($Field);

                # allow plugins to modify the value
                $SignalResult = $AF->signalEvent(
                    "EVENT_FIELD_DISPLAY_FILTER",
                    [
                        "Field" => $Field,
                        "Resource" => $Resource,
                        "Value" => $Url
                    ]
                );
                $Url = $SignalResult["Value"];

                # don't jump to URLs that appear to be invalid
                if (filter_var($Url, FILTER_VALIDATE_URL) === false) {
                    unset($Url);
                }
            }
        }
    }
}

# if we found a URL, redirect to it
if (isset($Url) && isset($Resource) && isset($Field)) {
    # signal URL click event
    $AF->signalEvent("EVENT_URL_FIELD_CLICK", [
        "ResourceId" => $Resource->id(),
        "FieldId" => $Field->id()
    ]);

    # go to page specified by URL
    $AF->setJumpToPage($Url);
    return;
}

# otherwise, return a 404 and let our HTML report the error
header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
