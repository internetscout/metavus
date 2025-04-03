<?PHP
#
#   FILE:  StoredFile.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

/**
 * Common functionality for classes that store files.
 */
trait StoredFile
{
    # functions we need classes to implement

    /**
     * Return the ItemId for this object.
     * @return int ItemId.
     */
    abstract public function id() : int;

    /**
     * Return the file name for this object.
     * @return string File name.
     */
    abstract protected function getFileName() : string;

    /**
     * Get the database for this object. (Need to do this rather than just
     * 'new Database()' in order to have the correct UpdateValue parameters so
     * that DB->updateXX() calls will work.)
     * @return Database The database.
     */
    abstract protected function getDB() : Database;

    /**
     * Verify that the stored file still has the same length and checksum as
     * when it was stored.
     * @return bool TRUE when the length/checksum match, FALSE otherwise.
     */
    public function checkFixity() : bool
    {
        $DB = $this->getDB();
        $StoredFileLength = $DB->updateIntValue("FileLength");
        $StoredFileChecksum = $DB->updateValue("FileChecksum");
        $FileName = $this->getFileName();

        # if the file does not exist (e.g., because we're in a sandbox) or if
        # it cannot be read, then we can't check fixity
        if (!is_readable($FileName)) {
            return false;
        }

        $FileLength = @filesize($FileName);

        # if we couldn't get the file length because of an error, can't check
        # fixity
        if ($FileLength === false) {
            return false;
        }

        $DB->updateDateValue("ContentLastChecked", "now");
        if ($FileLength != $StoredFileLength) {
            $DB->updateBoolValue("ContentUnchanged", false);
            return false;
        }

        $FileHash = hash_file(self::$ChecksumAlgo, $FileName);
        if ($FileHash != $StoredFileChecksum) {
            $DB->updateBoolValue("ContentUnchanged", false);
            return false;
        }

        return true;
    }

    /**
     * If this file lacks a checksum, compute one.
     * @return void
     */
    public function populateChecksum(): void
    {
        $DB = $this->getDB();
        $FileName = $this->getFileName();
        $CurrentChecksum = $DB->updateValue("FileChecksum");

        # if we already have a checksum, bail
        if (is_string($CurrentChecksum) && strlen($CurrentChecksum) > 0) {
            return;
        }

        # if the file does not exist (e.g., because we're in a sandbox) or if
        # it cannot be read, then we can't populate the checksum
        if (!is_readable($FileName)) {
            return;
        }

        # otherwise, compute and store a checksum
        $FileChecksum = hash_file(
            self::$ChecksumAlgo,
            $this->getFileName()
        );

        $DB->updateValue("FileChecksum", $FileChecksum);
        $DB->updateDateValue("ContentLastChecked", "now");
        $DB->updateBoolValue("ContentUnchanged", true);
    }

    /**
     * If this file lacks a stored length, attempt to determine and add one.
     * @return void
     */
    public function populateLength(): void
    {
        $DB = $this->getDB();
        $FileName = $this->getFileName();
        $StoredFileLength = $DB->updateIntValue("FileLength");

        # if we already have a stored length, bail
        if ($StoredFileLength != 0) {
            return;
        }

        # if the file does not exist (e.g., because we're in a sandbox) or if
        # it cannot be read, then we can't populate the length
        if (!is_readable($FileName)) {
            return;
        }

        $DB->updateIntValue(
            "FileLength",
            filesize($FileName)
        );
    }

    /**
     * Determine if the underlying file exists in the filesystem.
     * @return bool TRUE when file exists, FALSE otherwise (e.g., in a
     *   development sandbox).
     */
    public function fileExists(): bool
    {
        return file_exists($this->getFileName());
    }

    /**
     * Perform initial population of the file length and checksum information. If the file is small
     * @return void
     */
    protected function storeFileLengthAndChecksum(): void
    {
        $DB = $this->getDB();

        $FileName = $this->getFileName();
        $FileSize = filesize($FileName);

        # as of early 2020, computing a sha256sum on our production servers
        # takes < 1 s for files smaller than about 150 MiB; do those
        # in the foreground but queue a background task for any files that are
        # larger
        if ($FileSize < (150 * 1024 * 1024)) {
            $FileChecksum = hash_file(self::$ChecksumAlgo, $FileName);
        } else {
            $FileChecksum = "";
            $Callback = __CLASS__."::callMethod";
            if (!is_callable($Callback)) {
                throw new Exception(
                    "Required method ".$Callback." is not defined."
                );
            }
            ApplicationFramework::getInstance()
                ->queueUniqueTask(
                    $Callback,
                    [$this->id(), "populateChecksum"],
                    \ScoutLib\ApplicationFramework::PRIORITY_LOW,
                    "Populate checksum for ".__CLASS__." Id ".$this->id()
                );
        }

        $DB->updateIntValue("FileLength", $FileSize);
        $DB->updateValue("FileChecksum", $FileChecksum);
        $DB->updateDateValue("ContentLastChecked", "now");
        $DB->updateIntValue("ContentUnchanged", 1);
    }

    private static $ChecksumAlgo = "sha256";
    # use sha256 because it is reasonably fast, widely supported, and not currently broken
}
