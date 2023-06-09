<?PHP
#
#   FILE:  Download.php (ResourceExporter plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

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

# if file secret was supplied and is valid
if (array_key_exists("FS", $_GET)) {
    # if file secret is valid
    $Plugin = $GLOBALS["G_PluginManager"]->GetPluginForCurrentPage();
    $FileInfo = $Plugin->GetExportedFileInfo($_GET["FS"]);
    if ($FileInfo !== null) {
        # if current user is the one who created the file and file exists
        if ((User::getCurrentUser()->Id() == $FileInfo["ExporterId"]) &&
            (is_readable($FileInfo["LocalFileName"]))) {
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

            # send file to user, unbuffered to avoid memory issues
            $AF = ApplicationFramework::getInstance();
            $AF->AddUnbufferedCallback("OutputFile", [$FileName]);

            # turn off display of HTML template
            $AF->SuppressHTMLOutput();
        }
    }
}

# (if a download was not successfully set up, the HTML template will be loaded instead)
