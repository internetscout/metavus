<?PHP
#
#   FILE:  File.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2010-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\Database;
use ScoutLib\Item;
use ScoutLib\PluginManager;

/**
 * Class representing a stored (usually uploaded) file.
 */
class File extends Item
{
    use StoredFile;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    # status codes (set by constructor and returned by File::Status())
    const FILESTAT_OK =             0;
    const FILESTAT_COPYERROR =      1;
    const FILESTAT_PARAMERROR =     2;
    const FILESTAT_ZEROLENGTH =     3;
    const FILESTAT_DOESNOTEXIST =   4;
    const FILESTAT_UNREADABLE =     5;

    /**
     * Create a new File object using an existing file, moving the source if
     *       directed to do so, hardlinking when the source and destination
     *       are in the same directory, or copying the source otherwise.
     * @param string $SourceFile Name of existing file, with absolute or
     *       relative leading path, if needed.
     * @param string $DesiredFileName Desired name for file (if not the same
     *       as the existing name).  (OPTIONAL).
     * @param bool $MoveSourceFile TRUE if the source file should be moved
     *       to file storage rather than copied.
     * @return File|int New File object or error code if creation failed.
     */
    public static function create(
        string $SourceFile,
        string $DesiredFileName = null,
        bool $MoveSourceFile = true
    ) {
                # check that file exists
        if (!file_exists($SourceFile)) {
            return self::FILESTAT_DOESNOTEXIST;
        }

        # check that file is readable
        if (!is_readable($SourceFile)) {
            return self::FILESTAT_UNREADABLE;
        }

        # check that file is not zero length
        $FileLength = filesize($SourceFile);
        if (!$FileLength) {
            return self::FILESTAT_ZEROLENGTH;
        }

        # generate secret string (used to protect from unauthorized download)
        srand(intval((double)microtime() * 1000000));
        $SecretString = sprintf("%04X", rand(1, 30000));

        # get next file ID by adding file to database
        $DB = new Database();
        $DB->query("INSERT INTO Files (SecretString) VALUES ('".$SecretString."')");
        $FileId = $DB->getLastInsertId();

        # build name for stored file
        $BaseFileName = ($DesiredFileName === null)
                ? basename($SourceFile) : basename($DesiredFileName);
        $StoredFile = sprintf(
            self::getStorageDirectory()."/%06d-%s-%s",
            $FileId,
            $SecretString,
            $BaseFileName
        );

        if ($MoveSourceFile) {
            # move file if requested
            $Result = rename($SourceFile, $StoredFile);
        } elseif (dirname((string)realpath($SourceFile)) ==
                  dirname((string)realpath($StoredFile))) {
            # if source is already in file storage, use a hardlink to conserve space
            $Result = link($SourceFile, $StoredFile);
        } else {
            # otherwise, copy to file storage
            $Result = copy($SourceFile, $StoredFile);
        }

        # if copy attempt failed
        if ($Result === false) {
            # remove file from database
            $DB->query("DELETE FROM Files WHERE FileId = ".$FileId);

            # report error to caller
            return self::FILESTAT_COPYERROR;
        }

        # attempt to get file type
        $FileType = self::determineFileType($StoredFile);

        # save file info in database
        $DB->query("UPDATE Files SET"
                ." FileName = '".addslashes($BaseFileName)."',"
                ." FileType = '".addslashes($FileType)."'"
                ." WHERE FileId = ".$FileId);

        # create file
        $File = new File($FileId);
        $File->storeFileLengthAndChecksum();

        return $File;
    }

    /**
     * Create copy of File object.  The copy will have a new ID, but will
     * otherwise be identical.
     * @return File Copy of object.
     */
    public function duplicate(): File
    {
        $Copy = self::create(
            $this->getNameOfStoredFile(),
            $this->name(),
            false
        );
        if (!$Copy instanceof self) {
            throw new Exception("Copy failed with error ".$Copy);
        }
        $Copy->resourceId($this->resourceId());
        $Copy->fieldId($this->fieldId());
        return $Copy;
    }

    /**
     * Gets the length of the file.
     * @return int The length of the file.
     */
    public function getLength(): int
    {
        return $this->DB->updateIntValue("FileLength");
    }

    /**
     * Gets the file's type.
     * @return string The file's type.
     */
    public function getType(): string
    {
        return $this->DB->updateValue("FileType");
    }

    /**
     * Gets or sets the comment on the file.
     * @param string $NewValue The new comment on the file.  (OPTIONAL)
     * @return string The comment on the file.
     */
    public function comment(string $NewValue = null): string
    {
        return $this->DB->updateValue("FileComment", $NewValue);
    }

