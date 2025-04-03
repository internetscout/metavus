<?PHP
#
#   FILE:  IIIFImageServer.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\FormUI;
use Metavus\Plugin;
use Metavus\Plugins\IIIFImageServer\IIIFError;
use Metavus\Plugins\IIIFImageServer\IIIFImage;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

/**
 * Make images available via the IIIF Image API.
 * https://iiif.io/api/image/3.0/
 */
class IIIFImageServer extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

   /**
    * Set the plugin attributes.
    */
    public function register(): void
    {
        $this->Name = "IIIF Image Server";
        $this->Version = "1.0.0";
        $this->Description = "International Image Interoperability Framework (IIIF) "
            ." Image API support.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
        ];
        $this->EnabledByDefault = false;
    }

    /**
     * Perform any work needed when the plugin is first installed.
     * @return null|string NULL if installation succeeded, otherwise a string
     *      containing an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        $Result = $this->createTables(self::SQL_TABLES);
        if ($Result !== null) {
            return $Result;
        }

        # populate request info row
        $DB = new Database();
        $DB->query(
            "INSERT INTO IIIFImageServer_CacheInfo"
                ." (CacheLastPruned, CacheAdditionsSinceLastPrune)"
                ." VALUES (NULL, 0)"
        );

        return null;
    }

    /**
     * Initialize the plugin.
     * @return string|null NULL on success, error string otherwise.
     */
    public function initialize(): ?string
    {
        $Result = $this->checkCacheDirectory();
        if ($Result !== null) {
            return $Result;
        }

        $AF = ApplicationFramework::getInstance();

        # add lib directory to includes
        $BaseName = $this->getBaseName();
        $AF->addIncludeDirectories([
            "plugins/".$BaseName."/lib/openseadragon/",
        ]);

        # handler for IIIF Image API Image requests
        # see: https://iiif.io/api/image/3.0/#4-image-requests
        $AF->addCleanUrl(
            '%^iiif/([0-9]+)/([^/]+)/([^/]+)/([^/]+)/([^/]+)\.([^/]+)$%',
            "P_IIIFImageServer_HandleImageRequest",
            [
                "ID" => "$1",
                "Region" => "$2",
                "Size" => "$3",
                "Rotation" => "$4",
                "Quality" => "$5",
                "Format" => "$6",
            ],
            '/iiif/$ID/$Region/$Size/$Rotation/$Quality.$Format'
        );

        # handler for IIIF Image API Image Information requests
        # see https://iiif.io/api/image/3.0/#5-image-information
        $AF->addCleanUrl(
            '%^iiif/([0-9]+)/info.json$%',
            "P_IIIFImageServer_HandleInfoRequest",
            [
                "ID" => "$1",
            ],
            '/iiif/$ID/info.json'
        );

        return null;
    }

    /**
     * Set up plugin configuration options.
     * @return NULL if configuration setup succeeded, otherwise a string with
     *       an error message indicating why config setup failed.
     */
    public function setUpConfigOptions(): ?string
    {
        $this->CfgSetup["CacheTTL"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Cache Lifetime",
            "Help" => "How long to retain cached images.",
            "Default" => 120,
            "MinVal" => 15,
            "Units" => "minutes",
        ];
        $this->CfgSetup["MaxCacheSize"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Maximum Cache Size",
            "Help" => "Amount of storage the cache is allowed to consume. "
                ."The oldest images will be pruned if the cache grows larger than "
                ."this size.",
            "Default" => 50,
            "Units" => "MB",
        ];
        $this->CfgSetup["MaxItemsInCache"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Maximum Items In Cache",
            "Help" => "Max number of images to store in the cache. "
                ."The oldest images will be pruned if the number of images "
                ."in the cache grows larger than this value.",
            "Default" => 5000,
        ];

        return null;
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Get path to image file containing the image for a given IIIF API Image Request.
     * @param int $Id Image ID.
     * @param string $Region Requested region.
     * @param string $Size Requested size.
     * @param string $Rotation Requested rotation.
     * @param string $Quality Requested quality.
     * @param string $Format Requested format.
     * @return string|IIIFError Path to image on success, IIIFError otherswise.
     */
    public function getPathToImageFileForParams(
        int $Id,
        string $Region,
        string $Size,
        string $Rotation,
        string $Quality,
        string $Format
    ) {
        # construct path to desired image
        $DstFile = implode("_", [$Id, $Region, $Size, $Rotation, $Quality]).".".$Format;
        $DstPath = $this->getCachePath()."/".$DstFile;

        # if file has already been generated, nothing else to do
        if (file_exists($DstPath)) {
            return $DstPath;
        }

        $this->pruneGeneratedImageCacheIfNeeded();

        $Image = new IIIFImage($Id);

        # select region
        $Error = $Image->selectRegion($Region);
        if ($Error !== null) {
            return $Error;
        }

        # scale if needed
        $Error = $Image->scaleImage($Size);
        if ($Error !== null) {
            return $Error;
        }

        # rotate if needed
        $Error = $Image->rotateImage($Rotation);
        if ($Error !== null) {
            return $Error;
        }

        # select desired quality
        $Error = $Image->selectQuality($Quality);
        if ($Error !== null) {
            return $Error;
        }

        # save the result
        $Error = $Image->saveImageInFormat($Format, $DstPath);
        if ($Error !== null) {
            return $Error;
        }

        # increment count of images added to the cache
        $DB = new Database();
        $DB->query(
            "UPDATE IIIFImageServer_CacheInfo "
            ."SET CacheAdditionsSinceLastPrune = CacheAdditionsSinceLastPrune + 1"
        );

        return $DstPath;
    }

    /**
     * Handle CORS (Cross Origin Request Sharing) preflight requests.
     * @return bool TRUE if this was a CORS request, FALSE otherwise.
     * @see https://iiif.io/api/image/3.0/#71-cors
     * @see https://developer.mozilla.org/en-US/docs/Glossary/Preflight_request
     */
    public function handleCorsPreflightRequest() : bool
    {
        if (isset($_SERVER['REQUEST_METHOD'])
            && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            header($_SERVER["SERVER_PROTOCOL"]." 204 No Content");
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Methods: GET, OPTIONS");
            return true;
        }

        return false;
    }

    /**
     * Get the path of the cache directory.
     * @return string Returns the path of the cache directory (no
     *     trailing slash).
     */
    public function getCachePath(): string
    {
        static $Result = null;

        if (is_null($Result)) {
            $Result = getcwd() . "/local/data/caches/IIIF";
        }

        return $Result;
    }

    /**
     * Prune cache of generated images.
     * @return void
     */
    private function pruneGeneratedImageCacheIfNeeded(): void
    {
        $Now = time();
        $CacheTTL = 60 * $this->getConfigSetting("CacheTTL");
        $CacheAge = $Now - $this->getConfigSetting("CacheLastPruned");
        $NewEntries = $this->getConfigSetting("CacheAdditionsSinceLastPrune");

        # if we don't need to prune the cache yet, nothing to do
        if ($CacheAge < $CacheTTL && $NewEntries < self::CACHE_PRUNE_ENTRIES) {
            return;
        }

        $MaxCacheItems = $this->getConfigSetting("MaxItemsInCache");
        $MaxCacheSize = 1024 * 1024 * $this->getConfigSetting("MaxCacheSize");
        StdLib::pruneFileCache(
            $this->getCachePath(),
            $CacheTTL,
            $MaxCacheItems,
            $MaxCacheSize
        );

        $DB = new Database();
        $DB->query(
            "UPDATE IIIFImageServer_CacheInfo "
            ."SET CacheLastPruned = NOW(), CacheAdditionsSinceLastPrune = 0"
        );
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Make sure the cache directories exist and are usable, creating them if
     * necessary.
     * @return string|null Returns a string if there's an error and NULL otherwise.
     */
    private function checkCacheDirectory(): ?string
    {
        $CachePath = $this->getCachePath();

        # the cache directory doesn't exist, try to create it
        if (!file_exists($CachePath)) {
            $Result = @mkdir($CachePath, 0777, true);

            if (false === $Result) {
                return "Image cache directory ".$CachePath
                    ." could not be created.";
            }
        }

        # exists, but is not a directory
        if (!is_dir($CachePath)) {
            return "Image cache directory ".$CachePath
                ." is not a directory.";
        }

        # exists and is a directory, but is not writeable
        if (!is_writeable($CachePath)) {
            return "Image cache directory ".$CachePath
                ." is not writeable.";
        }

        return null;
    }

    # number of new entries in the cache to trigger pruning
    const CACHE_PRUNE_ENTRIES = 25;

    private const SQL_TABLES = [
        "CacheInfo" => "CREATE TABLE IF NOT EXISTS IIIFImageServer_CacheInfo (
                CacheLastPruned TIMESTAMP,
                CacheAdditionsSinceLastPrune INT)",
    ];
}
