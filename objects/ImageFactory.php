<?PHP
#
#   FILE:  ImageFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\ItemFactory;

class ImageFactory extends ItemFactory
{
    const SCALED_STORAGE_LOCATION = "local/data/caches/images/scaled";
    const IMAGE_STORAGE_LOCATION = "local/data/images";

    # ---- PUBLIC INTERFACE --------------------------------------------------
    /**
     * Class constructor
     */
    public function __construct()
    {
        # set up item factory base class
        parent::__construct(
            "Metavus\\Image",
            "Images",
            "ImageId"
        );
    }

    /**
     * Get the list of image Ids associated with a given record and field.
     * @param int $RecordId Record to search for.
     * @param int $FieldId Field to search for.
     * @return array Associated Images ID s for a given record.
     */
    public function getImageIdsForRecord(int $RecordId, int $FieldId): array
    {
        # find all images associated with this resource
        $this->DB->query(
            "SELECT ImageId FROM Images"
            ." WHERE ItemId = ".$RecordId
            ." AND FieldId = ".$FieldId
        );

        return ($this->DB->numRowsSelected() > 0) ?
            $this->DB->fetchColumn("ImageId") : [];
    }

    /**
     * Search image field for a provided string. Performs a case-insensitve
     *   search of alt text values.
     * @param int $FieldId Field to search.
     * @param string $SearchPhrase Phrase to search for.
     * @return array Record Ids that match the search
     */
    public function searchImageField(int $FieldId, string $SearchPhrase) : array
    {
        $this->DB->query(
            "SELECT DISTINCT ItemId FROM Images "
            ."WHERE POSITION('".$SearchPhrase."' IN LOWER(`AltText`)) "
            ."AND FieldId = ".$FieldId
        );

        return ($this->DB->numRowsSelected() > 0) ?
            $this->DB->fetchColumn("ItemId") : [];
    }

    /**
     * Get the Ids of all the records associated with a given Image field.
     * @param int $FieldId Image field.
     * @return array Record Ids.
     */
    public function getRecordIdsForField(int $FieldId)
    {
        $this->DB->query(
            "SELECT ItemId FROM Images WHERE FieldId=".$FieldId
        );

        return ($this->DB->numRowsSelected() > 0) ?
            $this->DB->fetchColumn("ItemId") : [];
    }

    /**
     * Convert IMAGEURL keywords to URLs.
     * @param string $Html Html to process.
     * @return string Modified html.
     */
    public static function convertKeywordsToUrls(string $Html): string
    {
        $AF = ApplicationFramework::getInstance();

        return preg_replace_callback(
            "%{{IMAGEURL\|Id:([0-9]+)\|Size:([A-Za-z-]+)}}%",
            function ($Matches) use ($AF) {
                $Id = (int)$Matches[1];
                $Size = $Matches[2];

                if (!Image::itemExists($Id)) {
                    $AF->logMessage(
                        ApplicationFramework::LOGLVL_WARNING,
                        "Nonexistent image included in HTML. (ID: ".$Id."). "
                        ."Url: ".$AF->fullUrl()
                    );
                    return "";
                }

                if (!Image::isSizeNameValid($Size)) {
                    $AF->logMessage(
                        ApplicationFramework::LOGLVL_WARNING,
                        "Invalid image size requested in HTML. (Size: ".$Size."). "
                        ."Url: ".$AF->fullUrl()
                    );
                    return "";
                }
                return (new Image($Id))->url($Size);
            },
            $Html
        );
    }

    /**
     * Convert Image URLs to IMAGEURL keywords.
     * @param string $Html Html to process.
     * @return string Modified html
     */
    public static function convertUrlsToKeywords(string $Html) : string
    {
        $AF = ApplicationFramework::getInstance();

        return preg_replace_callback(
            "%".self::SCALED_STORAGE_LOCATION."/"
            .self::SCALED_FILENAME_REGEX."%",
            function ($Matches) use ($AF) {
                $Id = intval($Matches[1]);
                $Width = intval($Matches[2]);
                $Height = intval($Matches[3]);

                if (!Image::itemExists($Id)) {
                    $AF->logMessage(
                        ApplicationFramework::LOGLVL_WARNING,
                        "Nonexistent image included in HTML. (ID: ".$Id."). "
                        ."Url: ".$AF->fullUrl()
                    );
                    return $Matches[0];
                }

                if (Image::isSizeValid($Width, $Height)) {
                    $Size = Image::getSizeName($Width, $Height);
                } else {
                    $Size = Image::getClosestSize($Width, $Height);
                    $AF->logMessage(
                        ApplicationFramework::LOGLVL_WARNING,
                        "Invalid image dimensions for UI requested in HTML. "
                        ."Dimensions were ".$Width."x".$Height.". "
                        ."Using ".$Size.", which is the closest available. "
                        ."Url: ".$AF->fullUrl()
                    );
                }

                return $AF->formatInsertionKeyword(
                    "IMAGEURL",
                    ["Id" => $Id, "Size" => $Size]
                );
            },
            $Html
        );
    }

