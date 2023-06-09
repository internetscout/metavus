<?PHP
#
#   FILE:  SearchParameterSetEditingUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use InvalidArgumentException;
use ScoutLib\HtmlOptionList;
use ScoutLib\StdLib;

/**
 * Class to create a user interface for editing SearchParameterSets.
 */
class SearchParameterSetEditingUI
{
    /**
     * Create a UI for specifying edits to SearchParameterSets.
     * @param string $FormFieldName HTML 'name' to use for <input> elements
     *         created by the UI.  If this UI is incorporated into a form
     *         containing other input elements, they must have names that
     *         differ from this one.
     * @param SearchParameterSet $SearchParams SearchParameterSet to display
     *  (OPTIONAL, uses an empty set if unspecified)
     */
    public function __construct(string $FormFieldName, SearchParameterSet $SearchParams = null)
    {
        $this->EditFormName = $FormFieldName;

        if ($SearchParams !== null) {
            $this->SearchParams = $SearchParams;
        } else {
            $this->SearchParams = new SearchParameterSet();
        }

        # get the list of fields that are allowed in searches for all schemas
        $this->MFields = [];
        $this->AllSchemas = MetadataSchema::getAllSchemas();
        foreach ($this->AllSchemas as $ScId => $Schema) {
            $this->AllowedSchemaIds[] = $ScId;

            $Fields = $Schema->getFields(
                null,
                MetadataSchema::MDFORDER_ALPHABETICAL
            );
            foreach ($Fields as $Field) {
                $this->MFields[] = $Field;
            }
        }

        $this->Factories = [];
    }

    /**
     * Get/set the list of allowed SchemaIds for this search.
     * @param array $NewValue Updated array of SchemaIds to allow (OPTIONAL)
     * @return array List of allowed Schema IDs
     */
    public function allowedSchemaIds(array $NewValue = null) : array
    {
        if ($NewValue !== null) {
            foreach ($NewValue as $SchemaId) {
                if (!MetadataSchema::schemaExistsWithId($SchemaId)) {
                    throw new Exception(
                        "Invalid Schema Id provided: ".$SchemaId
                    );
                }
            }

            $this->AllowedSchemaIds = $NewValue;
        }

        return $this->AllowedSchemaIds;
    }

    /**
     * Display editing form elements enclosed in a <table>.  Note that
     *         it still must be wrapped in a <form> that has a submit button.
     * @param string $TableId HTML identifier to use
     *        (OPTIONAL, default *   NULL).
     * @param string $TableStyle CSS class to attach for this table
     *        (OPTIONAL, default NULL).
     */
    public function displayAsTable(string $TableId = null, string $TableStyle = null)
    {
        print('<table id="'.defaulthtmlentities($TableId).'" '
              .'class="'.defaulthtmlentities($TableStyle).'" '
              .'style="width: 100%">');
        $this->displayAsRows();
        print('</table>');
    }

