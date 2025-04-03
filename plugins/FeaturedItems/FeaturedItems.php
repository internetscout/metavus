<?PHP
#
#   FILE:  FeaturedItems.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2022-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\ClassificationFactory;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\ResourceSummary;
use Metavus\SearchEngine;
use Metavus\SearchParameterSet;
use Metavus\User;
use ScoutLib\ApplicationFramework;

/**
* Plugin that provides a configurable list of featured items.
*/
class FeaturedItems extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Register information about this plugin.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Featured Items";
        $this->Version = "1.0.1";
        $this->Description = "Display a configurable list of featured items.";
        $this->Author = "Internet Scout";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
        ];
        $this->EnabledByDefault = true;
    }

    /**
     * Install this plugin.
     * @return string|null Returns NULL if installation succeeded, otherwise a string
     *       containing an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        # use Date of Record Creation as default sorting field
        $Schema = new MetadataSchema();
        $RecordCreationFieldId = $Schema->getFieldIdByName("Date of Record Creation");
        $this->setConfigSetting("SortingField", $RecordCreationFieldId);

        # set default search params to the most recent records
        $SearchParams = new SearchParameterSet();
        $SearchParams->addParameter("<= 90 days ago", $RecordCreationFieldId);
        $this->setConfigSetting("SearchParams", $SearchParams);

        return null;
    }

    /**
     * Set up plugin configuration options.
     * @return null|string if configuration setup succeeded, otherwise a string with
     *       an error message indicating why config setup failed.
     */
    public function setUpConfigOptions(): ?string
    {
        $CachedOptions = !$this->onOurConfigPage() ?
            $this->getConfigSetting("CachedOptions") : null;
        if (is_array($CachedOptions)) {
            $GroupingFields = $CachedOptions["GroupingFields"];
            $SortingFields = $CachedOptions["SortingFields"];
            $SchemaNames = $CachedOptions["SchemaNames"];
        } else {
            # get list of usable schemas
            $Schemas = MetadataSchema::getAllSchemas();
            unset($Schemas[MetadataSchema::SCHEMAID_USER]);

            # get list of schema names and sorting fields, grouping fields
            # organized by schema
            $SchemaNames = [];
            $SortingFields = [];
            $GroupingFields = [self::NO_GROUPING_FIELD => "--"];
            foreach ($Schemas as $SchemaId => $Schema) {
                $SchemaNames[$SchemaId] = $Schema->name();
                $SortingFields[$SchemaNames[$SchemaId]] = $Schema->getSortFields();

                $AllGroupingFields = $Schema->getFields(
                    MetadataSchema::MDFTYPE_OPTION |
                        MetadataSchema::MDFTYPE_CONTROLLEDNAME |
                        MetadataSchema::MDFTYPE_TREE
                );
                foreach ($AllGroupingFields as $FieldId => $GroupingField) {
                    $AllGroupingFields[$FieldId] = $GroupingField->name();
                }
                $GroupingFields[$SchemaNames[$SchemaId]] = $AllGroupingFields;
            }
            $this->setConfigSetting(
                "CachedOptions",
                [
                    "GroupingFields" => $GroupingFields,
                    "SortingFields" => $SortingFields,
                    "SchemaNames" => $SchemaNames,
                ]
            );
        }

        $this->CfgSetup["GeneralSection"] = [
            "Type" => FormUI::FTYPE_HEADING,
            "Label" => "General"
        ];

        $this->CfgSetup["ItemType"] = [
            "Type" => FormUI::FTYPE_OPTION,
            "Label" => "Item Type",
            "Help" => "The type of item to display.",
            "Options" => $SchemaNames,
            "Default" => MetadataSchema::SCHEMAID_DEFAULT,
            "AllowMultiple" => false
        ];

        $this->CfgSetup["SearchParams"] = [
            "Type" => FormUI::FTYPE_SEARCHPARAMS,
            "Label" => "Search Parameters",
            "Help" => "Search parameters that define which items to display."
        ];

        $this->CfgSetup["NumItems"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Number of Items",
            "Help" => "Maximum number of items to display, if not".
                " set via an Interface Configuration parameter.",
            "MinVal" => 1,
            "Default" => 10
        ];

        $this->CfgSetup["SortingField"] = [
            "Type" => FormUI::FTYPE_OPTION,
            "Label" => "Sorting Field",
            "Help" => "Sorting field for the search results. Must be a valid".
                " field for the selected item type.",
            "Options" => $SortingFields,
            "OptionThreshold" => 0,
            "ValidateFunction" => [
                "Metavus\\Plugins\\FeaturedItems",
                "validateField"
            ]
        ];

        $this->CfgSetup["SortDescending"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Sort Descending",
            "Default" => true,
            "Help" => "When enabled, the search results are sorted in".
                " descending order. Otherwise, they're sorted in ascending".
                " order."
        ];

        $this->CfgSetup["GroupingField"] = [
            "Type" => FormUI::FTYPE_OPTION,
            "Label" => "Grouping Field",
            "Help" => "This groups together results by their value".
                " for this field to ensure a variety of results. Must be a".
                " valid field for the selected item type.  (OPTIONAL)",
            "Options" => $GroupingFields,
            "OptionThreshold" => 0,
            "Default" => self::NO_GROUPING_FIELD,
            "ValidateFunction" => [
                "Metavus\\Plugins\\FeaturedItems",
                "validateField"
            ]
        ];

        $this->CfgSetup["CacheExpirationPeriod"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Cache Expiration Period",
            "Help" => "How long to keep the cache. If a grouping field is".
                " selected, this value is also used as the time period for".
                " rotating our grouped selected resources.",
            # TO DO:  Default for cache expiration is temporarily set to 0
            #       to avoid issues with non-deterministic results during
            #       automated testing, and should be changed back to 60 when
            #       something is implemented to clear the cache whenever the
            #       search engine indexes are updated.
            "Default" => 0,
            "Units" => "minutes",
        ];

        return null;
    }

    /**
     * Startup initialization for plugin.
     * @return NULL if initialization was successful, otherwise a string
     *       containing an error message indicating why initialization failed.
     */
    public function initialize(): ?string
    {
        (ApplicationFramework::getInstance())->registerInsertionKeywordCallback(
            "P-FEATUREDITEMS-DISPLAYFEATUREDITEMS",
            [$this, "displayFeaturedItems"],
            [],
            ["NumItems"]
        );

        Record::registerObserver(
            Record::EVENT_ADD | Record::EVENT_SET | Record::EVENT_REMOVE,
            [$this, "resourceUpdated"]
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
            "EVENT_PLUGIN_CONFIG_CHANGE" => "handleConfigChange",
        ];

        return $Events;
    }

    /**
     * Validation function for fields in config settings. Checks against the
     * selected schema.
     * @param string $FieldName Setting Name.
     * @param mixed $Value Setting Value.
     * @param array $Values All setting values.
     * @return string|null NULL on successful validation, error string otherwise.
     */
    public static function validateField($FieldName, $Value, $Values): ?string
    {
        $Schema = new MetadataSchema((int)$Values["ItemType"]);
        $FieldId = (int)$Value;

        if ($FieldName == "GroupingField") {
            if ($FieldId == self::NO_GROUPING_FIELD) {
                return null;
            }
        }

        if (!$Schema->fieldExists($FieldId)) {
            $Field = MetadataField::getField($FieldId);
            return "Field ".$Field->name()." does not exist for type ".
                $Schema->name().".";
        }

        return null;
    }

    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Retrieves the HTML of the featured items as ResourceSummary objects.
     * @param int $NumItems (Optional) Number of items to display. Default is 10.
     *       This value can be overridden by an argument passed to this plugin's
     *       insertion keyword.
     * @return string A string of all the HTML to display.
     */
    public function displayFeaturedItems($NumItems = null): string
    {
        $FeaturedItems = $this->getFeaturedItems($NumItems);
        $ShowScreenshot = RecordFactory::recordIdListHasAnyScreenshots($FeaturedItems);
        ob_start();
        foreach ($FeaturedItems as $RecordId) {
            $Summary = ResourceSummary::create($RecordId);
            $Summary->showScreenshot($ShowScreenshot);
            $Summary->display();
        }
        $FeaturedItemsHtml = ob_get_clean();

        if ($FeaturedItemsHtml === false) {
            return "";
        } else {
            $AF = ApplicationFramework::getInstance();
            $AF->addPageCacheTag("SearchResults");
            $AF->addPageCacheTag("SearchResults".$this->getConfigSetting("ItemType"));
            return $FeaturedItemsHtml;
        }
    }

    /**
     * Generates a list of Record IDs using the search parameters, sorting
     * fields, and grouping fields specified in this plugin's configuration.
     * A cache is used to avoid assembling this list for every pageload.
     * @param int $NumItems (Optional) Number of items to display. Default is 10.
     *       This value can be overridden by an argument passed to this plugin's
     *       insertion keyword.
     * @return array An array of Record IDs.
     */
    public function getFeaturedItems($NumItems = null): array
    {
        $AF = ApplicationFramework::getInstance();

        # if the cache was updated less than the configured expiration time,
        # use the cached result
        $CacheExpirationPeriod = 60 * $this->getConfigSetting("CacheExpirationPeriod");
        $CacheAge = time() - $this->getConfigSetting("FeaturedItemCacheLastUpdateTime");
        if ($CacheAge < $CacheExpirationPeriod) {
            return $this->getConfigSetting("FeaturedItemCache");
        }

        # if NumItems isn't overridden by keyword param, use config setting
        if (is_null($NumItems)) {
            $NumItems = $this->getConfigSetting("NumItems");
        }

        # get search params, abort if none set
        $SearchParams = $this->getConfigSetting("SearchParams");
        if (is_null($SearchParams)) {
            return [];
        }

        # configure search params
        $ItemType = (int)$this->getConfigSetting("ItemType");
        $SortingFieldId = (int)$this->getConfigSetting("SortingField");

        $SearchParams->itemTypes($ItemType);
        $SearchParams->sortBy($SortingFieldId);
        $SearchParams->sortDescending($this->getConfigSetting("SortDescending"));

        # get results
        $Engine = new SearchEngine();
        $Results = array_keys($Engine->search($SearchParams));
        $FeaturedItems = $this->pruneAndGroupRecords($Results, $NumItems);

        if (count($FeaturedItems) > 0 &&
            !$AF->taskIsInQueue(["\\Metavus\\SearchEngine", "runUpdateForItem"])) {
            $this->setConfigSetting("FeaturedItemCache", $FeaturedItems);
            $this->setConfigSetting("FeaturedItemCacheLastUpdateTime", time());
        }

        return $FeaturedItems;
    }

    /**
     * Callback executed whenever a resource is updated, i.e., added or modified.
     * @param int $Events Record::EVENT_* values OR'd together.
     * @param Record $Resource Just-updated resource.
     * @return void
     */
    public function resourceUpdated(int $Events, Record $Resource): void
    {
        $this->clearCaches();
    }

    /**
     * Handle changes to plugin configuration.
     * @param string $PluginName Name of plugin
     * @param string $ConfigSetting Setting to change.
     * @param mixed $OldValue Old value of setting.
     * @param mixed $NewValue New value of setting.
     * @returnvoid
     */
    public function handleConfigChange(
        string $PluginName,
        string $ConfigSetting,
        $OldValue,
        $NewValue
    ): void {
        if ($PluginName == $this->Name) {
            $this->clearCaches();
        }
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Represents that no grouping field was selected in the configuration.
     */
    const NO_GROUPING_FIELD = -1;

    /**
     * Takes the Record IDs for a specified schema and gets the viewable Records,
     * capped at the configured limit. If present, a grouping field is used to ensure a
     * distribution of records using values from that field.
     * @param array $RecordIds The schema-specific Record IDs to organize.
     * @param int $Limit The limit on the number of records.
     * @return array Array of Record IDs that we've organized.
     */
    private function pruneAndGroupRecords(
        array $RecordIds,
        int $Limit
    ): array {
        $SchemaId = (int)$this->getConfigSetting("ItemType");
        $RFactory = new RecordFactory($SchemaId);
        $FilteredRecords = $RFactory->getFirstNViewableRecords(
            $RecordIds,
            User::getAnonymousUser(),
            10 * $Limit
        );

        if (count($FilteredRecords) == 0) {
            # abort if we have no viewable records
            return [];
        } elseif (count($FilteredRecords) <= $Limit) {
            # if remaining records are less than or equal to the limit, just return them
            return $FilteredRecords;
        }

        # if present, group the records that we found by their grouping field
        $GroupingFieldId = (int)$this->getConfigSetting("GroupingField");
        if ($GroupingFieldId != -1) {
            $FilteredRecords = $this->groupRecordsByField(
                $FilteredRecords,
                $GroupingFieldId,
                $Limit
            );
        }

        # reduce list if we're over our configured limit
        return count($FilteredRecords) > $Limit ?
            array_slice($FilteredRecords, 0, $Limit) :
            $FilteredRecords;
    }

    /**
     * This method groups records by their value for a provided MetadataField.
     * The allowed field types are ControlledName, Option, and Classification.
     * If a Classification is provided, records are grouped based on the
     * top-level tree. The item rotation period in this plugin's config
     * is used to determine the index while selecting records from groups.
     * @param array $RecordIds The array of record IDs to work on.
     * @param int $FieldId The ID of the MetadataField to group by.
     * @param int $Limit The cap on the number of records to return.
     * @return array Array of record IDs that we've grouped.
     */
    private function groupRecordsByField(
        array $RecordIds,
        int $FieldId,
        int $Limit
    ): array {
        $GroupingField = MetadataField::getField($FieldId);

        # reduce down to the first 10 * $Limit records for consideration
        $RecordIds = array_slice($RecordIds, 0, 10 * $Limit, true);

        $RecordsByGroup = $this->groupRecordsByVocabulary($RecordIds, $GroupingField);

        # generate an index that increments periodically to use for record rotation
        $RecordRotationPeriod = $this->getConfigSetting("CacheExpirationPeriod") * 60;
        $Index = floor(time() / $RecordRotationPeriod);

        # loop until we have enough records
        $RecordsToDisplay = [];
        $Iteration = 0;
        $DisplayCount = 0;
        do {
            # iterate over our groups, selecting a record to display from each
            foreach ($RecordsByGroup as $VocabId => $RecordIds) {
                # wrap our global index to fit within the records we have
                # for this group, but indexing with each iteration
                $Index_i = ($Index + $Iteration) % count($RecordIds);

                $RecordsToDisplay[] = $RecordIds[$Index_i];
                $DisplayCount += 1;
            }
            $Iteration += 1;
        } while ($DisplayCount < $Limit);

        return $RecordsToDisplay;
    }

    /**
     * This method groups the provided Record IDs using the Tree or ControlledName
     * term they're associated with, if they have one. If they are grouped by a
     * Tree, they are indexed by the ID of the topmost parent of the term.
     * @param array $RecordIds The array of Record IDs to group.
     * @param MetadataField $Field The field to group by.
     * @return array An associative array of Record ID groups.
     */
    private function groupRecordsByVocabulary(
        array $RecordIds,
        MetadataField $Field
    ): array {
        $RecordIdsByGroup = [];
        foreach ($RecordIds as $RecordId) {
            $Record = new Record($RecordId);
            $VocabId = key($Record->get($Field));

            # if there's no such vocabulary, move to the next record
            if (is_null($VocabId)) {
                continue;
            }

            # group by CName or Tree
            if ($Field->type() == MetadataSchema::MDFTYPE_TREE) {
                # retrieve the field ID of the topmost parent of our term
                # for grouping by common ancestor
                $AncestorMap = ClassificationFactory::getAncestorMap([$VocabId]);
                $RootId = min(array_keys($AncestorMap));
                $RecordIdsByGroup[$RootId][] = $RecordId;
            } else {
                $RecordIdsByGroup[$VocabId][] = $RecordId;
            }
        }

        return $RecordIdsByGroup;
    }

    /**
     * Clear plugin caches.
     * @return void
     */
    private function clearCaches(): void
    {
        $this->setConfigSetting("FeaturedItemCache", null);
        $this->setConfigSetting("FeaturedItemCacheLastUpdateTime", null);
    }
}
