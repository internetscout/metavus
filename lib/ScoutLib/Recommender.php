<?PHP
#
#   FILE:  Recommender.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace ScoutLib;

/**
 * Recommendation engine.
 */
abstract class Recommender
{

    # ---- PUBLIC INTERFACE --------------------------------------------------
    # define content field types
    const CONTENTFIELDTYPE_TEXT =  1;
    const CONTENTFIELDTYPE_NUMERIC =  2;
    const CONTENTFIELDTYPE_CONTROLLEDNAME =  3;
    const CONTENTFIELDTYPE_DATE =  4;
    const CONTENTFIELDTYPE_DATERANGE =  5;

    /**
     * Object constructor.
     * @param object $DB Database to use.
     * @param string $ItemTableName Name of database table containing items.
     * @param string $RatingTableName Name of database table used to store
     *       item ratings.
     * @param string $ItemIdFieldName Name of column in ratings table that
     *       contains item IDs.
     * @param string $UserIdFieldName Name of column in ratings table that
     *       contains user IDs.
     * @param string $RatingFieldName Name of column in ratings table that
     *       contains rating value.
     * @param array $ContentFields Array of arrays with information about
     *       content fields, indexed by field identifiers, with the
     *       second-level arrays having "DBFieldName", "Weight", and
     *       "FieldType" elements.
     */
    public function __construct(
        &$DB,
        $ItemTableName,
        $RatingTableName,
        $ItemIdFieldName,
        $UserIdFieldName,
        $RatingFieldName,
        $ContentFields
    ) {

        # set default parameters
        $this->ContentCorrelationThreshold = 1;

        # save database object
        $this->DB =& $DB;

        # save new configuration values
        $this->ItemTableName = $ItemTableName;
        $this->RatingTableName = $RatingTableName;
        $this->ItemIdFieldName = $ItemIdFieldName;
        $this->UserIdFieldName = $UserIdFieldName;
        $this->RatingFieldName = $RatingFieldName;
        $this->ContentFields = $ContentFields;

        # set default debug state
        $this->DebugLevel = 0;
    }

    /**
     * Set level for debugging output.
     * @param int $Setting New debugging output level.
     */
    public function debugLevel($Setting): void
    {
        $this->DebugLevel = $Setting;
    }


    # ---- recommendation methods