    /**
     * Display the table rows for the editing form, without the surrounding
     *         <table> tags.
     */
    public function displayAsRows()
    {
        $Fields = $this->flattenSearchParams(
            $this->SearchParams
        );

        # make sure the necessary javascript is required
        $GLOBALS["AF"]->RequireUIFile("jquery-ui.js");
        $GLOBALS["AF"]->RequireUIFile("CW-QuickSearch.js");
        $GLOBALS["AF"]->RequireUIFile("SearchParameterSetEditingUI.js");

        # note that all of the fields we create for these rows will be named
        # $this->EditFormName.'[]' , combining them all into an array of results per
        #   http://php.net/manual/en/faq.html.php#faq.html.arrays

        # field types where a leading = should be stripped before display
        $StripEqualsTypes = [
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            MetadataSchema::MDFTYPE_OPTION,
            MetadataSchema::MDFTYPE_TREE,
            MetadataSchema::MDFTYPE_USER,
            MetadataSchema::MDFTYPE_FLAG,
        ];

        $Depth = 0;

        # the .mv-speui-X css classes below are used by
        # SearchParameterSetEditingUI.js to locate and manipulate elements
        foreach ($Fields as $FieldRow) {
            if (is_string($FieldRow) && $FieldRow == "(") {
                $Depth++;
                print('<tr><td colspan=2 style="padding-left: 2em;">'
                      .'<input type="hidden" name="'.$this->EditFormName.'[]" '
                      .'value="X-BEGIN-SUBGROUP-X"/>'
                      .'<table class="mv-speui-subgroup">');
            } elseif (is_string($FieldRow) && $FieldRow == ")") {
                $Depth--;
                $this->printTemplateRow();
                print('<input type="hidden" name="'.$this->EditFormName.'[]" '
                    .'value="X-END-SUBGROUP-X"/></table></td></tr>');
            } elseif (is_array($FieldRow) && isset($FieldRow["Logic"])) {
                print('<tr class="mv-speui-logic-row '.$this->EditFormName.'">'
                      .'<td colspan="3">'
                      .($Depth == 0 ? 'Top-Level Logic: ' : 'Subgroup with '));

                $ListName = $this->EditFormName."[]";
                $Options = ["AND" => "AND", "OR" => "OR"];
                $SelectedValue = $FieldRow["Logic"];

                $OptList = new HtmlOptionList($ListName, $Options, $SelectedValue);
                $OptList->classForList("logic");
                $OptList->printHtml();

                print (($Depth > 0 ? ' Logic' : '').'</td></tr>');
            } elseif (is_array($FieldRow) && isset($FieldRow["FieldId"])) {
                $FieldId = $FieldRow["FieldId"];
                $Values = $FieldRow["Values"];

                foreach ($Values as $CurVal) {
                    print('<tr class="mv-speui-field-row '.$this->EditFormName.'"'
                          .' style="white-space: nowrap;">'
                          .'<td><span class="btn btn-primary btn-sm '
                          .'mv-speui-delete">X</span></td><td>');

                    # for selectable fields, we need to generate all the
                    # html elements that we might need and then depend on
                    # javascript to display only those that are relevant

                    # each field will have four elements

                    # 1. a field selector
                    $this->printFieldSelector($FieldId);

                    # 2. a value selector (for option and flag values)
                    $this->printValueSelector($FieldId, $CurVal);

                    # normalize search text for the fields that display text
                    $SearchText = $CurVal;
                    if ($FieldId !== "X-KEYWORD-X") {
                        $Field = new MetadataField($FieldId);
                        if (in_array($Field->type(), $StripEqualsTypes)) {
                            $SearchText = (StdLib::strpos($CurVal, "=") === 0) ?
                                StdLib::substr($CurVal, 1) : $CurVal;
                        }
                    }

                    # 3. a text entry
                    print('<input type="text" class="mv-speui-field-value-edit" '
                          .'name="'.$this->EditFormName.'[]" '
                          .'placeholder="(search terms)" '
                          .'value="'.defaulthtmlentities($SearchText).'">');

                    # 4. an ajax search box
                    $this->printQuicksearch($FieldId, $SearchText);

                    print("</td></tr>");
                }
            }
        }

        # add a template row, used for adding new fields
        $this->printTemplateRow();
    }

