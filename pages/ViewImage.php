<?PHP
#
#   FILE:  ViewImage.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\User;
use ScoutLib\ApplicationFramework;

$AF = ApplicationFramework::getInstance();

/**
* Output the bytes of the file to the browser.
* @param string $Path Path to the file.
*/
function OutputFile($Path) : void
{
    $Handle = @fopen($Path, "rb");

    if (false === $Handle) {
        # couldn't open the file, just return to avoid further errors
        return;
    }

    while (!feof($Handle)) {
        # send the file in 500 KB chunks
        echo fread($Handle, 512000);
        flush();
    }

    fclose($Handle);
}

/**
 * Set a 404 header, to be used for errors.
 */
function Set404() : void
{
    header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
}

# ----- MAIN -----------------------------------------------------------------

# close the session to avoid serializing image loads
session_write_close();
$AF->suppressHtmlOutput();

# make sure required parameters are specified
if (!isset($_GET["RI"]) || !isset($_GET["FI"]) ||
    !isset($_GET["IX"]) || !isset($_GET["T"])) {
    Set404();
    return;
}

# pull out the requested resource
if (!Record::itemExists($_GET["RI"])) {
    Set404();
    return;
}
$Resource = new Record($_GET["RI"]);

# error out if field was invalid
if (!$Resource->getSchema()->fieldExists($_GET["FI"])) {
    Set404();
    return;
}

$Field = $Resource->getSchema()->getField($_GET["FI"]);

# error out if user cannot view this field
if (!$Resource->userCanViewField(User::getCurrentUser(), $Field)) {
    Set404();
    return;
}

# pull out requested index
$Index = intval($_GET["IX"]);

# retrieve images for this field
$Images = $Resource->get($Field, true);

# error out if requested image did not exist
if (count($Images) <= $Index) {
    Set404();
    return;
}

# pull out the desired image
$Images = array_slice($Images, $Index, 1);
$Image = current($Images);

$ImageSize = $_GET["T"];

# if this was a legacy size
if (in_array($ImageSize, ["t", "p", "f"])) {
    switch ($ImageSize) {
        case "t":
            $Filepath = $Image->url("mv-image-thumbnail");
            $Size = Image::SIZE_THUMBNAIL;
            break;

        case "p":
            $Filepath = $Image->url("mv-image-preview");
            $Size = Image::SIZE_PREVIEW;
            break;

        case "f":
            $Filepath = $Image->url("mv-image-large");
            $Size = Image::SIZE_FULL;
            break;

        default:
            # should be impossible, but include for completeness
            throw new \Exception(
                "Unknown image size requested."
            );
    }

    $Resource->getLegacyPersistentImageUrls($Field, $Size);
} else {
    $Filepath = $Image->url("mv-image-".$ImageSize);
    $Resource->getPersistentImageUrls($Field, "mv-image-".$ImageSize);
}

# send file to user, but unbuffered to avoid memory issues
putenv('no-gzip=1');
header("Content-Type: ".$Image->mimeType());
$AF->addUnbufferedCallback(
    __NAMESPACE__.'\OutputFile',
    [$Filepath]
);