    /**
     * Recommend items for specified user.
     * @param int $UserId ID of user.
     * @param int $StartingResult Index into list of recommended items (first
     *       item is 0).  (OPTIONAL, defaults to 0)
     * @param int $NumberOfResults Number of items to return.  (OPTIONAL,
     *       defaults to 10)
     * @return array Recommended items, with item IDs for the index and
     *      recommendation scores for the values.
     */
    public function recommend(
        $UserId,
        $StartingResult = 0,
        $NumberOfResults = 10
    ): array {
        if ($this->DebugLevel > 0) {
            print "REC:  Recommend(".$UserId.", ".$StartingResult.", "
                .$NumberOfResults.")<br>\n";
        }

        # load in user ratings
        $Ratings = array();
        $DB =& $this->DB;
        $DB->query("SELECT ".$this->ItemIdFieldName.", ".$this->RatingFieldName
                   ." FROM ".$this->RatingTableName
                   ." WHERE ".$this->UserIdFieldName." = ".$UserId);
        while ($Row = $DB->fetchRow()) {
            $Ratings[$Row[$this->ItemIdFieldName]] =
                    $Row[$this->RatingFieldName];
        }
        if ($this->DebugLevel > 1) {
            print "REC:  user has rated ".count($Ratings)." items<br>\n";
        }

        # for each item that user has rated
        $RecVals = [];
        foreach ($Ratings as $ItemId => $ItemRating) {
            # for each content correlation available for that item
            $DB->query("SELECT Correlation, ItemIdB "
                    ."FROM RecContentCorrelations "
                    ."WHERE ItemIdA = ".$ItemId);
            while ($Row = $DB->fetchRow()) {
                # multiply that correlation by normalized rating and add
                #       resulting value to recommendation value for that item
                if (isset($RecVals[$Row["ItemIdB"]])) {
                    $RecVals[$Row["ItemIdB"]] +=
                            $Row["Correlation"] * ($ItemRating - 50);
                } else {
                    $RecVals[$Row["ItemIdB"]] =
                            $Row["Correlation"] * ($ItemRating - 50);
                }
                if ($this->DebugLevel > 9) {
                    print "REC:  RecVal[".$Row["ItemIdB"]."] = "
                            .$RecVals[$Row["ItemIdB"]]."<br>\n";
                }
            }
        }
        if ($this->DebugLevel > 1) {
            print "REC:  found ".count($RecVals)." total recommendations<br>\n";
        }

        # calculate average correlation between items
        $ResultThreshold = $DB->query("SELECT AVG(Correlation) "
                ."AS Average FROM RecContentCorrelations", "Average");
        $ResultThreshold = round($ResultThreshold) * 2;

        # for each recommended item
        foreach ($RecVals as $ItemId => $RecVal) {
            # remove item from list if user already rated it
            if (isset($Ratings[$ItemId])) {
                unset($RecVals[$ItemId]);
            } else {
                # scale recommendation value back to match thresholds
                $RecVals[$ItemId] = round($RecVal / 50);

                # remove item from recommendation list if value is below threshold
                if ($RecVals[$ItemId] < $ResultThreshold) {
                    unset($RecVals[$ItemId]);
                }
            }
        }
        if ($this->DebugLevel > 1) {
            print "REC:  found ".count($RecVals)." positive recommendations<br>\n";
        }

        # sort recommendation list by value
        arsort($RecVals, SORT_NUMERIC);

        # save total number of results available
        $this->NumberOfResultsAvailable = count($RecVals);

        # trim result list to match range requested by caller
        $RecValKeys = array_slice(
            array_keys($RecVals),
            $StartingResult,
            $NumberOfResults
        );
        $RecValSegment = array();
        foreach ($RecValKeys as $Key) {
            $RecValSegment[$Key] = $RecVals[$Key];
        }

        # return recommendation list to caller
        return $RecValSegment;
    }

    /**
     * Add function to be called to filter returned recommendation list.
     * @param callable $FunctionName Filter function, that accepts an item ID
     *       and returns TRUE if item should be filtered out of results.
     */
    public function addResultFilterFunction($FunctionName): void
    {
        # save filter function name
        $this->FilterFuncs[] = $FunctionName;
    }

    /**
     * Get number of recommendations generated.
     * @return int Number of recommended items.
     */
    public function numberOfResults()
    {
        return $this->NumberOfResultsAvailable;
    }

    /**
     * Get time it took to generate the most recent recommendation.
     * @return float Time in seconds, with microseconds.
     */
    public function searchTime()
    {
        return $this->LastSearchTime;
    }

    /**
     * Return list of items used to generate recommendation of specified item.
     * @param int $UserId ID of user that recommendation was generated for.
     * @param int $RecommendedItemId ID of item that was recommended.
     * @return array Array with IDs of items that were used to generate
     *       recommendation for the index, and correlation values (indicate
     *       how strongly the item determine the recommendation) for the
     *       values.
     */
    public function getSourceList($UserId, $RecommendedItemId)
    {
        # pull list of correlations from DB
        $this->DB->query("SELECT * FROM RecContentCorrelations, ".$this->RatingTableName
                ." WHERE (ItemIdA = ".$RecommendedItemId
                        ." OR ItemIdB = ".$RecommendedItemId.")"
                        ." AND ".$this->UserIdFieldName." = ".$UserId
                        ." AND (RecContentCorrelations.ItemIdA = "
                                .$this->RatingTableName.".".$this->ItemIdFieldName
                        ." OR RecContentCorrelations.ItemIdB = "
                                .$this->RatingTableName.".".$this->ItemIdFieldName.")"
                        ." AND Rating >= 50 "
                ." ORDER BY Correlation DESC");

        # for each correlation
        $SourceList = array();
        while ($Row = $this->DB->fetchRow()) {
            # pick out appropriate item ID
            if ($Row["ItemIdA"] == $RecommendedItemId) {
                $ItemId = $Row["ItemIdB"];
            } else {
                $ItemId = $Row["ItemIdA"];
            }

            # add item to recommendation source list
            $SourceList[$ItemId] = $Row["Correlation"];
        }

        # return recommendation source list to caller
        return $SourceList;
    }