    /**
    * Extract values from a dynamics field edit/modification form.
    * @return SearchParameterSet extracted from post data.  If POST contains
    *         no data, an empty SearchParameterSet will be returned.
    */
    public function getValuesFromFormData()
    {
        if (!isset($_POST[$this->EditFormName])) {
            $Result = new SearchParameterSet();
        } else {
            # set up our result
            $GroupStack = [];
            array_push($GroupStack, new SearchParameterSet());

            # extract the array of data associated with our EditFormName
            $FormData = $_POST[$this->EditFormName];

            # extract and set the search logic, which is always the first
            # element in the HTML that we generate
            $Logic = array_shift($FormData);
            end($GroupStack)->Logic($Logic);

            while (count($FormData)) {
                # first element of each row is a field id
                $FieldId = array_shift($FormData);

                if ($FieldId == "X-BEGIN-SUBGROUP-X") {
                    # add a new subgroup to our stack of subgroups
                    array_push($GroupStack, new SearchParameterSet());
                    # extract and set the search logic
                    $Logic = array_shift($FormData);
                    end($GroupStack)->Logic($Logic);
                } elseif ($FieldId == "X-END-SUBGROUP-X") {
                    $Subgroup = array_pop($GroupStack);
                    $Tgt = end($GroupStack);
                    if ($Tgt === false) {
                        throw new Exception(
                            "Attempt to add set to an empty subgroup."
                        );
                    }

                    if ($Subgroup->parameterCount() > 0) {
                        $Tgt->AddSet($Subgroup);
                    }
                } else {
                    # for selectable fields, we'll have all possible
                    # elements and will need to grab the correct ones for
                    # the currently selected field
                    $SelectVal = array_shift($FormData);
                    $TextVal   = array_shift($FormData);
                    $SearchVal = array_shift($FormData);

                    if ($FieldId == "X-KEYWORD-X") {
                        $Val = $TextVal;
                        $Field = null;

                        if (strlen($TextVal) == 0) {
                            continue;
                        }
                    } else {
                        $Field = new MetadataField($FieldId);
                        $Factory = null;

                        # make sure we have factories for field types that need them
                        switch ($Field->type()) {
                            case MetadataSchema::MDFTYPE_TREE:
                            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                            case MetadataSchema::MDFTYPE_OPTION:
                            case MetadataSchema::MDFTYPE_USER:
                                if (!isset($this->Factories[$FieldId])) {
                                    $this->Factories[$FieldId] = $Field->getFactory();
                                }
                                $Factory = $this->Factories[$FieldId];
                                break;

                            default:
                                break;
                        }

                        # verify that we actually have a value for our selected field
                        switch ($Field->type()) {
                            case MetadataSchema::MDFTYPE_PARAGRAPH:
                            case MetadataSchema::MDFTYPE_URL:
                            case MetadataSchema::MDFTYPE_TEXT:
                            case MetadataSchema::MDFTYPE_NUMBER:
                            case MetadataSchema::MDFTYPE_DATE:
                            case MetadataSchema::MDFTYPE_TIMESTAMP:
                                # if we have no value for this field, no processing to do
                                if (strlen($TextVal) == 0) {
                                    continue 2;
                                }
                                break;

                            case MetadataSchema::MDFTYPE_TREE:
                            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                            case MetadataSchema::MDFTYPE_USER:
                                # if we have no value for this field, skip displaying it
                                if (strlen($SearchVal) == 0) {
                                    continue 2;
                                }
                                break;

                            # no need to check the types where there's
                            # a SelectVal, as that cannot be left empty
                            default:
                                break;
                        }

                        # extract the value for our field
                        switch ($Field->type()) {
                            case MetadataSchema::MDFTYPE_PARAGRAPH:
                            case MetadataSchema::MDFTYPE_URL:
                            case MetadataSchema::MDFTYPE_TEXT:
                            case MetadataSchema::MDFTYPE_NUMBER:
                            case MetadataSchema::MDFTYPE_DATE:
                            case MetadataSchema::MDFTYPE_TIMESTAMP:
                            case MetadataSchema::MDFTYPE_IMAGE:
                            case MetadataSchema::MDFTYPE_FILE:
                                $Val = $TextVal;
                                break;

                            case MetadataSchema::MDFTYPE_USER:
                                # (data provided in SearchVal is a User Id)
                                if (!$Factory->userExists($SearchVal)) {
                                    continue 2;
                                }

                                $Val = "=".(new User($SearchVal))->get("UserName");
                                break;

                            case MetadataSchema::MDFTYPE_TREE:
                                # (data provided in SearchVal is a Classification Id,
                                #  optionally prefixed with ~ to indicate 'is or under' vs 'is')
                                $ExactMatch = true;
                                if (strlen($SearchVal) > 0 && $SearchVal[0] == "~") {
                                    $ExactMatch = false;
                                    $SearchVal = substr($SearchVal, 1);
                                }

                                if (strlen($SearchVal) == 0) {
                                    continue 2;
                                }

                                $Item = $Factory->getItem($SearchVal);
                                $Val = ($ExactMatch ? "=" : "^").$Item->id();
                                break;

                            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                                # (data provided in SearchVal is a CName Id)
                                $Item = $Factory->getItem($SearchVal);
                                $Val = "=".$Item->id();
                                break;

                            case MetadataSchema::MDFTYPE_OPTION:
                                # (data provided is "FieldId-OptionId")
                                list($InputId, $InputVal) = explode("-", $SelectVal, 2);
                                $Item = $Factory->getItem($InputVal);
                                $Val = "=".$Item->id();
                                break;

                            case MetadataSchema::MDFTYPE_FLAG:
                                # (data provided is "FieldId-FlagValue")
                                list($InputId, $InputVal) = explode("-", $SelectVal, 2);
                                $Val = "=".$InputVal;
                                break;

                            default:
                                throw new Exception("Unsupported field type");
                        }
                    }

                    # add our value to the search parameters
                    $Tgt = end($GroupStack);
                    if ($Val instanceof SearchParameterSet) {
                        if ($Tgt === false) {
                            throw new Exception(
                                "Attempt to add subgroup to an empty set."
                            );
                        }
                        $Tgt->AddSet($Val);
                    } else {
                        if ($Tgt === false) {
                            throw new Exception(
                                "Attempt to add value without any search parameters."
                            );
                        }
                        $Tgt->AddParameter($Val, $Field);
                    }
                }
            }

            $Result = array_pop($GroupStack);
        }

        $this->SearchParams = $Result;

        return $Result;
    }

