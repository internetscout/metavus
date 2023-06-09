<?PHP
#
#   FILE:  TrendingResources.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2002-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Metavus\MetadataSchema;
use Metavus\Record;
use Metavus\InterfaceConfiguration;
use ScoutLib\ApplicationFramework;
use Metavus\User;
use ScoutLib\Plugin;
use ScoutLib\PluginManager;

/**
 * Plugin that displays the most viewed records with the most URL clicks or full record views,
 * depending on the sys config settings.
 */
class TrendingResources extends Plugin
{
    /**
     * Register information about this plugin.
     */
    public function register()
    {
        $this->Name = "Trending Resources";
        $this->Version = "1.0.0";
        $this->Description = "Displays a list of the records with the most URL clicks
        (or most full record views, if no URL field).";
        $this->Author = "Internet Scout";
        $this->Url = "http://scout.wisc.edu/cwis/";
        $this->Email = "scout@scout.wisc.edu";
        $this->Requires = [
            "MetavusCore" => "1.0.0",
            "MetricsRecorder" => "1.1.1",
        ];

        $this->CfgSetup["BoxHeader"] = [
            "Type" => "Text",
            "Label" => "Box Header",
            "Help" => "Title for list.",
            "Size" => 40,
            "MaxLength" => 60,
            "Default" => "Trending Records",
        ];
        $this->CfgSetup["ListLength"] = [
            "Type" => "Number",
            "Label" => "Number of Records to Display",
            "Help" => "Number of most popular records to display.",
            "MaxVal" => 20,
            "Default" => 5,
        ];
        $this->CfgSetup["PeriodLength"] = [
            "Type" => "Number",
            "Label" => "Trend Period Length",
            "Help" => "The number of days to go back and look for trending records",
            "MaxVal" => 365,
            "Default" => 90,
        ];
        $SchemaNames = MetadataSchema::getAllSchemaNames();
        unset($SchemaNames[MetadataSchema::SCHEMAID_USER]);
        $this->CfgSetup["ResourceTypeToInclude"] = [
            "Type" => "Option",
            "Label" => "Item Types to Display",
            "Help" => "Item types (schemas) to include in list.",
            "AllowMultiple" => true,
            "Options" => $SchemaNames,
            "Default" => array_keys($SchemaNames),
            "Rows" => count($SchemaNames),
        ];
    }

    /**
     * Initialize the plugin.This is called (if the plugin is enabled) after
     * all plugins have been loaded but before any methods for this plugin
     * (other than register()) have been called.
     * @return null|string NULL if initialization was successful, otherwise a
     *      string or array of strings containing error message(s) indicating
     *      why initialization failed.
     */
    public function initialize()
    {
        # register our insertion keywords
        $AF = ApplicationFramework::getInstance();
        $AF->registerInsertionKeywordCallback(
            "P-TRENDINGRESOURCES-TRENDINGRESOURCESBOX",
            [$this, "getHtmlForTrendingResourcesBox"]
        );

        return null;
    }

    /**
     * Generate and return an array for the most viewed resources.
     * @return array Generated array.
     */
    private function getTrendingRecords(): array
    {
        # get the list length + 5 in case some resources cannot be displayed
        $NumToFetch = $this->getConfigSetting("ListLength") + 5;

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # retrieve list of resources most viewed
        $PluginMgr = PluginManager::getInstance();
        $MRecorder = $PluginMgr->getPlugin("MetricsRecorder");
        $Resources = [];
        $TitlesLinkTo = InterfaceConfiguration::getInstance()->getString("TitlesLinkTo");
        $ViewCounts = [];
        $StartDate = "-".$this->getConfigSetting("PeriodLength")." days";
        foreach ($this->getConfigSetting("ResourceTypeToInclude") as $ResourceType) {
            $UrlFieldId = null;
            if ($TitlesLinkTo == "URL") {
                $UrlFieldId = (new MetadataSchema($ResourceType))->getFieldIdByMappedName("Url");
            }
            $Views = is_null($UrlFieldId) ?
                $MRecorder->getFullRecordViewCounts(
                    $ResourceType,
                    $StartDate,
                    null,
                    $NumToFetch
                ) :
                $MRecorder->getUrlFieldClickCounts(
                    $UrlFieldId,
                    $StartDate,
                    null,
                    0,
                    $NumToFetch
                );

            $ViewCounts = $ViewCounts + $Views["Counts"];
        }
        arsort($ViewCounts);
             # get the resources from the viewcount
        foreach ($ViewCounts as $RecordId => $ViewCount) {
            # skip record if it no longer exists
            if (!Record::itemExists($RecordId)) {
                continue;
            }

            # load resource
            $Resource = new Record($RecordId);

            # skip record if it is not viewable
            if (!$Resource->userCanView($User)) {
                continue;
            }

            # add resource to display list
            $Resources[] = $Resource;

            # stop if enough resources have been found
            if (count($Resources) >= $this->getConfigSetting("ListLength")) {
                break;
            }
        }
        return $Resources;
    }

    /**
     * Generate and return HTML for the most viewed resources box.
     * @return string Generated HTML.
     */
    public function getHtmlForTrendingResourcesBox(): string
    {
        $Resources = $this->getTrendingRecords();

        # return empty string if no records to display
        if (count($Resources) == 0) {
            return "";
        }

        $SchemaCssNames = [];
        $SchemaNames = [];
        foreach ($this->getConfigSetting("ResourceTypeToInclude") as $ResourceType) {
            $Schema = new MetadataSchema($ResourceType);
            $SchemaCssName = str_replace(
                [" ", "/"],
                '',
                strtolower($Schema->name())
            );
            $SchemaCssNames[$ResourceType] = $SchemaCssName;
            $SchemaNames[$ResourceType] = $Schema->abbreviatedName();
        }

        ob_start();
        ?><div class="mv-section mv-section-simple mv-html5-section">
            <div class="mv-section-header mv-html5-header">
                <img src="<?= $GLOBALS["AF"]->gUIFile("ArrowUp.svg") ?>">
                <span><?= htmlspecialchars($this->getConfigSetting("BoxHeader")) ?></span>
            </div>
            <div class="mv-section-body">
                <ul class="mv-bullet-list">
                    <?PHP
                    foreach ($Resources as $Resource) {
                        $Link = $Resource->getViewPageUrl();
                        $Title = htmlspecialchars(strip_tags($Resource->getMapped("Title")));
                        $SchemaId = $Resource->schemaId();

                        # add resource view to list in box
                        ?><li>
                            <span class="mv-sidebar-resource-tag
                                    <?= $SchemaCssNames[$SchemaId]; ?>">
                                <?= $SchemaNames[$SchemaId]; ?></span>
                            <a href="<?= $Link ?>">
                                <?= $Title ?>
                            </a>
                        </li><?PHP
                    }
                    ?>
                </ul>
            </div>
        </div><?PHP
        $Box = (string) ob_get_clean();
        return $Box;
    }
}