    /**
     * Dynamically generate and return list of items similar to specified item.
     * @param int $ItemId ID of item.
     * @param array $FieldList List of fields to consider.  (OPTIONAL,
     *       defaults to all fields)
     * @return array IDs for similar items.
     */
    public function findSimilarItems($ItemId, $FieldList = null)
    {
        if ($this->DebugLevel > 1) {
            print "REC:  searching for items similar to item \""
                    .$ItemId."\"<br>\n";
        }

        # make sure we have item IDs available
        $this->loadItemIds();

        # start with empty array
        $SimilarItems = array();

        # for every item
        foreach ($this->ItemIds as $Id) {
            # if item is not specified item
            if ($Id != $ItemId) {
                # calculate correlation of item to specified item
                $Correlation = $this->calculateContentCorrelation(
                    $ItemId,
                    $Id,
                    $FieldList
                );

                # if correlation is above threshold
                if ($Correlation > $this->ContentCorrelationThreshold) {
                    # add item to list of similar items
                    $SimilarItems[$Id] = $Correlation;
                }
            }
        }
        if ($this->DebugLevel > 3) {
            print "REC:  ".count($SimilarItems)." similar items to item \""
                    .$ItemId."\" found<br>\n";
        }

        # filter list of similar items (if any)
        if (count($SimilarItems) > 0) {
            $SimilarItems = $this->filterOnSuppliedFunctions($SimilarItems);
            if ($this->DebugLevel > 4) {
                print "REC:  ".count($SimilarItems)." similar items to item \""
                        .$ItemId."\" left after filtering<br>\n";
            }
        }

        # if any similar items left
        if (count($SimilarItems) > 0) {
            # sort list of similar items in order of most to least similar
            arsort($SimilarItems, SORT_NUMERIC);
        }

        # return list of similar items to caller
        return $SimilarItems;
    }

