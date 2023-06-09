<?PHP
#
#   FILE:  SavedSearch.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;

class SavedSearch
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    # search frequency mnemonics
    const SEARCHFREQ_NEVER =      0;
    const SEARCHFREQ_HOURLY =     1;
    const SEARCHFREQ_DAILY =      2;
    const SEARCHFREQ_WEEKLY =     3;
    const SEARCHFREQ_BIWEEKLY =   4;
    const SEARCHFREQ_MONTHLY =    5;
    const SEARCHFREQ_QUARTERLY =  6;
    const SEARCHFREQ_YEARLY =     7;

    /**
     * Object constructor.
     * @param int|null $SearchId Saved search ID or NULL for new search
     * @param string|null $SearchName Updated search name (OPTIONAL)
     * @param int|null $UserId User who owns this search (OPTIONAL)
     * @param int|null $Frequency Search mailing frequency (OPTIONAL)
     * @param mixed $SearchParameters SearchParameterSet describing this search (OPTIONAL)
     */
    public function __construct(
        $SearchId,
        $SearchName = null,
        $UserId = null,
        $Frequency = null,
        $SearchParameters = null
    ) {
        # get our own database handle
        $this->DB = new Database();

        # if search ID was provided
        if ($SearchId !== null) {
            # save search ID
            $this->SearchId = intval($SearchId);
            $this->DB->setValueUpdateParameters(
                "SavedSearches",
                "SearchId = ".intval($this->SearchId)
            );

            # initialize our local copies of data
            $this->DB->query(
                "SELECT * FROM SavedSearches "
                ."WHERE SearchId = ".$this->SearchId
            );

            if ($this->DB->numRowsSelected() == 0) {
                throw new Exception("Specified SearchId does not exist");
            }

            $Record = $this->DB->fetchRow();

            # get our Search Parameters
            if ($Record !== false && isset($Record["SearchData"])) {
                $this->SearchParameters = new SearchParameterSet($Record["SearchData"]);
            } else {
                $this->SearchParameters = new SearchParameterSet();
            }

            # update search details where provided
            if ($SearchName) {
                $this->searchName($SearchName);
            }

            if ($UserId) {
                $this->userId($UserId);
            }

            if ($Frequency) {
                $this->frequency($Frequency);
            }
        } else {
            # add new saved search to database
            $this->DB->query("INSERT INTO SavedSearches"
                    ." (SearchName, UserId, Frequency) VALUES ("
                    ."'".addslashes($SearchName)."', "
                    .intval($UserId).", "
                    .intval($Frequency).")");

            # retrieve and save ID of new search locally
            $this->SearchId = $this->DB->getLastInsertId();
            $this->DB->setValueUpdateParameters(
                "SavedSearches",
                "SearchId = ".intval($this->SearchId)
            );

            $this->initializeSearchParameters($SearchParameters);
            $this->initializeLastMatches();
        }
    }

    /**
     * Get/set search parameters.
     * @param SearchParameterSet|null $NewParams Updated search parameters
     * @return SearchParameterSet current search parameters
     */
    public function searchParameters(SearchParameterSet $NewParams = null): SearchParameterSet
    {
        if (!is_null($NewParams)) {
            $Data = $NewParams->data();

            $this->DB->query(
                "UPDATE SavedSearches SET SearchData = '". addslashes($Data)."'"
                ." WHERE SearchId = ".$this->SearchId
            );

            $this->SearchParameters = new SearchParameterSet($Data);
        }

        return clone $this->SearchParameters;
    }

    /**
     * Get/set name of search.
     * @param string $NewValue New name of search value.
     * @return string Current name of search value.
     */
    public function searchName(string $NewValue = null): string
    {
        return $this->DB->UpdateValue("SearchName", $NewValue);
    }

    /**
     * Get ID of search.
     * @return int Search ID.
     */
    public function id(): int
    {
        return $this->SearchId;
    }

    /**
     * Get/set user ID.
     * @param int $NewValue New user ID value.
     * @return int Current user ID value.
     */
    public function userId(int $NewValue = null): int
    {
        return $this->DB->UpdateIntValue("UserId", $NewValue);
    }

    /**
     * Get/set search frequency.
     * @param int $NewValue New search frequency value.
     * @return int Current search frequency value.
     */
    public function frequency(int $NewValue = null): int
    {
        return $this->DB->UpdateIntValue("Frequency", $NewValue);
    }

    /**
     * Update date this search was last run.
     */
    public function updateDateLastRun()
    {
        $this->DB->Query(
            "UPDATE SavedSearches SET DateLastRun = NOW() "
            ."WHERE SearchId = ".$this->SearchId
        );
    }

    /**
     * Get/set the date this search was last run.
     * @param string|null $NewValue Updated value (OPTIONAL
     * @return string current value
     */
    public function dateLastRun($NewValue = null): string
    {
        return $this->DB->UpdateDateValue("DateLastRun", $NewValue);
    }

    /**
     * Save array of last matches.
     * @param array $ArrayOfMatchingIds Matching Ids for a current search.
     */
    public function saveLastMatches(array $ArrayOfMatchingIds)
    {
        $NewValue = implode(",", $ArrayOfMatchingIds);
        $this->DB->UpdateValue("LastMatchingIds", $NewValue);
    }

    /**
     * Return array of most recently matched ResourceIds for a search
     * @return array of Resource Ids for most recent run of a search
     */
    public function lastMatches(): array
    {
        $MatchingIds = $this->DB->queryValue(
            "SELECT LastMatchingIds FROM SavedSearches "
            ."WHERE SearchId = ".$this->SearchId,
            "LastMatchingIds"
        );

        if (strlen($MatchingIds) == 0) {
            return [];
        }

        return explode(",", $MatchingIds);
    }

    /**
     * Get search groups as URL parameters
     * (e.g. something like F2=madison&F4=american+history&G22=17-41).
     * @return string containing URL parameters (no leading "?").
     */
    public function getSearchGroupsAsUrlParameters(): string
    {
        return self::translateSearchGroupsToUrlParameters(
            $this->searchParameters()->getAsLegacyArray()
        );
    }

    /**
     * Translate search group array into URL parameters
     * (e.g. something like F2=madison&F4=american+history&G22=17-41).
     * A search group array looks something like this:
     * @code
     * $SearchGroups = [
     *       "MAIN" => [
     *               "SearchStrings" => [
     *                       "XXXKeywordXXX" => "some words for keyword search",
     *                       "Title" => "some words we are looking for in titles",
     *               ],
     *               "Logic" => SearchEngine::LOGIC_AND,
     *               ],
     *       "23" => [
     *               "SearchStrings" => [
     *                       "Resource Type" => [
     *                               "=Event",
     *                               "=Image",
     *                       ],
     *               ],
     *               "Logic" => SearchEngine::LOGIC_OR,
     *       ],
     *       "25" => [
     *               "SearchStrings" => [
     *                       "Audience" => ["=Grades 10-12"],
     *                ],
     *               "Logic" => SearchEngine::LOGIC_OR,
     *       ],
     * ];
     * @endcode
     * where "23" and "25" are the field IDs and "Resource Type" and "Audience"
     * are the corresponding field names.
     * @param array $SearchGroups Search group array.
     * @return string containing URL parameters (no leading "?").
     */
    public static function translateSearchGroupsToUrlParameters(array $SearchGroups): string
    {
        # assume that no parameters will be found
        $UrlPortion = "";

        # for each group in parameters
        $Schema = new MetadataSchema();
        foreach ($SearchGroups as $GroupIndex => $Group) {
            # if group holds single parameters
            if ($GroupIndex == "MAIN") {
                # for each field within group
                foreach ($Group["SearchStrings"] as $FieldName => $Value) {
                    # add segment to URL for this field
                    if ($FieldName == "XXXKeywordXXX") {
                        $FieldId = "K";
                    } else {
                        $Field = $Schema->getField($FieldName);
                        $FieldId = $Field->id();
                    }
                    if (is_array($Value)) {
                        $UrlPortion .= "&F".$FieldId."=";
                        $ValueString = "";
                        foreach ($Value as $SingleValue) {
                            $ValueString .= $SingleValue." ";
                        }
                        $UrlPortion .= urlencode(trim($ValueString));
                    } else {
                        $UrlPortion .= "&F".$FieldId."=".urlencode($Value);
                    }
                }
            } else {
                # convert value based on field type
                $FieldId = (is_string($GroupIndex) && $GroupIndex[0] == "X")
                        ? substr($GroupIndex, 1)
                        : $GroupIndex;
                $Field = $Schema->getField($FieldId);
                $FieldName = $Field->name();
                $Values = self::translateValues(
                    $Field,
                    $Group["SearchStrings"][$FieldName],
                    "SearchGroup to Database"
                );

                # add values to URL
                $FirstValue = true;
                foreach ($Values as $Value) {
                    if ($FirstValue) {
                        $FirstValue = false;
                        $UrlPortion .= "&G".$FieldId."=".$Value;
                    } else {
                        $UrlPortion .= "-".$Value;
                    }
                }
            }
        }

        # trim off any leading "&"
        if (strlen($UrlPortion)) {
            $UrlPortion = substr($UrlPortion, 1);
        }

        # return URL portion to caller
        return $UrlPortion;
    }

    /**
     * Translate a search group array to an URL parameter array.
     * @param array $SearchGroups Search group array to translate.
     * @return array with strings like "F4" ("F" or "G" plus field ID) for the
     *       index and * "american+history" (search parameter) for the values.
     */
    public static function translateSearchGroupsToUrlParameterArray(array $SearchGroups): array
    {
        # assume that no parameters will be found
        $UrlPortion = [];

        # for each group in parameters
        $Schema = new MetadataSchema();
        foreach ($SearchGroups as $GroupIndex => $Group) {
            # if group holds single parameters
            if ($GroupIndex == "MAIN") {
                # for each field within group
                foreach ($Group["SearchStrings"] as $FieldName => $Value) {
                    # add segment to URL for this field
                    if ($FieldName == "XXXKeywordXXX") {
                        $FieldId = "K";
                    } else {
                        $Field = $Schema->getField($FieldName);
                        $FieldId = $Field->id();
                    }
                    if (is_array($Value)) {
                        $ValueString = "";
                        foreach ($Value as $SingleValue) {
                            $ValueString .= $SingleValue." ";
                        }

                        $UrlPortion["F".$FieldId] = urlencode(trim($ValueString));
                    } else {
                        $UrlPortion["F".$FieldId] = urlencode($Value);
                    }
                }
            } else {
                # convert value based on field type
                $FieldId = (is_string($GroupIndex) && $GroupIndex[0] == "X")
                        ? substr($GroupIndex, 1)
                        : $GroupIndex;
                $Field = $Schema->getField($FieldId);
                $FieldName = $Field->name();
                $Values = self::translateValues(
                    $Field,
                    $Group["SearchStrings"][$FieldName],
                    "SearchGroup to Database"
                );
                $LeadChar = ($Group["Logic"] == SearchEngine::LOGIC_AND) ? "H" : "G";

                # add values to URL
                $FirstValue = true;
                foreach ($Values as $Value) {
                    if ($FirstValue) {
                        $FirstValue = false;
                        $UrlPortion[$LeadChar.$FieldId] = $Value;
                    } else {
                        $UrlPortion[$LeadChar.$FieldId] .= "-".$Value;
                    }
                }
            }
        }

        # return URL portion to caller
        return $UrlPortion;
    }

    /**
     * Translate URL parameters to legacy search group array.
     * @param array|string $GetVars Get variables (as from $_GET)
     * @return array Legacy search group array
     */
    public static function translateUrlParametersToSearchGroups($GetVars): array
    {
        # if URL segment was passed in instead of GET var array
        if (is_string($GetVars)) {
            parse_str($GetVars, $GetVars);
        }

        # start with empty list of parameters
        $SearchGroups = [];

        $Schema = new MetadataSchema();
        $AllFields = $Schema->getFields(null, null, true);

        foreach ($AllFields as $Field) {
            $FieldId = $Field->Id();
            $FieldName = $Field->Name();

            # if URL included literal value for this field
            if (isset($GetVars["F".$FieldId])) {
                # retrieve value and add to search parameters
                $SearchGroups["MAIN"]["SearchStrings"][$FieldName] = $GetVars["F".$FieldId];
            }

            # if URL included group value for this field
            if (isset($GetVars["G".$FieldId])) {
                # retrieve and parse out values
                $Values = explode("-", $GetVars["G".$FieldId]);

                # translate values
                $Values = self::translateValues(
                    $Field,
                    $Values,
                    "Database to SearchGroup"
                );

                # add values to searchgroups
                $SearchGroups[$FieldId]["SearchStrings"][$FieldName] = $Values;
            }

            # if URL included group value for this field
            if (isset($GetVars["H".$FieldId])) {
                # retrieve and parse out values
                $Values = explode("-", $GetVars["H".$FieldId]);

                # translate values
                $Values = self::translateValues(
                    $Field,
                    $Values,
                    "Database to SearchGroup"
                );

                # add values to searchgroups
                $SearchGroups["X".$FieldId]["SearchStrings"][$FieldName] = $Values;
            }
        }

        # if keyword pseudo-field was included in URL
        if (isset($GetVars["FK"])) {
            # retrieve value and add to search parameters
            $SearchGroups["MAIN"]["SearchStrings"]["XXXKeywordXXX"] = $GetVars["FK"];
        }

        # set search logic
        foreach ($SearchGroups as $GroupIndex => $Group) {
            $SearchGroups[$GroupIndex]["Logic"] = ($GroupIndex == "MAIN")
                    ? SearchEngine::LOGIC_AND
                        : ((is_string($GroupIndex) && $GroupIndex[0] == "X")
                            ? SearchEngine::LOGIC_AND : SearchEngine::LOGIC_OR);
        }

        # return parameters to caller
        return $SearchGroups;
    }

    /**
     * Get multi-line string describing search criteria.
     * @param bool $IncludeHtml Whether to include HTML tags for formatting.  (OPTIONAL,
     *       defaults to TRUE)
     * @param bool $StartWithBreak Whether to start string with BR tag.  (OPTIONAL,
     *       defaults to TRUE)
     * @param int $TruncateLongWordsTo Number of characters to truncate long words to
     *       (use 0 for no truncation).  (OPTIONAL, defaults to 0)
     * @return string containing text describing search criteria.
     */
    public function getSearchGroupsAsTextDescription(
        bool $IncludeHtml = true,
        bool $StartWithBreak = true,
        int $TruncateLongWordsTo = 0
    ): string {
        return $this->SearchParameters->TextDescription(
            $IncludeHtml,
            $StartWithBreak,
            $TruncateLongWordsTo
        );
    }

    /**
     * Translate search group array into  multi-line string describing search criteria.
     * @param array $SearchGroups Search group array.
     * @param bool $IncludeHtml Whether to include HTML tags for formatting.  (OPTIONAL,
     *       defaults to TRUE)
     * @param bool $StartWithBreak Whether to start string with BR tag.  (OPTIONAL,
     *       defaults to TRUE)
     * @param int $TruncateLongWordsTo Number of characters to truncate long words to
     *       (use 0 for no truncation).  (OPTIONAL, defaults to 0)
     * @return string containing text describing search criteria.
     */
    public static function translateSearchGroupsToTextDescription(
        array $SearchGroups,
        bool $IncludeHtml = true,
        bool $StartWithBreak = true,
        int $TruncateLongWordsTo = 0
    ): string {
        $Schema = new MetadataSchema();

        # start with empty description
        $Descrip = "";

        # set characters used to indicate literal strings
        $LiteralStart = $IncludeHtml ? "<i>" : "\"";
        $LiteralEnd = $IncludeHtml ? "</i>" : "\"";
        $LiteralBreak = $IncludeHtml ? "<br>\n" : "\n";

        # if this is a simple keyword search
        if (isset($SearchGroups["MAIN"]["SearchStrings"]["XXXKeywordXXX"])
            && (count($SearchGroups) == 1)
            && (count($SearchGroups["MAIN"]["SearchStrings"]) == 1)) {
            # just use the search string
            $Descrip .= $LiteralStart;
            $Descrip .= defaulthtmlentities(
                $SearchGroups["MAIN"]["SearchStrings"]["XXXKeywordXXX"]
            );
            $Descrip .= $LiteralEnd . $LiteralBreak;
        } else {
            # start description on a new line (if requested)
            if ($StartWithBreak) {
                $Descrip .= $LiteralBreak;
            }

            # define list of phrases used to represent logical operators
            $WordsForOperators = [
                "=" => "is",
                ">" => "is greater than",
                "<" => "is less than",
                ">=" => "is at least",
                "<=" => "is no more than",
                "!" => "is not",
            ];

            # for each search group
            foreach ($SearchGroups as $GroupIndex => $Group) {
                # if group is main
                if ($GroupIndex == "MAIN") {
                    # for each field in group
                    foreach ($Group["SearchStrings"] as $FieldName => $Value) {
                        # determine wording based on operator
                        preg_match("/^[=><!]+/", $Value, $Matches);
                        if (count($Matches) && isset($WordsForOperators[$Matches[0]])) {
                            $Value = preg_replace("/^[=><!]+/", "", $Value);
                            $Wording = $WordsForOperators[$Matches[0]];
                        } else {
                            $Wording = "contains";
                        }

                        # if field is psuedo-field
                        if ($FieldName == "XXXKeywordXXX") {
                            # add criteria for psuedo-field
                            $Descrip .= "Keyword ".$Wording." "
                                    .$LiteralStart.htmlspecialchars($Value)
                                    .$LiteralEnd.$LiteralBreak;
                        } else {
                            # if field is valid
                            if ($Schema->fieldExists($FieldName)) {
                                $Field = $Schema->getField($FieldName);
                                # add criteria for field
                                $Descrip .= $Field->getDisplayName()." ".$Wording." "
                                    .$LiteralStart.htmlspecialchars($Value)
                                    .$LiteralEnd.$LiteralBreak;
                            }
                        }
                    }
                } else {
                    # for each field in group
                    $LogicTerm = ($Group["Logic"] == SearchEngine::LOGIC_AND)
                            ? "and " : "or ";
                    foreach ($Group["SearchStrings"] as $FieldName => $Values) {
                        # translate values
                        $Values = self::translateValues(
                            $FieldName,
                            $Values,
                            "SearchGroup to Display"
                        );

                        # for each value
                        $FirstValue = true;
                        foreach ($Values as $Value) {
                            # determine wording based on operator
                            preg_match("/^[=><!]+/", $Value, $Matches);
                            $Operator = $Matches[0];
                            $Wording = $WordsForOperators[$Operator];

                            # strip off operator
                            $Value = preg_replace("/^[=><!]+/", "", $Value);

                            # add text to description
                            if ($FirstValue) {
                                $Descrip .= $FieldName." ".$Wording." "
                                        .$LiteralStart.htmlspecialchars($Value)
                                        .$LiteralEnd.$LiteralBreak;
                                $FirstValue = false;
                            } else {
                                $Descrip .= ($IncludeHtml ?
                                            "&nbsp;&nbsp;&nbsp;&nbsp;" : "    ")
                                        .$LogicTerm.$Wording." ".$LiteralStart
                                        .htmlspecialchars($Value).$LiteralEnd
                                        .$LiteralBreak;
                            }
                        }
                    }
                }
            }
        }

        # if caller requested that long words be truncated
        if ($TruncateLongWordsTo > 4) {
            # break description into words
            $Words = explode(" ", $Descrip);

            # for each word
            $NewDescrip = "";
            foreach ($Words as $Word) {
                # if word is longer than specified length
                if (strlen(strip_tags($Word)) > $TruncateLongWordsTo) {
                    # truncate word and add ellipsis
                    $Word = StdLib::neatlyTruncateString($Word, $TruncateLongWordsTo - 3);
                }

                # add word to new description
                $NewDescrip .= " ".$Word;
            }

            # set description to new description
            $Descrip = $NewDescrip;
        }

        # return description to caller
        return $Descrip;
    }

    /**
     * Get list of fields to be searched
     * @return array of field names.
     */
    public function getSearchFieldNames(): array
    {
        return $this->SearchParameters->GetFields();
    }

    /**
     * Extract list of fields to be searched from search group array.
     * @param array $SearchGroups Search group array.
     * @return array of field names.
     */
    public static function translateSearchGroupsToSearchFieldNames(array $SearchGroups): array
    {
        # start out assuming no fields are being searched
        $FieldNames = [];

        # for each search group defined
        foreach ($SearchGroups as $Group) {
            # for each field in group
            foreach ($Group["SearchStrings"] as $FieldName => $Values) {
                # add field name to list of fields being searched
                $FieldNames[] = $FieldName;
            }
        }

        # return list of fields being searched to caller
        return $FieldNames;
    }

    /**
     * Get array of possible search frequency descriptions.
     * Frequencies may be excluded from list by supplying them as arguments.
     * @return array of search frequency descriptions indexed by SEARCHFREQ constants.
     */
    public static function getSearchFrequencyList(): array
    {
        # define list with descriptions
        $FreqDescr = [
            self::SEARCHFREQ_NEVER     => "Never",
            self::SEARCHFREQ_HOURLY    => "Hourly",
            self::SEARCHFREQ_DAILY     => "Daily",
            self::SEARCHFREQ_WEEKLY    => "Weekly",
            self::SEARCHFREQ_BIWEEKLY  => "Biweekly",
            self::SEARCHFREQ_MONTHLY   => "Monthly",
            self::SEARCHFREQ_QUARTERLY => "Quarterly",
            self::SEARCHFREQ_YEARLY    => "Yearly",
        ];

        # for each argument passed in
        $Args = func_get_args();
        foreach ($Args as $Arg) {
            # remove value from list
            $FreqDescr = array_diff_key($FreqDescr, [$Arg => ""]);
        }

        # return list to caller
        return $FreqDescr;
    }

    /**
     * Delete saved search.  (NOTE: Object is no longer usable after this call!)
     */
    public function delete()
    {
        $this->DB->Query("DELETE FROM SavedSearches"." WHERE SearchId = ".intval($this->SearchId));
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $SearchId;
    private $Record;
    private $SearchGroups;
    private $DB;
    private $SearchParameters;

    /**
     * Utility function to convert between value representations.
     * @param mixed $FieldOrFieldName Field to translate
     * @param mixed $Values Values to translate
     * @param string $TranslationType Desired translation
     * @return array translated value
     * (method accepts a value or array and always return an array)
     * (this is needed because values are represented differently:
     *                                 FLAG    USER    OPTION
     *     in DB / in URL / in forms   0/1     123     456
     *     used in SearchGroups        0/1     jdoe    cname
     *     displayed to user           On/Off  jdoe    cname
     * where "123" and "456" are option or controlled name IDs)
     */
    private static function translateValues(
        $FieldOrFieldName,
        $Values,
        string $TranslationType
    ): array {
        # start out assuming we won't find any values to translate
        $ReturnValues = [];

        # convert field name to field object if necessary
        if (is_object($FieldOrFieldName)) {
            $Field = $FieldOrFieldName;
        } else {
            static $Schema;
            if (!isset($Schema)) {
                $Schema = new MetadataSchema();
            }
            $Field = $Schema->getField($FieldOrFieldName);
        }

        # if incoming value is not an array
        if (!is_array($Values)) {
            # convert incoming value to an array
            $Values = [$Values];
        }

        # for each incoming value
        foreach ($Values as $Value) {
            switch ($TranslationType) {
                case "SearchGroup to Display":
                    # if field is Flag field
                    if ($Field->Type() == MetadataSchema::MDFTYPE_FLAG) {
                        # translate value to true/false label and add leading operator
                        $ReturnValues[] = ($Value == "=1") ?
                            "=".$Field->FlagOnLabel() : "=".$Field->FlagOffLabel();
                    } elseif ($Field->Name() == "Cumulative Rating") {
                        # translate numeric value to stars
                        $StarStrings = [
                            "20" => "*",
                            "40" => "**",
                            "60" => "***",
                            "80" => "****",
                            "100" => "*****",
                        ];
                        preg_match("/[0-9]+$/", $Value, $Matches);
                        $Number = $Matches[0];
                        preg_match("/^[=><!]+/", $Value, $Matches);
                        $Operator = $Matches[0];
                        $ReturnValues[] = $Operator.$StarStrings[$Number];
                    } else {
                        # use value as is
                        $ReturnValues[] = $Value;
                    }
                    break;

                case "SearchGroup to Database":
                    # strip off leading operator on value
                    $Value = preg_replace("/^[=><!]+/", "", $Value);

                    # look up index for value
                    if ($Field->Type() & (MetadataSchema::MDFTYPE_FLAG |
                                          MetadataSchema::MDFTYPE_NUMBER)) {
                        # (for flag or number fields the value index is already
                        # what is used in SearchGroups)
                        if (!is_null($Value) && intval($Value) >= 0) {
                            $ReturnValues[] = $Value;
                        }
                    } elseif ($Field->Type() == MetadataSchema::MDFTYPE_USER) {
                        # (for user fields the value index is the user ID)
                        $User = new User(strval($Value));
                        $ReturnValues[] = $User->id();
                    } elseif ($Field->Type() == MetadataSchema::MDFTYPE_OPTION) {
                        if (!isset($PossibleFieldValues)) {
                            $PossibleFieldValues = $Field->GetPossibleValues();
                        }
                        $NewValue = array_search($Value, $PossibleFieldValues);
                        if ($NewValue !== false) {
                            $ReturnValues[] = $NewValue;
                        }
                    } else {
                        $NewValue = $Field->GetIdForValue($Value);
                        if ($NewValue !== null) {
                            $ReturnValues[] = $NewValue;
                        }
                    }
                    break;

                case "Database to SearchGroup":
                    # look up value for index
                    if ($Field->Type() == MetadataSchema::MDFTYPE_FLAG) {
                        # (for flag fields the value index (0 or 1) is already
                        # what is used in Database)
                        if ($Value >= 0) {
                            $ReturnValues[] = "=".$Value;
                        }
                    } elseif ($Field->Type() == MetadataSchema::MDFTYPE_NUMBER) {
                        # (for flag fields the value index (0 or 1) is already
                        #  what is used in Database)

                        if ($Value >= 0) {
                            $ReturnValues[] = ">=".$Value;
                        }
                    } elseif ($Field->Type() == MetadataSchema::MDFTYPE_USER) {
                        $User = new User(intval($Value));
                        $ReturnValues[] = "=".$User->get("UserName");
                    } elseif ($Field->Type() == MetadataSchema::MDFTYPE_OPTION) {
                        if (!isset($PossibleFieldValues)) {
                            $PossibleFieldValues = $Field->GetPossibleValues();
                        }

                        if (isset($PossibleFieldValues[$Value])) {
                            $ReturnValues[] = "=".$PossibleFieldValues[$Value];
                        }
                    } else {
                        $NewValue = $Field->GetValueForId($Value);
                        if ($NewValue !== null) {
                            $ReturnValues[] = "=".$NewValue;
                        }
                    }
                    break;
            }
        }

        # return array of translated values to caller
        return $ReturnValues;
    }

    /**
     * Populate SearchParameters when search is created.
     * @param mixed $SearchParameters Initial parameter values.
     */
    private function initializeSearchParameters($SearchParameters)
    {
        # if given legacy data, modernize it
        if (!is_null($SearchParameters) && is_array($SearchParameters)) {
            $Params = new SearchParameterSet();
            $Params->setFromLegacyArray($SearchParameters);
            $SearchParameters = $Params;
        }

        # save possibly modified values
        $this->searchParameters($SearchParameters);
    }

    /**
     * Populate LastMatches when search is created.
     */
    private function initializeLastMatches()
    {
        # perform search
        $SearchEngine = new SearchEngine();
        $SearchResults = $SearchEngine->searchAll($this->SearchParameters);

        # build the list of results the user can see
        $EndUser = new User($this->userId());
        $NewItemIds = [];
        foreach ($SearchResults as $SchemaId => $SchemaResults) {
            $RFactory = new RecordFactory($SchemaId);
            $SchemaItemIds = $RFactory->filterOutUnviewableRecords(
                array_keys($SchemaResults),
                $EndUser
            );
            $NewItemIds = array_merge(
                $NewItemIds,
                $SchemaItemIds
            );
        }

        # if visible search results were found, save them
        if (count($NewItemIds)) {
            $this->saveLastMatches($NewItemIds);
        }
    }
}
