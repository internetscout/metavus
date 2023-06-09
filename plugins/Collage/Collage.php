<?PHP
#
#   FILE:  Collage.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2021-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Metavus\Image;
use Metavus\MetadataSchema;
use Metavus\Plugins\Collage\RecordImageCollage;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\SearchEngine;
use Metavus\SearchParameterSet;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Plugin;

/**
 * Create an insertion keyword that can be used to request
 *  a collage of records based on user-configurable search
 */
class Collage extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set the plugin attributes.
     */
    public function register()
    {
        $this->Name = "Collage";
        $this->Version = "1.1.1";
        $this->Description = "Add a resource collage on keyword insertion with ".
            "user defined search parameters for resource selection.";
        $this->Author = "Internet Scout";
        $this->Url = "http://metavus.net";
        $this->Email = "scout@scout.wisc.edu";
        $this->Requires = [
            "MetavusCore" => "1.0.0",
        ];
        $this->EnabledByDefault = true;
        $this->CfgSetup["TileWidth"] = [
            "Type" => "Number",
            "Label" => "Tile Length",
            "Help" => "Width/height (in pixels) for collage image tile.",
            "Default" => 150
        ];
        $this->CfgSetup["NumRows"] = [
            "Type" => "Number",
            "Label" => "Number of Rows",
            "Help" => "How many rows of images should be in the collage.",
            "Default" => 3
        ];
        $this->CfgSetup["MaxExpectedViewportWidth"] = [
            "Type" => "Number",
            "Label" => "Max Expected Screen Width",
            "Help" => "Maximum expected width of any given user's monitor.",
            "Units" => "Pixels",
            "Default" => 1920
        ];
        $this->CfgSetup["DialogWidth"] = [
            "Type" => "Number",
            "Label" => "Dialog Width",
            "Help" => "Width for popup that displays when tile is clicked.",
            "Units" => "Pixels",
            "Default" => 600
        ];
        $this->CfgSetup["OrderPersistencePeriod"] = [
            "Type" => "Number",
            "Label" => "Order Persistence Period",
            "Units" => "Hours",
            "Help" => "Length of time between shuffling the images in the collage.",
            "Default" => 4,
            "MinVal" => 1
        ];
        $this->CfgSetup["RecordCacheDuration"] = [
            "Type" => "Number",
            "Label" => "Record Cache Duration",
            "Units" => "Minutes",
            "Help" => "Length of time  to cache the list of records used in "
                ."the collage for a given user.",
            "Default" => 30,
            "MinVal" => 0,
            "MaxVal" => 1440, # (1 day)
        ];
    }

    /**
     * Set up keyword for inserting resource collage
     * @return string|null error string or null on success
     */
    public function initialize()
    {
        (ApplicationFramework::getInstance())->registerInsertionKeywordCallback(
            "RESOURCECOLLAGE-DISPLAYCOLLAGE",
            [$this, "getCollageHtml"],
            ["SchemaId"]
        );

        return null;
    }

    /**
     * Hook event callbacks into the application framework.
     * @return array Events to be hooked into the application framework.
     */
    public function hookEvents(): array
    {
        $Events = [
            "EVENT_RESOURCE_ADD" => "resourceUpdated",
            "EVENT_RESOURCE_MODIFY" => "resourceUpdated",
            "EVENT_RESOURCE_DELETE" => "resourceUpdated",
            "EVENT_PLUGIN_CONFIG_CHANGE" => "handleConfigChange",
        ];

        return $Events;
    }

    /**
     * Set up configuration options.
     */
    public function setUpConfigOptions()
    {
        $SchemaList = MetadataSchema::getAllSchemaNames();
        unset($SchemaList[MetadataSchema::SCHEMAID_USER]);

        foreach ($SchemaList as $TypeSchemaId => $TypeSchemaName) {
            $this->CfgSetup["DisplayResources_".$TypeSchemaId] = [
                "Type" => "Search Parameters",
                "Label" => $TypeSchemaName." Search Parameters",
                "Help" => "Search parameters that define which records of type ".
                    $TypeSchemaName." to display in the collage."
            ];
        }

        return null;
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Callback executed whenever a resource is updated, i.e., added or modified.
     * @param Record $Resource Just-updated resource.
     */
    public function resourceUpdated(Record $Resource)
    {
        $this->clearCaches();
    }

    /**
     * Handle changes to plugin configuration.
     * @param string $PluginName Name of plugin
     * @param string $ConfigSetting Setting to change.
     * @param mixed $OldValue Old value of setting.
     * @param mixed $NewValue New value of setting.
     */
    public function handleConfigChange(
        string $PluginName,
        string $ConfigSetting,
        $OldValue,
        $NewValue
    ) {
        if ($PluginName == $this->Name) {
            $this->clearCaches();
        }
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Get resource collage html for this plugin's config settings
     * @param int $SchemaId ID of schema to grab collage for
     * @return string html for collage, may be empty if no resources have
     *  screenshots on this site.
     */
    public function getCollageHtml(int $SchemaId)
    {
        # get the collage records for this Schema
        $RecordIds = $this->getRecordIdsForCollage($SchemaId);

        # generate collage HTML if we do not have one cached
        $CollageHtml = $this->loadCollageHtmlFromCache($RecordIds);
        if ($CollageHtml === null) {
            $CollageHtml = RecordImageCollage::getHtml($RecordIds);
            $this->saveCollageHtmlToCache($RecordIds, $CollageHtml);
        }

        # add supporting HTML if no supporting HTML has previously been sent
        #       and collage HTML contains content
        static $SupportingHtmlNotYetSent = true;
        if ($SupportingHtmlNotYetSent && ($CollageHtml != "")) {
            $CollageHtml .= RecordImageCollage::getSupportingHtml();
            $SupportingHtmlNotYetSent = false;
        }
        return $CollageHtml;
    }

    /**
     * Get number of images expected to appear in collage.  (Actual number
     * of images may be greater if viewport width exceeds maximum expected
     * viewport width.)
     * @return int Number of images expected to appear in collage.
     */
    public function getNumberOfImages(): int
    {
        $TileWidth = $this->configSetting("TileWidth");
        $NumRows = $this->configSetting("NumRows");
        $ViewportWidth = $this->configSetting("MaxExpectedViewportWidth");
        return (int)ceil($ViewportWidth / $TileWidth) * $NumRows;
    }

    # ---- PRIVATE INTERFACE ---------------------------------------------------

    /**
     * Get the records to display in a collage for a specified schema.
     * @param int $SchemaId Schema ID to fetch records for
     * @return array Record IDs
     */
    private function getRecordIdsForCollage(int $SchemaId) : array
    {
        $User = User::getCurrentUser();

        # load the cache
        $Cache = $this->getConfigSetting("CollageRecordCache") ?? [];
        $CacheTimes = $this->getConfigSetting("CollageRecordCacheTimes") ?? [];

        # expire old cache entries
        $Now = time();
        $MaxAge = $this->getConfigSetting("RecordCacheDuration") * 60;
        $CachePruned = false;
        foreach ($CacheTimes as $CacheKey => $CachedAt) {
            if ($Now - $CachedAt > $MaxAge) {
                unset($Cache[$CacheKey]);
                unset($CacheTimes[$CacheKey]);
                $CachePruned = true;
            }
        }
        if ($CachePruned) {
            $this->setConfigSetting("CollageRecordCache", $Cache);
            $this->setConfigSetting("CollageRecordCacheTimes", $CacheTimes);
        }

        $CacheKey = $SchemaId."-".($User->isLoggedIn() ? $User->id() : "X");

        # return cached entry if we have one
        if (isset($Cache[$CacheKey])) {
            return $Cache[$CacheKey];
        }

        # get mapped screenshot field
        # (in case of non-default schema with different screenshot field)
        $Schema = new MetadataSchema($SchemaId);
        $ScreenshotField = $Schema->getFieldByMappedName("Screenshot");

        # generate search parameters with requirement that there is a
        #       screenshot (plus user-configured collage params)
        $SearchParams = new SearchParameterSet();
        $SearchParams->addParameter("=1", $ScreenshotField);
        $SearchParams->logic("AND");
        $SearchParams->itemTypes($SchemaId);
        $SearchParamsWithUserSet = clone $SearchParams;
        if (!is_null($this->configSetting("DisplayResources_".$SchemaId))) {
            $SearchParamsWithUserSet->addSet(
                $this->configSetting("DisplayResources_".$SchemaId)
            );
        }

        $Engine = new SearchEngine();
        $RFactory = new RecordFactory($SchemaId);
        $NumberOfImages = $this->getNumberOfImages();

        # attempt to get resources with user collage params
        $SearchResults = $Engine->search($SearchParamsWithUserSet);
        $RecordIds = array_keys($SearchResults);
        $RecordIds = $RFactory->filterOutUnviewableRecords($RecordIds, $User);
        $RecordIds = $this->getFirstNValidImages(
            $RecordIds,
            $NumberOfImages
        );

        # if we don't have enough usable resources, consider any resources with screenshots
        if (count($RecordIds) < $NumberOfImages) {
            $SearchResults = $Engine->search($SearchParams);
            $RecordIds = array_keys($SearchResults);
            $RecordIds = $RFactory->filterOutUnviewableRecords($RecordIds, $User);
            $RecordIds = $this->getFirstNValidImages(
                $RecordIds,
                $NumberOfImages
            );
        }

        $Cache[$CacheKey] = $RecordIds;
        $CacheTimes[$CacheKey] = $Now;

        $this->setConfigSetting("CollageRecordCache", $Cache);
        $this->setConfigSetting("CollageRecordCacheTimes", $CacheTimes);

        return $RecordIds;
    }

    /**
     * Given a list of record IDs, get the first N entries that have an image
     * at least as large as this plugin's tile size. This assumes square tiles.
     * @param array $RecordIds The list of record IDs.
     * @param int $NumberOfImages The maximum number of record IDs to return.
     * @return array Up to N record IDs.
     */
    private function getFirstNValidImages(
        array $RecordIds,
        int $NumberOfImages
    ): array {
        $ValidImageCount = 0;
        $Results = [];
        $TileWidth = $this->getConfigSetting("TileWidth");
        $ImageSize = Image::getNextLargestSize($TileWidth, $TileWidth);
        foreach ($RecordIds as $RecordId) {
            $Record = new Record($RecordId);
            $Image = $Record->getMapped("Screenshot", true);
            $Image = reset($Image);
            # skip record if no images in Screenshot field
            if ($Image === false) {
                continue;
            }

            # check bounds of image against tile size
            $ImageUrl = $Image->url($ImageSize);
            $ActualSize = getimagesize($ImageUrl);
            if ($ActualSize !== false
                     && ($ActualSize[0] >= $TileWidth)
                     && ($ActualSize[1] >= $TileWidth)) {
                $Results[] = $RecordId;
                $ValidImageCount++;
            }

            # stop once we have enough images
            if ($ValidImageCount == $NumberOfImages) {
                break;
            }
        }

        return $Results;
    }

    /**
     * Load collage HTML (if available) from cache.
     * @param array $RecordIds IDs of records to be displayed in collage.
     * @return string|null Collage HTML or NULL if no cached value available.
     */
    private function loadCollageHtmlFromCache(array $RecordIds)
    {
        $this->pruneCollageHtmlCache();
        $HtmlCacheKey = sha1(join("-", $RecordIds));
        $HtmlCache = $this->getConfigSetting("CollageCache");
        return $HtmlCache[$HtmlCacheKey] ?? null;
    }

    /**
     * Save collage HTML to cache.
     * @param array $RecordIds IDs of records to be displayed in collage.
     * @param string $Html Collage HTML to save to cache.
     */
    private function saveCollageHtmlToCache(array $RecordIds, string $Html): void
    {
        $CacheKey = sha1(join("-", $RecordIds));

        $Cache = $this->getConfigSetting("CollageCache");
        $Cache[$CacheKey] = $Html;
        $this->setConfigSetting("CollageCache", $Cache);

        $CacheTimes = $this->getConfigSetting("CollageCacheSaveTimes");
        $CacheTimes[$CacheKey] = time();
        $this->setConfigSetting("CollageCacheSaveTimes", $CacheTimes);
    }

    /**
     * Prune expired entries out of collage HTML cache.
     */
    private function pruneCollageHtmlCache(): void
    {
        $Cache = $this->getConfigSetting("CollageCache") ?? [];
        $CacheTimes = $this->getConfigSetting("CollageCacheSaveTimes") ?? [];

        $ExpirationTime = time()
                - ($this->getConfigSetting("OrderPersistencePeriod") * 3600);
        $FilterFunc = function ($Key) use ($CacheTimes, $ExpirationTime) {
            return $CacheTimes[$Key] > $ExpirationTime;
        };
        $Cache = array_filter($Cache, $FilterFunc, ARRAY_FILTER_USE_KEY);
        $CacheTimes = array_filter($CacheTimes, $FilterFunc, ARRAY_FILTER_USE_KEY);

        $this->setConfigSetting("CollageCache", $Cache);
        $this->setConfigSetting("CollageCacheSaveTimes", $CacheTimes);
    }

    /**
     * Clear plugin caches.
     */
    private function clearCaches()
    {
        # clear caches of record IDs
        $this->setConfigSetting("CollageRecordCache", null);
        $this->setConfigSetting("CollageRecordCacheTimes", null);

        # clear caches of html
        $this->setConfigSetting("CollageCache", null);
        $this->setConfigSetting("CollageCacheSaveTimes", null);
    }
}
