<?PHP
#
#   FILE:  HandleImageRequest.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\IIIFImageServer;
use Metavus\Plugins\IIIFImageServer\IIIFError;
use ScoutLib\ApplicationFramework;


# ----- MAIN -----------------------------------------------------------------

# handle IIIF image requests
# @see https://iiif.io/api/image/3.0/#4-image-requests

$AF = ApplicationFramework::getInstance();
$AF->suppressHtmlOutput();

$ImageServer = IIIFImageServer::getInstance();

# check that required parameters were provided
$RequiredParameters = [
    "ID",
    "Region",
    "Size",
    "Rotation",
    "Quality",
    "Format",
];
foreach ($RequiredParameters as $Parameter) {
    if (!isset($_GET[$Parameter])) {
        header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
        print "400 Bad Request - ".$Parameter." not provided.";
        return;
    }
}

# if this request is a CORS preflight, handle it and then exit
if ($ImageServer->handleCorsPreflightRequest()) {
    return;
}

$DstPath = $ImageServer->getPathToImageFileForParams(
    (int)$_GET["ID"],
    $_GET["Region"],
    $_GET["Size"],
    $_GET["Rotation"],
    $_GET["Quality"],
    $_GET["Format"]
);

# if there was an error, report it to the user
if ($DstPath instanceof IIIFError) {
    $DstPath->reportError();
    return;
}

# otherwise, serve the image to the user
$AF->downloadFile($DstPath);