    /**
    * Get/Set search parameters.
    * @param SearchParameterSet|null $SearchParams New setting (OPTIONAL)
    * @return SearchParameterSet Current SearchParameterSet
    */
    public function searchParameters(SearchParameterSet $SearchParams = null): SearchParameterSet
    {
        if ($SearchParams !== null) {
            $this->SearchParams = clone $SearchParams;
        }

        return clone $this->SearchParams;
    }

    /**
    * Get/set the max number of characters a label of a field option list
    *        will be displayed.
    * @param int $NewValue Max length of a field option list's label. Use
    *       zero for no limit (OPTIONAL, default to no limit).
    *       If NULL is passed in, this function will not set a new max
    *       length of a field option list.
    * @return int Current maximum length of a field option list's label.
    *       Zero means no limit.
    */
    public function maxFieldLabelLength(int $NewValue = null): int
    {
        if (!is_null($NewValue)) {
            $this->MaxFieldLabelLength = $NewValue;
        }
        return $this->MaxFieldLabelLength;
    }

    /**
    * Get/set the max number of characters a label of a value option list
    *       will be displayed.
    * @param int $NewValue Max length of a field option list's label. Use
    *       zero for no limit (OPTIONAL, default to no limit).
    *       If NULL is passed in, this function will not set a new max
    *       length of a value option list.
    * @return int Current maximum length of a value option list's label.
    *       Zero means no limit.
    */
    public function maxValueLabelLength(int $NewValue = null): int
    {
        if (!is_null($NewValue)) {
            $this->MaxValueLabelLength = $NewValue;
        }
        return $this->MaxValueLabelLength;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $EditFormName;
    private $SearchParams;
    private $MFields;
    private $AllSchemas;
    private $AllowedSchemaIds = [];
    private $Factories;
    private $MaxFieldLabelLength = 0;
    private $MaxValueLabelLength = 0;

    /**
    * Convert a SearchParameterSet into a flat array that can be easily
    *         iterated over when outputting HTML form elements, normalizing
    *         search terms into the format used by the editing elements.
    * @param SearchParameterSet $SearchParams Paramters to convert.
    * @return array Where each element is one of
    *           (array)  [ "Logic" => LogicSetting ]
    *           (array)  [ "FieldId" => (int|string) ID, "Values" => (array) Values ]
    *           (string) "("  -- denoting the beginning of a subgroup
    *           (string) ")"  -- denoting the end of a subgroup
    *         FieldId will be (string) "X-KEYWORD-X" for keyword searches or
    *         an int ID corresponding to a Metadata Field.  Values are arrays
    *         of search terms in the format expected for the HTML forms.
    * @see getValuesFromFormData()
    * @see printQuicksearch()
    */
    private function flattenSearchParams(SearchParameterSet $SearchParams): array
    {
        $Result = [];

        $Result[] = ["Logic" => $SearchParams->logic()];

        $KeywordStrings = $SearchParams->getKeywordSearchStrings();
        if (count($KeywordStrings)) {
            $Result[] = [
                "FieldId" => "X-KEYWORD-X",
                "Values" => $KeywordStrings
            ];
        }

        # iterate over search strings, normalizing them to use Item IDs for
        # Tree, CName, and Option fields
        $SearchStrings = $SearchParams->getSearchStrings();
        foreach ($SearchStrings as $FieldId => $Values) {
            $Field = new MetadataField($FieldId);
            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_TREE:
                    $Values = $this->normalizeTreeValues($Field, $Values);
                    break;

                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                case MetadataSchema::MDFTYPE_OPTION:
                    $Values = $this->normalizeCNameValues($Field, $Values);
                    break;
            }

            $Result[] = [
                "FieldId" => $FieldId,
                "Values" => $Values
            ];
        }

        $Subgroups = $SearchParams->getSubgroups();
        if (count($Subgroups)) {
            foreach ($Subgroups as $Subgroup) {
                if ($Subgroup->parameterCount() == 0) {
                    continue;
                }

                if ($this->isTreeFacetSubgroup($Subgroup)) {
                    $SearchStrings = $Subgroup->getSearchStrings();
                    # if this *was* a tree facet subgroup, then the search
                    # strings will be either
                    # (array) [ (int)FieldId => (array) [ "=XYZ", "^XYZ --" ] ]
                    # OR
                    # (array) [ (int)FieldId => (array) [ "^XYZ --", "=XYZ" ] ]

                    $FieldId = key($SearchStrings);
                    $Terms = reset($SearchStrings);

                    # sort the strings to put them in the first format mentioned above
                    # so that we can extract the XYZ part with a substr() call
                    sort($Terms);

                    $Field = new MetadataField($FieldId);
                    $Item = $Field->getFactory()->getItem(substr($Terms[0], 1));

                    $Result[] = [
                        "FieldId" => $FieldId,
                        "Values" => [ "~".$Item->id() ],
                    ];
                } else {
                    $Result[] = "(";
                    $SubgroupItems = $this->flattenSearchParams($Subgroup);
                    foreach ($SubgroupItems as $Item) {
                        $Result[] = $Item;
                    }
                    $Result[] = ")";
                }
            }
        }
        return $Result;
    }

