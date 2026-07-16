<?PHP
#
#   FILE:  Download.php (RecordExporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\Plugins\RecordExporter;
use ScoutLib\ApplicationFramework;
use Metavus\User;

# ----- EXPORTED FUNCTIONS ---------------------------------------------------

function OutputFile(string $Path): void
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

# ----- MAIN -----------------------------------------------------------------

$AF = ApplicationFramework::getInstance();
$Plugin = RecordExporter::getInstance();

# if no file secret provided, bail
if (!array_key_exists("FS", $_GET)) {
    return;
}

$FileInfo = $Plugin->getExportedFileInfo($_GET["FS"]);

# if file secret was invalid, bail
if ($FileInfo === null) {
    return;
}

# if file is owned by another user or is unreadable, bail
if ((User::getCurrentUser()->id() != $FileInfo["ExporterId"]) ||
    (!is_readable($FileInfo["LocalFileName"]))) {
    return;
}

# set headers to download file
$FileName = $FileInfo["LocalFileName"];
$MimeType = @mime_content_type($FileName);
if ($MimeType === false) {
    $MimeType = "application/octet-stream";
}
header("Content-Type: ".$MimeType);
header("Content-Length: ".filesize($FileName));
header('Content-Disposition: attachment; filename="'
       .basename($FileInfo["FileName"]).'"');

# turn off display of HTML template
$AF->suppressHtmlOutput();

# send file to user, unbuffered to avoid memory issues
$AF->addUnbufferedCallback("OutputFile", [$FileName]);
