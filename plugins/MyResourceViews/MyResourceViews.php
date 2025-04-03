<?PHP
#
#   FILE:  MyResourceViews.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout::phpstan

namespace Metavus\Plugins;
use Metavus\MetadataSchema;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Plugin;

/**
 * Plugin that adds the recently viewed resources for the current user to the
 * sidebar.
 */
class MyResourceViews extends Plugin
{
    /**
     * Register information about this plugin.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "My Resource Views";
        $this->Version = "1.1.0";
        $this->Description = "Displays list of resources that user"
                ." has most recently viewed.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "MetricsRecorder" => "1.1.1",
        ];
        $this->EnabledByDefault = true;

        $this->CfgSetup["BoxHeader"] = [
            "Type" => "Text",
            "Label" => "Box Header",
            "Help" => "Title for box in sidebar.",
            "Size" => 40,
            "MaxLength" => 60,
            "Default" => "Recently Viewed",
        ];
        $this->CfgSetup["ListLength"] = [
            "Type" => "Number",
            "Label" => "Number of Resources to Display",
            "Help" => "Number of recent-viewed resources to display in box.",
            "MaxVal" => 20,
            "Default" => 5,
        ];
        $SchemaNames = MetadataSchema::getAllSchemaNames();
        unset($SchemaNames[MetadataSchema::SCHEMAID_USER]);
        $this->CfgSetup["ResourceTypeToInclude"] = [
            "Type" => "Option",
            "Label" => "Item Types to Display",
            "Help" => "Types of items to be included in box.",
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
    public function initialize(): ?string
    {
        # register our insertion keywords
        $AF = ApplicationFramework::getInstance();
        $AF->registerInsertionKeywordCallback(
            "P-MYRESOURCEVIEWS-RECENTLYVIEWEDBOX",
            [$this, "getHtmlForRecentlyViewedBox"]
        );

        return null;
    }

    /**
     * Generate and return HTML for the recently viewed resources box.
     * @return string Generated HTML.
     */
    public function getHtmlForRecentlyViewedBox(): string
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # return nothing if no user logged in
        if (!$User->isLoggedIn()) {
            return "";
        }

        $Box = "";
        # get the list length + 5 in case some resources cannot be displayed
        $NumToFetch = $this->getConfigSetting("ListLength") + 5;

        # retrieve list of resources recently viewed by user
        $MetricsRecorderPlugin = MetricsRecorder::getInstance();
        $Views = $MetricsRecorderPlugin->getFullRecordViews($User, $NumToFetch);

        # get the resources from the views
        $Resources = [];
        foreach ($Views as $View) {
            # if resource still exists
            if (Record::itemExists($View["ResourceId"])) {
                # load resource
                $Resource = new Record($View["ResourceId"]);

                # if resource type should be displayed
                #       and user can view the resource
                if (in_array(
                    $Resource->getSchemaId(),
                    $this->getConfigSetting("ResourceTypeToInclude")
                )
                && $Resource->userCanView($User)) {
                    # add resource to display list
                    $SchemaCSSName = "mv-sidebar-resource-tag-".
                    str_replace(
                        [
                            " ",
                            "/"
                        ],
                        '',
                        strtolower($Resource->getSchema()->name())
                    );
                    $Resources[] = [
                        "Resource" => $Resource,
                        "SchemaCSSName" => $SchemaCSSName,
                        "SchemaName" => $Resource->getSchema()->abbreviatedName()
                    ];

                    # stop if enough resources have been found
                    if (count($Resources) >= $this->getConfigSetting("ListLength")) {
                        break;
                    }
                }
            }
        }

        # if there were resources found
        if (count($Resources)) {
            ob_start();
            ?><div class="mv-section mv-section-simple mv-html5-section">
                <div class="mv-section-header mv-html5-header">
                    <img src="<?=
                        ApplicationFramework::getInstance()->gUIFile("EyeOpen.svg") ?>" alt="">
                    <span><?= htmlspecialchars($this->getConfigSetting("BoxHeader")) ?></span>
                </div>
                <div class="mv-section-body">
                    <ul class="mv-bullet-list">
                        <?PHP
                        foreach ($Resources as $Rsrc) {
                            # format link to full record page
                            $Link = $Rsrc["Resource"]->getViewPageUrl();

                            # format view label
                            $Title = $Rsrc["Resource"]->getMapped("Title");
                            $Label = strip_tags($Title ?? "");

                            # add resource view to list in box
                            ?><li>
                                <span class="mv-sidebar-resource-tag
                                        <?= $Rsrc["SchemaCSSName"]; ?>">
                                    <?= $Rsrc["SchemaName"]; ?></span>
                                <a href="<?= $Link; ?>">
                                    <?= $Label; ?>
                                </a>
                            </li><?PHP
                        }
                        ?>
                    </ul>
                </div>
            </div><?PHP
            $Box = (string)ob_get_clean();
        }

        # return generated box to caller
        return $Box;
    }
}