    /**
     * Normalize values for a tree field to use Item IDs by converting all the
     *         formats that may be provided from Search Parameter Sets from
     *         both current and past versions of the facets into Item ID
     *         format expected in form data (i.e. '~ItemId' for a begins with
     *         search and "ItemId" for an exact match).
     * @param MetadataField $Field Field to use.
     * @param array $Values Stored values to normalize.
     * @return array Normalized values.
     * @see getValuesFromFormData()
     * @see printQuicksearch()
     */
    private function normalizeTreeValues(
        MetadataField $Field,
        array $Values
    ) : array {
        if ($Field->type() != MetadataSchema::MDFTYPE_TREE) {
            throw new InvalidArgumentException(
                __METHOD__." is not valid for ".$Field->typeAsName()." fields."
            );
        }

        $Factory = $Field->getFactory();

        # iterate over values
        foreach ($Values as $Index => $Value) {
            $Prefix = "";

            # '^XYZ' format or legacy '^XYZ --' format for 'begins with'
            if (preg_match('%^\^(.+)( -- *)?$%', $Value, $Matches)) {
                $Prefix = "~";
                $Item = $Factory->getItemByName($Matches[1]);
            # '^ItemId' format for 'begins with'
            } elseif (preg_match('%^\^([0-9]+)$%', $Value, $Matches)) {
                $Prefix = "~";
                $Item = $Factory->getItem($Matches[1]);
            # '=ItemId' format for exact match
            } elseif (preg_match('%^=([0-9]+)$%', $Value, $Matches)) {
                $Item = $Factory->getItem($Matches[1]);
            # '=XYZ' format for exact match
            } elseif (preg_match('%^=(.+)$%', $Value, $Matches)) {
                $Item = $Factory->getItemByName($Matches[1]);
            } else {
            # otherwise fall back to 'contains' the term
                $Item = $Factory->getItem($Value);
            }

            $Values[$Index] = $Prefix.$Item->id();
        }

        return $Values;
    }

