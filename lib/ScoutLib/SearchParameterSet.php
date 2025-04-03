<?PHP
#
#   FILE:  SearchParameterSet.php
#
#   Part of the ScoutLib application support library
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use InvalidArgumentException;

/**
 * Set of parameters used to perform a search.
 */
class SearchParameterSet
{

    # ---- SETUP / CONFIGURATION  ---------------------------------------------
    /** @name Setup / Configuration */ /*@(*/

    /**
     * Class constructor, used to create a new set or reload an existing
     * set from previously-constructed data.
     * @param string $Data Existing search parameter set data, previously
     *       retrieved with SearchParameterSet::Data().  (OPTIONAL)
     * @throws InvalidArgumentException If incoming set data appears invalid.
     * @see SearchParameterSet::Data()
     */
    public function __construct(?string $Data = null)
    {
        # if set data supplied
        if ($Data !== null) {
            # set internal values from data
            $this->loadFromData($Data);
        } else {
            # set initial logic
            $this->Logic = self::$DefaultLogic;
        }
    }

    /**
     * Class clone handler, implemented so that clones will be deep
     * copies rather than PHP5's default shallow copy.
     */
    public function __clone()
    {
        foreach ($this->Subgroups as &$Group) {
            $Group = clone $Group;
        }
    }

    /**
     * On unserialize(), make sure settings from non-namespaced stored objects
     *   are properly restored.
     */
    public function __wakeup()
    {
        StdLib::loadLegacyPrivateVariables($this);
    }

