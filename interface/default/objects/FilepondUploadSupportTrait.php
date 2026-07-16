<?PHP
#
#   FILE:  FilepondUploadSupportTrait.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;

/**
 * FilePond upload support for FormUI.
 */
trait FilepondUploadSupportTrait
{
    /**
     * Clean up data from incomplete or canceled downloads in the FilePond
     * upload directory.
     */
    public static function cleanFilePondUploadDir() : void
    {
        # nothing to do when FilePond upload dir does not (yet) exist
        if (!is_dir(self::$UploadDir)) {
            return;
        }

        # directories where we've not added a new chunk of data in the last
        # MaxAge seconds are assumed to belong to canceled or interrupted
        # downloads
        $MaxAge = 3600;
        $Now = time();

        $DirsToDelete = [];

        $DirEntries = scandir(self::$UploadDir);
        if ($DirEntries === false) {
            throw new Exception(
                "scandir() on ".self::$UploadDir." failed"
                    ." (should be impossible)."
            );
        }
        foreach ($DirEntries as $Entry) {
            if ($Entry == "." || $Entry == "..") {
                continue;
            }

            # skip dirs that aren't named with a Filepond Transfer ID
            if (preg_match(self::$TransferIdRegex, $Entry) !== 1) {
                continue;
            }

            $TargetPath = self::$UploadDir."/".$Entry;

            # skip symlinks
            if (is_link($TargetPath)) {
                continue;
            }

            # skip non-directories
            if (!is_dir($TargetPath)) {
                continue;
            }

            # if a file was added to the dir within the last MaxAge seconds
            # (which is what mtime means for dirs), then skip it
            if ($Now - filemtime($TargetPath) < $MaxAge) {
                continue;
            }

            # otherwise, it should be deleted
            $DirsToDelete [] = $TargetPath;
        }

        foreach ($DirsToDelete as $Dir) {
            $DirEntries = scandir($Dir);
            if ($DirEntries === false) {
                throw new Exception(
                    "scandir() on ".$Dir." failed"
                        ." (should be impossible)."
                );
            }
            foreach ($DirEntries as $File) {
                $TargetPath = $Dir."/".$File;
                if (is_file($TargetPath)) {
                    unlink($TargetPath);
                }
            }

            rmdir($Dir);
        }
    }

    /**
     * Output Javascript to use the FilePond upload library.
     * @param string $FormTableId ID of table containing the form to disable
     *     submit buttons on once one of them is clicked.
     */
    protected function printFilepondJavascript(
        string $FormTableId
    ): void {
        static $Initialized = false;
        if (!$Initialized) {
            $AF = ApplicationFramework::getInstance();
            $SysConfig = SystemConfiguration::getInstance();

            $AF->requireUIFile("filepond.min.css");
            $AF->requireUIFile("filepond.js");
            $AF->requireUIFile("filepond.jquery.js");
            ?>
            <script type="text/javascript">
            $(document).ready(function() {
                FilePond.setOptions({
                    server: {
                        url: '<?= $AF->baseUrl() ?>lib/FilePond/server/index.php'
                    },
                    chunkUploads: true,
                    chunkSize: <?= $SysConfig->getInt("UploadChunkSize") * 1024 * 1024 ?>,
                    credits: false
                });
            });
            </script>
            <?PHP
            $Initialized = true;
        }
        ?>
        <script type="text/javascript">
        $(document).ready(function() {
            var Form = $("#<?= $FormTableId ?>").parents("form").first();

            $("input[type='file']", Form).filepond();
            $("button[type='submit'][value='Upload']", Form).hide();

            // on upload start, add 'data-clicked' to trigger the "lockout"
            // from printDoubleClickSubmitLockoutJavascript() so that users
            // can't submit the form before the upload completes
            Form.on('FilePond:processfilestart', function(Event) {
                Form.data("clicked", true);
                Form.data("upload-in-progress", true);
            });

            // on upload completion
            Form.on('FilePond:processfile', function(Event) {
                Form.data("clicked", false);
                Form.data("upload-in-progress", false);
                var Row = $(Event.target).parents("tr.mv-content-tallrow");
                if (Event.detail.error === null) {
                    $("button[type='submit'][value='Upload']", Row).click();
                }
            });

            // Add a 'beforeunload' handler to attempt to prevent the user
            // from navigating away from the page while an upload is in
            // progress
            // (cf. https://developer.mozilla.org/en-US/docs/Web/API/Window/
            // beforeunload_event )
            $(window).on('beforeunload', function(Event) {
                if (Form.data("upload-in-progress")) {
                    Event.preventDefault();
                    Event.returnValue = true;
                }
            });
        });
        </script>
        <?PHP
    }