    /**
     * Normalize values for a Controlled Name or Option field to use Item Ids.
     * @param MetadataField $Field Field to use.
     * @param array $Values Stored values to normalize.
     * @return array Normalized values.
     */
    private function normalizeCNameValues($Field, $Values)
    {
        $ValidFieldTypes = [
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            MetadataSchema::MDFTYPE_OPTION,
        ];

        if (!in_array($Field->type(), $ValidFieldTypes)) {
            throw new InvalidArgumentException(
                __METHOD__." is not valid for ".$Field->typeAsName()." fields."
            );
        }

        $Factory = $Field->getFactory();
        foreach ($Values as $Index => $Value) {
            # =ItemId format
            if (preg_match('%^=([0-9]+)$%', $Value, $Matches)) {
                $Item = $Factory->getItem($Matches[1]);
            # =XYZ format
            } elseif (preg_match('%^=(.+)$%', $Value, $Matches)) {
                $Item = $Factory->getItemByName($Matches[1]);
            } else {
            # otherwise, fall back to 'contains' the term
                $Item = $Factory->getItemByName($Value);
            }
            $Values[$Index] = "=".$Item->id();
        }

        return $Values;
    }

    /**
     * Determine if a subgroup represents an 'is or begins with' search
     *         against a Tree field in the style used by faceted search.
     * @param SearchParameterSet $SearchParams Parameters to check.
     * @return bool TRUE for facet-style 'is or begins with' subgroups, FALSE
     *         otherwise.
     */
    private function isTreeFacetSubgroup(SearchParameterSet $SearchParams)
    {
        if ($SearchParams->logic() != "OR") {
            return false;
        }

        if (count($SearchParams->getKeywordSearchStrings()) > 0) {
            return false;
        }

        if (count($SearchParams->getSubgroups()) > 0) {
            return false;
        }

        $SearchStrings = $SearchParams->getSearchStrings();
        if (count($SearchStrings) != 1) {
            return false;
        }

        $Terms = reset($SearchStrings);
        if (count($Terms) != 2) {
            return false;
        }

        sort($Terms);
        if ($Terms[0][0] != "=" ||
            $Terms[1] != "^".substr($Terms[0], 1)." -- ") {
            return false;
        }

        $Field = new MetadataField(key($SearchStrings));
        if ($Field->type() != MetadataSchema::MDFTYPE_TREE) {
            return false;
        }

        return true;
    }

    /**
    * Print HTML elements for the field selector.
    * @param string|null $FieldId Currently selected field.
    */
    private function printFieldSelector($FieldId)
    {
        $ListName = $this->EditFormName."[]";
        $SelectedValue = [];

        # "Keyword" option is always here
        $Options["X-KEYWORD-X"] = "Keyword";
        $OptionClass["X-KEYWORD-X"] = "field-type-keyword";
        if ($FieldId == "X-KEYWORD-X") {
            $SelectedValue[] = "X-KEYWORD-X";
        }

        # prepare options for print
        foreach ($this->MFields as $MField) {
            if (!in_array($MField->schemaId(), $this->AllowedSchemaIds)) {
                continue;
            }

            $TypeName = defaulthtmlentities(
                str_replace(' ', '', strtolower($MField->TypeAsName()))
            );

            if (!$MField->Optional()) {
                $TypeName .= " required";
            }

            $FieldName = $MField->Name();
            if ($MField->SchemaId() != MetadataSchema::SCHEMAID_DEFAULT) {
                $FieldName = $this->AllSchemas[$MField->SchemaId()]->Name()
                           .": ".$FieldName;
            }

            $Options[$MField->Id()] = defaulthtmlentities($FieldName);
            $OptionClass[$MField->Id()] = "field-type-".$TypeName;

            if ($FieldId == $MField->Id()) {
                $SelectedValue[] = $MField->Id();
            }
        }

        # instantiate option list and print
        $OptList = new HtmlOptionList($ListName, $Options, $SelectedValue);
        $OptList->classForList("mv-speui-field-subject");
        $OptList->classForOptions($OptionClass);
        $OptList->maxLabelLength($this->MaxFieldLabelLength);
        $OptList->printHtml();
    }