    /**
     * Get/set default logic for all search parameter sets.
     * @param string $NewValue New logic value ("AND" or "OR").  (OPTIONAL)
     * @return string Current logic value ("AND" or "OR").
     * @throws InvalidArgumentException If new value is not "AND" or "OR".
     */
    public static function defaultLogic(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            if (($NewValue != "AND") && ($NewValue != "OR")) {
                throw new InvalidArgumentException("Invalid default logic value (\""
                        .$NewValue."\").");
            }
            self::$DefaultLogic = $NewValue;
        }
        return self::$DefaultLogic;
    }

    /**
     * Get/set a function used to retrieve a canonical value for a field.
     * This function should accept a single mixed parameter and return a canonical
     * integer value for the field.
     * @param callable $Func Function to set (OPTIONAL).
     * @return callable Current CanonicalFieldFunction.
     * @throws InvalidArgumentException If function supplied is not null or callable.
     */
    public static function canonicalFieldFunction(?callable $Func = null)
    {
        if (is_callable($Func)) {
            self::$CanonicalFieldFunction = $Func;
        } elseif (!is_null($Func)) {
            throw new InvalidArgumentException("Invalid function supplied.");
        }
        return self::$CanonicalFieldFunction;
    }

    /**
     * Get/set a function used to retrieve a printable representation for a field
     * name.  This function should accept a single mixed parameter and return a
     * human-readable name for the field.
     * @param callable $Func Function to set (OPTIONAL).
     * @return callable Current PrintableFieldFunction.
     * @throws InvalidArgumentException If function supplied is not null or callable.
     */
    public static function printableFieldFunction(?callable $Func = null)
    {
        if (is_callable($Func)) {
            self::$PrintableFieldFunction = $Func;
        } elseif (!is_null($Func)) {
            throw new InvalidArgumentException("Uncallable function supplied.");
        }
        return self::$PrintableFieldFunction;
    }

    /**
     * Get/set a function used to retrieve a printable representation for a field
     * value.  This function should accept two parameters:  a mixed parameter
     * for the field and a string parameter for the value, and return a
     * human-readable name for the value.
     * @param callable $Func Function to set (OPTIONAL).
     * @return callable Current PrintableValueFunction.
     * @throws InvalidArgumentException If function supplied is not null or callable.
     */
    public static function printableValueFunction(?callable $Func = null)
    {
        if (is_callable($Func)) {
            self::$PrintableValueFunction = $Func;
        } elseif (!is_null($Func)) {
            throw new InvalidArgumentException("Uncallable function supplied.");
        }
        return self::$PrintableValueFunction;
    }


    /*@)*/
    # ---- SET CONSTRUCTION ---------------------------------------------------
    /** @name Set Construction */ /*@(*/

    /**
     * Add search parameter to set.  If a canonical field function is set,
     * the field to search can be anything accepted by that function, otherwise
     * the $Field argument must be a type usable as an array index (e.g. an
     * integer or a string).
     * @param string|array $SearchStrings String or array of strings to search for.
     * @param mixed $Field Field to search.  (OPTIONAL - defaults to
     *       keyword search if no field specified)
     * @see SearchParameterSet::canonicalFieldFunction()
     */
    public function addParameter($SearchStrings, $Field = null): void
    {
        # normalize field value if supplied
        $Field = self::normalizeField($Field);

        # make sure search strings are an array
        if (!is_array($SearchStrings)) {
            $SearchStrings = array($SearchStrings);
        }

        # for each search string
        foreach ($SearchStrings as $String) {
            # if field specified
            if ($Field !== null) {
                # add strings to search values for field
                $this->SearchStrings[$Field][] = $String;
            } else {
                # add strings to keyword search values
                $this->KeywordSearchStrings[] = $String;
            }
        }
    }

    /**
     * Remove search parameter from set.
     * @param string|array|null $SearchStrings String or array of strings to
     *       match, or NULL to remove all entries that match the specified field.
     * @param mixed $Field Field to match.  (OPTIONAL - defaults to keyword
     *       search match if no field specified)
     * @see SearchParameterSet::canonicalFieldFunction()
     */
    public function removeParameter($SearchStrings, $Field = null): void
    {
        # normalize field value if supplied
        $Field = self::normalizeField($Field);

        # if search strings specified
        if ($SearchStrings != null) {
            # make sure search strings are an array
            if (!is_array($SearchStrings)) {
                $SearchStrings = array($SearchStrings);
            }

            # for each search string
            foreach ($SearchStrings as $String) {
                # if field specified
                if ($Field !== null) {
                    # if there are search parameters for this field
                    if (isset($this->SearchStrings[$Field])) {
                        # remove any matching search parameters
                        $NewSearchStrings = array();
                        foreach ($this->SearchStrings[$Field] as $Value) {
                            if ($Value != $String) {
                                $NewSearchStrings[] = $Value;
                            }
                        }
                        if (count($NewSearchStrings)) {
                            $this->SearchStrings[$Field] = $NewSearchStrings;
                        } else {
                            unset($this->SearchStrings[$Field]);
                        }
                    }
                } else {
                    # remove any matching keyword search parameters
                    $NewSearchStrings = array();
                    foreach ($this->KeywordSearchStrings as $Value) {
                        if ($Value != $String) {
                            $NewSearchStrings[] = $Value;
                        }
                    }
                    $this->KeywordSearchStrings = $NewSearchStrings;
                }
            }
        } else {
            # if field specified
            if ($Field !== null) {
                # clear any search strings for this field
                if (isset($this->SearchStrings[$Field])) {
                    unset($this->SearchStrings[$Field]);
                }
            } else {
                # clear all keyword search parameters
                $this->KeywordSearchStrings = array();
            }
        }

        # for each parameter subgroup
        $NewSubgroups = array();
        foreach ($this->Subgroups as $Group) {
            # remove parameter from subgroup
            $Group->RemoveParameter($SearchStrings, $Field);

            # if the subgroup is not empty
            if ($Group->ParameterCount()) {
                # keep subgroup
                $NewSubgroups[] = $Group;
            }
        }
        $this->Subgroups = $NewSubgroups;
    }

    /**
     * Get/set logic for set.
     * @param string|int $NewValue New setting, either "AND" or "OR" or
     *       SearchEngine logic constants.  (OPTIONAL)
     * @return string Current logic setting.
     * @throws InvalidArgumentException If new setting is invalid.
     */
    public function logic($NewValue = null): string
    {
        # if new value supplied
        if ($NewValue !== null) {
            # normalize value
            $NormValue = ($NewValue == SearchEngine::LOGIC_OR)
                    ? "OR"
                    : (($NewValue == SearchEngine::LOGIC_AND)
                            ? "AND"
                            : strtoupper((string)$NewValue));

            # error out if value appears invalid
            if (($NormValue !== "AND") && ($NormValue !== "OR")) {
                throw new InvalidArgumentException("New logic setting"
                        ." is invalid (".$NewValue.").");
            }

            # save new setting
            $this->Logic = $NormValue;
        }

        # return current logic setting to caller
        return $this->Logic;
    }

    /**
     * Get/set allowed item types.  By default there are no restrictions on
     * item types.
     * @param mixed $ItemTypes Allowed item type or array of item types.  To
     *       clear any restrictions, pass in FALSE.  (OPTIONAL)
     * @return array|false Allowed item types, or FALSE if no restrictions
     *       on item type are set.
     */
    public function itemTypes($ItemTypes = null)
    {
        if ($ItemTypes !== null) {
            if ($ItemTypes === false) {
                $this->ItemTypes = false;
            } else {
                if (!is_array($ItemTypes)) {
                    $ItemTypes = [$ItemTypes];
                }
                $this->ItemTypes = array_unique($ItemTypes);
            }
        }
        return $this->ItemTypes;
    }

    /**
     * Get/set field to sort results by.  By default results are sorted by
     * relevance score.  Subgroup values for this setting are ignored.
     * @param mixed $NewValue Field to sort by or array of fields to sort by
     *       indexed by item type, or FALSE to sort results by relevance.  (OPTIONAL)
     * @return mixed Current field to sort results by or array of fields to
     *       sort by indexed by item type, or FALSE if results will be sorted
     *       by relevance score.
     */
    public function sortBy($NewValue = null)
    {
        if ($NewValue !== null) {
            $this->SortBy = $NewValue;
        }
        return $this->SortBy;
    }

    /**
     * Get/set whether to sort results will be sorted in descending (as opposed
     * to ascending) order.  By default results are sorted in descending order.
     * Subgroup values for this setting are ignored.
     * @param bool|array $NewValue Boolean value or array of boolean values
     *       indexed by item type.  If TRUE, results will be sorted in descending
     *       order, otherwise results will be sending in ascending order.  (OPTIONAL)
     * @return bool|array Boolean value or array of boolean values indexed by
     *       item type.  If TRUE, results will be sorted in descending order
     *       otherwise if FALSE, results will be sorted in ascending order.
     */
    public function sortDescending($NewValue = null)
    {
        if ($NewValue !== null) {
            $this->SortDescending = $NewValue;
        }
        return $this->SortDescending;
    }

    /**
     * Add subgroup of search parameters to set.
     * @param SearchParameterSet $Set Subgroup to add.
     */
    public function addSet(SearchParameterSet $Set): void
    {
        $this->Subgroups[] = $Set;
    }


    /*@)*/
    # ---- DATA RETRIEVAL -----------------------------------------------------
    /** @name Data Retrieval */ /*@(*/

    /**
     * Get number of search parameters in set, including those in subgroups.
     * @return int Parameter count.
     */
    public function parameterCount(): int
    {
        $Count = count($this->KeywordSearchStrings);
        foreach ($this->SearchStrings as $Field => $Strings) {
            $Count += count($Strings);
        }
        foreach ($this->Subgroups as $Group) {
            $Count += $Group->ParameterCount();
        }
        return $Count;
    }

    /**
     * Get search strings in set.
     * @param bool $IncludeSubgroups If TRUE, include search strings from
     *       any parameter subgroups.  (OPTIONAL, defaults to FALSE)
     * @return array Array of arrays of search strings, with canonical field
     *       identifiers for the root index.
     */
    public function getSearchStrings(bool $IncludeSubgroups = false): array
    {
        $SearchStrings = $this->SearchStrings;
        if ($IncludeSubgroups) {
            foreach ($this->Subgroups as $Group) {
                $SubStrings = $Group->getSearchStrings(true);
                foreach ($SubStrings as $Field => $Strings) {
                    if (isset($SearchStrings[$Field])) {
                        $SearchStrings[$Field] = array_merge(
                            $SearchStrings[$Field],
                            $Strings
                        );
                    } else {
                        $SearchStrings[$Field] = $Strings;
                    }
                }
            }
        }
        return $SearchStrings;
    }

    /**
     * Get search strings for specified field.
     * @param mixed $Field Field identifier.
     * @param bool $IncludeSubgroups If TRUE, search strings for subgroups
     *       will be returned as well.  (OPTIONAL, defaults to TRUE)
     * @return array Search strings.
     * @see SearchParameterSet::canonicalFieldFunction()
     */
    public function getSearchStringsForField($Field, bool $IncludeSubgroups = true): array
    {
        # normalize field value
        $Field = self::normalizeField($Field);

        # start with our string values
        $Strings = isset($this->SearchStrings[$Field])
                ? $this->SearchStrings[$Field] : array();

        # if strings from subgroups should also be returned
        if ($IncludeSubgroups) {
            # for each subgroup
            foreach ($this->Subgroups as $Group) {
                # add any strings from that subgroup
                $Strings = array_merge(
                    $Strings,
                    $Group->getSearchStringsForField($Field)
                );
            }
        }

        # return all strings found to caller
        return $Strings;
    }

    /**
     * Get keyword search strings in set.
     * @return array Array of keyword search strings.
     */
    public function getKeywordSearchStrings(): array
    {
        return $this->KeywordSearchStrings;
    }

    /**
     * Get parameter subgroups.
     * @return array Array of Parameter subgroup (SearchParameterSet) objects.
     */
    public function getSubgroups(): array
    {
        return $this->Subgroups;
    }

    /**
     * Get fields used in search parameters (including subgroups).
     * @return array list of canonical field identifiers.
     */
    public function getFields(): array
    {
        # retrieve our fields
        $Fields = array_keys($this->SearchStrings);

        # for each subgroup
        foreach ($this->Subgroups as $Group) {
            # add fields from subgroup to the list
            $Fields = array_merge($Fields, $Group->GetFields());
        }

        # filter out duplicates and sort to ensure consistency
        $Fields = array_unique($Fields);
        sort($Fields);

        # return list of field identifiers to caller
        return $Fields;
    }


    /*@)*/
    # ---- DATA TRANSLATION ---------------------------------------------------
    /** @name Data Translation */ /*@(*/

    /**
     * Get/set search parameter set data, in the form of an opaque string.
     * "SortBy" and "SortDescending" will not be preserved.
     * This method can be used to retrieve an opaque string containing
     * set data, which can then be saved (e.g. to a database) and later used
     * to reload a search parameter set.  (Use instead of serialize() to avoid
     * future issues with internal class changes.)
     * @param string $NewValue New search parameter set data.  (OPTIONAL)
     * @return string Current search parameter set data (opaque value).
     * @throws InvalidArgumentException If incoming set data appears invalid.
     */
    public function data(?string $NewValue = null): string
    {
        # if new data supplied
        if ($NewValue !== null) {
            # unpack set data and load
            $this->loadFromData($NewValue);
        }

        # serialize current data and return to caller
        $Data = array();
        if ($this->Logic !== "AND") {
            $Data["Logic"] = $this->Logic;
        }
        if (count($this->SearchStrings)) {
            $Data["SearchStrings"] = $this->SearchStrings;
        }
        if (count($this->KeywordSearchStrings)) {
            $Data["KeywordSearchStrings"] = $this->KeywordSearchStrings;
        }
        if (count($this->Subgroups)) {
            foreach ($this->Subgroups as $Subgroup) {
                $Data["Subgroups"][] = $Subgroup->Data();
            }
        }
        if ($this->ItemTypes !== false) {
            $Data["ItemTypes"] = array_unique($this->ItemTypes);
        }
        return serialize($Data);
    }

    /**
     * Get/set search parameter set, in the form of URL parameters.
     * "SortBy" and "SortDescending" are not included in parameters.
     * @param string|array $NewValue New parameter set in the form of a URL
     *       parameter string or URL parameter array.  (OPTIONAL)
     * @return array URL parameter values, with parameter names for the index.
     * @throws InvalidArgumentException If some new settings are invalid.
     */
    public function urlParameters($NewValue = null): array
    {
        # if new value supplied
        if ($NewValue !== null) {
            # set new parameters
            $this->setFromUrlParameters($NewValue);
        }

        # get existing search parameters as URL parameters
        $Params = $this->getAsUrlParameters();

        # sort parameters by parameter name to normalize result
        ksort($Params);

        # return parameters to caller
        return $Params;
    }

    /**
     * Get/set search parameter set, in the form of an URL parameter string.
     * "SortBy" and "SortDescending" are not included in parameters.
     * @param string|array $NewValue New parameter set in the form of a URL
     *       parameter string or URL parameter array.  (OPTIONAL)
     * @return string URL parameter string.
     */
    public function urlParameterString($NewValue = null): string
    {
        # get/set parameters
        $Params = $this->urlParameters($NewValue);

        # assemble into string and return it to caller
        return http_build_query($Params);
    }

    /**
     * Get text description of search parameter set.
     * @param bool $IncludeHtml Whether to include HTML tags for formatting.
     *       (OPTIONAL, defaults to TRUE)
     * @param bool $StartWithBreak Whether to start string with BR tag.
     *       (OPTIONAL, defaults to TRUE)
     * @param int $TruncateLongWordsTo Number of characters to truncate long
     *       words to (use 0 for no truncation).  (OPTIONAL, defaults to 0)
     * @param string $Indent For internal (recursive) use only.
     * @return string Text description of search parameters.
     */
    public function textDescription(
        bool $IncludeHtml = true,
        bool $StartWithBreak = true,
        int $TruncateLongWordsTo = 0,
        string $Indent = ""
    ): string {
        # define list of phrases used to represent logical operators
        $OperatorPhrases = [
            "=" => "is",
            "==" => "is",
            ">" => "is greater than",
            "<" => "is less than",
            ">=" => "is at least",
            "<=" => "is no more than",
            "!" => "is not",
            "!=" => "is not",
            "^" => "begins with",
            "$" => "ends with",
            "@" => "was last modified on or after",
            "@>" => "was last modified after",
            "@>=" => "was last modified on or after",
            "@<" => "was last modified before",
            "@<=" => "was last modified on or before",
        ];
        $AgoOperatorPhrases = [
            "@>" => "was last modified more than",
            "@>=" => "was last modified at least or more than",
            "@<" => "was last modified less than",
            "@<=" => "was last modified at most or less than",
        ];

        # set characters used to indicate literal strings
        $LiteralStart = $IncludeHtml ? "<i>" : "\"";
        $LiteralEnd = $IncludeHtml ? "</i>" : "\"";
        $LiteralBreak = $IncludeHtml ? "<br>\n" : "\n";
        $Indent .= $IncludeHtml ? "&nbsp;&nbsp;&nbsp;&nbsp;" : "  ";

        # for each keyword search string
        $Descriptions = array();
        foreach ($this->KeywordSearchStrings as $SearchString) {
            # skip empty keyword search strings
            if (!strlen($SearchString)) {
                continue;
            }

            # escape search string if appropriate
            if ($IncludeHtml) {
                $SearchString = defaulthtmlentities($SearchString);
            }

            # add string to list of descriptions
            $Descriptions[] = $LiteralStart.$SearchString.$LiteralEnd;
        }

        # for each field with search strings
        foreach ($this->SearchStrings as $FieldId => $SearchStrings) {
            # retrieve field name
            $FieldName = call_user_func(self::$PrintableFieldFunction, $FieldId);

            # for each search string
            foreach ($SearchStrings as $SearchString) {
                # extract operator from search string
                $MatchResult = preg_match(
                    SearchEngine::COMPARISON_OPERATOR_PATTERN,
                    $SearchString,
                    $Matches
                );
                $Operator = ($MatchResult == 1 && isset($Matches[1])) ?
                    $Matches[1] : null;

                # determine operator phrase
                if (($MatchResult == 1) && isset($OperatorPhrases[$Operator])) {
                    if (isset($AgoOperatorPhrases[$Operator])
                            && strstr($SearchString, "ago")) {
                        $OpPhrase = $AgoOperatorPhrases[$Operator];
                    } else {
                        $OpPhrase = $OperatorPhrases[$Operator];
                    }
                    $SearchString = trim(preg_replace(
                        SearchEngine::COMPARISON_OPERATOR_PATTERN,
                        "",
                        $SearchString
                    ));
                } else {
                    $OpPhrase = "contains";
                }

                # translate value if appropriate
                if (isset(self::$PrintableValueFunction)) {
                    $SearchString = call_user_func(
                        self::$PrintableValueFunction,
                        $FieldId,
                        $SearchString
                    );
                }

                # escape field name and search string if appropriate
                if ($IncludeHtml) {
                    $FieldName = defaulthtmlentities($FieldName);
                    $SearchString = defaulthtmlentities($SearchString);
                }

                # assemble printable version of value
                $Value = $LiteralStart.$SearchString.$LiteralEnd;

                # handle empty search strings for equality and inequality
                if (!strlen($SearchString)) {
                    if (($OpPhrase == "is") || ($OpPhrase == "is not")) {
                        $Value = "empty";
                    }
                }

                # handle special case of dates relative to current time
                if (strcasecmp($SearchString, "now") == 0) {
                    if (($Operator == "<") || ($Operator == "<=")) {
                        $OpPhrase = "is in the past";
                        $Value = "";
                    } elseif (($Operator == ">") || ($Operator == ">=")) {
                        $OpPhrase = "is in the future";
                        $Value = "";
                    }
                }

                # assemble field and operator and value into description
                $Descriptions[] = $FieldName." ".$OpPhrase." ".$Value;
            }
        }

        # for each subgroup
        foreach ($this->Subgroups as $Subgroup) {
            # if subgroup is not empty
            if ($Subgroup->ParameterCount() > 0) {
                # retrieve description for subgroup
                $SubgroupDescrip = $Subgroup->textDescription(
                    $IncludeHtml,
                    $StartWithBreak,
                    $TruncateLongWordsTo,
                    $Indent
                );

                # add parens around description if it contains multiple elements
                #       and there will be other descriptions at the same level
                if ($Subgroup->ParameterCount() > 1) {
                    if ((count($this->Subgroups) > 1)
                            || (count($Descriptions) > 0)) {
                        $SubgroupDescrip = "(".$SubgroupDescrip.")";
                    }
                }

                # add subsgroup description to current list of descriptions
                $Descriptions[] = $SubgroupDescrip;
            }
        }

        # join descriptions with appropriate conjunction
        $Descrip = join(
            $LiteralBreak.$Indent." ".strtolower($this->Logic)." ",
            $Descriptions
        );

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
            $Descrip = StdLib::closeOpenTags($NewDescrip);
        }

        if (isset(self::$TextDescriptionFilterFunction)) {
            $Descrip = call_user_func_array(
                self::$TextDescriptionFilterFunction,
                [$Descrip]
            );
        }

        # return description to caller
        return trim($Descrip);
    }


    /*@)*/
    # ---- UTILITY METHODS ----------------------------------------------------
    /** @name Utility Methods */ /*@(*/

    /**
     * Modify all search strings with the specified regular expression.
     * @param string $Pattern Regular expression pattern.
     * @param string $Replacement Replacement string.
     */
    public function replaceSearchString(string $Pattern, string $Replacement): void
    {
        # modify our fielded search strings
        foreach ($this->SearchStrings as $Field => $Strings) {
            $this->SearchStrings[$Field] =
                    preg_replace($Pattern, $Replacement, $Strings);
        }

        # modify our keyword search strings
        if (count($this->KeywordSearchStrings)) {
            $this->KeywordSearchStrings =
                    preg_replace($Pattern, $Replacement, $this->KeywordSearchStrings);
        }

        # modify any subgroups
        foreach ($this->Subgroups as $Group) {
            $Group->ReplaceSearchString($Pattern, $Replacement);
        }
    }

    /**
     * Create a new search parameter set from supplied XML data.
     * @param iterable $Xml Element containing XML data.
     * @return SearchParameterSet Resulting new search parameter set.
     * @throws Exception if bad data encountered in XML.
     */
    public static function createFromXml($Xml)
    {
        # create new set
        $NewSet = new static();

        # for each XML child
        foreach ($Xml as $Tag => $Value) {
            # take action based on element name
            switch ($Tag) {
                case "Set":
                    # convert child data to new set
                    $NewSubset = static::createFromXml($Value);

                    # add new set to our search parameter set
                    $NewSet->addSet($NewSubset);
                    break;

                case "Parameter":
                    # start out assuming no field will be supplied
                    $Field = null;

                    # pull out parameters
                    $SearchStrings = [];
                    foreach ($Value as $ParamName => $ParamValue) {
                        $ParamValue = trim($ParamValue);
                        switch ($ParamName) {
                            case "Field":
                                $Field = self::normalizeField($ParamValue);
                                if ($Field === null) {
                                    # record error about unknown field and bail
                                    throw new Exception("Unknown field found"
                                            ." in AddParameter (".$ParamValue.").");
                                }
                                break;

                            case "Value":
                                $SearchStrings[] = $ParamValue;
                                break;

                            default:
                                # record error about unknown parameter name and bail
                                throw new Exception(
                                    "Unknown tag found in AddParameter (".$ParamName.")."
                                );
                        }
                    }

                    # if no search strings found
                    if (!count($SearchStrings)) {
                        # record error about no field value and bail
                        throw new Exception(
                            "No search strings specified in AddParameter."
                        );
                    }

                    # add parameter to set
                    $NewSet->addParameter($SearchStrings, $Field);
                    break;

                default:
                    # if child looks like valid method name
                    if (method_exists(get_called_class(), $Tag)) {
                        # strip any excess whitespace off of value
                        $Value = trim($Value);

                        # convert constants if needed
                        if (defined($Value)) {
                            $Value = constant($Value);
                        # convert booleans if needed
                        } elseif (strtoupper($Value) == "TRUE") {
                            $Value = true;
                        } elseif (strtoupper($Value) == "FALSE") {
                            $Value = false;
                        }

                        # set value using child data
                        $NewSet->$Tag((string)$Value);
                    } else {
                        # record error about bad tag
                        throw new Exception("Unknown tag encountered (".$Tag.").");
                    }
                    break;
            }
        }

        # return PrivSet to caller
        return $NewSet;
    }


    /*@)*/
    # ---- BACKWARD COMPATIBILITY ---------------------------------------------
    /** @name Backward Compatibility */ /*@(*/

    /**
     * Retrieve search parameters in legacy array format.  This method is
     * provided for backward compatibility only, and its use is deprecated.
     * Searches conducted using the legacy format may not return identical
     * results because the legacy format does not support nested search groups.
     * @return array Parameters in legacy array format.
     * @see SearchEngine::GroupedSearch()
     */
    public function getAsLegacyArray(): array
    {
        $Legacy = array();

        $Group = $this->convertToLegacyGroup();
        if (count($Group)) {
            $Legacy["MAIN"] = $Group;
        }

        # for each subgroup
        foreach ($this->Subgroups as $Subgroup) {
            # skip empty search groups
            if (count($Subgroup->SearchStrings) == 0) {
                continue;
            }

            $SubLegacy = $Subgroup->ConvertToLegacyGroup();

            # give an index based on the FieldId of the first
            # element in the SearchStrings
            $FieldId = call_user_func(
                self::$CanonicalFieldFunction,
                current(array_keys($SubLegacy["SearchStrings"]))
            );

            # add groups from legacy array to our array
            if (!isset($Legacy[$FieldId])) {
                $Legacy[$FieldId] = $SubLegacy;
            } else {
                $Num = count($Legacy[$FieldId]);
                $Legacy[$FieldId."-".$Num] = $SubLegacy;
            }

            if (count($Subgroup->Subgroups)) {
                throw new Exception(
                    "Attempt to convert SearchParameterSet containing nested subgroups "
                        ."to legacy format"
                );
            }
        }

        # return array to caller
        return $Legacy;
    }

    /**
     * Set search parameters from legacy array format.
     * @param array $SearchGroups Legacy format search groups.
     */
    public function setFromLegacyArray(array $SearchGroups): void
    {
        # clear current settings
        $this->KeywordSearchStrings = array();
        $this->SearchStrings = array();
        $this->Subgroups = array();

        # iterate over legacy search groups
        foreach ($SearchGroups as $GroupId => $SearchGroup) {
            if ($GroupId == "MAIN") {
                # add terms from the main search group to ourself
                $this->loadFromLegacyGroup($SearchGroup);
            } else {
                # create subgroups for other groups
                $Subgroup = new static();
                $Subgroup->loadFromLegacyGroup($SearchGroup);

                # add any non-empty groups
                if ($Subgroup->parameterCount()) {
                    $this->addSet($Subgroup);
                }
            }
        }
    }

    /**
     * Set search parameters from legacy URL string.
     * @param string $ParameterString Legacy url string.
     */
    public function setFromLegacyUrl(string $ParameterString): void
    {
        # clear current settings
        $this->KeywordSearchStrings = array();
        $this->SearchStrings = array();
        $this->Subgroups = array();

        # extact array of parameters from passed string
        parse_str($ParameterString, $GetVars);

        # iterate over the provided parameters
        foreach ($GetVars as $Key => $Val) {
            # if this param gives search information
            if (preg_match("/^([FGH])(K|[0-9]+)$/", (string)$Key, $Matches)) {
                # extract what kind of search it was which field
                $Type = $Matches[1];
                $FieldId = $Matches[2];

                # for 'contains' searches
                if ($Type == "F") {
                    # add this to our search strings
                    $this->addParameter(
                        $Val,
                        ($FieldId == "K" ? null : $FieldId)
                    );
                } else {
                    # otherwise, create a subgroup for this parameter
                    $Subgroup = new static();

                    # set logic based on the search type
                    $Subgroup->logic($Type == "H" ? "AND" : "OR");

                    # extract the values and add them to a subgroup
                    $Values = is_array($Val) ? $Val : explode("-", $Val);
                    $Subgroup->addParameter(
                        self::translateLegacySearchValues($FieldId, $Values),
                        $FieldId
                    );

                    # if subgroup was non-empty, slurp it up
                    if ($Subgroup->parameterCount()) {
                        $this->addSet($Subgroup);
                    }
                }
            }
        }
    }

    /**
     * Determine if a URL uses legacy format.
     * @param string $ParameterString Url parameter string
     * @return bool True for legacy URLs, false otherwise
     */
    public static function isLegacyUrl(string $ParameterString): bool
    {
        parse_str($ParameterString, $QueryVars);

        return (array_key_exists("Q", $QueryVars) &&
                $QueryVars["Q"] == "Y") ? true : false;
    }

    /**
     * Convert legacy URL to the current URL format.
     * @param string $ParameterString Legacy url parameter string
     * @return string converted URL parameter string
     */
    public static function convertLegacyUrl(string $ParameterString): string
    {
        $SearchParams = new static();
        $SearchParams->setFromLegacyUrl($ParameterString);

        return $SearchParams->urlParameterString();
    }

    /**
     * Register function used to converrt values from the format used
     * in a Legacy URL string to the modern version.
     * @param callable $Func Function to convert legacy URL parameter
     *     values into modern versions
     */
    public static function setLegacyUrlTranslationFunction(callable $Func): void
    {
        self::$LegacyUrlTranslationFunction = $Func;
    }

    /**
     * Register function used to filter text descriptions of searches
     * prior to display.
     * @param callable $Func Function to beautify text
     * descriptions. Must take a string as input and return a possibly
     * modified string.
     */
    public static function setTextDescriptionFilterFunction(callable $Func): void
    {
        self::$TextDescriptionFilterFunction = $Func;
    }

    /**
     * Translate legacy search values to modern equivalents if possible.
     * @param mixed $Field Supplied field.
     * @param mixed $Values Supplied values.
     * @return array Translated values.
     */
    public static function translateLegacySearchValues($Field, $Values): array
    {
        if (($Field !== null) && isset(self::$LegacyUrlTranslationFunction)) {
            $Values = call_user_func(
                self::$LegacyUrlTranslationFunction,
                $Field,
                $Values
            );
        }
        return $Values;
    }

    /*@)*/
    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $KeywordSearchStrings = array();
    private $ItemTypes = false;
    private $Logic;
    private $SearchStrings = array();
    private $SortBy = false;
    private $SortDescending = true;
    private $Subgroups = array();

    private static $CanonicalFieldFunction;
    private static $DefaultLogic = "AND";
    private static $LegacyUrlTranslationFunction;
    private static $PrintableFieldFunction;
    private static $PrintableValueFunction;
    private static $TextDescriptionFilterFunction;
    private static $UrlParameterPrefix = "F";

    const URL_ITEMTYPE_INDICATOR = "01";
    const URL_KEYWORDFREE_RANGE = "A-JL-Z";
    const URL_KEYWORD_INDICATOR = "K";
    const URL_LOGIC_INDICATOR = "00";

    /**
     * Load set from serialized data.
     * @param string $Serialized Set data.
     * @throws InvalidArgumentException If incoming set data appears invalid.
     */
    private function loadFromData(string $Serialized): void
    {
        # unpack new data
        $Data = @unserialize($Serialized);
        if (($Data === false) || !is_array($Data)) {
            throw new InvalidArgumentException("Incoming set data"
                    ." appears invalid.");
        }

        # load logic
        $this->Logic = isset($Data["Logic"])
                ? $Data["Logic"] : self::$DefaultLogic;

        # load search strings
        $this->SearchStrings = isset($Data["SearchStrings"])
                ? $Data["SearchStrings"] : array();
        $this->KeywordSearchStrings = isset($Data["KeywordSearchStrings"])
                ? $Data["KeywordSearchStrings"] : array();

        # load any subgroups
        $this->Subgroups = array();
        if (isset($Data["Subgroups"])) {
            foreach ($Data["Subgroups"] as $SubgroupData) {
                $this->Subgroups[] = new static($SubgroupData);
            }
        }

        # load any item type restrictions
        if (isset($Data["ItemTypes"])) {
            $this->ItemTypes = array_unique($Data["ItemTypes"]);
        }
    }

    /**
     * Convert a SearchParameterSet to a legacy array subgroup.
     * @return array Legacy array subgroup
     */
    private function convertToLegacyGroup(): array
    {
        $Group = array();
        # for each set of search strings
        foreach ($this->SearchStrings as $Field => $Strings) {
            # get text name of field
            $FieldName = call_user_func(self::$PrintableFieldFunction, $Field);

            # add set to group
            $Group["SearchStrings"][$FieldName] = $Strings;
        }

        # for each keyword search string
        foreach ($this->KeywordSearchStrings as $String) {
            # add string to keyword entry in group
            $Group["SearchStrings"]["XXXKeywordXXX"][] = $String;
        }

        # if we had any search terms
        if (count($Group)) {
            # smash single-value arrays to a scalar
            foreach ($Group["SearchStrings"] as &$Tgt) {
                if (count($Tgt) == 1) {
                    $Tgt = current($Tgt);
                }
            }

            # set logic for search group
            $Group["Logic"] = ($this->Logic == "OR")
                    ? SearchEngine::LOGIC_OR : SearchEngine::LOGIC_AND;
        }

        return $Group;
    }

    /**
     * Load from legacy format search group.
     * @param array $Group Legacy search group
     */
    private function loadFromLegacyGroup(array $Group): void
    {
        # set logic appropriately
        $this->logic(
            ($Group["Logic"] == SearchEngine::LOGIC_OR) ?
                        "OR" : "AND"
        );

        # if this group had no search strings, we're done
        if (!isset($Group["SearchStrings"])) {
            return;
        }

        # otherwise, load the search strings
        foreach ($Group["SearchStrings"] as $Field => $Params) {
            # skip empty groups
            if (count($Params) == 0) {
                continue;
            }

            $this->addParameter(
                $Params,
                ($Field == "XXXKeywordXXX" ? null : $Field)
            );
        }
    }

    /**
     * Get the search set parameter set as an array of URL parameters.
     * "SortBy" and "SortDescending" are not preserved.
     * URL parameters names follow this format:
     *     URL Parameter Prefix ("F")
     *     Set Prefix (one or more letters) (OPTIONAL)
     *     Field ID (number) or Keyword Search Indicator ("K")
     *     Parameter Suffix (single letter) (omitted for first parameter)
     * The magic field ID "00" indicates a search logic value and the magic
     * field ID "01" indicates an item type restriction.
     * @param string $SetPrefix Prefix to use after the URL parameter prefix.
     * @return array Array of URL parameters.
     */
    private function getAsUrlParameters(string $SetPrefix = ""): array
    {
        # for each search string group in set
        $Params = array();
        foreach ($this->SearchStrings as $FieldId => $Values) {
            # get numeric version of field ID if not already numeric
            if (!is_numeric($FieldId)) {
                $FieldId = call_user_func(self::$CanonicalFieldFunction, $FieldId);
            }

            $Params = $this->addUrlParameters(
                $Params,
                $SetPrefix.$FieldId,
                "search parameters for field ID ".$FieldId,
                $Values
            );
        }

        # for each keyword search string
        $Params = $this->addUrlParameters(
            $Params,
            $SetPrefix.self::URL_KEYWORD_INDICATOR,
            "keyword search parameters",
            $this->KeywordSearchStrings
        );

        # add logic if not default
        if ($this->Logic != self::$DefaultLogic) {
            $Params[self::$UrlParameterPrefix.$SetPrefix
                    .self::URL_LOGIC_INDICATOR] = $this->Logic;
        }

        # add item type restrictions if set
        if ($this->ItemTypes !== false) {
            $Params = $this->addUrlParameters(
                $Params,
                $SetPrefix.self::URL_ITEMTYPE_INDICATOR,
                "item type restrictions",
                $this->ItemTypes
            );
        }

        # for each search parameter subgroup
        $SetLetter = "A";
        foreach ($this->Subgroups as $Subgroup) {
            # check for too many subgroups
            if ($SetLetter == "Z") {
                throw new Exception("Maximum search parameter complexity"
                        ." exceeded:  more than 24 search parameter subgroups.");
            }

            # retrieve URL string for subgroup and add it to URL
            $Params = array_merge($Params, $Subgroup->GetAsUrlParameters(
                $SetPrefix.$SetLetter
            ));

            # move to next set letter (skipping over URL keyword indicator)
            $SetLetter = ($SetLetter == chr(ord(self::URL_KEYWORD_INDICATOR) - 1))
                    ? chr(ord(self::URL_KEYWORD_INDICATOR) + 1)
                    : chr(ord($SetLetter) + 1);
        }

        # return constructed URL parameter string to caller
        return $Params;
    }

    /**
     * Add URL parameters for each value in supplied array.
     * @param array $Params Existing parameters.
     * @param string $Prefix Name prefix to use (after standard prefix).
     * @param string $Description Value description, to use in error messages.
     * @param array $Values Values to add.
     * @return array New (possibly expanded) URL parameter array.
     */
    private function addUrlParameters(
        array $Params,
        string $Prefix,
        string $Description,
        array $Values
    ): array {
        $ParamSuffix = "";
        foreach ($Values as $Value) {
            if ($ParamSuffix == "!") {
                throw new Exception("Maximum search parameter complexity"
                        ." exceeded:  more than 27 ".$Description.".");
            }
            $Index = self::$UrlParameterPrefix.$Prefix.$ParamSuffix;
            $Params[$Index] = $Value;
            $ParamSuffix = ($ParamSuffix == "") ? "A"
                    : (($ParamSuffix == "Z") ? "!" : chr(ord($ParamSuffix) + 1));
        }
        return $Params;
    }

    /**
     * Set search parameter from URL parameters in the same format as
     * produced by GetAsUrlParameters().
     * @param string|array $UrlParameters URL parameter string or array.
     * @see SearchParameterSet::getAsUrlParameters()
     * @throws InvalidArgumentException If some new settings are invalid.
     */
    private function setFromUrlParameters($UrlParameters): void
    {
        # if string was passed in
        if (is_string($UrlParameters)) {
            # split string into parameter array
            parse_str($UrlParameters, $UrlParamsFromSplit);

            # error out if no parameters found and string was non-empty
            if ((count($UrlParamsFromSplit) == 0) && (strlen($UrlParameters) != 0)) {
                throw new InvalidArgumentException("Unable to parse URL"
                        ." parameter string (\"".$UrlParameters."\").");
            }
            $UrlParameters = $UrlParamsFromSplit;
        }

        # pare down parameter array to search parameter elements
        # (filter out any parameter that does not begin with $UrlParameterPrefix)
        $UrlParameterPrefix = self::$UrlParameterPrefix;
        $FilterFunc = function ($ParamName) use ($UrlParameterPrefix) {
            return (strpos($ParamName, $UrlParameterPrefix) === 0);
        };
        $UrlParameters = array_filter($UrlParameters, $FilterFunc, ARRAY_FILTER_USE_KEY);

        # for each search parameter
        foreach ($UrlParameters as $ParamName => $SearchString) {
            # strip off standard search parameter prefix
            $ParamName = substr($ParamName, strlen(self::$UrlParameterPrefix));

            # split parameter into component parts
            $SplitResult = preg_match(
                "/^([".self::URL_KEYWORDFREE_RANGE."]*)"
                    ."([0-9".self::URL_KEYWORD_INDICATOR."]+?)([A-Z]*)$/",
                $ParamName,
                $Matches
            );

            # if split was successful
            if ($SplitResult === 1) {
                # pull components from split pieces
                $SetPrefix = $Matches[1];
                $FieldId = $Matches[2];
                $ParamSuffix = $Matches[3];

                # if set prefix indicates parameter is part of our set
                if ($SetPrefix == "") {
                    # Note: cannot use a switch here because it does 'loose
                    # comparison' such that "01" will compare equal to "1",
                    # which incorrectly interprets any searches for FieldId 1 as
                    # item type settings
                    # see https://www.php.net/manual/en/control-structures.switch.php
                    if ($FieldId === self::URL_ITEMTYPE_INDICATOR) {
                        # parse out item types and save
                        $this->ItemTypes[] = $SearchString;
                    } elseif ($FieldId === self::URL_KEYWORD_INDICATOR) {
                        # add string to keyword searches
                        $this->KeywordSearchStrings[] = $SearchString;
                    } elseif ($FieldId === self::URL_LOGIC_INDICATOR) {
                        # set logic
                        $this->logic($SearchString);
                    } else {
                        # add string to searches for appropriate field
                        $this->SearchStrings[$FieldId][] = $SearchString;
                    }
                } else {
                    # add parameter to array for subgroup
                    $SubgroupIndex = $SetPrefix[0];
                    $SubgroupPrefix = (strlen($SetPrefix) > 1)
                            ? substr($SetPrefix, 1) : "";
                    $SubgroupParamIndex = self::$UrlParameterPrefix
                            .$SubgroupPrefix.$FieldId.$ParamSuffix;
                    $SubgroupParameters[$SubgroupIndex][$SubgroupParamIndex]
                            = $SearchString;
                }
            }
        }

        if (is_array($this->ItemTypes)) {
            $this->ItemTypes = array_unique($this->ItemTypes);
        }

        # if subgroups were found
        if (isset($SubgroupParameters)) {
            # for each identified subgroup
            foreach ($SubgroupParameters as $SubgroupIndex => $Parameters) {
                # create subgroup and set parameters
                $Subgroup = new static();
                $Subgroup->setFromUrlParameters($Parameters);

                # add subgroup to our set
                $this->Subgroups[] = $Subgroup;
            }
        }
    }

    /**
     * Normalize supplied field to canonical field identifier if possible.
     * @param mixed $Field Supplied field.
     * @return integer|null Canonical field identifier.
     */
    private static function normalizeField($Field)
    {
        if (($Field !== null) && isset(self::$CanonicalFieldFunction)) {
            $Field = call_user_func(self::$CanonicalFieldFunction, $Field);
        }
        return $Field;
    }
}