    /**
     * Define a fallback function to catch IMAGEURL keywords that appear in
     * HTML for to be hooked by Bootloader.
     * @param int $ImageId Image Id.
     * @param string $Size Image size.
     * @return string Replacement text.
     */
    public static function imageKeywordReplacementFallback(
        int $ImageId,
        string $Size
    ): string {
        $AF = ApplicationFramework::getInstance();

        if (!Image::itemExists($ImageId)) {
            $AF->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "IMAGEURL keyword for invalid Image ID"
                ." (".$ImageId.") found in HTML."
                ." Url: ".$AF->fullUrl()
            );
            return $AF->formatInsertionKeyword(
                "IMAGEURL",
                ["Id" => $ImageId, "Size" => $Size]
            );
        }

        if (!Image::isSizeNameValid($Size)) {
            $AF->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "IMAGEURL keyword for valid image but with invalid size "
                ." (".$Size.") found in HTML."
                ." Url: ".$AF->fullUrl()
            );
            return $AF->formatInsertionKeyword(
                "IMAGEURL",
                ["Id" => $ImageId, "Size" => $Size]
            );
        }

        $AF->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "IMAGEURL keyword found in HTML."
            ." Url: ".$AF->fullUrl()
        );
        return (new Image($ImageId))->url($Size);
    }

    /**
     * Check that the image storage directories are available, creating them and
     * attempting to change their permissions if possible.
     * @return array Returns an array of error messages or an empty array when
     *   there are no errors.
     */
    public static function checkImageStorageDirectories() : array
    {
        static $ErrorsFound = false;

        # if we've already run this check, just return the cached result
        if ($ErrorsFound !== false) {
            return $ErrorsFound;
        }

        # determine paths
        $Paths = [
            "Source" => self::IMAGE_STORAGE_LOCATION,
            "Scaled" => self::SCALED_STORAGE_LOCATION,
        ];

        # assume everything will be okay
        $ErrorsFound = [];

        foreach ($Paths as $Type => $ImagePath) {
            if (!is_dir($ImagePath) || !is_writable($ImagePath)) {
                if (!is_dir($ImagePath)) {
                    @mkdir($ImagePath, 0755, true);
                } else {
                    @chmod($ImagePath, 0755);
                }
                if (!is_dir($ImagePath)) {
                    $ErrorsFound[] = $Type." Storage Directory Not Found";
                } elseif (!is_writable($ImagePath)) {
                    $ErrorsFound[] = $Type." Storage Directory Not Writable";
                }
            }
        }

        # return any errors found to caller
        return $ErrorsFound;
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
            "SELECT ImageId FROM Images WHERE "
            ."FileChecksum IS NOT NULL AND "
            ."ContentLastChecked < NOW() - INTERVAL ".self::$FixityCheckInterval." DAY "
        );
        $ImageIds = $DB->fetchColumn("ImageId");

        $AF = ApplicationFramework::getInstance();
        foreach ($ImageIds as $ImageId) {
            $AF->queueUniqueTask(
                "\\Metavus\\Image::callMethod",
                [$ImageId, "checkFixity"],
                ApplicationFramework::PRIORITY_LOW,
                "Check fixity for image with ID ".$ImageId
            );
        }
    }

    /**
     * Get the list of files that no longer match the length and checksum they
     * had when they were updated.
     * @return array where each row gives an ImageId.
     */
    public static function getImagesWithFixityProblems(): array
    {
        $DB = new Database();
        $DB->query("SELECT ImageId FROM Images WHERE ContentUnchanged = 0");
        return $DB->fetchColumn("ImageId");
    }

    /**
     * Delete scaled versions of images that refer to sizes we no longer use
     * or source images that no longer exist.
     * @return void
     */
    public static function cleanOldScaledImages(): void
    {
        # get all image sizes defined in all interfaces
        $AllSizes = static::getAllImageSizes();

        $FileNames = static::getAllScaledImageFileNames();
        foreach ($FileNames as $FileName) {
            # parse file name to extract image ID, width, and height
            preg_match('/^'.self::SCALED_FILENAME_REGEX.'$/', $FileName, $Matches);
            if (!isset($Matches[1]) || !isset($Matches[2]) || !isset($Matches[3])) {
                throw new Exception(
                    "Scaled Image filename in unrecognized format: "
                        .$FileName
                );
            }

            $ImageId = intval($Matches[1]);
            $Size = intval($Matches[2])."x".intval($Matches[3]);

            # delete scaled versions where the generated size is no longer
            # used by any UIs
            $FullFileName = self::SCALED_STORAGE_LOCATION."/".$FileName;
            if (!in_array($Size, $AllSizes)) {
                unlink($FullFileName);
            }

            # delete scaled versions were the source image no longer exists
            if (!Image::itemExists($ImageId)) {
                unlink($FullFileName);
                continue;
            }
        }
    }

    /**
     * Delete all scaled versions of images.  All cached versions of pages
     * that might contain images should also be deleted after doing this, so
     * that scaled versions can be regenerated as needed.
     * @return void
     */
    public static function deleteAllScaledImages(): void
    {
        $FileNames = static::getAllScaledImageFileNames();
        foreach ($FileNames as $FileName) {
            $FullFileName = self::SCALED_STORAGE_LOCATION."/".$FileName;
            if (file_exists($FullFileName)) {
                unlink($FullFileName);
            }
        }
    }

    /**
     * Delete all scaled versions of images and clear global page cache.
     * Intended to be run as a queued task.  (Needed for command line
     * use because the user will often not have the necessary OS
     * permissions to remove the scaled image files.)
     * @return void
     * @see ImageFactory::deleteAllScaledImages()
     */
    public static function deleteAllScaledImagesAsTask(): void
    {
        static::deleteAllScaledImages();
        (ApplicationFramework::getInstance())->clearPageCache();
    }

    /**
     * Delete all image symlinks from Record::IMAGE_CACHE_PATH. Must be called
     * whenever the files these symlinks point to could be removed (e.g.,
     * after deleting the large/preview/thumbnail directories).
     * @return void
     */
    public static function deleteAllImageSymlinks(): void
    {
        # nuke all the image symlinks because they are now invalid
        if (!is_dir(Record::IMAGE_CACHE_PATH)) {
            return;
        }

        $Entries = scandir(Record::IMAGE_CACHE_PATH);

        # scandir() returns FALSE on errors
        if ($Entries === false) {
            return;
        }

        foreach ($Entries as $Entry) {
            $Link = Record::IMAGE_CACHE_PATH."/".$Entry;
            if (is_link($Link)) {
                unlink($Link);
            }
        }
    }

    # ---- PRIVATE STATIC INTERFACE ------------------------------------------

    private static $FixityCheckInterval = 30; // days

    # regular expression (without bounding delimiters) for name of scaled image files
    # ($Matches[1] = image ID, $Matches[2] = image width, $Matches[3] = image height)
    const SCALED_FILENAME_REGEX = "img_([0-9]+)_([0-9]+)x([0-9]+)\.[a-z]+";

    /**
     * Get all images sizes from all interface directories found under
     * "interface" and "local/interface".
     */
    protected static function getAllImageSizes(): array
    {
        $AllSizes = [];

        # load list of interface config files
        $InterfaceConfigFiles = glob("interface/*/interface.ini");
        if ($InterfaceConfigFiles === false) {
            $InterfaceConfigFiles = [];
        }

        # if local interface tree exists
        if (is_dir("local/interface")) {
            # add local versions of interface config files to list
            $LocalInterfaceConfigFiles = glob("local/interface/*/interface.ini");
            if ($LocalInterfaceConfigFiles !== false) {
                $InterfaceConfigFiles = array_merge(
                    $InterfaceConfigFiles,
                    $LocalInterfaceConfigFiles
                );
            }
        }

        # for each interface config file
        foreach ($InterfaceConfigFiles as $InterfaceConfigFile) {
            # parse out all settings from config file
            $Config = parse_ini_file($InterfaceConfigFile);

            # if file was successfully parsed and contains image size settings
            if (($Config !== false) && isset($Config["ImageSizes"])) {
                # add image size settings to list
                $AllSizes = array_merge(
                    array_values($Config["ImageSizes"]),
                    $AllSizes
                );
            }
        }

        # prune out any duplicate sizes
        $AllSizes = array_unique($AllSizes);

        return $AllSizes;
    }

    /**
     * Get (names of) all scaled image files currently present.
     * @return array Names of all scaled image files.
     */
    protected static function getAllScaledImageFileNames(): array
    {
        # return nothing if scaled storage directory doesn't exist
        if (!is_dir(self::SCALED_STORAGE_LOCATION)) {
            return [];
        }

        # get list of all files in scaled file storage directory
        $FileNames = scandir(self::SCALED_STORAGE_LOCATION);

        # (scandir() returns FALSE on errors)
        if ($FileNames === false) {
            return [];
        }

        # filter list to only those that appear to be scaled files
        $FilterFunc = function ($FileName) {
            return (preg_match('/^'.self::SCALED_FILENAME_REGEX.'$/', $FileName) == 1);
        };
        $FileNames = array_filter($FileNames, $FilterFunc);

        return $FileNames;
    }
}