    /**
    * Print HTML elements for the value selector for Option and Flag fields.
    * @param int|null $FieldId Currently selected FieldId.
    * @param string $CurVal Currently selected value.
    */
    private function printValueSelector($FieldId, string $CurVal)
    {
        # parameters of the option list
        $ListName = $this->EditFormName."[]";
        $Options = [];
        $OptionClass = [];
        $SelectedValue = [];

        # prepare options for print
        foreach ($this->MFields as $MField) {
            if (!in_array($MField->schemaId(), $this->AllowedSchemaIds)) {
                continue;
            }

            if ($MField->Type() == MetadataSchema::MDFTYPE_FLAG ||
                $MField->Type() == MetadataSchema::MDFTYPE_OPTION) {
                foreach ($MField->getPossibleValues() as $Id => $Val) {
                    $IsSelected = false;
                    $Key = $MField->Id()."-".$Id;

                    if ($MField->Id() == $FieldId && $CurVal == "=".$Id) {
                        $IsSelected = true;
                    }

                    $Options[$Key] = defaulthtmlentities($Val);
                    $OptionClass[$Key] = "field-id-".$MField->Id();

                    if ($IsSelected) {
                        $SelectedValue[] = $Key;
                    }
                }
            }
        }

        # instantiate an option list and print
        $OptList = new HtmlOptionList($ListName, $Options, $SelectedValue);
        $OptList->classForList("mv-speui-field-value-select");
        $OptList->classForOptions($OptionClass);
        $OptList->maxLabelLength($this->MaxValueLabelLength);
        $OptList->printHtml();
    }

    /**
    * Output quicksearch field for ControlledName and Tree fields.
    * @param int|null $FieldId Currently selected FieldId.
    * @param string $CurVal Currently selected field value.
    */
    private function printQuicksearch($FieldId, string $CurVal)
    {
        $ExactMatch = false;
        $ItemId = "";

        if ($FieldId !== null && $FieldId != "X-KEYWORD-X") {
            $Field = new MetadataField($FieldId);

            if (!isset($this->Factories[$FieldId])) {
                $this->Factories[$FieldId] = false;

                $Factory = $Field->getFactory();
                if ($Factory !== null) {
                    $this->Factories[$FieldId] = $Factory;
                }
            }

            if ($Field->type() == MetadataSchema::MDFTYPE_TREE) {
                if (strlen($CurVal) > 0 && $CurVal[0] == "~") {
                    $CurVal = substr($CurVal, 1);
                } else {
                    $ExactMatch = true;
                }
            }

            if ($this->Factories[$FieldId] !== false) {
                if ($Field->type() == MetadataSchema::MDFTYPE_USER) {
                    $ItemId = $this->Factories[$FieldId]->getItemIdByName($CurVal);
                } else {
                    $Item = $this->Factories[$FieldId]->getItem($CurVal);
                    $ItemId = $Item->id();
                    $CurVal = $Item->name();
                }
            }
        }

        print '<span class="mv-speui-operator '
            .'mv-speui-operator-controlledname mv-speui-operator-user">'
            .'&nbsp;Is&nbsp;</span>';
        print '<select class="mv-speui-operator mv-speui-operator-tree">'
            .'<option value="~"'.(!$ExactMatch ? " selected" : "").'>Begins With</option>'
            .'<option value=""'.($ExactMatch ? " selected" : "").'>Is</option>'
            .'</select>';
        QuickSearchHelper::printQuickSearchField(
            QuickSearchHelper::DYNAMIC_SEARCH,
            $ItemId,
            $CurVal,
            false,
            $this->EditFormName
        );
    }

    /**
     * Output template row for JS to copy when new fields are added.
     */
    private function printTemplateRow()
    {
        # the .mv-speui-X css classes below are used by
        # SearchParameterSetEditingUI.js to locate and manipulate elements
        print(
            "<tr class=\"mv-speui-field-row mv-speui-template-row ".$this->EditFormName."\""
                    ." style=\"white-space: nowrap;\">"
            ."<td>"
            ."<span class=\"btn btn-primary btn-sm "
            ."mv-speui-delete\">X</span>"
            ."</td><td>");
        $this->printFieldSelector(null);
        $this->printValueSelector(null, "");
        print("<input type=\"text\" class=\"mv-speui-field-value-edit\" "
              ."name=\"".$this->EditFormName."[]\" placeholder=\"(search terms)\" "
              ."value=\"\">");
        $this->printQuicksearch(null, "");
        print("</td></tr>");
        print("<tr><td colspan=2>"
              ."<span class=\"btn btn-primary btn-sm "
              ."mv-speui-add-field\">Add Field</span>"
              ."<span class=\"btn btn-primary btn-sm "
              ."mv-speui-add-subgroup\">Add Subgroup</span>"
              ."</td></tr>");
    }
}
