<?PHP
#
#   FILE:  OAIItemFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2009-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\OAIPMHServer;

use Metavus\MetadataSchema;
use Metavus\RecordFactory;
use Metavus\SearchEngine;
use Metavus\SearchParameterSet;
use Metavus\User;
use ScoutLib\ApplicationFramework;

class OAIItemFactory implements \ScoutLib\OAIItemFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Construct an OAIPMHServer OAI ItemFactory for handling OAI Items
     * @param array $RepDescr repository description
     * @param array $RetrievalSearchParameters retrieval search parameters as array
     *     with field => value
     */
    public function __construct(array $RepDescr, $RetrievalSearchParameters = null)
    {
        # save the repository description
        $this->RepDescr = $RepDescr;

        # save any supplied retrieval parameters
        $this->RetrievalSearchParameters = $RetrievalSearchParameters;
    }

    /**
     * Get an Item.
     * @param int $ItemId ItemId to fetch.
     * @return mixed Requested Items.
     */
    public function getItem(int $ItemId)
    {
        # add link to full record page for item
        $Protocol = isset($_SERVER["HTTPS"]) ? "https://" : "http://";
        $ServerName = ($_SERVER["SERVER_NAME"] != "127.0.0.1")
                ? $_SERVER["SERVER_NAME"]
                : $_SERVER["HTTP_HOST"];
        $ServerName = str_replace('/', '', $ServerName);
        $SearchInfo["fullRecordLink"] =
            $Protocol.$ServerName.dirname($_SERVER["SCRIPT_NAME"])
            ."/index.php?P=FullRecord&ID=".$ItemId;

        # if a search score is available for the item
        if (isset($this->SearchScores) && isset($this->SearchScores[$ItemId])) {
            # add search info for item
            $SearchInfo["searchScore"] = $this->SearchScores[$ItemId];
            $SearchInfo["searchScoreScale"] = $this->SearchScoreScale;
        }

        # attempt to create item
        $Item = new OAIItem($ItemId, $this->RepDescr, $SearchInfo);

        # if item creation failed
        if ($Item->status() == -1) {
            # return NULL to indicate that no item was found with that ID
            return null;
        } else {
            # return item to caller
            return $Item;
        }
    }

    /**
     * Get a list of items, optionally restricted by creation date.
     * @param string $StartingDate Starting date for list (OPTIONAL).
     * @param string $EndingDate Ending date for list.
     * @return array Requested Items.
     */
    public function getItems(
        string $StartingDate = null,
        string $EndingDate = null
    ): array {
        return $this->getItemsInSet(null, $StartingDate, $EndingDate);
    }

    /**
     * Get array of Items in a specified OAI set (if supported).
     * @param string $Set OAI set specification.(OPTIONAL)
     * @param string $StartingDate Starting date  (OPTIONAL).
     * @param string $EndingDate Ending date  (OPTIONAL).
     * @param SearchParameterSet $SearchParams Search parameters to search for
     *     to narrow results.(OPTIONAL)
     * @return array Requested items.
     */
    public function getItemsInSet(
        string $Set = null,
        string $StartingDate = null,
        string $EndingDate = null,
        $SearchParams = null
    ): array {
        $AF = ApplicationFramework::getInstance();
        $SearchParams = (is_null($SearchParams)) ? new SearchParameterSet() : $SearchParams;

        # if begin/end dates were specified, add search parameters
        if ($StartingDate !== null) {
            $SearchParams->addParameter(">=".$StartingDate, "Date Of Record Creation");
        }

        if ($EndingDate !== null) {
            $SearchParams->addParameter("<=".$EndingDate, "Date Of Record Creation");
        }

        # if set specified
        if ($Set != null) {
            # load set mappings
            $this->loadSetNameInfo();

            # if set is valid
            if (isset($this->SetFields[$Set])) {
                # add field spec to search strings
                $SearchParams->addParameter(
                    "= ".$this->SetValues[$Set],
                    $this->SetFields[$Set]
                );
            } else {
                # set will not match anything so return empty array to caller
                return [];
            }
        }

        # set up search parameter groups
        if ($this->RetrievalSearchParameters) {
            foreach ($this->RetrievalSearchParameters as $Field => $Value) {
                $SearchParams->addParameter($Value, $Field);
            }
        }

        # allow any hooked handlers to modify search parameters if desired
        $SignalResult = $AF->signalEvent(
            "OAIPMHServer_EVENT_MODIFY_RESOURCE_SEARCH_PARAMETERS",
            ["SearchParameters" => $SearchParams]
        );
        $SearchParams = $SignalResult["SearchParameters"];

        # get a ResourceFactory
        $RFactory = new RecordFactory();

        # if our search has no conditions, and just wants all the resources
        if ($SearchParams->parameterCount() == 0) {
            # pull them out of the item factory and construct a SearchResult
            $SearchResults = array_fill_keys($RFactory->getItemIds(), 1);
            $this->SearchScoreScale = 1;
        } else {
            # otherwise, perform search for desired items
            $Engine = new SearchEngine();
            $SearchParams->itemTypes(MetadataSchema::SCHEMAID_DEFAULT);
            $SearchResults = $Engine->search($SearchParams);
            $this->SearchScoreScale = $Engine->fieldedSearchWeightScale($SearchParams);
        }

        # filter out non-viewable resources
        $ViewableIds = $RFactory->filterOutUnviewableRecords(
            array_keys($SearchResults),
            User::getCurrentUser()
        );
        $SearchResults = array_intersect_key(
            $SearchResults,
            array_flip($ViewableIds)
        );

        # save search scores for
        $this->SearchScores = $SearchResults;

        # extract resource IDs from search results
        $ItemIds = array_keys($SearchResults);

        # allow any hooked handlers to filter results if desired
        $SignalResult = $AF->signalEvent(
            "OAIPMHServer_EVENT_FILTER_RESULTS",
            ["ItemIds" => $ItemIds]
        );
        $ItemIds = $SignalResult["ItemIds"];

        # return array of resource IDs to caller
        return $ItemIds;
    }

    /**
     * Retrieve IDs of items that match search parameters (only needed if
     * OAI-SQ supported).
     * @param SearchParameterSet $SearchParams Search strings with field IDs for index.
     * @param string $StartingDate Starting date for results.(OPTIONAL)
     * @param string $EndingDate Ending date for results.(OPTIONAL)
     * @return array IDs of found items.
     */
    public function searchForItems($SearchParams, $StartingDate = null, $EndingDate = null)
    {
        # perform search and return results to caller
        return $this->getItemsInSet(null, $StartingDate, $EndingDate, $SearchParams);
    }

    /**
     * Get the list of supported OAI sets.
     * @return array List of supported sets, with human-readable set names for index..
     */
    public function getListOfSets(): array
    {
        # make sure set name info is loaded
        $this->loadSetNameInfo();

        # return list of sets to caller
        return $this->SetSpecs;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $SetSpecs;
    private $SetFields;
    private $SetValues;
    private $RepDescr;
    private $RetrievalSearchParameters;
    private $SearchScores;
    private $SearchScoreScale;

    /**
     * Normalize value for use as an OAI set spec.
     * @param string $Name Name to normalize.
     * @return string Name normalized.
     */
    private function normalizeForSetSpec(string $Name): string
    {
        return preg_replace("/[^a-zA-Z0-9\-_.!~*'()]/", "", $Name);
    }

    /**
     * Load normalized set names and name mappings.
     */
    private function loadSetNameInfo()
    {
        # if set names have not already been loaded
        if (!isset($this->SetSpecs)) {
            # start with empty list of sets
            $this->SetSpecs = [];
            $this->SetFields = [];
            $this->SetValues = [];

            # for each metadata field that is a type that can be used for sets
            $Schema = new MetadataSchema();
            $Fields = $Schema->getFields(MetadataSchema::MDFTYPE_TREE
                    | MetadataSchema::MDFTYPE_CONTROLLEDNAME
                    | MetadataSchema::MDFTYPE_OPTION);
            foreach ($Fields as $Field) {
                # if field is flagged as being used for OAI sets
                if ($Field->useForOaiSets()) {
                    # retrieve all possible values for field
                    $FieldValues = $Field->getPossibleValues();

                    # prepend field name to each value and add to list of sets
                    $FieldName = $Field->name();
                    $NormalizedFieldName = $this->normalizeForSetSpec($FieldName);
                    foreach ($FieldValues as $Value) {
                        $SetSpec = $NormalizedFieldName.":"
                                .$this->normalizeForSetSpec($Value);
                        $this->SetSpecs[$FieldName.": ".$Value] = $SetSpec;
                        $this->SetFields[$SetSpec] = $FieldName;
                        $this->SetValues[$SetSpec] = $Value;
                    }
                }
            }
        }
    }
}
