<?PHP
#
#   FILE:  SearchEngine.php
#
#   Open Source Metadata Archive Search Engine (OSMASE)
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use PorterStemmer;
use ScoutLib\Database;
use ScoutLib\SearchParameterSet;

/**
 * Core metadata archive search engine class.
 */
abstract class SearchEngine
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    # possible types of logical operators
    const LOGIC_AND = 1;
    const LOGIC_OR = 2;

    # flags used for indicating field types
    const FIELDTYPE_TEXT = 1;
    const FIELDTYPE_NUMERIC = 2;
    const FIELDTYPE_DATE = 3;
    const FIELDTYPE_DATERANGE = 4;

    # flags used for indicating word states
    const WORD_PRESENT = 1;
    const WORD_EXCLUDED = 2;
    const WORD_REQUIRED = 4;

    # weight multipliers for synonym and stem matches
    const WEIGHT_SYNONYMS = 0.5;
    const WEIGHT_STEMMEDTERMS = 0.5;

    /**
     * Object constructor.
     * @param string $ItemTableName Name of database table containing items.
     * @param string $ItemIdFieldName Name of column in item database table
     *       containing item IDs.
     * @param string $ItemTypeFieldName Name of column in item database table
     *       containing item types.
     */
    public function __construct(
        string $ItemTableName,
        string $ItemIdFieldName,
        string $ItemTypeFieldName
    ) {

        # create database object for our use
        $this->DB = new Database();

        # save item access parameters
        $this->ItemTableName = $ItemTableName;
        $this->ItemIdFieldName = $ItemIdFieldName;
        $this->ItemTypeFieldName = $ItemTypeFieldName;

        # set default debug state
        $this->DebugLevel = 0;
    }

    /**
     * Add field to include in searching.
     * @param int $FieldId ID of field.
     * @param int $FieldType Type of field (FIELDTYPE_ constant value).
     * @param mixed $ItemTypes Item type or array of item types to which the
     *       field applies.
     * @param int $Weight Numeric search weight for field.
     * @param bool $UsedInKeywordSearch If TRUE, field is included in keyword
     *       searches.
     */
    public static function addField(
        int $FieldId,
        int $FieldType,
        $ItemTypes,
        int $Weight,
        bool $UsedInKeywordSearch
    ): void {

        # save values
        self::$FieldInfo[$FieldId]["FieldType"] = $FieldType;
        self::$FieldInfo[$FieldId]["Weight"] = $Weight;
        self::$FieldInfo[$FieldId]["InKeywordSearch"] =
            $UsedInKeywordSearch ? true : false;
        self::$FieldInfo[$FieldId]["ItemTypes"] = is_array($ItemTypes)
            ? $ItemTypes : array($ItemTypes);
    }

    /**
     * Get type of specified field (text/numeric/date/daterange).
     * @param int $FieldId ID of field.
     * @return int Field type (FIELDTYPE_ constant).
     */
    public function fieldType(int $FieldId): int
    {
        return self::$FieldInfo[$FieldId]["FieldType"];
    }

    /**
     * Get search weight for specified field.
     * @param int $FieldId ID of field.
     * @return int Search weight.
     */
    public function fieldWeight(int $FieldId): int
    {
        return self::$FieldInfo[$FieldId]["Weight"];
    }

    /**
     * Get whether specified field is included in keyword searches.
     * @param int $FieldId ID of field.
     * @return bool TRUE if field is included in keyword search, otherwise FALSE.
     */
    public function fieldInKeywordSearch(int $FieldId): bool
    {
        return self::$FieldInfo[$FieldId]["InKeywordSearch"];
    }

    /**
     * Set debug output level.  Values above zero trigger diagnostic output.
     * @param int $NewValue New debugging level.
     */
    public function debugLevel(int $NewValue): void
    {
        $this->DebugLevel = $NewValue;
    }


    # ---- search functions

    /**
     * Perform search with specified parameters, returning results in a
     * flat array indexed by item ID.  If multiple types of items are present
     * in the results, they will first be sorted by item type, and then
     * within item type will be sorted by whatever sorting parameters were
     * specified.
     * @param mixed $SearchParams Search parameters as SearchParameterSet
     *       object or keyword search string.
     * @return array Array of search result scores, indexed by item ID.
     */
    public function search($SearchParams): array
    {
        return self::flattenMultiTypeResults($this->searchAll($SearchParams));
    }

    /**
     * Perform search with specified parameters, returning results separated
     * by item type.
     * @param mixed $SearchParams Search parameters as SearchParameterSet
     *       object or keyword search string.
     * @return array Array of arrays of search result scores, with the item
     *       type for the first index and the IDs of items found by search
     *       as the second index.
     */
    public function searchAll($SearchParams): array
    {
        # if keyword search string was passed in
        if (is_string($SearchParams)) {
            # convert string to search parameter set
            $SearchString = $SearchParams;
            $SearchParams = new SearchParameterSet();
            $SearchParams->addParameter($SearchString);
        }

        # interpret and filter out magic debugging keyword (if any)
        $KeywordStrings = $SearchParams->GetKeywordSearchStrings();
        foreach ($KeywordStrings as $String) {
            $FilteredString = $this->extractDebugLevel($String);
            if ($FilteredString != $String) {
                $SearchParams->removeParameter($String);
                $SearchParams->addParameter($FilteredString);
            }
        }
        $this->dMsg(0, "Description: " . $SearchParams->textDescription());

        # save start time to use in calculating search time
        $StartTime = microtime(true);

        # clear parsed search term list
        $this->SearchTermList = array();

        # perform search
        $Scores = $this->rawSearch($SearchParams);

        # count, sort, and trim search result scores list
        $Scores = $this->sortScores(
            $Scores,
            $SearchParams->sortBy(),
            $SearchParams->sortDescending()
        );

        # record search time
        $this->LastSearchTime = microtime(true) - $StartTime;

        # return search results to caller
        $this->dMsg(0, "Ended up with " . $this->NumberOfResultsAvailable . " results");
        return $Scores;
    }

    /**
     * Add function that will be called to filter search results.
     * @param callable $FunctionName Function to be called.
     */
    public function addResultFilterFunction(callable $FunctionName): void
    {
        # save filter function name
        $this->FilterFuncs[] = $FunctionName;
    }

    /**
     * Get number of results found by most recent search.
     * @param int $ItemType Type of item.  (OPTIONAL, defaults to total
     *       for all items)
     * @return int Result count.
     */
    public function numberOfResults(?int $ItemType = null): int
    {
        return ($ItemType === null) ? $this->NumberOfResultsAvailable
            : (isset($this->NumberOfResultsPerItemType[$ItemType])
                ? $this->NumberOfResultsPerItemType[$ItemType] : 0);
    }

    /**
     * Get normalized list of search terms.
     * @return array Array of search terms.
     */
    public function searchTerms(): array
    {
        return $this->SearchTermList;
    }

    /**
     * Get time that last search took, in seconds.
     * @return float Time in seconds, with microseconds.
     */
    public function searchTime(): float
    {
        return $this->LastSearchTime;
    }

    /**
     * Get total of weights for all fields involved in search, useful for
     * assessing scale of scores in search results.
     * @param SearchParameterSet $SearchParams Search parameters.
     * @return int Total of weights.
     */
    public function fieldedSearchWeightScale(SearchParameterSet $SearchParams): int
    {
        $Weight = 0;
        $FieldIds = $SearchParams->getFields();
        foreach ($FieldIds as $FieldId) {
            if (array_key_exists($FieldId, self::$FieldInfo)) {
                $Weight += self::$FieldInfo[$FieldId]["Weight"];
            }
        }
        if (count($SearchParams->getKeywordSearchStrings())) {
            foreach (self::$FieldInfo as $FieldId => $Info) {
                if ($Info["InKeywordSearch"]) {
                    $Weight += $Info["Weight"];
                }
            }
        }
        return $Weight;
    }


    # ---- search database update functions

    /**
     * Update search database for the specified item.
     * @param int $ItemId ID of item.
     * @param int $ItemType Numerical type of item.
     */
    public function updateForItem(int $ItemId, int $ItemType): void
    {
        # delete any existing info for this item
        $this->DB->query("DELETE FROM SearchWordCounts WHERE ItemId = " . $ItemId);
        $this->DB->query("DELETE FROM SearchItemTypes WHERE ItemId = " . $ItemId);

        # save item type
        $this->DB->query("INSERT INTO SearchItemTypes (ItemId, ItemType)"
            . " VALUES (" . intval($ItemId) . ", " . intval($ItemType) . ")");

        # for each metadata field
        foreach (self::$FieldInfo as $FieldId => $Info) {
            # if valid search weight for field and field applies to this item
            if (($Info["Weight"] > 0)
                && in_array($ItemType, $Info["ItemTypes"])) {
                # retrieve text for field
                $Text = $this->getFieldContent($ItemId, $FieldId);

                # if text is array
                if (is_array($Text)) {
                    # for each text string in array
                    foreach ($Text as $String) {
                        # record search info for text
                        $this->recordSearchInfoForText(
                            $ItemId,
                            $FieldId,
                            $Info["Weight"],
                            $String,
                            $Info["InKeywordSearch"]
                        );
                    }
                } elseif (is_string($Text) && strlen($Text)) {
                    # record search info for text
                    $this->recordSearchInfoForText(
                        $ItemId,
                        $FieldId,
                        $Info["Weight"],
                        $Text,
                        $Info["InKeywordSearch"]
                    );
                }
            }
        }
    }

    /**
     * Update search database for the specified range of items.
     * @param int $StartingItemId ID of item to start with.
     * @param int $NumberOfItems Maximum number of items to update.
     * @return int ID of last item updated, or PHP_INT_MAX if no items
     *      were found to be updated.
     */
    public function updateForItems(int $StartingItemId, int $NumberOfItems): int
    {
        # retrieve IDs for specified number of items starting at specified ID
        $this->DB->query("SELECT " . $this->ItemIdFieldName . ", " . $this->ItemTypeFieldName
            . " FROM " . $this->ItemTableName
            . " WHERE " . $this->ItemIdFieldName . " >= " . $StartingItemId
            . " ORDER BY " . $this->ItemIdFieldName . " LIMIT " . $NumberOfItems);
        $ItemIds = $this->DB->fetchColumn(
            $this->ItemTypeFieldName,
            $this->ItemIdFieldName
        );

        # for each retrieved item ID
        $ItemId = PHP_INT_MAX;
        foreach ($ItemIds as $ItemId => $ItemType) {
            # update search info for item
            $this->updateForItem($ItemId, $ItemType);
        }

        # return ID of last item updated to caller
        return $ItemId;
    }

    /**
     * Drop all data pertaining to item from search database.
     * @param int $ItemId ID of item to drop from database.
     */
    public function dropItem(int $ItemId): void
    {
        # drop all entries pertaining to item from word count table
        $this->DB->query("DELETE FROM SearchWordCounts WHERE ItemId = " . $ItemId);
        $this->DB->query("DELETE FROM SearchItemTypes WHERE ItemId = " . $ItemId);
    }

    /**
     * Drop all data pertaining to field from search database.
     * @param int $FieldId ID of field to drop.
     */
    public function dropField(int $FieldId): void
    {
        # drop all entries pertaining to field from word counts table
        $this->DB->query("DELETE FROM SearchWordCounts WHERE FieldId = \'" . $FieldId . "\'");
    }

    /**
     * Get total number of search terms indexed by search engine.
     * @return int Count of terms.
     */
    public function searchTermCount(): int
    {
        return $this->DB->query("SELECT COUNT(*) AS TermCount"
            . " FROM SearchWords", "TermCount");
    }

    /**
     * Get total number of items indexed by search engine.
     * @return int Count of items.
     */
    public function itemCount(): int
    {
        return $this->DB->query("SELECT COUNT(DISTINCT ItemId) AS ItemCount"
            . " FROM SearchWordCounts", "ItemCount");
    }

    /**
     * Add synonyms.
     * @param string $Word Word for which synonyms should apply.
     * @param array $Synonyms Array of synonyms.
     * @return int Count of new synonyms added.  (May be less than the number
     *       passed in, if some synonyms were already defined.)
     */
    public function addSynonyms(string $Word, array $Synonyms): int
    {
        # asssume no synonyms will be added
        $AddCount = 0;

        # get ID for word
        $WordId = $this->getWordId($Word, true);

        # for each synonym passed in
        foreach ($Synonyms as $Synonym) {
            # get ID for synonym
            $SynonymId = $this->getWordId($Synonym, true);

            $this->DB->query("LOCK TABLES SearchWordSynonyms WRITE");
            # if synonym is not already in database
            $this->DB->query("SELECT * FROM SearchWordSynonyms"
                . " WHERE (WordIdA = " . $WordId
                . " AND WordIdB = " . $SynonymId . ")"
                . " OR (WordIdB = " . $WordId
                . " AND WordIdA = " . $SynonymId . ")");
            if ($this->DB->NumRowsSelected() == 0) {
                # add synonym entry to database
                $this->DB->query("INSERT INTO SearchWordSynonyms"
                    . " (WordIdA, WordIdB)"
                    . " VALUES (" . $WordId . ", " . $SynonymId . ")");
                $AddCount++;
            }
            $this->DB->query("UNLOCK TABLES");
        }

        # report to caller number of new synonyms added
        return $AddCount;
    }

    /**
     * Remove synonym(s).
     * @param string $Word Word for which synonyms should apply.
     * @param array $Synonyms Array of synonyms to remove.  If not
     *       specified, all synonyms for word will be removed.  (OPTIONAL)
     */
    public function removeSynonyms(string $Word, ?array $Synonyms = null): void
    {
        # find ID for word
        $WordId = $this->getWordId($Word);

        # if ID found
        if ($WordId !== null) {
            # if no specific synonyms provided
            if ($Synonyms === null) {
                # remove all synonyms for word
                $this->DB->query("DELETE FROM SearchWordSynonyms"
                    . " WHERE WordIdA = '" . $WordId . "'"
                    . " OR WordIdB = '" . $WordId . "'");
            } else {
                # for each specified synonym
                foreach ($Synonyms as $Synonym) {
                    # look up ID for synonym
                    $SynonymId = $this->getWordId($Synonym);

                    # if synonym ID was found
                    if ($SynonymId !== null) {
                        # delete synonym entry
                        $this->DB->query("DELETE FROM SearchWordSynonyms"
                            . " WHERE (WordIdA = '" . $WordId . "'"
                            . " AND WordIdB = '" . $SynonymId . "')"
                            . " OR (WordIdB = '" . $WordId . "'"
                            . " AND WordIdA = '" . $SynonymId . "')");
                    }
                }
            }
        }
    }

    /**
     * Remove all synonyms.
     */
    public function removeAllSynonyms(): void
    {
        $this->DB->query("DELETE FROM SearchWordSynonyms");
    }

    /**
     * Get synonyms for word.
     * @param string $Word Word for which synonyms should apply.
     * @return array Array of synonyms.
     */
    public function getSynonyms(string $Word): array
    {
        # assume no synonyms will be found
        $Synonyms = array();

        # look up ID for word
        $WordId = $this->getWordId($Word);

        # if word ID was found
        if ($WordId !== null) {
            # look up IDs of all synonyms for this word
            $this->DB->query("SELECT WordIdA, WordIdB FROM SearchWordSynonyms"
                . " WHERE WordIdA = " . $WordId
                . " OR WordIdB = " . $WordId);
            $SynonymIds = array();
            while ($Record = $this->DB->fetchRow) {
                $SynonymIds[] = ($Record["WordIdA"] == $WordId)
                    ? $Record["WordIdB"] : $Record["WordIdA"];
            }

            # for each synonym ID
            foreach ($SynonymIds as $SynonymId) {
                # look up synonym word and add to synonym list
                $Synonyms[] = $this->getWord($SynonymId);
            }
        }

        # return synonyms to caller
        return $Synonyms;
    }

    /**
     * Get all synonyms.
     * @return array Array of arrays of synonyms, with words for index.
     */
    public function getAllSynonyms(): array
    {
        # assume no synonyms will be found
        $SynonymList = array();

        # for each synonym ID pair
        $OurDB = new Database();
        $OurDB->query("SELECT WordIdA, WordIdB FROM SearchWordSynonyms");
        while ($Record = $OurDB->fetchRow()) {
            # look up words
            $Word = $this->getWord($Record["WordIdA"]);
            $Synonym = $this->getWord($Record["WordIdB"]);

            # if we do not already have an entry for the word
            #       or synonym is not listed for this word
            if (!isset($SynonymList[$Word])
                || !in_array($Synonym, $SynonymList[$Word])) {
                # add entry for synonym
                $SynonymList[$Word][] = $Synonym;
            }

            # if we do not already have an entry for the synonym
            #       or word is not listed for this synonym
            if (!isset($SynonymList[$Synonym])
                || !in_array($Word, $SynonymList[$Synonym])) {
                # add entry for word
                $SynonymList[$Synonym][] = $Word;
            }
        }

        # for each word
        # (this loop removes reciprocal duplicates)
        foreach ($SynonymList as $Word => $Synonyms) {
            # for each synonym for that word
            foreach ($Synonyms as $Synonym) {
                # if synonym has synonyms and word is one of them
                if (isset($SynonymList[$Synonym])
                    && isset($SynonymList[$Word])
                    && in_array($Word, $SynonymList[$Synonym])
                    && in_array($Synonym, $SynonymList[$Word])) {
                    # if word has less synonyms than synonym
                    if (count($SynonymList[$Word])
                        < count($SynonymList[$Synonym])) {
                        # remove synonym from synonym list for word
                        $SynonymList[$Word] = array_diff(
                            $SynonymList[$Word],
                            array($Synonym)
                        );

                        # if no synonyms left for word
                        if (!count($SynonymList[$Word])) {
                            # remove empty synonym list for word
                            unset($SynonymList[$Word]);
                        }
                    } else {
                        # remove word from synonym list for synonym
                        $SynonymList[$Synonym] = array_diff(
                            $SynonymList[$Synonym],
                            array($Word)
                        );

                        # if no synonyms left for word
                        if (!count($SynonymList[$Synonym])) {
                            # remove empty synonym list for word
                            unset($SynonymList[$Synonym]);
                        }
                    }
                }
            }
        }

        # sort array alphabetically (just for convenience)
        foreach ($SynonymList as $Word => $Synonyms) {
            asort($SynonymList[$Word]);
        }
        ksort($SynonymList);

        # return 2D array of synonyms to caller
        return $SynonymList;
    }

    /**
     * Set all synonyms.  This removes any existing synonyms and replaces
     * them with the synonyms passed in.
     * @param array $SynonymList Array of arrays of synonyms, with words for index.
     */
    public function setAllSynonyms(array $SynonymList): void
    {
        # remove all existing synonyms
        $this->removeAllSynonyms();

        # for each synonym entry passed in
        foreach ($SynonymList as $Word => $Synonyms) {
            # add synonyms for word
            $this->addSynonyms($Word, $Synonyms);
        }
    }

    /**
     * Load synonyms from a file.  Each line of file should contain one word
     *   at the beginning of the line, followed by one or more synonyms
     *   separated by spaces or commas.  Blank lines or lines beginning with
     *   "#" (i.e. comments) will be ignored.
     * @param string $FileName Name of file containing synonyms (with path if needed).
     * @return array of synonyms added
     * @throws Exception parseSynonymsFromText will throw an exception on
     *   non-alphanumeric character/duplicate synonyms
     */
    public function parseSynonymsFromFile(string $FileName): array
    {
        # initialize empty array to hold synonyms
        $Synonyms = [];

        if (!is_readable($FileName)) {
            throw new Exception($FileName . " isn't a readable file.");
        }

        # read in contents of file
        $Lines = file($FileName, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        # if file contained lines
        if (($Lines !== false) && count($Lines)) {
            # parse synonyms from text
            $Synonyms = $this->parseSynonymsFromText($Lines);
        }

        # return synonyms to caller
        return $Synonyms;
    }

    /**
     * Parse synonyms given a list of lines to parse from
     * @param array $Text to parse
     * @return array Dictionary of arrays indexed on target term
     * @throws Exception on finding a non-alphanumeric character in a word or
     *   if duplicate synonyms
     */
    public function parseSynonymsFromText(array $Text): array
    {
        $Synonyms = [];

        foreach ($Text as $Line) {
            # strip comments from lines
            $Line = preg_replace('/\s*#.*/', '', $Line);

            # if not content after stripping comments, continue
            if (strlen($Line) == 0) {
                continue;
            }

            # split line into words
            $Words = preg_split("/[\s,=]+/", $Line);

            # if no words found, skip line
            if (($Words === false) || (count($Words) == 0)) {
                continue;
            }

            # make sure all words are alphanumeric, if not throw exception
            foreach ($Words as $Index => $Word) {
                if (!strlen($Word)) {
                    unset($Words[$Index]);
                    continue;
                }
                if (!ctype_alnum($Word)) {
                    throw new Exception(
                        "Synonym text parsing encountered non-alphanumeric "
                        ."word '" . $Word . "'"
                    );
                }
            }

            # separate out word and synonyms
            $Word = array_shift($Words);

            if (isset($Synonyms[$Word]) && count(array_intersect($Words, $Synonyms[$Word]))) {
                $Duplicates = implode(", ", array_intersect($Words, $Synonyms[$Word]));
                throw new Exception(
                    "Duplicate synonym(s) found for '" . $Word . "': " . $Duplicates
                );
            }

            # add synonyms
            $Synonyms[$Word] = $Words;
        }

        return $Synonyms;
    }

    /**
     * Flatten a two-dimensional array keyed by ItemType with results
     * for each type as the outer values into
     * array(ItemId => ItemScore).
     * @param array $Results Two-dimensional array(ItemType => array(ItemId => ItemScore)).
     * @return array One-dimensional array, with item IDs for the index.
     */
    public static function flattenMultiTypeResults(array $Results): array
    {
        $FlatScores = [];
        foreach ($Results as $ItemType => $ItemScores) {
            $FlatScores += $ItemScores;
        }

        return $FlatScores;
    }

    /**
     * Expand a one-dimensional array(ItemId => ItemScore) into a
     * two-dimensional array(ItemType => array(ItemId => ItemScore)).
     * @param array $Results One-dimensional array(ItemId => ItemScore).
     * @return array Two-dimensional array, with item types for the top-level
     *       (outer) index, and item ID for the second level (inner) index.
     */
    public static function buildMultiTypeResults(array $Results): array
    {
        $DB = new Database();
        $DB->query("SELECT * FROM SearchItemTypes");
        $ItemTypes = $DB->fetchColumn("ItemType", "ItemId");

        $SplitScores = [];
        foreach ($Results as $ItemId => $ItemScore) {
            $ItemType = $ItemTypes[$ItemId];
            $SplitScores[$ItemType][$ItemId] = $ItemScore;
        }

        return $SplitScores;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    protected $DB;
    protected $DebugLevel;
    protected $FilterFuncs;
    protected $ItemIdFieldName;
    protected $ItemTableName;
    protected $ItemTypeFieldName;
    protected $LastSearchTime;
    protected $NumberOfResultsAvailable;
    protected $NumberOfResultsPerItemType;
    protected $StemmingEnabled = true;
    protected $SynonymsEnabled = true;

    private $ExcludedTermCount;
    private $FieldIds;
    private $InclusiveTermCount;
    private $RequiredTermCount;
    private $RequiredTermCounts;
    private $SearchTermList;

    private static $ItemTypeCache = [];
    private static $FieldInfo = [];

    const KEYWORD_FIELD_ID = -100;
    const STEM_ID_OFFSET = 1000000;


    # ---- private methods (searching)

    /**
     * Perform search based on supplied parameters.  This is an internal
     * method, with no timing or result score cleaning or other trappings,
     * suitable for recursively searching subgroups.
     * @param SearchParameterSet $SearchParams Search parameter set.
     * @return array Result scores, with item IDs for the index.
     */
    private function rawSearch(SearchParameterSet $SearchParams): array
    {
        # retrieve search strings
        $SearchStrings = $SearchParams->getSearchStrings();
        $KeywordSearchStrings = $SearchParams->getKeywordSearchStrings();

        # add keyword searches (if any) to fielded searches
        if (count($KeywordSearchStrings)) {
            $SearchStrings[self::KEYWORD_FIELD_ID] = $KeywordSearchStrings;
        }

        # normalize search strings
        $NormalizedSearchStrings = array();
        foreach ($SearchStrings as $FieldId => $SearchStringArray) {
            if (!is_array($SearchStringArray)) {
                $SearchStringArray = array($SearchStringArray);
            }
            foreach ($SearchStringArray as $String) {
                $String = trim($String);
                if (strlen($String)) {
                    $NormalizedSearchStrings[$FieldId][] = $String;
                }
            }
        }
        $SearchStrings = $NormalizedSearchStrings;

        # if we have strings to search for
        $Scores = [];
        if (count($SearchStrings)) {
            # perform search
            $Scores = $this->searchAcrossFields(
                $SearchStrings,
                $SearchParams->logic()
            );

            $this->dMsg(2, "Have " . count($Scores)
                . " results after search string processing");

            # if this search uses AND logic and has SearchStrings that don't give any results,
            # cease processing because we're done
            if (($SearchParams->logic() == "AND") && (count($Scores) == 0)) {
                return $Scores;
            }
        }

        # for each subgroup
        foreach ($SearchParams->getSubgroups() as $Subgroup) {
            # skip subgroup if it has no parameters
            if ($Subgroup->parameterCount() == 0) {
                continue;
            }

            # perform subgroup search
            $NewScores = $this->rawSearch($Subgroup);

            # added subgroup search scores to previous scores as appropriate
            if (count($Scores)) {
                $Scores = $this->combineScores(
                    $Scores,
                    $NewScores,
                    $SearchParams->logic()
                );
            } else {
                $Scores = $NewScores;
            }

            # if this group has AND logic and we've hit a group with no results,
            # cease processing because we're done
            if (($SearchParams->logic() == "AND") && (count($Scores) == 0)) {
                break;
            }
        }
        if (isset($NewScores)) {
            $this->dMsg(2, "Have " . count($Scores)
                . " results after subgroup processing");
        }

        # pare down results to just allowed item types (if specified)
        $AllowedItemTypes = $SearchParams->itemTypes();
        if ($AllowedItemTypes !== false) {
            $this->loadItemTypeCache(array_keys($Scores));
            foreach ($Scores as $ItemId => $Score) {
                if (!in_array($this->getItemType($ItemId), $AllowedItemTypes)) {
                    unset($Scores[$ItemId]);
                }
            }
            $this->dMsg(3, "Have " . count($Scores)
                . " results after paring to allowed item types");
        }

        # return search results to caller
        return $Scores;
    }

    /**
     * Combine two sets of search result scores, based on specified logic.
     * @param array $ScoresA First set of scores.
     * @param array $ScoresB Second set of scores.
     * @param string $Logic Logic to use ("AND" or "OR").
     * @return array Resulting combined scores.
     */
    private function combineScores(array $ScoresA, array $ScoresB, string $Logic): array
    {
        if ($Logic == "OR") {
            $Scores = $ScoresA;
            foreach ($ScoresB as $ItemId => $Score) {
                if (isset($Scores[$ItemId])) {
                    $Scores[$ItemId] += $Score;
                } else {
                    $Scores[$ItemId] = $Score;
                }
            }
        } else {
            $Scores = array();
            foreach ($ScoresA as $ItemId => $Score) {
                if (isset($ScoresB[$ItemId])) {
                    $Scores[$ItemId] = $Score + $ScoresB[$ItemId];
                }
            }
        }
        return $Scores;
    }

    /**
     * Perform search across multiple fields and return raw (untrimmed)
     * results to caller.
     * @param array $SearchStrings Array of search strings, with field IDs
     *       for index.
     * @param string $Logic Search logic ("AND" or "OR").
     * @return array Array of search result scores, with the IDs of items
     *       found by search as the index.
     */
    private function searchAcrossFields(array $SearchStrings, string $Logic): array
    {
        # start by assuming no search will be done
        $Scores = array();

        # clear word counts
        $this->ExcludedTermCount = 0;
        $this->InclusiveTermCount = 0;
        $this->RequiredTermCount = 0;
        $this->RequiredTermCounts = array();

        # for each field
        $NeedComparisonSearch = false;
        foreach ($SearchStrings as $FieldId => $SearchStringArray) {
            # for each search string for this field
            foreach ($SearchStringArray as $SearchString) {
                # if field is keyword or field is text and does not look
                #       like comparison match
                $NotComparisonSearch = !preg_match(
                    self::COMPARISON_OPERATOR_PATTERN,
                    $SearchString
                );
                if (($FieldId == self::KEYWORD_FIELD_ID)
                    || (isset(self::$FieldInfo[$FieldId])
                        && (self::$FieldInfo[$FieldId]["FieldType"]
                            == self::FIELDTYPE_TEXT)
                        && $NotComparisonSearch)) {
                    if ($FieldId == self::KEYWORD_FIELD_ID) {
                        $this->dMsg(0, "Performing keyword search for string \""
                            . $SearchString . "\"");
                    } else {
                        $this->dMsg(0, "Searching text field "
                            . $FieldId . " for string \"" . $SearchString . "\"");
                    }

                    # normalize text and split into words
                    $Words[$FieldId] =
                        $this->parseSearchStringForWords($SearchString, $Logic);

                    # calculate scores for matching items
                    if (count($Words[$FieldId])) {
                        $Scores = $this->searchForWords(
                            $Words[$FieldId],
                            $FieldId,
                            $Scores
                        );
                        $this->dMsg(3, "Have "
                            . count($Scores) . " results after word search");
                    }

                    # split into phrases
                    $Phrases[$FieldId] = $this->parseSearchStringForPhrases(
                        $SearchString,
                        $Logic
                    );

                    # handle any phrases
                    if (count($Phrases[$FieldId])) {
                        $Scores = $this->searchForPhrases(
                            $Phrases[$FieldId],
                            $Scores,
                            $FieldId,
                            true,
                            false
                        );
                        $this->dMsg(3, "Have "
                            . count($Scores) . " results after phrase search");
                    }
                } else {
                    # set flag to indicate possible comparison search candidate found
                    $NeedComparisonSearch = true;
                }
            }
        }

        # perform comparison searches
        if ($NeedComparisonSearch) {
            $Scores = $this->searchForComparisonMatches(
                $SearchStrings,
                $Logic,
                $Scores
            );
            $this->dMsg(3, "Have " . count($Scores) . " results after comparison search");
        }

        # if no results found, no required terms, and exclusions specified
        if ((count($Scores) == 0)
                && ($this->RequiredTermCount == 0)
                && ($this->ExcludedTermCount > 0)) {
            # determine which item types are implicated for keyword searches
            $KeywordItemTypes = [];
            foreach (self::$FieldInfo as $FieldId => $Info) {
                if ($Info["InKeywordSearch"]) {
                    $KeywordItemTypes = array_merge(
                        $KeywordItemTypes,
                        $Info["ItemTypes"]
                    );
                }
            }
            $KeywordItemTypes = array_unique($KeywordItemTypes);

            # determine what item types were in use for the fields we
            # are searching
            $FieldTypes = [];
            foreach ($SearchStrings as $FieldId => $Info) {
                $MyTypes = ($FieldId == self::KEYWORD_FIELD_ID) ?
                    $KeywordItemTypes :
                    self::$FieldInfo[$FieldId]["ItemTypes"];

                $FieldTypes = array_merge(
                    $FieldTypes,
                    $MyTypes
                );
            }
            $FieldTypes = array_unique($FieldTypes);

            # load all records for these field types
            $Scores = $this->loadScoresForAllRecords($FieldTypes);
        }

        # if search results found
        if (count($Scores)) {
            # for each search text string
            foreach ($SearchStrings as $FieldId => $SearchStringArray) {
                # for each search string for this field
                foreach ($SearchStringArray as $SearchString) {
                    # if field is text
                    if (($FieldId == self::KEYWORD_FIELD_ID)
                        || (isset(self::$FieldInfo[$FieldId])
                            && (self::$FieldInfo[$FieldId]["FieldType"]
                                == self::FIELDTYPE_TEXT))) {
                        # if there are words in search text
                        if (isset($Words[$FieldId])) {
                            # handle any excluded words
                            $Scores = $this->filterOnExcludedWords(
                                $Words[$FieldId],
                                $Scores,
                                $FieldId
                            );
                        }

                        # handle any excluded phrases
                        if (isset($Phrases[$FieldId])) {
                            $Scores = $this->searchForPhrases(
                                $Phrases[$FieldId],
                                $Scores,
                                $FieldId,
                                false,
                                true
                            );
                        }
                    }
                }
                $this->dMsg(3, "Have " . count($Scores)
                    . " results after processing exclusions");
            }

            # strip off any results that don't contain required words
            $Scores = $this->filterOnRequiredWords($Scores);
        }

        # return search result scores to caller
        return $Scores;
    }

    /**
     * Search for words in specified field.
     * @param array $Words Terms to search for.
     * @param int $FieldId ID of field to search.
     * @param array $Scores Existing array of search result scores to build
     *       on, with item IDs for the index and scores for the values.
     * @return array Array of search result scores, with item IDs for the
     *       index and scores for the values.
     */
    private function searchForWords(
        array $Words,
        int $FieldId,
        ?array $Scores = null
    ): array {
        # start with empty search result scores list if none passed in
        if ($Scores == null) {
            $Scores = array();
        }

        # for each word
        foreach ($Words as $Word => $Flags) {
            $Counts = [];
            if ($FieldId == self::KEYWORD_FIELD_ID) {
                $this->dMsg(2, "Performing keyword search for word \"" . $Word . "\"");
            } else {
                $this->dMsg(2, "Searching for word \"" . $Word . "\" in field " . $FieldId);
            }

            # if word is not excluded
            if (!($Flags & self::WORD_EXCLUDED)) {
                # look up word ID
                $this->dMsg(2, "Looking up word \"" . $Word . "\"");
                $WordId = $this->getWordId($Word);

                # if word ID found
                if ($WordId !== null) {
                    # look up counts for word
                    $Counts = $this->getSearchWordCounts($WordId, $FieldId, $Counts);

                    # if synonym support is enabled
                    if ($this->SynonymsEnabled) {
                        # look for any synonyms
                        $SynonymIds = $this->getSynonymIdsForWordId($WordId);

                        # for each synonym
                        foreach ($SynonymIds as $SynonymId) {
                            # retrieve counts for synonym
                            $Counts = $this->getSearchWordCounts(
                                $SynonymId,
                                $FieldId,
                                $Counts,
                                self::WEIGHT_SYNONYMS
                            );
                        }
                    }
                }

                # if stemming is enabled
                if ($this->StemmingEnabled) {
                    # retrieve word stem
                    $Stem = PorterStemmer::Stem($Word);

                    # look up stem ID
                    $this->dMsg(2, "Looking up stem \"" . $Stem . "\"");
                    $StemId = $this->getStemId($Stem);

                    # if stem ID found
                    if ($StemId !== null) {
                        # get counts for stem
                        $Counts = $this->getSearchWordCounts(
                            $StemId,
                            $FieldId,
                            $Counts,
                            self::WEIGHT_STEMMEDTERMS
                        );

                        # if stem and word are different
                        if ($Stem != $Word) {
                            # look up word ID for stem
                            $StemWordId = $this->getWordId($Stem);

                            # if word ID for stem found
                            if ($StemWordId !== null) {
                                # get counts for stem as word
                                $Counts = $this->getSearchWordCounts(
                                    $StemWordId,
                                    $FieldId,
                                    $Counts,
                                    self::WEIGHT_STEMMEDTERMS
                                );
                            }
                        }
                    }
                }

                # if counts were found
                if (count($Counts)) {
                    # for each count
                    foreach ($Counts as $ItemId => $Count) {
                        # if word flagged as required
                        if ($Flags & self::WORD_REQUIRED) {
                            # increment required word count for record
                            if (isset($this->RequiredTermCounts[$ItemId])) {
                                $this->RequiredTermCounts[$ItemId]++;
                            } else {
                                $this->RequiredTermCounts[$ItemId] = 1;
                            }
                        }

                        # add to item record score
                        if (isset($Scores[$ItemId])) {
                            $Scores[$ItemId] += $Count;
                        } else {
                            $Scores[$ItemId] = $Count;
                        }
                    }
                }
            }
        }

        # return basic scores to caller
        return $Scores;
    }

    /**
     * Retrieve counts for specified search term.
     * @param int $WordId ID of search term.
     * @param int $FieldId ID of field in which to look for term.
     * @param array $Counts Current count of term occurrences, indexed by item ID.
     * @param float $Multiplier Number to multiply by when adding to counts of
     *      term occurrences.  (OPTIONAL, defaults to 1)
     * @return array Updated count of term occurrences, indexed by item ID.
     */
    protected function getSearchWordCounts(
        int $WordId,
        int $FieldId,
        array $Counts,
        float $Multiplier = 1
    ): array {
        # retrieve counts for stem
        $this->DB->Query("SELECT ItemId,Count FROM SearchWordCounts"
            . " WHERE WordId = " . $WordId
            . " AND FieldId = " . $FieldId);
        $NewCounts = $this->DB->FetchColumn("Count", "ItemId");

        # for each count
        foreach ($NewCounts as $ItemId => $Count) {
            # adjust count if necessary
            if ($Multiplier != 1) {
                $Count = ceil($Count * $Multiplier);
            }

            # add count to existing counts
            if (isset($Counts[$ItemId])) {
                $Counts[$ItemId] += $Count;
            } else {
                $Counts[$ItemId] = $Count;
            }
        }

        # return updated counts to caller
        return $Counts;
    }

    /**
     * Retrieve IDs of synonyms (if any) for term.
     * @param int $WordId ID of term to look up synonyms for.
     * @return array IDs of any synonyms for term.
     */
    protected function getSynonymIdsForWordId(int $WordId): array
    {
        $SynonymIds = [];

        $this->DB->Query("SELECT WordIdA, WordIdB FROM SearchWordSynonyms"
            . " WHERE WordIdA = " . $WordId . " OR WordIdB = " . $WordId);

        while ($Record = $this->DB->FetchRow()) {
            $SynonymIds[] = ($Record["WordIdA"] == $WordId)
                ? $Record["WordIdB"] : $Record["WordIdA"];
        }

        return $SynonymIds;
    }

    /**
     * Extract phrases (terms surrounded by quotes) from search string.
     * @param string $SearchString Search string.
     * @param string $Logic Search logic ("AND" or "OR").
     * @return array Array with phrases for the index and word states
     *       (WORD_PRESENT, WORD_EXCLUDED, WORD_REQUIRED) for values.
     */
    private function parseSearchStringForPhrases(string $SearchString, string $Logic): array
    {
        # split into chunks delimited by double quote marks
        $Pieces = explode("\"", $SearchString);   # "

        # for each pair of chunks
        $Index = 2;
        $Phrases = array();
        while ($Index < count($Pieces)) {
            # grab phrase from chunk
            $Phrase = trim(addslashes($Pieces[$Index - 1]));
            $Flags = self::WORD_PRESENT;

            # grab first character of phrase
            $FirstChar = substr($Pieces[$Index - 2], -1);

            # set flags to reflect any option characters
            if ($FirstChar == "-") {
                $Flags |= self::WORD_EXCLUDED;
                if (!isset($Phrases[$Phrase])) {
                    $this->ExcludedTermCount++;
                }
            } else {
                if ((($Logic == "AND")
                        && ($FirstChar != "~"))
                    || ($FirstChar == "+")) {
                    $Flags |= self::WORD_REQUIRED;
                    if (!isset($Phrases[$Phrase])) {
                        $this->RequiredTermCount++;
                    }
                }
                if (!isset($Phrases[$Phrase])) {
                    $this->InclusiveTermCount++;
                    $this->SearchTermList[] = $Phrase;
                }
            }
            $Phrases[$Phrase] = $Flags;

            # move to next pair of chunks
            $Index += 2;
        }

        # return phrases to caller
        return $Phrases;
    }

    /**
     * Search for phrase in specified field.
     * @param int $FieldId ID of field to search.
     * @param string $Phrase Phrase to search for.
     */
    abstract protected function searchFieldForPhrases(
        int $FieldId,
        string $Phrase
    ): array;

    /**
     * Perform comparison searches.
     * @param array $FieldIds IDs of fields to search.
     * @param array $Operators Search operators.
     * @param array $Values Target values.
     * @param string $Logic Search logic ("AND" or "OR").
     * @return array of ItemIds that matched.
     */
    abstract protected function searchFieldsForComparisonMatches(
        array $FieldIds,
        array $Operators,
        array $Values,
        string $Logic
    ): array;

    /**
     * Search for specified phrases in specified field.
     * @param array $Phrases List of phrases to search for.
     * @param array $Scores Current search result scores.
     * @param int $FieldId ID of field to search.
     * @param bool $ProcessNonExcluded If TRUE, non-excluded search terms
     *       will be searched for.
     * @param bool $ProcessExcluded If TRUE, excluded search terms will be
     *       searched for.
     * @return array Updated search result scores.
     */
    private function searchForPhrases(
        array $Phrases,
        array $Scores,
        int $FieldId,
        bool $ProcessNonExcluded = true,
        bool $ProcessExcluded = true
    ): array {

        # if phrases are found
        if (count($Phrases) > 0) {
            # if this is a keyword search
            if ($FieldId == self::KEYWORD_FIELD_ID) {
                # for each field
                foreach (self::$FieldInfo as $KFieldId => $Info) {
                    # if field is marked to be included in keyword searches
                    if ($Info["InKeywordSearch"]) {
                        # call ourself with that field
                        $Scores = $this->searchForPhrases(
                            $Phrases,
                            $Scores,
                            $KFieldId,
                            $ProcessNonExcluded,
                            $ProcessExcluded
                        );
                    }
                }
            } else {
                # for each phrase
                foreach ($Phrases as $Phrase => $Flags) {
                    $this->dMsg(2, "Searching for phrase '" . $Phrase
                        . "' in field " . $FieldId);

                    # if phrase flagged as excluded and we are doing excluded
                    #       phrases or phrase flagged as non-excluded and we
                    #       are doing non-excluded phrases
                    if (($ProcessExcluded && ($Flags & self::WORD_EXCLUDED))
                        || ($ProcessNonExcluded && !($Flags & self::WORD_EXCLUDED))) {
                        # retrieve list of items that contain phrase
                        $ItemIds = $this->searchFieldForPhrases(
                            $FieldId,
                            $Phrase
                        );

                        # for each item that contains phrase
                        foreach ($ItemIds as $ItemId) {
                            # if we are doing excluded phrases and phrase
                            #       is flagged as excluded
                            if ($ProcessExcluded && ($Flags & self::WORD_EXCLUDED)) {
                                # knock item off of list
                                unset($Scores[$ItemId]);
                            } elseif ($ProcessNonExcluded) {
                                # calculate phrase value based on number of
                                #       words and field weight
                                $PhraseScore = count((array)preg_split(
                                    "/[\s]+/",
                                    $Phrase,
                                    -1,
                                    PREG_SPLIT_NO_EMPTY
                                )) * self::$FieldInfo[$FieldId]["Weight"];
                                $this->dMsg(2, "Phrase score is " . $PhraseScore);

                                # bump up item record score
                                if (isset($Scores[$ItemId])) {
                                    $Scores[$ItemId] += $PhraseScore;
                                } else {
                                    $Scores[$ItemId] = $PhraseScore;
                                }

                                # if phrase flagged as required
                                if ($Flags & self::WORD_REQUIRED) {
                                    # increment required word count for record
                                    if (isset($this->RequiredTermCounts[$ItemId])) {
                                        $this->RequiredTermCounts[$ItemId]++;
                                    } else {
                                        $this->RequiredTermCounts[$ItemId] = 1;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        # return updated scores to caller
        return $Scores;
    }

    /**
     * Filter scores to remove results that contain excluded terms in specified
     * field.
     * @param array $Words Terms as the index with word flags for the values.
     * @param array $Scores Current search result scores.
     * @param string $FieldId ID of field.
     * @return array Filtered search result scores.
     */
    private function filterOnExcludedWords(
        array $Words,
        array $Scores,
        string $FieldId
    ): array {
        $DB = $this->DB;

        # for each word
        foreach ($Words as $Word => $Flags) {
            # if word flagged as excluded
            if ($Flags & self::WORD_EXCLUDED) {
                # look up record ID for word
                $WordId = $this->getWordId($Word);

                # if word is in DB
                if ($WordId !== null) {
                    # look up counts for word
                    $DB->Query("SELECT ItemId FROM SearchWordCounts"
                            ." WHERE WordId=".intval($WordId)
                            ." AND FieldId=".intval($FieldId));

                    # for each count
                    while ($Record = $DB->FetchRow()) {
                        # if item record is in score list
                        $ItemId = $Record["ItemId"];
                        if (isset($Scores[$ItemId])) {
                            # remove item record from score list
                            $this->dMsg(3, "Filtering out item " . $ItemId
                                . " because it contained word \"" . $Word . "\"");
                            unset($Scores[$ItemId]);
                        }
                    }
                }
            }
        }

        # returned filtered score list to caller
        return $Scores;
    }

    /**
     * Filter scores to remove results that do not meet required term counts.
     * @param array $Scores Current search result scores.
     * @return array Filtered search result scores.
     */
    private function filterOnRequiredWords(array $Scores): array
    {
        # if there were required words
        if ($this->RequiredTermCount > 0) {
            # for each item
            foreach ($Scores as $ItemId => $Score) {
                # if item does not meet required word count
                if (!isset($this->RequiredTermCounts[$ItemId])
                    || ($this->RequiredTermCounts[$ItemId]
                        < $this->RequiredTermCount)) {
                    # filter out item
                    $this->dMsg(4, "Filtering out item " . $ItemId
                        . " because it didn't have required word count of "
                        . $this->RequiredTermCount
                        . (isset($this->RequiredTermCounts[$ItemId])
                            ? " (only had "
                            . $this->RequiredTermCounts[$ItemId]
                            : " (had none")
                        . ")");
                    unset($Scores[$ItemId]);
                }
            }
        }

        # return filtered list to caller
        return $Scores;
    }

    /**
     * Sort search result scores list.
     * @param array $Scores Current set of search scores.
     * @param mixed $SortByField ID of field or array of IDs of fields
     *       (indexed by item type) to sort results by.
     * @param mixed $SortDescending If TRUE, sort in descending order, otherwise
     *       sort in ascending order.  May also be array of boolean values, with
     *       item types for the index.
     * @return array New set of search scores.
     */
    private function sortScores(array $Scores, $SortByField, $SortDescending): array
    {
        # perform any requested filtering
        $this->dMsg(0, "Have " . count($Scores) . " results before filter callbacks");
        $Scores = $this->filterOnSuppliedFunctions($Scores);

        # save total number of results available
        $this->NumberOfResultsAvailable = count($Scores);

        # sort search scores into item type bins
        $this->loadItemTypeCache(array_keys($Scores));

        $NewScores = array();
        foreach ($Scores as $Id => $Score) {
            $ItemType = $this->getItemType($Id);
            if ($ItemType !== null) {
                $NewScores[$ItemType][$Id] = $Score;
            }
        }
        $Scores = $NewScores;

        # for each item type
        $NewSortByField = array();
        $NewSortDescending = array();
        foreach ($Scores as $ItemType => $TypeScores) {
            # normalize sort field parameter
            $NewSortByField[$ItemType] = !is_array($SortByField) ? $SortByField
                : (isset($SortByField[$ItemType])
                    ? $SortByField[$ItemType] : false);

            # normalize sort direction parameter
            $NewSortDescending[$ItemType] = !is_array($SortDescending) ? $SortDescending
                : (isset($SortDescending[$ItemType])
                    ? $SortDescending[$ItemType] : true);
        }
        $SortByField = $NewSortByField;
        $SortDescending = $NewSortDescending;

        # for each item type
        foreach ($Scores as $ItemType => $TypeScores) {
            # save number of results
            $this->NumberOfResultsPerItemType[$ItemType] = count($TypeScores);

            # if no sorting field specified
            if ($SortByField[$ItemType] === false) {
                # sort result list by score
                if ($SortDescending[$ItemType]) {
                    arsort($Scores[$ItemType], SORT_NUMERIC);
                } else {
                    asort($Scores[$ItemType], SORT_NUMERIC);
                }
            } else {
                # get list of item IDs in sorted order
                $SortedIds = $this->getItemIdsSortedByField(
                    $ItemType,
                    $SortByField[$ItemType],
                    $SortDescending[$ItemType]
                );

                # if we have sorted item IDs
                if (count($SortedIds) && count($TypeScores)) {
                    # strip sorted ID list down to those that appear in search results
                    $SortedIds = array_intersect(
                        $SortedIds,
                        array_keys($TypeScores)
                    );

                    # rebuild score list in sorted order
                    $NewScores = array();
                    foreach ($SortedIds as $Id) {
                        $NewScores[$Id] = $TypeScores[$Id];
                    }
                    $Scores[$ItemType] = $NewScores;
                } else {
                    # sort result list by score
                    arsort($Scores[$ItemType], SORT_NUMERIC);
                }
            }
        }

        # returned cleaned search result scores list to caller
        return $Scores;
    }

    /**
     * Filter search scores through any supplied functions.
     * @param array $Scores Current set of search scores.
     * @return array An possibly pared-down set of search scores.
     */
    protected function filterOnSuppliedFunctions(array $Scores): array
    {
        # if filter functions have been set
        if (isset($this->FilterFuncs)) {
            # for each result
            foreach ($Scores as $ItemId => $Score) {
                # for each filter function
                foreach ($this->FilterFuncs as $FuncName) {
                    # if filter function return TRUE for item
                    if (call_user_func($FuncName, $ItemId)) {
                        # discard result
                        $this->dMsg(2, "Filter callback <i>" . $FuncName
                            . "</i> rejected item " . $ItemId);
                        unset($Scores[$ItemId]);

                        # bail out of filter func loop
                        continue 2;
                    }
                }
            }
        }

        # return filtered list to caller
        return $Scores;
    }

    /**
     * Scan through incoming search strings for comparison searches (e.g. "<= X")
     * perform the appropriate searches, and update the search scores accordingly.
     * @param array $SearchStrings Array of search string arrays, with field names
     *       for the index.
     * @param string $Logic Search logic ("AND" or "OR").
     * @param array $Scores Current search scores, with item IDs for the index.
     * @return array An updated set of search scores.
     */
    private function searchForComparisonMatches(
        array $SearchStrings,
        string $Logic,
        array $Scores
    ): array {
        # for each field
        $Index = 0;
        $Values = [];
        $FieldIds = [];
        foreach ($SearchStrings as $SearchFieldId => $SearchStringArray) {
            # if field is not keyword
            if ($SearchFieldId != self::KEYWORD_FIELD_ID) {
                # skip invalid fields
                if (!isset(self::$FieldInfo[$SearchFieldId])) {
                    continue;
                }
                # for each search string for this field
                foreach ($SearchStringArray as $SearchString) {
                    # look for comparison operators
                    $FoundOperator = preg_match(
                        self::COMPARISON_OPERATOR_PATTERN,
                        $SearchString,
                        $Matches
                    );

                    # if a comparison operator was found
                    #       or this is a field type that is always a comparison search
                    if ($FoundOperator ||
                        (self::$FieldInfo[$SearchFieldId]["FieldType"]
                            != self::FIELDTYPE_TEXT)) {
                        # determine value to compare against
                        $Value = trim(preg_replace(
                            self::COMPARISON_OPERATOR_PATTERN,
                            '',
                            $SearchString
                        ));

                        # if no comparison operator was found
                        if (!$FoundOperator) {
                            # assume comparison is equality
                            $Operators[$Index] = "=";
                        } else {
                            # use operator from comparison match
                            $Operators[$Index] = $Matches[1];
                        }

                        # if operator was found
                        if (isset($Operators[$Index])) {
                            # save value
                            $Values[$Index] = $Value;

                            # save field name
                            $FieldIds[$Index] = $SearchFieldId;
                            $this->dMsg(3, "Added comparison (field = <i>"
                                . $FieldIds[$Index] . "</i>  op = <i>"
                                . $Operators[$Index] . "</i>  val = <i>"
                                . $Values[$Index] . "</i>)");

                            # move to next comparison array entry
                            $Index++;
                        }
                    }
                }
            }
        }

        # if comparisons found
        if (isset($Operators)) {
            # perform comparisons on fields and gather results
            $Results = $this->searchFieldsForComparisonMatches(
                $FieldIds,
                $Operators,
                $Values,
                $Logic
            );

            # if search logic is set to AND
            if ($Logic == "AND") {
                # if results were found
                if (count($Results)) {
                    # if there were no prior results and no terms for keyword search
                    if ((count($Scores) == 0) && ($this->InclusiveTermCount == 0)) {
                        # add all results to scores
                        foreach ($Results as $ItemId) {
                            $Scores[$ItemId] = 1;
                        }
                    } else {
                        $FlippedResults = array_flip($Results);

                        # remove anything from scores that is not part of results
                        foreach ($Scores as $ItemId => $Score) {
                            if (!isset($FlippedResults[$ItemId])) {
                                unset($Scores[$ItemId]);
                            }
                        }
                    }
                } else {
                    # clear scores
                    $Scores = array();
                }
            } else {
                # add result items to scores
                foreach ($Results as $ItemId) {
                    if (isset($Scores[$ItemId])) {
                        $Scores[$ItemId] += 1;
                    } else {
                        $Scores[$ItemId] = 1;
                    }
                }
            }
        }

        # return results to caller
        return $Scores;
    }

    /**
     * Scan incoming search strings for debug level magic keyword, set debug
     * level if found, and remove keyword from strings.
     * @param array|string $SearchStrings Incoming search strings.
     * @return array|string Array of search strings with any debug level magic keywords
     *       removed.
     */
    private function setDebugLevel($SearchStrings)
    {
        # if search info is an array
        if (is_array($SearchStrings)) {
            # for each array element
            foreach ($SearchStrings as $FieldId => $SearchStringArray) {
                # if element is an array
                if (is_array($SearchStringArray)) {
                    # for each array element
                    foreach ($SearchStringArray as $Index => $SearchString) {
                        # pull out search string if present
                        $SearchStrings[$FieldId][$Index] =
                            $this->extractDebugLevel($SearchString);
                    }
                } else {
                    # pull out search string if present
                    $SearchStrings[$FieldId] =
                        $this->extractDebugLevel($SearchStringArray);
                }
            }
        } else {
            # pull out search string if present
            $SearchStrings = $this->extractDebugLevel($SearchStrings);
        }

        # return new search info to caller
        return $SearchStrings;
    }

    /**
     * Scan incoming search string for debug level magic keyword, set debug
     * level if found, and remove keyword from string.
     * @param string $SearchString Incoming search string.
     * @return string Search string with any debug level magic keyword removed.
     */
    private function extractDebugLevel(string $SearchString): string
    {
        # if search string contains debug level indicator
        if (strstr($SearchString, "DBUGLVL=")) {
            # remove indicator and set debug level
            $Level = preg_replace("/^\\s*DBUGLVL=([1-9]{1,2}).*/", "\\1", $SearchString);
            if ($Level > 0) {
                $this->DebugLevel = $Level;
                $this->dMsg(0, "Setting debug level to " . $Level);
                $SearchString = preg_replace(
                    "/\s*DBUGLVL=".$Level."\s*/",
                    "",
                    $SearchString
                );
            }
        }

        # return (possibly) modified search string to caller
        return $SearchString;
    }

    /**
     * Load and return search result scores array containing all possible records.
     * @param array $ItemTypes ItemTypes to include in result.
     * @return array Scores with item IDs for the index.
     */
    private function loadScoresForAllRecords(array $ItemTypes): array
    {
        # if no item types were provided return an empty array
        if (count($ItemTypes) == 0) {
            return [];
        }

        # get all the ItemIds belonging to the given types
        $this->DB->query("SELECT " . $this->ItemIdFieldName . " AS ItemId"
            . " FROM " . $this->ItemTableName
            . " WHERE " . $this->ItemTypeFieldName . " IN(" . implode(",", $ItemTypes) . ")");

        # return array with all scores to caller
        return array_fill_keys($this->DB->fetchColumn("ItemId"), 1);
    }

    /**
     * Return item IDs sorted by a specified field.
     * @param int $ItemType Type of item.
     * @param int|string $Field ID or name of field by which to sort.
     * @param bool $SortDescending If TRUE, sort in descending order, otherwise
     *       sort in ascending order.
     * @return array of ItemIds
     */
    abstract public static function getItemIdsSortedByField(
        int $ItemType,
        $Field,
        bool $SortDescending
    ): array;

    # ---- private methods (search DB building)

    /**
     * Update weighted count for term/item/field combination in DB.
     * @param string $Word Word for which count should be updated.
     * @param int $ItemId ID of item for which count applies.
     * @param int $FieldId ID of field for which count applies.
     * @param int $Weight Numeric weight to apply to count.  (OPTIONAL - defaults to 1)
     */
    private function updateWordCount(
        string $Word,
        int $ItemId,
        int $FieldId,
        int $Weight = 1
    ): void {
        # retrieve ID for word
        $WordIds[] = $this->getWordId($Word, true);

        # if stemming is enabled and word looks appropriate for stemming
        if ($this->StemmingEnabled && !is_numeric($Word)) {
            # retrieve stem of word
            $Stem = PorterStemmer::Stem($Word, true);

            # if stem is different
            if ($Stem != $Word) {
                # retrieve ID for stem of word
                $WordIds[] = $this->getStemId($Stem, true);
            }
        }

        # for word and stem of word
        foreach ($WordIds as $WordId) {
            $this->DB->query(
                "INSERT INTO SearchWordCounts "
                ." (WordId, ItemId, FieldId, Count) VALUES "
                ." (" . $WordId . ", " . $ItemId . ", " . $FieldId . ", " . $Weight . ") "
                ." ON DUPLICATE KEY UPDATE Count = SearchWordCounts.Count + ".$Weight
            );
            # decrease weight for stem
            $Weight = ceil($Weight / 2);
        }
    }

    /**
     * Retrieve content for specified field for specified item.
     * @param int $ItemId ID of item.
     * @param string $FieldId ID of field.
     * @return string|array|null Text value or array of text values or NULL or
     *      empty array if no values available.
     */
    abstract protected function getFieldContent(int $ItemId, string $FieldId);

    /**
     * Update word counts for indicated field for indicated item.
     * @param int $ItemId ID of item.
     * @param int $FieldId ID of field.
     * @param int $Weight Weight of field.
     * @param string $Text Text to parse.
     * @param bool $IncludeInKeyword Whether this field should be included
     *       in keyword searching.
     */
    private function recordSearchInfoForText(
        int $ItemId,
        int $FieldId,
        int $Weight,
        string $Text,
        bool $IncludeInKeyword
    ): void {

        # normalize text
        $Words = $this->parseSearchStringForWords($Text, "OR", true);

        # if there was text left after parsing
        if (count($Words)) {
            # for each word
            foreach ($Words as $Word => $Flags) {
                # update count for word
                $this->updateWordCount($Word, $ItemId, $FieldId);

                # if text should be included in keyword searches
                if ($IncludeInKeyword) {
                    # update keyword field count for word
                    $this->updateWordCount(
                        $Word,
                        $ItemId,
                        self::KEYWORD_FIELD_ID,
                        $Weight
                    );
                }
            }
        }
    }

    # ---- common private methods (used in both searching and DB build)

    /**
     * Normalize and parse search string into list of search terms.
     * @param string $SearchString Search string.
     * @param string $Logic Search logic ("AND" or "OR").
     * @param bool $IgnorePhrases Whether to ignore phrases and groups and
     *       treat the words within them as regular search terms.  (OPTIONAL,
     *       defaults to FALSE)
     * @return array Array with search terms for the index and word states
     *       (WORD_PRESENT, WORD_EXCLUDED, WORD_REQUIRED) for values.
     */
    private function parseSearchStringForWords(
        string $SearchString,
        string $Logic,
        bool $IgnorePhrases = false
    ): array {

        # strip off any surrounding whitespace
        $Text = trim($SearchString);

        # define phrase and group search patterns separately, so that we can
        #       later replace them easily if necessary
        $PhraseSearchPattern = "/\"[^\"]*\"/";
        $GroupSearchPattern = "/\\([^)]*\\)/";

        # set up search string normalization replacement strings (NOTE:  these
        #       are performed in sequence, so the order IS SIGNIFICANT)
        $ReplacementPatterns = array(
            # get rid of possessive plurals
            "/'s[^\\w\\d~+-]+/u" => " ",
            # get rid of single quotes / apostrophes
            "/'/" => "",
            # get rid of phrases
            $PhraseSearchPattern => " ",
            # get rid of groups
            $GroupSearchPattern => " ",
            # convert everything but 'word' characters, digits, and tilde/plus/minus to a space
            "/[^\\w\\d~+-]+/u" => " ",
            # truncate any runs of minus/plus/tilde to just the first char
            "/([~+-])[~+-]+/" => "\\1",
            # convert two alphanumerics segments separated by a minus into
            #       both separate words and a single combined word
            "/([~+-]?)([\\w\\d]+)-([\\w\\d]+)/u" => "\\1\\2 \\1\\3 \\1\\2\\3",
            # convert minus/plus/tilde preceded by anything but whitespace to a space
            "/([^\\s])[~+-]+/i" => "\\1 ",
            # convert minus/plus/tilde followed by whitespace to a space
            "/[~+-]+\\s/i" => " ",
            # convert multiple spaces to one space
            "/[ ]+/" => " ",
        );

        # if we are supposed to ignore phrasing (series of words in quotes)
        #       and grouping (series of words surrounded by parens)
        if ($IgnorePhrases) {
            # switch phrase removal to double quote removal
            #       and switch group removal to paren removal
            $NewReplacementPatterns = [];
            foreach ($ReplacementPatterns as $Pattern => $Replacement) {
                if ($Pattern == $PhraseSearchPattern) {
                    $Pattern = "/\"/";
                } elseif ($Pattern == $GroupSearchPattern) {
                    $Pattern = "/[\(\)]+/";
                }
                $NewReplacementPatterns[$Pattern] = $Replacement;
            }
            $ReplacementPatterns = $NewReplacementPatterns;
        }

        # remove punctuation from text and normalize whitespace
        $Text = preg_replace(
            array_keys($ReplacementPatterns),
            $ReplacementPatterns,
            $Text
        );
        $this->dMsg(2, "Normalized search string is \"" . $Text . "\"");

        # convert text to lower case
        $Text = strtolower($Text);

        # strip off any extraneous whitespace
        $Text = trim($Text);

        # start with an empty array
        $Words = array();

        # if we have no words left after parsing
        if (strlen($Text) != 0) {
            # for each word
            foreach (explode(" ", $Text) as $Word) {
                # grab first character of word
                $FirstChar = substr($Word, 0, 1);

                # strip off option characters and set flags appropriately
                $Flags = self::WORD_PRESENT;
                if ($FirstChar == "-") {
                    $Word = substr($Word, 1);
                    $Flags |= self::WORD_EXCLUDED;
                    if (!isset($Words[$Word])) {
                        $this->ExcludedTermCount++;
                    }
                } else {
                    if ($FirstChar == "~") {
                        $Word = substr($Word, 1);
                    } elseif (($Logic == "AND")
                        || ($FirstChar == "+")) {
                        if ($FirstChar == "+") {
                            $Word = substr($Word, 1);
                        }
                        $Flags |= self::WORD_REQUIRED;
                        if (!isset($Words[$Word])) {
                            $this->RequiredTermCount++;
                        }
                    }
                    if (!isset($Words[$Word])) {
                        $this->InclusiveTermCount++;
                        $this->SearchTermList[] = $Word;
                    }
                }

                # store flags to indicate word found
                $Words[$Word] = $Flags;
                $this->dMsg(3, "Word identified (" . $Word . ")");
            }
        }

        # return normalized words to caller
        return $Words;
    }

    /**
     * Get ID for specified word.
     * @param string $Word Word to look up ID for.
     * @param bool $AddIfNotFound If TRUE, word will be added to database
     *       if not found.  (OPTIONAL, defaults to FALSE)
     * @return int|null ID for word or NULL if word was not found.
     */
    private function getWordId(string $Word, bool $AddIfNotFound = false)
    {
        static $WordIdCache;

        # if word was in ID cache
        if (isset($WordIdCache[$Word])) {
            # use ID from cache
            return $WordIdCache[$Word];
        }

        if ($AddIfNotFound) {
            $this->DB->query("LOCK TABLES SearchWords WRITE");
        }

        # look up ID in database
        $WordId = $this->DB->query(
            "SELECT WordId"
            . " FROM SearchWords"
            . " WHERE WordText='" . addslashes($Word) . "'",
            "WordId"
        );

        # if ID was not found and caller requested it be added
        if ($AddIfNotFound) {
            if ($WordId === null) {
                # add word to database
                $this->DB->query(
                    "INSERT INTO SearchWords (WordText)"
                    . " VALUES ('" . addslashes(strtolower($Word)) . "')"
                );
                # get ID for newly added word
                $WordId = $this->DB->getLastInsertId();
            }

            $this->DB->query("UNLOCK TABLES");
        }

        # if ID was found, save ID to cache
        if ($WordId !== null) {
            $WordIdCache[$Word] = $WordId;
        }

        # return ID to caller
        return $WordId;
    }

    /**
     * Get ID for specified word stem.
     * @param string $Stem Word stem to look up ID for.
     * @param bool $AddIfNotFound If TRUE, word stem will be added to database
     *       if not found.  (OPTIONAL, defaults to FALSE)
     * @return int|null ID for word stem or NULL if word stem was not found.
     */
    private function getStemId(string $Stem, bool $AddIfNotFound = false)
    {
        static $StemIdCache;

        # if stem was in ID cache
        if (isset($StemIdCache[$Stem])) {
            # use ID from cache
            return $StemIdCache[$Stem];
        }

        if ($AddIfNotFound) {
            $this->DB->query("LOCK TABLES SearchStems WRITE");
        }

        # look up ID in database
        $StemId = $this->DB->query(
            "SELECT WordId"
            . " FROM SearchStems"
            . " WHERE WordText='" . addslashes($Stem) . "'",
            "WordId"
        );

        # if ID was not found and caller requested it be added
        if ($AddIfNotFound) {
            if ($StemId === null) {
                # add stem to database
                $this->DB->query(
                    "INSERT INTO SearchStems (WordText)"
                    . " VALUES ('" . addslashes(strtolower($Stem)) . "')"
                );

                # get ID for newly added stem
                $StemId = $this->DB->getLastInsertId();
            }

            $this->DB->query("UNLOCK TABLES");
        }

        # if stem was found, adjust from DB ID value to stem ID value and
        # save ID to cache
        if ($StemId !== null) {
            $StemId = (int)$StemId + self::STEM_ID_OFFSET;
            $StemIdCache[$Stem] = $StemId;
        }

        # return ID to caller
        return $StemId;
    }

    /**
     * Get word for specified word ID.
     * @param int $WordId ID to look up.
     * @return string|bool Word for specified ID, or FALSE if ID was not found.
     */
    private function getWord(int $WordId)
    {
        static $WordCache;

        # if word was in cache
        if (isset($WordCache[$WordId])) {
            # use word from cache
            $Word = $WordCache[$WordId];
        } else {
            # adjust search location and word ID if word is stem
            $TableName = "SearchWords";
            if ($WordId >= self::STEM_ID_OFFSET) {
                $TableName = "SearchStems";
                $WordId -= self::STEM_ID_OFFSET;
            }

            # look up word in database
            $Word = $this->DB->query(
                "SELECT WordText"
                . " FROM " . $TableName
                . " WHERE WordId='" . $WordId . "'",
                "WordText"
            );

            # save word to cache
            $WordCache[$WordId] = $Word;
        }

        # return word to caller
        return $Word;
    }

    /**
     * Get type of specified item. For best performance, call
     * loadItemTypeCache() first with a list of Ids to load.
     * @param int $ItemId ID for item.
     * @return int|null Item type, or NULL if item type is unknown.
     */
    private function getItemType(int $ItemId)
    {
        if (!isset(self::$ItemTypeCache[$ItemId])) {
            $this->loadItemTypeCache([$ItemId]);
        }

        return self::$ItemTypeCache[$ItemId] !== false
            ? (int)self::$ItemTypeCache[$ItemId] : null;
    }

    /**
     * Load the ItemTypeCache for a give set of ItemIds.
     * @param array $ItemIds
     */
    private function loadItemTypeCache(array $ItemIds): void
    {
        # nothing to do when no items provided
        if (count($ItemIds) == 0) {
            return;
        }

        # determine which ids we don't already have information for
        $MissingIds = array_diff(
            $ItemIds,
            array_keys(self::$ItemTypeCache)
        );

        if (count($MissingIds) > 0) {
            # bulk load those ids as efficiently as we can
            $ChunkSize = Database::getIntegerDataChunkSize($MissingIds);
            foreach (array_chunk($MissingIds, $ChunkSize) as $ChunkIds) {
                $this->DB->query(
                    "SELECT ItemType, ItemId FROM SearchItemTypes "
                    ."WHERE ItemId IN (".implode(",", $ChunkIds).")"
                );
                self::$ItemTypeCache += $this->DB->fetchColumn("ItemType", "ItemId");
            }
        }

        # determine if any provided ids were invalid
        $InvalidIds = array_diff(
            $ItemIds,
            array_keys(self::$ItemTypeCache)
        );
        foreach ($InvalidIds as $Id) {
            self::$ItemTypeCache[$Id] = false;
        }
    }

    /**
     * Print debug message if level set high enough.
     * @param int $Level Level of message.
     * @param string $Msg Message to print.
     */
    protected function dMsg(int $Level, string $Msg): void
    {
        if ($this->DebugLevel > $Level) {
            print "SE:  " . $Msg . "<br>\n";
        }
    }

    # ---- BACKWARD COMPATIBILITY --------------------------------------------

    # possible types of logical operators
    const SEARCHLOGIC_AND = 1;
    const SEARCHLOGIC_OR = 2;

    # pattern to detect search strings that are explicit comparisons,
    # with the operator in the first matching subgroup
    # should match the following formats
    #   comparisons against a value: ^, $, >, >=, =, <=, <, !=
    #   modification time comparisons: @, @>, @>=, @=, @<=, @<, @!=
    const COMPARISON_OPERATOR_PATTERN = '/^([$^]|@?(:?[><!]=|[><=])|@)/';
}
