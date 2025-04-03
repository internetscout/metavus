<?PHP
#
#   FILE:  FileFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2007-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\ItemFactory;

/**
 * Factory for manipulating File objects.
 */
class FileFactory extends ItemFactory
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     * @param int $FieldId Metadata field ID.  (OPTIONAL)
     */
    public function __construct(?int $FieldId = null)
    {
        # save field ID for our later use
        $this->FieldId = $FieldId;

        # set up item factory base class
        parent::__construct(
            "File",
            "Files",
            "FileId",
            "FileName",
            false,
            ($FieldId ? "FieldId = ".intval($FieldId) : null)
        );
    }

    /**
     * Retrieve all files (names or objects) for specified resource.
     * @param int|Record $ResourceOrResourceId Resource object or ID.
     * @param bool $ReturnObjects Whether to return File objects instead of names.
     * @return array Array of name strings or File objects, with file IDs
     *       for index.  (OPTIONAL, defaults to TRUE)
     */
    public function getFilesForResource(
        $ResourceOrResourceId,
        bool $ReturnObjects = true
    ): array {
        # start out assuming that no files will be found
        $ReturnValue = [];

        # sanitize resource ID or grab it from object
        $ResourceOrResourceId = ($ResourceOrResourceId instanceof Record)
                ? $ResourceOrResourceId->id() : intval($ResourceOrResourceId);

        # retrieve names and IDs of files associated with resource
        $this->DB->Query(
            "SELECT FileId, FileName FROM Files"
            ." WHERE RecordId = ".$ResourceOrResourceId
            ." AND FieldId"
            .($this->FieldId ? "=".$this->FieldId : ">0")
        );
        $FileNames = $this->DB->FetchColumn("FileName", "FileId");

        # if files were found
        if (count($FileNames)) {
            # if caller asked us to return objects
            if ($ReturnObjects) {
                # for each file
                foreach ($FileNames as $FileId => $FileName) {
                    # create file object and add it to array
                    $ReturnValue[$FileId] = new File($FileId);
                }
            } else {
                # return array of file names with IDs as index
                $ReturnValue = $FileNames;
            }
        }

        # return resulting array of files or file names to caller
        return $ReturnValue;
    }

    /**
     * Create copy of File and return to caller.
     * @param File $FileToCopy File object for file to copy.
     * @return File New File object.
     */
    public function copy(File $FileToCopy): File
    {
        return $FileToCopy->duplicate();
    }

    /**
     * Get the list of files that no longer match the length and checksum they
     * had when they were updated.
     * @return array FileIds
     */
    public function getFilesWithFixityProblems()
    {
        $DB = new Database();
        $DB->query("SELECT FileId FROM Files WHERE ContentUnchanged = 0");
        return $DB->fetchColumn("FileId");
    }

    /**
     * Queue tasks to check the fixity of files that have not been checked in
     * self::$FixityCheckInterval days.
     * @return void
     */
    public static function queueFixityChecks(): void
    {
        $DB = new Database();
        $DB->query(
            "SELECT FileId FROM Files WHERE "
            ."FileChecksum IS NOT NULL AND "
            ."ContentLastChecked < NOW() - INTERVAL ".self::$FixityCheckInterval." DAY"
        );
        $FileIds = $DB->fetchColumn("FileId");

        $AF = ApplicationFramework::getInstance();
        foreach ($FileIds as $FileId) {
            $AF->queueUniqueTask(
                "\\Metavus\\File::callMethod",
                [$FileId, "checkFixity"],
                \ScoutLib\ApplicationFramework::PRIORITY_LOW,
                "Check fixity for File Id ".$FileId
            );
        }
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $FieldId;

    # check fixity for files every 30 days
    private static $FixityCheckInterval = 30;
}
