<?PHP
#
#   FILE:  GetMarker.php (GoogleMaps plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2019-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

# ----- MAIN -----------------------------------------------------------------
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * Convert hex colors to RGB.
 * @param string $Hex Color information
 * @return array Array with R, G, and B members.
 */
function hexToRgb($Hex)
{
    return [
        "R" => hexdec($Hex[0].$Hex[1]),
        "G" => hexdec($Hex[2].$Hex[3]),
        "B" => hexdec($Hex[4].$Hex[5]),
    ];
}

$FgHex = strtolower(StdLib::getArrayValue($_GET, "FG", "ffffff"));
$BgHex = strtolower(StdLib::getArrayValue($_GET, "BG", "000000"));
$Text = StdLib::getArrayValue($_GET, "T", "");

$AF = ApplicationFramework::getInstance();
$Plugin = PluginManager::getInstance()->getPluginForCurrentPage();

# if we have a cached copy of this marker, serve the request from cache
$CachedFile = $Plugin->getMarkerFilePath($Text, $BgHex, $FgHex);

header('Content-type: image/png');
$AF->suppressHTMLOutput();
if (file_exists($CachedFile)) {
    $AF->AddUnbufferedCallback("readfile", [$CachedFile]);
    return;
}

# otherwise we'll need to generate the marker
$FG = hexToRgb($FgHex);
$BG = hexToRgb($BgHex);

# disable url fingerprinting so that gUIFile() will give paths we can use
$AF->urlFingerprintingEnabled(false);

# load the shadow background
$Canvas = imagecreatefrompng(
    getcwd()."/".$AF->gUIFile("marker-shadow.png")
);
imagesavealpha($Canvas, true);

# load the the template marker
$Img = imagecreatefrompng(
    getcwd()."/".$AF->gUIFile("marker-black.png")
);

# colorize it
imagefilter($Img, IMG_FILTER_COLORIZE, $BG["R"], $BG["G"], $BG["B"]);

# add the text overlay
$FgColor = imagecolorallocatealpha($Img, $FG["R"], $FG["G"], $FG["B"], 0);
imagestring($Img, 3, 5, 0, $Text, $FgColor);

# merge our foreground on to the canvas
imagecopy(
    $Canvas,
    $Img,
    0,
    0,
    0,
    0,
    imagesx($Img),
    imagesy($Img)
);

# load the marker outline
$Outline = imagecreatefrompng(
    getcwd()."/".$AF->gUIFile("marker-outline.png")
);

# merge the outline on to our image
imagecopy(
    $Canvas,
    $Outline,
    0,
    0,
    0,
    0,
    imagesx($Outline),
    imagesy($Outline)
);

# generate the png
ob_start();
imagepng($Canvas);
$Data = ob_get_contents();
ob_end_clean();

# save and then output the generated image
file_put_contents($CachedFile, $Data);
print $Data;