    /**
     * Check for an upload via the FilePond upload library for a given form
     * field, returning the path to the uploaded file if there was one.
     * @param string $FormFieldName Form field name to check.
     * @return string|null Name of uploaded file or NULL when there was not one.
     * @throws Exception on scandir() failure.
     * @throws Exception on multiple files in one upload directory (not
     *     possible with our FilePond configuration).
     * @see https://pqina.nl/filepond/
     */
    protected function preprocessFilepondUpload(string $FormFieldName): ?string
    {
        if (!isset($_POST[$FormFieldName])) {
            return null;
        }

        # look in the `transfer` dir configured by lib/filepond/config.php,
        # which contains completed uploads
        $FilepondTransferDir = $this->getFilepondTransferDir(
            $_POST[$FormFieldName]
        );

        $FilesToSkip = [".htaccess", ".metadata",];

        $Files = [];
        $DirEntries = scandir($FilepondTransferDir);
        if ($DirEntries === false) {
            throw new Exception(
                "scandir() on ".$FilepondTransferDir." failed"
                    ." (should be impossible)."
            );
        }
        foreach ($DirEntries as $File) {
            # skip non-file entries
            if (!is_file($FilepondTransferDir."/".$File)) {
                continue;
            }

            if (in_array($File, $FilesToSkip)) {
                continue;
            }

            $Files[] = $FilepondTransferDir."/".$File;
        }

        if (count($Files) == 0) {
            throw new Exception(
                "No files present in a FilePond upload directory"
                    ." (should be impossible)."
            );
        }

        if (count($Files) > 1) {
            throw new Exception(
                "Multiple files in a FilePond upload directory"
                    ." (should be impossible)."
            );
        }

        $TmpFile = array_shift($Files);

        return $TmpFile;
    }

    /**
     * Get the validated transfer directory for a FilePond upload.
     * @param string $TransferId Transfer ID submitted by FilePond.
     * @return string Path to validated transfer directory.
     * @throws Exception when the transfer ID or directory is invalid.
     */
    protected function getFilepondTransferDir(string $TransferId): string
    {
        # reject empty transfer ids
        if (strlen($TransferId) == 0) {
            throw new Exception(
                "No filename provided for FilePond upload "
                    ." (should be impossible)."
            );
        }

        # reject path traversal, separators, and other unexpected characters
        if (preg_match(self::$TransferIdRegex, $TransferId) !== 1) {
            throw new Exception("Invalid FilePond upload transfer identifier.");
        }

        # verify that the configured upload root exists
        $BaseDir = realpath(self::$UploadDir);
        if ($BaseDir === false) {
            throw new Exception("FilePond upload directory does not exist.");
        }

        # verify that the requested transfer directory exists
        $TransferDir = realpath(self::$UploadDir."/".$TransferId);
        if ($TransferDir === false || !is_dir($TransferDir)) {
            throw new Exception(
                "FilePond upload transfer directory does not exist."
            );
        }

        # verify that the transfer directory remains inside the upload root
        $BaseDirWithSeparator = rtrim($BaseDir, DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR;
        if (strncmp(
            $TransferDir,
            $BaseDirWithSeparator,
            strlen($BaseDirWithSeparator)
        ) !== 0) {
            throw new Exception("Invalid FilePond upload transfer directory.");
        }

        return $TransferDir;
    }

    /**
     * Clean up after FilePond uploads.
     * @param string $FormFieldName Form field name to check.
     */
    protected function postprocessFilepondUpload(string $FormFieldName): void
    {
        if (!isset($_POST[$FormFieldName])
                || strlen($_POST[$FormFieldName]) == 0) {
            return;
        }

        # try to get transfer dir, bailing if this fails
        try {
            $TargetDir = $this->getFilepondTransferDir($_POST[$FormFieldName]);
        } catch (Exception $Ex) {
            return;
        }

        # clean up uploads
        $DirEntries = scandir($TargetDir);
        if ($DirEntries === false) {
            throw new Exception(
                "scandir() on ".$TargetDir." failed"
                    ." (should be impossible)."
            );
        }
        foreach ($DirEntries as $Entry) {
            $TargetPath = $TargetDir."/".$Entry;
            if (is_file($TargetPath)) {
                unlink($TargetPath);
            }
        }

        rmdir($TargetDir);

        self::cleanFilePondUploadDir();
    }

    # (support for constants in traits was added in php 8.2; convert to constants
    # when that becomes our minimum version.)
    private static $UploadDir = "tmp/FilePondUploads";
    private static $TransferIdRegex = '/^[0-9a-f]{32}$/i';
}
