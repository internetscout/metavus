<?PHP
#
#   FILE:  XmlSitemap.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\User;
use Metavus\MetadataSchema;
use Metavus\Record;
use Metavus\RecordFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Plugin;

class XmlSitemap extends Plugin
{
    /**
     * Register information about this plugin.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "XML Sitemap";
        $this->Version = "1.0.0";
        $this->Description =
            "Provide search engine crawlers with an XML sitemap "
            ."listing all available resources.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
        ];
        $this->EnabledByDefault = true;
    }

    /**
     * Initialize this plugin, setting up a CleanURL for the xml sitemap.
     */
    public function initialize(): ?string
    {
        $Result = $this->checkCacheDirectory();
        if ($Result !== null) {
            return $Result;
        }

        ApplicationFramework::getInstance()->addCleanUrl("%^sitemap.xml$%", "P_XmlSitemap_Sitemap");

        Record::registerObserver(
            Record::EVENT_ADD | Record::EVENT_SET | Record::EVENT_REMOVE,
            [$this, "updateResourceTimestamp"]
        );

        return null;
    }

    /**
     * Uninstall the plugin.
     * @return NULL|string NULL if successful or an error message otherwise
     */
    public function uninstall(): ?string
    {
        $this->deleteCacheFile();
        return null;
    }

    /**
     * Set up configuration for the plugin.
     */
    public function setUpConfigOptions(): ?string
    {
        $this->CfgSetup["MemLimitForUpdate"] = [
            "Type" => "Number",
            "Label" => "Memory Limit for Updates",
            "Help" => "Listing all the resources in your collection may require more "
                     ."memory than normal operation, as permissions may need to be "
                     ."evaluated for every resource in the collection. If you are "
                     ."running out of memory, this can raise the PHP memory limit.",
            "Units" => "MB",
            "Default" => 256,
        ];

        $SchemaOptions = MetadataSchema::getAllSchemaNames();
        unset($SchemaOptions[MetadataSchema::SCHEMAID_USER]);

        $this->CfgSetup["Schemas"] = [
            "Type" => "Option",
            "Label" => "Schemas to include in sitemap",
            "Help" => "Publicly viewable resources from all selected schemas "
                    ."will be included in the generated XML Sitemap",
            "AllowMultiple" => true,
            "Rows" => count($SchemaOptions),
            "Options" => $SchemaOptions,
            "Default" => [MetadataSchema::SCHEMAID_DEFAULT],
        ];

        return null;
    }

    /**
     * Hook the events into the application framework.
     * @return array Returns an array of events to be hooked into the application
     *      framework.
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_DAILY" => "DailyMaintenance",
            "EVENT_PLUGIN_CONFIG_CHANGE" => "pluginConfigChange",
        ];
    }

    /**
     * Handle plugin configuration changes.
     * @param string $PluginName Name of the plugin that has changed.
     * @param string $ConfigSetting Name of the setting that has change.
     * @param mixed $OldValue The old value of the setting.
     * @param mixed $NewValue The new value of the setting.
     * @return void
     */
    public function pluginConfigChange($PluginName, $ConfigSetting, $OldValue, $NewValue): void
    {
        # only worried about changes to the XmlSitemap plugin
        if ($PluginName != "XML Sitemap") {
            return;
        }

        if ($ConfigSetting == "Schemas") {
            # delete sitemap if schemas were updated
            $this->deleteCacheFile();
        }
    }

    /**
     * Update the timestamp storing the last change to any resource.
     * @param int $Events Record::EVENT_* values OR'd together.
     * @param Record $Resource The resource being affected.
     * @return void
     */
    public function updateResourceTimestamp(int $Events, Record $Resource): void
    {
        $this->setConfigSetting("ResourcesLastModified", time());
    }

    /**
     * Daily maintenance to regenerate the XML sitemap if it has gotten
     * stale.
     * @return void
     */
    public function dailyMaintenance(): void
    {
        $CacheFile = $this->getCachePath()."/sitemap.xml";

        # if the cache has gone stale, regenerate it
        if (!file_exists($CacheFile) || (filemtime($CacheFile) <
                $this->getConfigSetting("ResourceLastModified"))) {
            file_put_contents($CacheFile, $this->generateSitemap());
        }
    }

    /**
     * Fetch the XML sitemap.
     * @return string xml sitemap content
     */
    public function getSitemap(): string
    {
        $CacheFile = $this->getCachePath()."/sitemap.xml";

        # if we have a cached sitemap file, just return that
        #  otherwise, generate one in the forgeround
        if (!file_exists($CacheFile)) {
            $Xml = $this->generateSitemap();
            file_put_contents($CacheFile, $Xml);
        } else {
            $Xml = file_get_contents($CacheFile);
            if ($Xml === false) {
                throw new Exception("Could not load sitemap cache file \""
                        .$CacheFile."\".");
            }
        }

        return $Xml;
    }

    /**
     * Get the path of the cache directory.
     * @return string Returns the path of the cache directory.
     */
    private function getCachePath(): string
    {
        return getcwd() . "/local/data/caches/XmlSitemap";
    }

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
                return $CachePath." could not be created.";
            }
        }

        # exists, but is not a directory
        if (!is_dir($CachePath)) {
            return $CachePath." is not a directory.";
        }

        # exists and is a directory, but is not writeable
        if (!is_writeable($CachePath)) {
            return $CachePath." is not writeable.";
        }

        return null;
    }

    /**
     * Genreate XML for the sitemap.
     * @return string xml sitemap.
     */
    private function generateSitemap(): string
    {
        $AF = ApplicationFramework::getInstance();
        # increase memory limit
        ini_set(
            "memory_limit",
            $this->getConfigSetting("MemLimitForUpdate")."M"
        );

        # set up an anon user
        $AnonUser = User::getAnonymousUser();

        # compute our URL prefix
        $UrlPrefix = $AF->rootUrl()
            .$AF->basePath();

        # generate start tags for the sitemap
        $Xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        # iterate over all the schemas that we're including
        foreach ($this->getConfigSetting("Schemas") as $SCId) {
            $Schema = new MetadataSchema($SCId);

            # grab a Resource Factory for this schema
            $RFactory = new RecordFactory($SCId);

            # and ask it for a list of viewable resources
            $ViewableIds = $RFactory->filterOutUnviewableRecords(
                $RFactory->getItemIds(),
                $AnonUser
            );

            # iterate over viewable resources
            foreach ($ViewableIds as $Id) {
                # compute the view page path
                $PagePath = str_replace('$ID', $Id, $Schema->getViewPage());
                if ($PagePath[0] == "?") {
                    $PagePath = "index.php".$PagePath;
                }

                # append this element to the sitemap
                $Xml .= "<url><loc>".defaulthtmlentities(
                    $UrlPrefix
                    .$AF->getCleanRelativeUrlForPath($PagePath)
                )."</loc></url>\n";
            }
        }

        # end the sitemap
        $Xml .= "</urlset>";

        return $Xml;
    }

    /**
     * Delete the cached sitemap.xml
     * @return void
     */
    private function deleteCacheFile(): void
    {
        $CacheFile = $this->getCachePath()."/sitemap.xml";
        if (file_exists($CacheFile)) {
            unlink($CacheFile);
        }
    }
}