    /**
     * Gets or sets the field ID of the File.
     * @param int $NewValue The new field ID of the File.  (OPTIONAL)
     * @return int The field ID of the File.
     */
    public function fieldId(int $NewValue = null): int
    {
        return $this->DB->updateIntValue("FieldId", $NewValue);
    }

    /**
     * Gets or sets the resource ID of the File.
     * @param int $NewValue The new resource ID of the File.  (OPTIONAL)
     * @return int The resource ID of the File.
     */
    public function resourceId(int $NewValue = null): int
    {
        return $this->DB->updateIntValue("RecordId", $NewValue);
    }

    /**
     * Gets the MIME type of the file.
     * @return string The MIME type of the file.
     */
    public function getMimeType(): string
    {
        return strlen($this->getType()) ? $this->getType() : "application/octet-stream";
    }

    /**
     * Returns the relative download link to download the file. If .htaccess
     * files are supported, the redirect that includes the file name is used.
     * @return string The relative link to download the file.
     */
    public function getLink(): string
    {
        # if CleanURLs are enabled, use the redirect that includes
        # the file name so that browsers don't use index.php as the name
        # for the downloaded file
        if ((PluginManager::getInstance())->pluginEnabled("CleanURLs")) {
            return "downloads/".$this->Id."/".rawurlencode($this->name());
        # otherwise use the download portal
        } else {
            return "index.php?P=DownloadFile&Id=".$this->Id;
        }
    }

    /**
     * Deletes the file and removes its entry from the database. Other methods
     * are invalid after calling this.
     */
    public function destroy()
    {
        # delete file
        $FileName = $this->getNameOfStoredFile();
        if (file_exists($FileName)) {
            unlink($FileName);
        }

        # call parent method
        parent::destroy();
    }

    /**
     * Returns the relative link to the stored file.
     * @return string The relative link to the stored file
     */
    public function getNameOfStoredFile(): string
    {
        # for each possible storage location
        foreach (self::$StorageLocations as $Dir) {
            # build file name for that location
            $FileName = sprintf(
                $Dir."/%06d-%s-%s",
                $this->Id,
                $this->DB->updateValue("SecretString"),
                $this->name()
            );

            # if file can be found in that location
            if (file_exists($FileName)) {
                # return file name to caller
                return $FileName;
            }
        }

        # build file name for default (most preferred) location
        $FileName = sprintf(
            self::getStorageDirectory()."/%06d-%s-%s",
            $this->Id,
            $this->DB->updateValue("SecretString"),
            $this->name()
        );

        # return file name to caller
        return $FileName;
    }

    /**
     * Get file storage directory.
     * @return string Relative directory path (with no trailing slash).
     */
    public static function getStorageDirectory(): string
    {
        # for each possible storage location
        foreach (self::$StorageLocations as $Dir) {
            # if location exists and is writeable
            if (is_dir($Dir) && is_writeable($Dir)) {
                # return location to caller
                return $Dir;
            }
        }

        # return default (most preferred) location to caller
        return self::$StorageLocations[0];
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /** File storage directories, in decreasing order of preference. */
    private static $StorageLocations = [
        "local/data/files",
        "FileStorage",
    ];

    /**
     * Get file name (required by StoredFile trait).
     * @return string File name.
     */
    protected function getFileName() : string
    {
        return $this->getNameOfStoredFile();
    }

    /**
     * Provide a copy of our database (required by StoredFile trait).
     * @return Database The database.
     */
    protected function getDB() : Database
    {
        return $this->DB;
    }

    /**
     * Get MIME type for specified file, if possible.
     * @param string $FileName Name of file, with absolute or relative leading path, if needed.
     * @return string MIME type, or empty string if unable to determine type.
     */
    protected static function determineFileType(string $FileName): string
    {
        $FileType = mime_content_type($FileName);

        # handle Office XML formats
        # These are recognized by PHP as zip files (because they are), but
        # IE (and maybe other things?) need a special-snowflake MIME type to
        # handle them properly.
        # For a list of the required types, see
        # https://technet.microsoft.com/en-us/library/ee309278(office.12).aspx
        if ($FileType == "application/zip; charset=binary") {
            $MsftPrefix = "application/vnd.openxmlformats-officedocument";

            $FileExt = strtolower(pathinfo($FileName, PATHINFO_EXTENSION));

            switch ($FileExt) {
                case "docx":
                    $FileType = $MsftPrefix.".wordprocessingml.document";
                    break;

                case "xlsx":
                    $FileType = $MsftPrefix.".spreadsheetml.sheet";
                    break;

                case "pptx":
                    $FileType = $MsftPrefix.".presentationml.slideshow";
                    break;

                default:
                    # do nothing
            }
        }

        if ($FileType === false) {
            $FileType = "";
        }

        return $FileType;
    }
}