    /**
     * Dynamically generate and return list of recommended field values for item.
     * @param int $ItemId ID of item.
     * @param array $FieldList List of fields to recommend values for.  (OPTIONAL,
     *       defaults to all fields)
     * @return array Array of arrays of recommended values, with fields for
     *       the top-level index.
     */
    public function recommendFieldValues($ItemId, $FieldList = null)
    {
        if ($this->DebugLevel > 1) {
            print "REC:  generating field value recommendations for item \""
                    .$ItemId."\"<br>\n";
        }

        # start with empty array of values
        $RecVals = array();

        # generate list of similar items
        $SimilarItems = $this->findSimilarItems($ItemId, $FieldList);

        # if similar items found
        if (count($SimilarItems) > 0) {
            # prune list of similar items to only top third of better-than-average
            $AverageCorr = intval(array_sum($SimilarItems) / count($SimilarItems));
            reset($SimilarItems);
            $HighestCorr = current($SimilarItems);
            $CorrThreshold = intval($HighestCorr - (($HighestCorr - $AverageCorr) / 3));
            if ($this->DebugLevel > 8) {
                print "REC:  <i>Average Correlation: $AverageCorr"
                        ." &nbsp;&nbsp;&nbsp;&nbsp; Highest Correlation:"
                        ." $HighestCorr &nbsp;&nbsp;&nbsp;&nbsp; Correlation"
                        ." Threshold: $CorrThreshold </i><br>\n";
            }
            foreach ($SimilarItems as $ItemId => $ItemCorr) {
                if ($ItemCorr < $CorrThreshold) {
                    unset($SimilarItems[$ItemId]);
                }
            }
            if ($this->DebugLevel > 6) {
                print "REC:  ".count($SimilarItems)
                        ." similar items left after threshold pruning<br>\n";
            }

            # for each item
            foreach ($SimilarItems as $SimItemId => $SimItemCorr) {
                # for each field
                foreach ($this->ContentFields as $FieldName => $FieldAttributes) {
                    # load field data for this item
                    $FieldData = $this->getFieldValue($SimItemId, $FieldName);

                    # if field data is array
                    if (is_array($FieldData)) {
                        # for each field data value
                        foreach ($FieldData as $FieldDataVal) {
                            # if data value is not empty
                            $FieldDataVal = trim($FieldDataVal);
                            if (strlen($FieldDataVal) > 0) {
                                # increment count for data value
                                $RecVals[$FieldName][$FieldDataVal]++;
                            }
                        }
                    } else {
                        # if data value is not empty
                        $FieldData = trim($FieldData);
                        if (strlen($FieldData) > 0) {
                            # increment count for data value
                            $RecVals[$FieldName][$FieldData]++;
                        }
                    }
                }
            }

            # for each field
            $MatchingCountThreshold = 3;
            foreach ($RecVals as $FieldName => $FieldVals) {
                # determine cutoff threshold
                arsort($FieldVals, SORT_NUMERIC);
                reset($FieldVals);
                $HighestCount = current($FieldVals);
                $AverageCount = intval(array_sum($FieldVals) / count($FieldVals));
                $CountThreshold = intval($AverageCount
                        + (($HighestCount - $AverageCount) / 2));
                if ($CountThreshold < $MatchingCountThreshold) {
                    $CountThreshold = $MatchingCountThreshold;
                }
                if ($this->DebugLevel > 8) {
                    print "REC:  <i>Field: $FieldName &nbsp;&nbsp;&nbsp;&nbsp;"
                            ." Average Count: $AverageCount &nbsp;&nbsp;&nbsp;&nbsp;"
                            ." Highest Count: $HighestCount &nbsp;&nbsp;&nbsp;&nbsp;"
                            ." Count Threshold: $CountThreshold </i><br>\n";
                }

                # for each field data value
                foreach ($FieldVals as $FieldVal => $FieldValCount) {
                    # if value count is below threshold
                    if ($FieldValCount < $CountThreshold) {
                        # unset value
                        unset($RecVals[$FieldName][$FieldVal]);
                    }
                }

                if ($this->DebugLevel > 3) {
                    print "REC:  found ".count($RecVals[$FieldName])
                            ." recommended values for field \""
                            .$FieldName."\" after threshold pruning<br>\n";
                }
            }
        }

        # return recommended values to caller
        return $RecVals;
    }


    # ---- database update methods

    /**
     * Update recommender data for range of items.
     * @param int $StartingItemId ID of first item to update.
     * @param int $NumberOfItems Number of items to update.
     * @return int ID of last item updated.
     */
    public function updateForItems($StartingItemId, $NumberOfItems)
    {
        if ($this->DebugLevel > 0) {
            print "REC:  UpdateForItems(".$StartingItemId.", "
                .$NumberOfItems.")<br>\n";
        }
        # make sure we have item IDs available
        $this->loadItemIds();

        # for every item
        $ItemsUpdated = 0;
        $ItemId = null;
        foreach ($this->ItemIds as $ItemId) {
            # if item ID is within requested range
            if ($ItemId >= $StartingItemId) {
                # update recommender info for item
                if ($this->DebugLevel > 1) {
                    print("REC:  doing item ".$ItemId."<br>\n");
                }
                $this->updateForItem($ItemId, true);
                $ItemsUpdated++;

                # if we have done requested number of items
                if ($ItemsUpdated >= $NumberOfItems) {
                    # bail out
                    if ($this->DebugLevel > 1) {
                        print "REC:  bailing out with item ".$ItemId."<br>\n";
                    }
                    return $ItemId;
                }
            }
        }

        # return ID of last item updated to caller
        return $ItemId;
    }

    /**
     * Update recommender data for specified item.
     * @param int $ItemId ID of item to update.
     * @param bool $FullPass If TRUE, update is assumed to be part of an
     *       update of all items.  (OPTIONAL, default to FALSE)
     */
    public function updateForItem($ItemId, $FullPass = false): void
    {
        if ($this->DebugLevel > 1) {
            print "REC:  updating for item \"".$ItemId."\"<br>\n";
        }

        # make sure we have item IDs available
        $this->loadItemIds();

        # clear existing correlations for this item
        $this->DB->query("DELETE FROM RecContentCorrelations "
                ."WHERE ItemIdA = ".$ItemId."");

        # for every item
        foreach ($this->ItemIds as $Id) {
            # if full pass and item is later in list than current item
            if (($FullPass == false) || ($Id > $ItemId)) {
                # update correlation value for item and target item
                $this->updateContentCorrelation($ItemId, $Id);
            }
        }
    }

