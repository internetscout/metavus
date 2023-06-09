<?PHP
#
#   FILE:  QuickSearchHelper.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

/**
* Convenience class for QuickSearch responses, making it easy to share
* functions common to different types of QuickSearch objects.
*/
class QuickSearchHelper
{
    const USER_SEARCH = "UserSearch";
    const DYNAMIC_SEARCH = "DynamicSearch";

    /**
     * Search a field for values matching a specified search string
     * @param MetadataField $Field Metadata field.
     * @param string $SearchString Search string.
     * @param array $IdExclusions Array of IDs for values to exclude.
     * @param array $ValueExclusions Array of values to exclude.
     * @return array Returns an array containing the number of search results,
     *       the number of additional results available, and the search results.
     */
    public static function searchField(
        MetadataField $Field,
        $SearchString,
        array $IdExclusions = [],
        array $ValueExclusions = []
    ) {
        $MaxResults = $Field->numAjaxResults();

        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_USER:
                return self::searchForUsers(
                    $SearchString,
                    $MaxResults,
                    $IdExclusions,
                    $ValueExclusions,
                    $Field->userPrivilegeRestrictions()
                );

            case MetadataSchema::MDFTYPE_REFERENCE:
                if (count($ValueExclusions)) {
                    throw new Exception(
                        "Cannot exclude resource by value."
                        ."Did you want IdExclusions instead?"
                    );
                }

                return self::searchForResources(
                    $Field,
                    $SearchString,
                    $MaxResults,
                    $IdExclusions
                );

            default:
                return self::searchForValues(
                    $Field,
                    $SearchString,
                    $MaxResults,
                    $IdExclusions,
                    $ValueExclusions
                );
        }
    }

    /**
    * Highlight all instances of the search string in the result label
    * @param string|array $SearchTerms The string(s) to highlight, optionally
    *       array of strings.
    * @param string $LabelForFormatting The label in which to highlight the
    *       search string.
    * @return string Returns the formatted label string.
    */
    public static function highlightSearchString($SearchTerms, $LabelForFormatting)
    {
        if (!is_array($SearchTerms)) {
            $SearchTerms = [$SearchTerms];
        }

        foreach ($SearchTerms as $SearchString) {
            $Patterns = [];
            $Index = 0;
            $InQuote = false;
            $SearchString = trim($SearchString);
            $ExplodedSearch = preg_split('/\s+/', $SearchString);

            if ($ExplodedSearch === false) {
                throw new Exception("Unable to parse search string.");
            }

            #Iterate through each term in the search string
            foreach ($ExplodedSearch as $Term) {
                #Handle quoted terms differently
                #if the first character is a quote
                if ($Term[0] == '"') {
                    $InQuote = true;
                }

                if (substr($Term, -1) == '"') {
                    #last character is a quote means that we've found the end of the term.
                    $InQuote = false;
                }

                #remove all of the quotes if we're matched
                $Term = str_replace('"', "", $Term);

                #Add the term to the list of patterns we'll be highlighting in the result
                # string at the current index (quoted terms will be appended to the index,
                # unquoted terms are added at a new index).
                $Patterns[$Index] = (isset($Patterns[$Index]) ?
                      $Patterns[$Index]." " : "").$Term;

                if (!$InQuote) {
                    # if we are not in a quoted term, the next term should go at
                    # a new index in the pattern array.
                    $Index++;
                }
            }

            # iterate over our terms, escaping them and including bolding
            # for segments two more ore characters longer
            $PregPatterns = [];
            foreach ($Patterns as $Term) {
                if (strlen($Term) >= 2) {
                    $PregPatterns = "/".preg_quote($Term, "/")."/i";
                }
            }

            # do the highlighting
            $LabelForFormatting = preg_replace(
                $PregPatterns,
                "<b>$0</b>",
                $LabelForFormatting
            );
        }

        return $LabelForFormatting;
    }

    /**
    * Print the blank text field quick search field for the QuickSearch
    * JS object
    * @param int|string $FieldId Integer FieldId to search a metadata field,
    *   QuickSearchHelper::USER_SEARCH to search for users, or
    *   QuickSearchHelper::DYNAMIC_SEARCH to produce HTML elements for a
    *   quicksearch that aren't yet associated with a field but can be in
    *   front-end javascript.
    * @param int|string $CurrentValue The option's Id value, not for user display
    * @param string $CurrentDisplayValue The value to initially populate the field with
    * @param boolean $CloneAfter Whether to place a clone after this field
    * @param string $FormFieldName Value to use for the input name
    *   attribute in the generated html (OPTIONAL, defaults to field name)
    */
    public static function printQuickSearchField(
        $FieldId,
        $CurrentValue,
        $CurrentDisplayValue,
        $CloneAfter = false,
        $FormFieldName = null
    ) {
        $GLOBALS["AF"]->requireUIFile('jquery-ui.css', ApplicationFramework::ORDER_FIRST);
        $GLOBALS["AF"]->requireUIFile("jquery-ui.js");
        $GLOBALS["AF"]->requireUIFile("CW-QuickSearch.js");

        if (is_numeric($FieldId)) {
            $Field = new MetadataField(intval($FieldId));

            if ($FormFieldName === null) {
                $FormFieldName = "F_".$Field->dBFieldName();
            }
            $SafeFieldId = intval($FieldId);
        } elseif (in_array($FieldId, [self::USER_SEARCH, self::DYNAMIC_SEARCH])) {
            if ($FormFieldName === null) {
                throw new Exception(
                    "FormFieldName required for User or Dynamic Quick Search elements."
                );
            }
            $SafeFieldId = $FieldId;
        } else {
            throw new Exception("Invalid FieldId");
        }

        $FieldCssClass = ($FieldId == self::DYNAMIC_SEARCH) ?
            "mv-quicksearch-template" :
            "mv-quicksearch-fieldid-".$SafeFieldId ;

        $SafeCurrentValue = defaulthtmlentities($CurrentValue);
        $SafeCurrentDisplayValue = defaulthtmlentities($CurrentDisplayValue);
        // @codingStandardsIgnoreStart
        ?>
        <div class="mv-quicksearch <?= $FieldCssClass ?>" data-fieldid="<?= $SafeFieldId ?>">
          <textarea class="mv-quicksearch-display mv-resourceeditor-metadatafield
            <?= $FormFieldName; ?> mv-autoresize"><?= $SafeCurrentDisplayValue ?></textarea>
          <input name="<?= $FormFieldName ?>[]"
                 class="mv-quicksearch-value" type="hidden" value="<?= $SafeCurrentValue ?>" />
          <div style="display: none;" class='mv-quicksearch-message'></div>
          <div style="display: none;" class='mv-quicksearch-menu'></div>
        </div>
        <?PHP if ($CloneAfter) {?>
        <div class="mv-quicksearch-template mv-quicksearch mv-quicksearch-fieldid-<?= $SafeFieldId; ?>"
                       style="display: none;" data-fieldid="<?= $SafeFieldId; ?>">
          <textarea class="mv-quicksearch-display mv-resourceeditor-metadatafield
            <?= $FormFieldName; ?> mv-autoresize"></textarea>
          <input name="<?= $FormFieldName; ?>[]"
                 class="mv-quicksearch-value" type="hidden" value="<?= $SafeCurrentValue ?>" />
          <div style="display: none;" class='mv-quicksearch-message'></div>
          <div style="display: none;" class='mv-quicksearch-menu'></div>
        </div>
        <?PHP
        }
        // @codingStandardsIgnoreEnd
    }

        /**
    * Perform a search for users.
    * @param string $SearchString Search string.
    * @param int $MaxResults The maximum number of search results.
    * @param array $IdExclusions Array of user IDs for users to exclude.
    * @param array $ValueExclusions Array of values to exclude.
    * @param array $RequiredPrivs Array of privileges a user must have.
    * @return array giving the number of results displayed number of
    *   additional results available, and the results to display.
    */
    public static function searchForUsers(
        $SearchString,
        $MaxResults = 15,
        array $IdExclusions = [],
        array $ValueExclusions = [],
        array $RequiredPrivs = []
    ) {
        # the factory used for searching
        $UserFactory = new UserFactory();

        # get the minimum word length for fuzzy query matching
        $MinLen = Database::getFullTextSearchMinWordLength();

        # initialize the result variables
        $SearchResults = [];
        $ResultsNeeded = $MaxResults;

        # if the search string is less than the minimum length, do exact query
        # matching first
        if (strlen($SearchString) < $MinLen) {
            $SearchResults = $UserFactory->findUserNames(
                $SearchString,
                "UserName",
                "UserName",
                0, # defaults
                PHP_INT_MAX,
                $IdExclusions,
                $ValueExclusions
            );

            # decrement the max results by how many were found
            $ResultsNeeded -= count($SearchResults);
        }

        # if there are still some results to fetch, perform fuzzy matching
        if ($ResultsNeeded > 0) {
            $FuzzyResults = $UserFactory->getMatchingUsers(
                $SearchString,
                "UserName"
            );

            # filter results based on Id and Value exclusions
            foreach ($FuzzyResults as $UserId => $Result) {
                if (!in_array($UserId, $IdExclusions) &&
                    !in_array($Result["UserName"], $ValueExclusions)) {
                    $SearchResults[$UserId] = $Result["UserName"];
                }
            }
        }

        # if there were required privs, limit results to users that have them
        if (count($RequiredPrivs)) {
            $UserFactory = new UserFactory();
            $PossibleValues = $UserFactory->getUsersWithPrivileges(
                $RequiredPrivs
            );

            $SearchResults = array_intersect_key(
                $SearchResults,
                $PossibleValues
            );
        }

        # slice out just the results we want
        $TotalResults = count($SearchResults);
        $SearchResults = array_slice($SearchResults, 0, $MaxResults, true);

        $NumResults = count($SearchResults);
        $NumAdditionalResults = $TotalResults - $NumResults;

        return [
            $NumResults,
            $NumAdditionalResults,
            $SearchResults
        ];
    }

    /**
    * Prepare a search string for use in a MATCH () AGAINST SQL statement
    * @param string $SearchString Search string.
    * @return string Returns the prepared search string.
    * @see ItemFactory::searchForItemNames()
    * @see ClassificationFactory::findMatchingRecentlyUsedValues()
    */
    public static function prepareSearchString($SearchString)
    {
        # remove "--", which causes searches to fail and is often in classifications
        #  Also remove unwanted punctuation
        $SearchString = str_replace(
            [
                "--",
                ",",
                ".",
                ":"
            ],
            " ",
            $SearchString
        );

        # split the search string into words
        $Words = preg_split('/\s+/', $SearchString, -1, PREG_SPLIT_NO_EMPTY);
        if ($Words === false) {
            throw new Exception("Unable to parse search string.");
        }

        # the variable that holds the prepared search string
        $PreparedSearchString = "";
        $InQuotedString = false;

        # iterate over all the words
        foreach ($Words as $Word) {
            # include quoted strings directly
            $InQuotedString |= preg_match('/^[+-]?"/', $Word);
            if ($InQuotedString) {
                $PreparedSearchString .= $Word." ";
                $InQuotedString &= (substr($Word, -1) != '"');
            # append a * to every word outside a quoted string
            } elseif (strlen($Word) > 1) {
                $PreparedSearchString .= $Word."* ";
            }
        }

        # clean up trailing whitespace, ensure quotes are closed
        $PreparedSearchString = trim($PreparedSearchString);
        if ($InQuotedString) {
            $PreparedSearchString .= '"';
        }

        return $PreparedSearchString;
    }

    /**
    * Put search results from database queries that came out in
    * database-order (i.e.the order in which entries were created) into
    * an oredering designed to put the terms people likely wanted near the top.
    * Divides the results into seven bins based on the occurrence of
    * the search string.Using regex notation (\W is a 'non-word
    * character'), the bins are:
    * 1) ^String$       (Exact match)
    * 2) String$        (Ends with String)
    * 3) ^String\W.*    (Starts with String as a word)
    * 4) ^String.*      (Starts with String as part of a word)
    * 5).*String\W.*   (Contains String as a word somewhere in the middle)
    * 6).*String.*     (Contains String somewhere in the middle)
    * 7) Everything else -- this comes up most often with multi-word
    *     strings where both words occur but are dispersed throughout
    *     the term
    *
    * Bins are returned in the above order, sorted alphabetically within a bin.
    * @param Array $Results Array( $ItemId => $ItemName ) as produced by the
    *   ItemFactory searching methods.
    * @param String $SearchString To use to decide the bins.
    * @param Integer $MaxResults To return
    * @return array Array of Results
    */
    private static function sortSearchResults($Results, $SearchString, $MaxResults)
    {
        $Matches = [
            "Exact" => [],
            "End"   => [],
            "BegSp" => [],
            "Beg"   => [],
            "MidSp" => [],
            "Mid"   => [],
            "Other" => []
        ];

        # escape regex characters
        $SafeStr = preg_quote(trim(preg_replace(
            '/\s+/',
            " ",
            str_replace(
                [
                    "--",
                    ",",
                    ".",
                    ":"
                ],
                " ",
                $SearchString
            )
        )), '/');

        # iterate over search results, sorting them into bins
        foreach ($Results as $Key => $Val) {
            # apply the same normalization to our value as we did our search string
            $TestVal = preg_quote(trim(preg_replace(
                '/\s+/',
                " ",
                str_replace(
                    [
                        "--",
                        ",",
                        ".",
                        ":"
                    ],
                    " ",
                    (string)$Val
                )
            )), '/');

            if (preg_match('/^'.$SafeStr.'$/i', $TestVal)) {
                $ix = "Exact";
            } elseif (preg_match('/^'.$SafeStr.'\\W/i', $TestVal)) {
                $ix = "BegSp";
            } elseif (preg_match('/^'.$SafeStr.'/i', $TestVal)) {
                $ix = "Beg";
            } elseif (preg_match('/'.$SafeStr.'$/i', $TestVal)) {
                $ix = "End";
            } elseif (preg_match('/'.$SafeStr.'\\W/i', $TestVal)) {
                $ix = "MidSp";
            } elseif (preg_match('/'.$SafeStr.'/i', $TestVal)) {
                $ix = "Mid";
            } else {
                $ix = "Other";
            }

            $Matches[$ix][$Key] = $Val;
        }

        # assemble the sorted results
        $SortedResults = [];
        foreach (["Exact", "BegSp", "Beg", "End", "MidSp", "Mid", "Other"] as $ix) {
            asort($Matches[$ix]);
            $SortedResults += $Matches[$ix];
        }

        # trim down the list to the requested number
        $SortedResults = array_slice($SortedResults, 0, $MaxResults, true);

        return $SortedResults;
    }

    /**
    * Search for resources by keyword searching using a search string and returning
    * a maximum number of results.
    * @param MetadataField $DstField Metadata field we're requesting the search for.
    * @param string $SearchString Search string for a keyword search.
    * @param int $MaxResults Maximum number of results to return.
    * @param array $IdExclusions Array of resource IDs for resources
    *     to exclude (OPTIONAL).
    * @return array Returns an array containing the number of search results,
    *       the number of additional results available, and the search results
    */
    private static function searchForResources(
        $DstField,
        $SearchString,
        $MaxResults,
        array $IdExclusions = []
    ) {
        # construct search groups based on the keyword
        $SearchParams = new SearchParameterSet();
        $SearchParams->addParameter($SearchString);

        # perform search
        $SearchEngine = new SearchEngine();
        $SearchResults = $SearchEngine->searchAll($SearchParams);

        # get the list of referenceable schemas for this field
        $ReferenceableSchemaIds = $DstField->referenceableSchemaIds();

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # iterate over search results from desired schemas
        $SearchResultsNew = [];
        foreach ($SearchResults as $SchemaId => $SchemaResults) {
            if (in_array($SchemaId, $ReferenceableSchemaIds)) {
                # filter resources the user cannot see
                $RFactory = new RecordFactory($SchemaId);
                $ViewableIds = $RFactory->filterOutUnviewableRecords(
                    array_keys($SchemaResults),
                    $User
                );

                # add these results to our list of all search results
                $SearchResultsNew += array_intersect_key(
                    $SchemaResults,
                    array_flip($ViewableIds)
                );
            }
        }
        $SearchResults = $SearchResultsNew;

        # filter out excluded resource IDs if necessary
        if (count($IdExclusions)) {
            $SearchResults = array_diff_key(
                $SearchResults,
                array_flip($IdExclusions)
            );
        }

        # pull out mapped titles for all resources
        $ResourceData = [];
        foreach ($SearchResults as $ResourceId => $Score) {
            $Resource = new Record($ResourceId);
            $ResourceData[$ResourceId] = $Resource->getForDisplay(
                $Resource->getSchema()->getFieldByMappedName("Title")
            );
        }

        # determine how many results we had in total
        $TotalResults = count($ResourceData);

        # sort resources by title and subset if necessary
        $ResourceData = self::sortSearchResults(
            $ResourceData,
            $SearchString,
            $MaxResults
        );

        # compute the number of available and additional results
        $NumSearchResults = count($ResourceData);
        $NumAdditionalSearchResults = $TotalResults - count($ResourceData);

        return [
            $NumSearchResults,
            $NumAdditionalSearchResults,
            $ResourceData
        ];
    }

    /**
    * Search a non-User, non-Reference metadata field for values that
    * match a search string.
    * @param MetadataField $Field Metadata field to search
    * @param string $SearchString Search string.
    * @param int $MaxResults Max number of results to return.
    * @param array $IdExclusions IDs to exclude from search results.
    * @param array $ValueExclusions Values to exclude from results.
    * @return array giving the number of results displayed, number of
    *   additional results available, and the results to display.
    */
    private static function searchForValues(
        MetadataField $Field,
        $SearchString,
        $MaxResults,
        array $IdExclusions,
        array $ValueExclusions
    ) {
        $Factory = $Field->getFactory();
        if ($Factory === null) {
            throw new InvalidArgumentException("Attempt to call searchForValues()"
                    ." using a type of metadata field that does not have a factory.");
        }

        # get the minimum word length for fuzzy query matching
        $MinLen = Database::getFullTextSearchMinWordLength();

        # initialize the result variables
        $Results = [];
        $Total = 0;

        # if the search string is less than the minimum length, do exact query
        # matching first
        if (strlen($SearchString) < $MinLen) {
            # search for results and get the total
            $Results += $Factory->searchForItemNames(
                $SearchString,
                $MaxResults,
                false,
                true,
                0, # defaults
                $IdExclusions,
                $ValueExclusions
            );
            $Total += $Factory->getCountForItemNames(
                $SearchString,
                false,
                true, # defaults,
                $IdExclusions,
                $ValueExclusions
            );

            # decrement the max results by how many were returned when doing exact
            # matching
            $MaxResults -= count($Results);
        }

        # if more results should be fetched
        if ($MaxResults > 0) {
            $PreparedSearchString = self::prepareSearchString($SearchString);

            if (strlen($SearchString) >= $MinLen) {
                $RecentValues = $Factory->findMatchingRecentlyUsedValues(
                    $PreparedSearchString,
                    5,
                    $IdExclusions,
                    $ValueExclusions
                );

                if (is_array($RecentValues) && count($RecentValues) > 0) {
                    $Results += $RecentValues;
                    $Results += [-1 => "<hr>"];
                }
            }

            # search for results and get the total
            $Results += self::sortSearchResults(
                $Factory->searchForItemNames(
                    $PreparedSearchString,
                    2000,
                    false,
                    true,
                    0, # defaults
                    $IdExclusions,
                    $ValueExclusions
                ),
                $SearchString,
                $MaxResults
            );
            $Total += $Factory->getCountForItemNames(
                $PreparedSearchString,
                false,
                true, # defaults,
                $IdExclusions,
                $ValueExclusions
            );
        }

        # get additional totals
        $NumSearchResults = count($Results);
        $NumAdditionalSearchResults = $Total - $NumSearchResults;

        return [
            $NumSearchResults,
            $NumAdditionalSearchResults,
            $Results
        ];
    }
}
