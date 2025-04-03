<?PHP
#
#   FILE:  HandleInfoRequest.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\IIIFImageServer;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\RasterImageFile;

# ----- MAIN -----------------------------------------------------------------

# handle IIIF image information requests
# @see https://iiif.io/api/image/3.0/#5-image-information

$AF = ApplicationFramework::getInstance();
$MyPlugin = IIIFImageServer::getInstance();

# ensure required parameters were set
$RequiredParameters = [
    "ID",
];
foreach ($RequiredParameters as $Parameter) {
    if (!isset($_GET[$Parameter])) {
        header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request");
        print "400 Bad Request - ".$Parameter." not provided.";
        return;
    }
}

# handle CORS preflight requests
# @see https://iiif.io/api/image/3.0/#51-image-information-request
# @see https://developer.mozilla.org/en-US/docs/Glossary/Preflight_request
if (isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header($_SERVER["SERVER_PROTOCOL"]." 204 No Content");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    return;
}

$ID = $_GET["ID"];

$Image = new RasterImageFile(
    (new Image($ID))->getFullPathForOriginalImage()
);


# @see https://iiif.io/api/image/3.0/#52-technical-properties
$Result = [
    "@context" => "http://iiif.io/api/image/3/context.json",
    "id" => ApplicationFramework::baseUrl()."iiif/".$ID,
    "type" => "ImageService3",
    "protocol" => "http://iiif.io/api/image",
    "profile" => "level2",
    "width" => $Image->getXSize(),
    "height" => $Image->getYSize(),
    "extraFormats" => ["png", "jpg"],
    "preferredFormats" => ["jpg"],
];

$AF->beginAjaxResponse();
print json_encode($Result);