    /**
     * Drop item from stored recommender data.
     * @param int $ItemId ID of item to drop.
     */
    public function dropItem($ItemId): void
    {
        # drop all correlation entries referring to item
        $this->DB->query("DELETE FROM RecContentCorrelations "
                         ."WHERE ItemIdA = ".$ItemId." "
                            ."OR ItemIdB = ".$ItemId);
    }

    /**
     * Prune any stored correlation values that are below-average.
     */
    public function pruneCorrelations(): void
    {
        # get average correlation
        $AverageCorrelation = $this->DB->query("SELECT AVG(Correlation) "
                ."AS Average FROM RecContentCorrelations", "Average");

        # dump all below-average correlations
        if ($AverageCorrelation > 0) {
            $this->DB->query("DELETE FROM RecContentCorrelations "
                    ."WHERE Correlation <= ".$AverageCorrelation."");
        }
    }

    /**
     * Retrieve all item IDs.
     * @return Array of item IDs.
     */
    public function getItemIds()
    {
        if (self::$ItemIdCache === null) {
            $this->DB->query("SELECT ".$this->ItemIdFieldName." AS Id FROM "
                    .$this->ItemTableName." ORDER BY ".$this->ItemIdFieldName);
            self::$ItemIdCache = $this->DB->fetchColumn("Id");
        }
        return self::$ItemIdCache;
    }

    /**
     * Clear internal caches of item and correlation data.  This is primarily
     * intended for situations where memory may have run low.
     */
    public static function clearCaches(): void
    {
        self::$CorrelationCache = null;
        self::$ItemIdCache = null;
        self::$ItemDataCache = null;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $ItemIds;

    private $ContentCorrelationThreshold;
    private $ContentFields;
    private $ItemTableName;
    private $RatingTableName;
    private $ItemIdFieldName;
    private $UserIdFieldName;
    private $RatingFieldName;
    private $DB;
    private $FilterFuncs;
    private $LastSearchTime;
    private $NumberOfResultsAvailable;
    private $DebugLevel;

    private static $ItemIdCache = null;
    private static $ItemDataCache = null;
    private static $CorrelationCache = null;

    /**
     * Load internal item ID cache (if not already loaded).
     */
    protected function loadItemIds(): void
    {
        # if item IDs not already loaded
        if (!isset($this->ItemIds)) {
            # load item IDs from DB
            $this->DB->query("SELECT ".$this->ItemIdFieldName." AS Id FROM "
                    .$this->ItemTableName." ORDER BY ".$this->ItemIdFieldName);
            $this->ItemIds = $this->DB->fetchColumn("Id");
        }
    }

    /**
     * Get value for a given field.
     * @param int $ItemId Item to retreive value from
     * @param string $FieldName Field name to retrieve
     * @return mixed Value for requested field
     */
    abstract protected function getFieldValue(int $ItemId, string $FieldName);

    /**
     * Get data for field.
     * @param int $ItemId ID of item to retrieve data for.
     * @param string $FieldName Name of field.
     * @return array Retrieved data.
     */
    protected function getFieldData($ItemId, $FieldName)
    {
        # if data not already loaded
        if (!isset(self::$ItemDataCache[$ItemId][$FieldName])) {
            # load field value from DB
            $FieldValue = $this->getFieldValue($ItemId, $FieldName);

            # if field value is array
            if (is_array($FieldValue)) {
                # concatenate together text from array elements
                $FieldValue = implode(" ", $FieldValue);
            }

            # normalize text and break into word array
            self::$ItemDataCache[$ItemId][$FieldName] =
                    $this->normalizeAndParseText($FieldValue);
        }

        # return cached data to caller
        return self::$ItemDataCache[$ItemId][$FieldName];
    }

    /**
     * Calculate content correlation between two items and return value to caller.
     * @param int $ItemIdA ID for first item.
     * @param int $ItemIdB ID for second item.
     * @param array $FieldList List of fields to correlate.  (OPTIONAL, defaults
     *       to all fields)
     * @return int Correlation value.
     */
    protected function calculateContentCorrelation($ItemIdA, $ItemIdB, $FieldList = null)
    {
        if ($this->DebugLevel > 10) {
            print("REC:  calculating correlation"
                ." between items $ItemIdA and $ItemIdB<br>\n");
        }

        # order item ID numbers
        if ($ItemIdA > $ItemIdB) {
            $Temp = $ItemIdA;
            $ItemIdA = $ItemIdB;
            $ItemIdB = $Temp;
        }

        # if we already have the correlation
        if (isset(self::$CorrelationCache[$ItemIdA][$ItemIdB])) {
            # retrieve correlation from cache
            $TotalCorrelation = self::$CorrelationCache[$ItemIdA][$ItemIdB];
        } else {
            # if list of fields to correlate specified
            if ($FieldList !== null) {
                # create list with only specified fields
                $ContentFields = [];
                foreach ($FieldList as $FieldName) {
                    $ContentFields[$FieldName] = $this->ContentFields[$FieldName];
                }
            } else {
                # use all fields
                $ContentFields = $this->ContentFields;
            }

            # for each content field
            $TotalCorrelation = 0;
            foreach ($ContentFields as $FieldName => $FieldAttributes) {
                # if field is of a type that we use for correlation
                $FieldType = intval($FieldAttributes["FieldType"]);
                if (($FieldType == self::CONTENTFIELDTYPE_TEXT)
                        || ($FieldType == self::CONTENTFIELDTYPE_CONTROLLEDNAME)) {
                    # load data
                    $ItemAData = $this->getFieldData($ItemIdA, $FieldName);
                    $ItemBData = $this->getFieldData($ItemIdB, $FieldName);
                    if ($this->DebugLevel > 15) {
                        print "REC:  loaded ".count($ItemAData)
                                ." terms for item #".$ItemIdA." and "
                                .count($ItemBData)." terms for item #"
                                .$ItemIdB." for field \"".$FieldName."\"<br>\n";
                    }

                    # get correlation
                    $Correlation = $this->calcTextCorrelation(
                        $ItemAData,
                        $ItemBData
                    );

                    # add correlation multiplied by weight to total
                    $TotalCorrelation += $Correlation * $FieldAttributes["Weight"];
                }
            }

            # store correlation to cache
            self::$CorrelationCache[$ItemIdA][$ItemIdB] = $TotalCorrelation;
        }

        # return correlation value to caller
        if ($this->DebugLevel > 9) {
            print("REC:  correlation between items $ItemIdA and $ItemIdB"
                    ." found to be $TotalCorrelation<br>\n");
        }
        return $TotalCorrelation;
    }

    /**
     * Calculate content correlation between two items and update in DB.
     * @param int $ItemIdA ID for first item.
     * @param int $ItemIdB ID for second item.
     */
    protected function updateContentCorrelation($ItemIdA, $ItemIdB): void
    {
        if ($this->DebugLevel > 6) {
            print("REC:  updating correlation between"
                ." items $ItemIdA and $ItemIdB<br>\n");
        }

        # bail out if two items are the same
        if ($ItemIdA == $ItemIdB) {
            return;
        }

        # calculate correlation
        $Correlation = $this->calculateContentCorrelation($ItemIdA, $ItemIdB);

        # save new correlation
        $this->contentCorrelation($ItemIdA, $ItemIdB, $Correlation);
    }

    /**
     * Normalize text string and parse into words.
     * @param string $Text Text string.
     * @return array Resulting words.
     */
    protected function normalizeAndParseText($Text)
    {
        $StopWords = array(
            "a",
            "about",
            "also",
            "an",
            "and",
            "are",
            "as",
            "at",
            "be",
            "but",
            "by",
            "can",
            "each",
            "either",
            "for",
            "from",
            "has",
            "he",
            "her",
            "here",
            "hers",
            "him",
            "his",
            "how",
            "i",
            "if",
            "in",
            "include",
            "into",
            "is",
            "it",
            "its",
            "me",
            "neither",
            "no",
            "nor",
            "not",
            "of",
            "on",
            "or",
            "so",
            "she",
            "than",
            "that",
            "the",
            "their",
            "them",
            "then",
            "there",
            "these",
            "they",
            "this",
            "those",
            "through",
            "to",
            "too",
            "very",
            "what",
            "when",
            "where",
            "while",
            "who",
            "why",
            "will",
            "you",
            ""
        );

        # strip any HTML tags
        $Text = strip_tags($Text);

        # strip any punctuation
        $Text = preg_replace("/,\\.\\?-\\(\\)\\[\\]\"/", " ", $Text);   # "

        # normalize whitespace
        $Text = trim(preg_replace("/[\\s]+/", " ", $Text));

        # convert to all lower case
        $Text = strtolower($Text);

        # split text into arrays of words
        $Words = explode(" ", $Text);

        # filter out all stop words
        $Words = array_diff($Words, $StopWords);

        # return word array to caller
        return $Words;
    }

    /**
     * Get value for correlation between two sets of words.
     * @param array $WordsA First set of words.
     * @param array $WordsB Second set of words.
     * @return int Value of correlation.
     */
    protected function calcTextCorrelation($WordsA, $WordsB)
    {
        # get array containing intersection of two word arrays
        $IntersectWords = array_intersect($WordsA, $WordsB);

        # return number of words remaining as score
        return count($IntersectWords);
    }

    /**
     * Get/set stored value for correlation between two items.
     * @param int $ItemIdA ID of first item.
     * @param int $ItemIdB ID of second item.
     * @param int $NewCorrelation New value for correlation.  (OPTIONAL)
     * @return int Current correlation value.
     */
    protected function contentCorrelation($ItemIdA, $ItemIdB, $NewCorrelation = -1)
    {
        # if item ID A is greater than item ID B
        if ($ItemIdA > $ItemIdB) {
            # swap item IDs
            $Temp = $ItemIdA;
            $ItemIdA = $ItemIdB;
            $ItemIdB = $Temp;
        }

        # if new correlation value provided
        if ($NewCorrelation != -1) {
            # if new value is above threshold
            if ($NewCorrelation >= $this->ContentCorrelationThreshold) {
                # insert new correlation value in DB
                $this->DB->query("INSERT INTO RecContentCorrelations "
                        ."(ItemIdA, ItemIdB, Correlation) "
                        ."VALUES (".$ItemIdA.", ".$ItemIdB.", ".$NewCorrelation.")");

                # return correlation value is new value
                $Correlation = $NewCorrelation;
            } else {
                # return value is zero
                $Correlation = 0;
            }
        } else {
            # retrieve correlation value from DB
            $Correlation = $this->DB->query(
                "SELECT Correlation FROM RecContentCorrelations "
                    ."WHERE ItemIdA = ".$ItemIdA." AND ItemIdB = .".$ItemIdB,
                "Correlation"
            );

            # if no value found in DB
            if ($Correlation == false) {
                # return value is zero
                $Correlation = 0;
            }
        }

        # return correlation value to caller
        return $Correlation;
    }

    /**
     * Run results through supplied filter functions.
     * @param array $Results Results to filter.
     * @return array Filtered results.
     */
    protected function filterOnSuppliedFunctions($Results)
    {
        # if filter functions have been set
        if (count($this->FilterFuncs) > 0) {
            # for each result
            foreach ($Results as $ResourceId => $Result) {
                # for each filter function
                foreach ($this->FilterFuncs as $FuncName) {
                    # if filter protected function return TRUE for result resource
                    if ($FuncName($ResourceId)) {
                        # discard result
                        if ($this->DebugLevel > 2) {
                            print("REC:      filter callback rejected resource"
                                    ." ".$ResourceId."<br>\n");
                        }
                        unset($Results[$ResourceId]);

                        # bail out of filter func loop
                        continue 2;
                    }
                }
            }
        }

        # return filtered list to caller
        return $Results;
    }
}
