<?PHP
#
#   FILE:  SearchEngine.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Date;
use ScoutLib\StdLib;

class SearchEngine extends \ScoutLib\SearchEngine
{
    /**
     * Class constructor.
     */
    public function __construct()
    {
        # pass database handle and config values to real search engine object
        parent::__construct("Records", "RecordId", "SchemaId");

        # if field info already loaded, no need to load it again
        if (count(self::$FieldTypes) > 0) {
            return;
        }

        # for each schema
        $Schemas = MetadataSchema::getAllSchemas();
        foreach ($Schemas as $SchemaId => $Schema) {
            # for each field defined in schema
            $Fields = $Schema->getFields();
            foreach ($Fields as $FieldId => $Field) {
                # save metadata field type
                self::$FieldTypes[$FieldId] = $Field->Type();

                # determine field type for searching
                switch ($Field->Type()) {
                    case MetadataSchema::MDFTYPE_TEXT:
                    case MetadataSchema::MDFTYPE_PARAGRAPH:
                    case MetadataSchema::MDFTYPE_USER:
                    case MetadataSchema::MDFTYPE_TREE:
                    case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                    case MetadataSchema::MDFTYPE_OPTION:
                    case MetadataSchema::MDFTYPE_IMAGE:
                    case MetadataSchema::MDFTYPE_FILE:
                    case MetadataSchema::MDFTYPE_URL:
                    case MetadataSchema::MDFTYPE_REFERENCE:
                    case MetadataSchema::MDFTYPE_EMAIL:
                        $FieldType = self::FIELDTYPE_TEXT;
                        break;

                    case MetadataSchema::MDFTYPE_NUMBER:
                    case MetadataSchema::MDFTYPE_FLAG:
                        $FieldType = self::FIELDTYPE_NUMERIC;
                        break;

                    case MetadataSchema::MDFTYPE_DATE:
                        $FieldType = self::FIELDTYPE_DATERANGE;
                        break;

                    case MetadataSchema::MDFTYPE_TIMESTAMP:
                        $FieldType = self::FIELDTYPE_DATE;
                        break;

                    case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
                    case MetadataSchema::MDFTYPE_POINT:
                        $FieldType = null;
                        break;

                    default:
                        throw new Exception("ERROR: unknown field type ".$Field->Type());
                }

                if ($FieldType !== null) {
                    # add field to search engine
                    self::addField(
                        $FieldId,
                        $FieldType,
                        $Field->schemaId(),
                        $Field->searchWeight(),
                        $Field->includeInKeywordSearch()
                    );
                }
            }
        }
    }

    /**
     * Perform search with specified parameters, returning results in a
     * flat array indexed by item ID.  If multiple types of items are present
     * in the results, they will first be sorted by item type, and then
     * within item type will be sorted by whatever sorting parameters were
     * specified.  (This method is a temporary measure, to catch legacy usage
     * of Search() where too many parameters are being passed.)
     * @param mixed $SearchParams Search parameters as SearchParameterSet
     *       object or keyword search string.
     * @return array Array of search result scores, indexed by item ID.
     */
    public function search($SearchParams): array
    {
        if (func_num_args() > 1) {
            (ApplicationFramework::getInstance())->logError(
                ApplicationFramework::LOGLVL_WARNING,
                "SearchEngine::Search() called with more than one parameter ("
                        .func_num_args().") by ".StdLib::getMyCaller()."."
            );
        }
        return parent::search($SearchParams);
    }

    /**
     * Overloaded version of method to retrieve text from DB.
     * @param int $ItemId ID of item to retrieve value for.
     * @param string $FieldId ID of field to retrieve value for.
     * @return mixed Text value or array of text values or NULL or empty array
     *       if no values available.
     */
    public function getFieldContent(int $ItemId, string $FieldId)
    {
        # get resource object
        $Resource = new Record($ItemId);

        # check if field still exists
        if (!$Resource->getSchema()->fieldExists($FieldId)) {
            return null;
        }

        # if this is a reference field
        if (self::$FieldTypes[$FieldId] == MetadataSchema::MDFTYPE_REFERENCE) {
            # retrieve IDs of referenced items
            $ReferredItemIds = $Resource->get($FieldId);

            # for each referred item
            $ReturnValue = [];
            foreach ($ReferredItemIds as $RefId) {
                # retrieve title value for item and add to returned values
                $RefResource = new Record($RefId);
                $ReturnValue[] = $RefResource->getMapped("Title");
            }

            # return referred item titles to caller
            return $ReturnValue;
        } else {
            # retrieve text (including variants) from resource object and return to caller
            return $Resource->get($FieldId, false, true);
        }
    }

    /**
     * Perform phrase searching.
     * @param int $FieldId ID of field to search.
     * @param string $Phrase Phrase to look for.
     * @return array of matching ItemIds.
     * @throws InvalidArgumentException If field ID is not numeric.
     */
    public function searchFieldForPhrases(int $FieldId, string $Phrase): array
    {
        # normalize and escape search phrase for use in SQL query
        $SearchPhrase = strtolower(addslashes($Phrase));

        # query DB for matching list based on field type
        $Field = MetadataField::getField((int)$FieldId);
        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_URL:
            case MetadataSchema::MDFTYPE_EMAIL:
                $QueryString = "SELECT DISTINCT RecordId FROM Records "
                        ."WHERE POSITION('".$SearchPhrase."'"
                            ." IN LOWER(`".$Field->dBFieldName()."`)) ";
                break;

            case MetadataSchema::MDFTYPE_FILE:
                $QueryString = "SELECT DISTINCT RecordId FROM Files "
                        ."WHERE FieldId = ".$FieldId
                        ." AND (POSITION('".$SearchPhrase."' IN LOWER(FileName))"
                        ." OR POSITION('".$SearchPhrase."' IN LOWER(FileComment)))";
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
                $Factory = new ImageFactory();
                return $Factory->searchImageField(
                    (int)$FieldId,
                    $SearchPhrase
                );

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                $QueryString = "SELECT DISTINCT RecordNameInts.RecordId "
                        ."FROM RecordNameInts, ControlledNames "
                        ."WHERE POSITION('".$SearchPhrase."' IN LOWER(ControlledName)) "
                        ."AND ControlledNames.ControlledNameId"
                                ." = RecordNameInts.ControlledNameId "
                        ."AND ControlledNames.FieldId = ".intval($FieldId);
                $SecondQueryString = "SELECT DISTINCT RecordNameInts.RecordId "
                        ."FROM RecordNameInts, ControlledNames, VariantNames "
                        ."WHERE POSITION('".$SearchPhrase."' IN LOWER(VariantName)) "
                        ."AND VariantNames.ControlledNameId"
                                ." = RecordNameInts.ControlledNameId "
                        ."AND ControlledNames.ControlledNameId"
                                ." = RecordNameInts.ControlledNameId "
                        ."AND ControlledNames.FieldId = ".intval($FieldId);
                break;

            case MetadataSchema::MDFTYPE_OPTION:
                $QueryString = "SELECT DISTINCT RecordNameInts.RecordId "
                        ."FROM RecordNameInts, ControlledNames "
                        ."WHERE POSITION('".$SearchPhrase."' IN LOWER(ControlledName)) "
                        ."AND ControlledNames.ControlledNameId"
                                ." = RecordNameInts.ControlledNameId "
                        ."AND ControlledNames.FieldId = ".intval($FieldId);
                break;

            case MetadataSchema::MDFTYPE_TREE:
                $QueryString = "SELECT DISTINCT RecordClassInts.RecordId "
                        ."FROM RecordClassInts, Classifications "
                        ."WHERE POSITION('".$SearchPhrase
                                ."' IN LOWER(ClassificationName)) "
                        ."AND Classifications.ClassificationId"
                                ." = RecordClassInts.ClassificationId "
                        ."AND Classifications.FieldId = ".intval($FieldId);
                break;

            case MetadataSchema::MDFTYPE_USER:
                $UserId = $this->DB->queryValue("SELECT UserId FROM APUsers "
                        ."WHERE POSITION('".$SearchPhrase
                                ."' IN LOWER(UserName)) "
                        ."OR POSITION('".$SearchPhrase
                                ."' IN LOWER(RealName))", "UserId");
                if ($UserId != null) {
                    $QueryString = "SELECT DISTINCT RecordId FROM RecordUserInts "
                                     ."WHERE UserId = ".$UserId
                                     ." AND FieldId = ".intval($FieldId);
                }
                break;

            case MetadataSchema::MDFTYPE_NUMBER:
                if ($SearchPhrase > 0) {
                    $QueryString = "SELECT DISTINCT RecordId FROM Records "
                            ."WHERE `".$Field->dBFieldName()
                                    ."` = ".(int)$SearchPhrase;
                }
                break;

            case MetadataSchema::MDFTYPE_FLAG:
            case MetadataSchema::MDFTYPE_DATE:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
            case MetadataSchema::MDFTYPE_REFERENCE:
                # (these types not yet handled by search engine for phrases)
                break;
        }

        # build match list based on results returned from DB
        if (isset($QueryString)) {
            $this->dMsg(7, "Performing phrase search query (<i>".$QueryString."</i>)");
            $StartTime = microtime(true);
            $this->DB->query($QueryString);
            if ($this->DebugLevel > 9) {
                $EndTime = microtime(true);
                if (($StartTime - $EndTime) > 0.1) {
                    printf(
                        "SE:  Query took %.2f seconds<br>\n",
                        ($EndTime - $StartTime)
                    );
                }
            }
            $MatchList = $this->DB->fetchColumn("RecordId");
            if (isset($SecondQueryString)) {
                $this->dMsg(7, "Performing second phrase search query"
                        ." (<i>".$SecondQueryString."</i>)");
                if ($this->DebugLevel > 9) {
                    $StartTime = microtime(true);
                }
                $this->DB->query($SecondQueryString);
                if ($this->DebugLevel > 9) {
                    $EndTime = microtime(true);
                    if (($StartTime - $EndTime) > 0.1) {
                        printf(
                            "SE:  query took %.2f seconds<br>\n",
                            ($EndTime - $StartTime)
                        );
                    }
                }
                $MatchList = $MatchList + $this->DB->fetchColumn("RecordId");
            }
        } else {
            $MatchList = [];
        }

        # return list of matching resources to caller
        return $MatchList;
    }

    /**
     * Perform comparison searches.
     * @param array $FieldIds IDs of fields to search.
     * @param array $Operators Search operators.
     * @param array $Values Target values.
     * @param string $Logic Search logic ("AND" or "OR").
     * @return array of ItemIds that matched.
     */
    protected function searchFieldsForComparisonMatches(
        array $FieldIds,
        array $Operators,
        array $Values,
        string $Logic
    ): array {

        # use SQL keyword appropriate to current search logic for combining operations
        $CombineWord = ($Logic == "AND") ? " AND " : " OR ";

        # initialize results to null
        # (if we use an empty array here then we won't be able to tell the
        # difference between not having any results yet and having zero results
        # from the first comparison query)
        $Results = null;

        # for each comparison
        foreach ($FieldIds as $Index => $FieldId) {
            # skip field if it is not valid
            if (!MetadataSchema::fieldExistsInAnySchema($FieldId)) {
                continue;
            }

            $Field = MetadataField::getField($FieldId);
            $Operator = $Operators[$Index];
            $Value = $Values[$Index];

            $ProcessingType = ($Operator[0] == "@")
                    ? "Modification Comparison" : $Field->type();
            switch ($ProcessingType) {
                case MetadataSchema::MDFTYPE_TEXT:
                case MetadataSchema::MDFTYPE_PARAGRAPH:
                case MetadataSchema::MDFTYPE_NUMBER:
                case MetadataSchema::MDFTYPE_FLAG:
                case MetadataSchema::MDFTYPE_URL:
                case MetadataSchema::MDFTYPE_EMAIL:
                    $QueryConditions["Records"][] = $this->getTextComparisonSql(
                        $Field->dBFieldName(),
                        $Operator,
                        $Value
                    );
                    break;

                case MetadataSchema::MDFTYPE_USER:
                    if (is_numeric($Value) &&
                        $Field->getFactory()->userExists((int)$Value)) {
                        $User = new User($Value);
                    } else {
                        $UserNames = $Field->getFactory()->findUserNames($Value);
                        if (count($UserNames) == 0) {
                            throw new Exception(
                                "Provided value (".$Value.") is not a valid "
                                ."UserId or a valid UserName."
                            );
                        } elseif (count($UserNames) == 1) {
                            $User = new User(key($UserNames));
                        } else {
                            throw new Exception(
                                "Provided value (".$Value.") matches multiple "
                                ."UserNames (which shouldn't even be possible)."
                            );
                        }
                    }
                    $QueryConditions["RecordUserInts"][] =
                        $this->getUserComparisonSql(
                            $FieldId,
                            $Operator,
                            $User->id()
                        );
                    break;

                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                    $QueryIndex = "RecordNameInts".$FieldId;
                    if ($Operator == "!=") {
                        # separate exclusion queries from regular queries
                        $QueryIndex .= "X";
                        # if no exclusion query started yet, start one
                        if (!isset($Queries[$QueryIndex])) {
                            $Queries[$QueryIndex] = [
                                "IsExclusion" => true,
                                "SchemaId" => $Field->schemaId()
                            ];
                        }
                    }

                    $Queries[$QueryIndex] =
                    $this->getComparisonQueryForControlledName(
                        $Queries[$QueryIndex] ?? [],
                        ($Operator == "!=" ) ? "=" : $Operator,
                        $Value,
                        $FieldId
                    );
                    break;

                case MetadataSchema::MDFTYPE_OPTION:
                    $QueryIndex = "RecordNameInts".$FieldId;
                    if ($Operator == "!=") {
                        # separate exclusion queries from regular queries
                        $QueryIndex .= "X";
                        # if no exclusion query started yet, start one
                        if (!isset($Queries[$QueryIndex])) {
                            $Queries[$QueryIndex] = [
                                "IsExclusion" => true,
                                "SchemaId" => $Field->schemaId()
                            ];
                        }
                    }

                    $Queries[$QueryIndex] =
                            $this->getComparisonQueryForOption(
                                $Queries[$QueryIndex] ?? [],
                                ($Operator == "!=") ? "=" : $Operator,
                                $Value,
                                $FieldId
                            );
                    break;

                case MetadataSchema::MDFTYPE_TREE:
                    $QueryIndex = "RecordClassInts".$FieldId;
                    # for tree fields where the user provided '^ItemId', treat as a search
                    # for ItemId or any of its descendants;
                    # for tree fields where the user searched "^XYZ --", treat this as
                    # "=XYZ" OR "^XYZ -- "
                    if (($Operator == "^")
                            && preg_match("%^([0-9]+|(.+) -- *)$%", $Value) ) {
                        # if using AND logic create a separate query for
                        # each provided value
                        if ($Logic == "AND") {
                            $QueryIndex .= "_".md5($Value);
                        }
                    }
                    $Queries[$QueryIndex] = $this->getComparisonQueryForTree(
                        $Queries[$QueryIndex] ?? [],
                        $Operator,
                        $Value,
                        $FieldId
                    );
                    break;

                case MetadataSchema::MDFTYPE_TIMESTAMP:
                    # if we have an SQL conditional
                    $TimestampConditional = $this->getTimeComparisonSql(
                        $Field,
                        $Operator,
                        $Value
                    );
                    if ($TimestampConditional) {
                        # add conditional
                        $QueryConditions["Records"][] = $TimestampConditional;
                    }
                    break;

                case MetadataSchema::MDFTYPE_DATE:
                    if (Date::isValidDate($Value)) {
                        $Date = new Date($Value);
                        if ($Date->precision()) {
                            $QueryConditions["Records"][] =
                                    " ( ".$Date->sqlCondition(
                                        $Field->dBFieldName()."Begin",
                                        $Field->dBFieldName()."End",
                                        $Operator
                                    )." ) ";
                        }
                    }
                    break;

                case MetadataSchema::MDFTYPE_REFERENCE:
                    $QueryIndex = "ReferenceInts".$FieldId;
                    if (!isset($Queries[$QueryIndex]["Query"])) {
                        $Queries[$QueryIndex]["Query"] =
                                "SELECT DISTINCT RI.SrcRecordId AS RecordId"
                                ." FROM ReferenceInts AS RI, Records AS R "
                                ." WHERE RI.FieldId = ".intval($FieldId)
                                ." AND (";
                        $Queries[$QueryIndex]["Close"] = true;
                        $Queries[$QueryIndex]["Count"] = 1;
                    } else {
                        $Queries[$QueryIndex]["Query"] .= $CombineWord;
                        $Queries[$QueryIndex]["Count"]++;
                    }

                    if (is_numeric($Value)) {
                        # add subquery for specific resource ID
                        $Queries[$QueryIndex]["Query"] .= "(RI.DstRecordId ".$Operator." '"
                                .addslashes((string)$Value)."')";
                    } else {
                        # iterate over all the schemas this field can reference,
                        # gluing together an array of subqueries for the mapped
                        # title field of each as we go
                        $SchemaIds = $Field->referenceableSchemaIds();

                        # if no referenceable schemas configured, fall back to
                        # searching all schemas
                        if (count($SchemaIds) == 0) {
                            $SchemaIds = MetadataSchema::getAllSchemaIds();
                        }

                        $Subqueries = [];
                        foreach ($SchemaIds as $SchemaId) {
                            $Schema = new MetadataSchema($SchemaId);
                            $MappedTitle = $Schema->getFieldByMappedName("Title");
                            if ($MappedTitle !== null) {
                                $Subqueries[] = $this->getTextComparisonSql(
                                    $MappedTitle->dBFieldName(),
                                    $Operator,
                                    $Value,
                                    "R"
                                );
                            }
                        }

                        # OR together all the subqueries, add it to the query
                        # for our field
                        $Queries[$QueryIndex]["Query"] .=
                                "((".implode(" OR ", $Subqueries).")"
                                ." AND R.RecordId = RI.DstRecordId)";
                    }
                    break;

                case MetadataSchema::MDFTYPE_FILE:
                case MetadataSchema::MDFTYPE_IMAGE:
                    if ($ProcessingType == MetadataSchema::MDFTYPE_FILE) {
                        $TableName = "Files";
                        $ItemIdColumnName = "RecordId";
                    } else {
                        $TableName = "Images";
                        $ItemIdColumnName = "ItemId";
                    }
                    $QueryIndex = $TableName.$FieldId;
                    if (!isset($Queries[$QueryIndex]["Query"])) {
                        $Queries[$QueryIndex]["Query"] = "SELECT R.RecordId,"
                                        ." SUM(F.".$ItemIdColumnName." IS NOT NULL)"
                                                ." AS FileCount"
                                ." FROM Records R"
                                ." LEFT JOIN ".$TableName." F"
                                ." ON (R.RecordId = F.".$ItemIdColumnName
                                        ." AND F.FieldId = ".intval($FieldId).")"
                                ." WHERE R.SchemaId = ".intval($Field->schemaId())
                                ." GROUP BY R.RecordId"
                                ." HAVING ";
                    } else {
                        $Queries[$QueryIndex]["Query"] .= $CombineWord;
                    }
                    $Queries[$QueryIndex]["Query"] .=
                            " (FileCount ".$Operator." ".$Value.")";
                    break;

                case "Modification Comparison":
                    # if we have an SQL conditional
                    $TimestampConditional = $this->getTimeComparisonSql(
                        $Field,
                        $Operator,
                        $Value
                    );
                    if ($TimestampConditional) {
                        # add conditional
                        $QueryConditions["RecordFieldTimestamps"][] =
                                $TimestampConditional;
                    }
                    break;

                default:
                    throw new Exception("Search of unknown field type ("
                            .$ProcessingType.").");
            }
        }

        # if query conditions found
        if (isset($QueryConditions)) {
            # for each query condition group
            foreach ($QueryConditions as $TargetTable => $Conditions) {
                # add entry with conditions to query list
                if (isset($Queries[$TargetTable]["Query"])) {
                    $Queries[$TargetTable]["Query"] .= $CombineWord
                            .implode($CombineWord, $Conditions);
                } else {
                    $Queries[$TargetTable]["Query"] = "SELECT DISTINCT RecordId"
                            ." FROM ".$TargetTable." WHERE "
                            .implode($CombineWord, $Conditions);
                }
            }
        }

        # if queries found
        if (isset($Queries)) {
            # for each assembled query
            foreach ($Queries as $QueryIndex => $Query) {
                if (isset($Query["IsExclusion"])) {
                    $SchemaId = $Query["SchemaId"];
                    $IsExclusion = $Query["IsExclusion"];
                    unset($Query["IsExclusion"]);
                    unset($Query["SchemaId"]);
                } else {
                    $IsExclusion = false;
                    $SchemaId = null;
                }
                # For searches that exclude terms, we find the records that
                # should not match the search (those with excluded terms), then
                # remove those records from the full list of records in the
                # schema. To get the list of exclusions we need to switch the
                # logic. For example, if the search was:
                # Format != html AND format != docx, the records to exclude will
                # be those where Format = html OR Format = docx - the ones
                # where either term matches.
                # This is a consequence of DeMorgan's law. (In cases where the
                # search was Format != html OR format != docx, the records to
                # exclude will be those have BOTH html and docx as values for
                # Format.)

                $RequireAllTerms = $IsExclusion
                        ? ($Logic == "OR") : ($Logic == "AND");

                if (isset($Query["Query"])) {
                    # query does not have multiple parts
                    $ResourceIds =
                            $this->runComparisonQuery(
                                $Query["Query"],
                                $Query["Count"] ?? 0,
                                $Query["Column"] ?? "",
                                $Query["Close"] ?? false,
                                $RequireAllTerms
                            );
                    $this->dMsg(5, "Comparison query produced <i>"
                            .count($ResourceIds)."</i> results");
                } else {
                    # for each part of query
                    $ResourceIds = [];
                    foreach ($Query as $PartIndex => $PartQuery) {
                        $ResourceIds =
                                $ResourceIds +
                                $this->runComparisonQuery(
                                    $PartQuery["Query"],
                                    $PartQuery["Count"] ?? 0,
                                    $PartQuery["Column"] ?? "",
                                    $PartQuery["Close"] ?? false,
                                    $RequireAllTerms
                                );

                        $this->dMsg(5, "Comparison query produced <i>"
                                .count($ResourceIds)."</i> results");
                    }
                }

                if ($IsExclusion) {
                    # for exclusion search, results are all of the schema's
                    # records that do *not* match the exclusion query

                    $RFactory = new RecordFactory($SchemaId);
                    $AllRecordsInSchema = $RFactory->getItemIds();
                    $ResourceIds = array_diff($AllRecordsInSchema, $ResourceIds);
                }

                if (!is_null($Results)) {
                    # if search logic is set to AND
                    if ($Logic == "AND") {
                        # remove anything from results that was not returned from query
                        $Results = array_intersect($Results, $ResourceIds);
                    } else {
                        # add values returned from query to results
                        $Results = array_unique(array_merge($Results, $ResourceIds));
                    }
                } else {
                    # set results to values returned from query
                    $Results = $ResourceIds;
                }
            }
        }

        # if all fields passed are invalid (e.g., deleted)
        if (is_null($Results)) {
            $Results = [];
        }

        # return results to caller
        return $Results;
    }


    /**
     * Complete and execute the provided comparison query and return IDs of
     * matching records. Query is completed with a GROUP BY ... HAVING
     * clause that implements AND logic if all searched terms should be
     * included in the results.
     * @param string $Query An SQL query segment for this field.
     * @param int $Count The count of values being compared for this segment.
     * @param string $Column The database column the value is being compared
     *         against.
     * @param bool $Close If set, the segment needs a a closing parenthese
     *         added.
     * @param boolean $RequireAllTerms TRUE if all of the searched terms must
     *         be present in matched records.
     * @return array IDs of resources that match the query.
     */
    private function runComparisonQuery(
        string $Query,
        int $Count,
        string $Column,
        bool $Close,
        bool $RequireAllTerms
    ): array {
        # The HAVING clause specifies conditions that apply to groups of records
        # defined by GROUP BY (here, "RecordId"). Whether each of these groups
        # is included in the set of result or not is determined by the clause:
        # "HAVING COUNT(DISTINCT Col) = N"
        # This will only include the IDs of records that match exactly N of the
        # searched terms, where N is the number of terms. Therefore, all of the
        # terms have to have been matched for the ID of a record to be included
        # in the search results if GROUP BY.. HAVING is included with the query.

        # If query was flagged to be closed (because it might cover multiple
        # terms), add closing paren
        if ($Close == true) {
            $Query .= ") ";
            if ($RequireAllTerms
                    && ($Count > 1)) {
                $Query .= "GROUP BY RecordId"
                        ." HAVING COUNT(DISTINCT ".$Column
                        .") = ".$Count;
            }
        }

        # perform query and retrieve IDs
        $this->dMsg(5, "Performing comparison query <i>" .$Query."</i>");
        $this->DB->query($Query);
        $ResourceIds = $this->DB->fetchColumn("RecordId");
        $this->dMsg(5, "Comparison query produced <i>"
                .count($ResourceIds)."</i> results");

        return $ResourceIds;
    }


    /**
     * Build or extend SQL query for comparison to controlled name value for
     * specified field.  The components are passed in and out via an array of
     * associative arrays, with the following indexes on the inner array:
     *      "Query" - An SQL query segment for this field.
     *      "Count" - The count of values being compared for this segment.
     *      "Column" - The database column against which the value is being compared.
     *      "Close" - If set, a closing parenthese needs to be added to the segment.
     * @param array $QueryInfo Current query info for specified field.
     * @param string $Operator Operator for comparison.
     * @param string $Value Value for comparison.
     * @param int $FieldId ID of field.
     * @return array Updated components.
     */
    private function getComparisonQueryForControlledName(
        array $QueryInfo,
        string $Operator,
        string $Value,
        int $FieldId
    ) {
        # if we do not have a regular query started for this field
        if (!isset($QueryInfo["Regular"]["Query"])) {
            # begin regular query
            $QueryInfo["Regular"]["Query"] = "SELECT DISTINCT RecordId"
                    ." FROM RecordNameInts, ControlledNames "
                    ." WHERE ControlledNames.FieldId = ".intval($FieldId)
                    ." AND ( ";
            $QueryInfo["Regular"]["Close"] = true;
            $QueryInfo["Regular"]["Count"] = 1;
            $QueryInfo["Regular"]["Column"] = "ControlledName";
        } else {
            # add conjunction to regular query
            $QueryInfo["Regular"]["Query"] .= " OR ";
            $QueryInfo["Regular"]["Count"]++;
        }

        # if value appears to be intended to match an exact ID
        if (($Operator == "=") && (strval(intval($Value)) === $Value)) {
            # add ID comparison SQL for value to regular query
            $QueryInfo["Regular"]["Query"] .=
                    "RecordNameInts.ControlledNameId = ".intval($Value);

            # return update query to caller (no need to add variant)
            return $QueryInfo;
        }

        # if we do not have a variant query started for this field
        if (!isset($QueryInfo["Variant"]["Query"])) {
            # begin regular query
            $QueryInfo["Variant"]["Query"] = "SELECT DISTINCT RecordId"
                    ." FROM RecordNameInts, ControlledNames, VariantNames "
                    ." WHERE ControlledNames.FieldId = ".intval($FieldId)
                    ." AND ( ";
            $QueryInfo["Variant"]["Close"] = true;
            $QueryInfo["Variant"]["Count"] = 1;
            $QueryInfo["Variant"]["Column"] = "ControlledName";
        } else {
            # add conjunction to regular query
            $QueryInfo["Variant"]["Query"] .= " OR ";
            $QueryInfo["Variant"]["Count"]++;
        }

        # add conditions for this value to queries
        $QueryInfo["Regular"]["Query"] .=
                "(RecordNameInts.ControlledNameId"
                        ." = ControlledNames.ControlledNameId"
                ." AND ".$this->getTextComparisonSql(
                    "ControlledName",
                    $Operator,
                    $Value
                )
                .")";
        $QueryInfo["Variant"]["Query"] .=
                "(RecordNameInts.ControlledNameId = ControlledNames.ControlledNameId"
                ." AND RecordNameInts.ControlledNameId = VariantNames.ControlledNameId"
                ." AND ".$this->getTextComparisonSql(
                    "VariantName",
                    $Operator,
                    $Value
                )
                        .")";
        return $QueryInfo;
    }

    /**
     * Build or extend SQL query for comparison to option value for specified
     * field.  The components are passed in and out via an associative array,
     * with the following indexes:
     *      "Query" - An SQL query segment for this field.
     *      "Count" - The count of values being compared for this segment.
     *      "Column" - The database column against which the value is being compared.
     *      "Close" - If set, a closing parenthese needs to be added to the segment.
     * @param array $QueryInfo Current query info for specified field.
     * @param string $Operator Operator for comparison.
     * @param string $Value Value for comparison.
     * @param int $FieldId ID of field.
     * @return array Updated components.
     */
    private function getComparisonQueryForOption(
        array $QueryInfo,
        string $Operator,
        string $Value,
        int $FieldId
    ) {
        # begin or extend query
        if (!isset($QueryInfo["Query"])) {
            $QueryInfo["Query"] = "SELECT DISTINCT RecordId"
                    ." FROM RecordNameInts, ControlledNames "
                    ." WHERE ControlledNames.FieldId = ".$FieldId
                    ." AND ( ";
            $QueryInfo["Close"] = true;
            $QueryInfo["Count"] = 1;
            $QueryInfo["Column"] = "ControlledName";
        } else {
            $QueryInfo["Query"] .= " OR ";
            $QueryInfo["Count"]++;
        }

        # if value appears to be intended to match an exact ID
        if (($Operator == "=") && (strval(intval($Value)) === $Value)) {
            # add ID comparison SQL for value
            $QueryInfo["Query"] .= "RecordNameInts.ControlledNameId = ".intval($Value);
        } else {
            # add text comparison SQL for value
            $ValueComparisonSql = $this->getTextComparisonSql(
                "ControlledName",
                $Operator,
                $Value
            );
            $QueryInfo["Query"] .= "(RecordNameInts.ControlledNameId"
                    ." = ControlledNames.ControlledNameId"
                    ." AND ".$ValueComparisonSql.")";
        }

        return $QueryInfo;
    }

    /**
     * Build or extend SQL query for comparison to tree value for specified
     * field.  The components are passed in and out via an associative array,
     * with the following indexes:
     *      "Query" - An SQL query segment for this field.
     *      "Count" - The count of values being compared for this segment.
     *      "Column" - The database column against which the value is being compared.
     *      "Close" - If set, a closing parenthese needs to be added to the segment.
     * @param array $QueryInfo Current query info for specified field.
     * @param string $Operator Operator for comparison.
     * @param string $Value Value for comparison.
     * @param int $FieldId ID of field.
     * @return array Updated components.
     */
    private function getComparisonQueryForTree(
        array $QueryInfo,
        string $Operator,
        string $Value,
        int $FieldId
    ) {
        if (!isset($QueryInfo["Query"])) {
            $QueryInfo["Query"] = "SELECT DISTINCT RecordId"
                ." FROM RecordClassInts, Classifications"
                ." WHERE RecordClassInts.ClassificationId"
                ." = Classifications.ClassificationId"
                ." AND Classifications.FieldId"
                ." = ".$FieldId." AND ( ";
            $QueryInfo["Close"] = true;
            $QueryInfo["Count"] = 1;
            $QueryInfo["Column"] = "ClassificationName";
        } else {
            $QueryInfo["Query"] .= " OR ";
            $QueryInfo["Count"]++;
        }

        # if value appears to represent tree branch (i.e. "^XYZ --" or "^ItemId")
        if (($Operator == "^")
                && preg_match("%^(?:([0-9]+)|(.+) -- *)$%", $Value, $Matches)) {
            if (strlen($Matches[1]) &&
                Classification::itemExists((int)$Matches[1])) {
                $Term = (new Classification((int)$Matches[1]))->name();
            } else {
                $Term = isset($Matches[2]) ? $Matches[2] : "";
            }

            # add text comparison SQL for value and any descendants in tree
            # (in effect, treat this as "=XYZ" OR "^XYZ -- ")
            $QueryInfo["Query"] .= "("
                .$this->getTextComparisonSql(
                    "ClassificationName",
                    "=",
                    $Term
                )
                ." OR "
                .$this->getTextComparisonSql(
                    "ClassificationName",
                    "^",
                    $Term." -- "
                )
                .") ";
        # else if value appears to be intended to match an exact ID
        } elseif (($Operator == "=") && (strval(intval($Value)) === $Value)) {
            # add ID comparison SQL for value
            $QueryInfo["Query"] .= "RecordClassInts.ClassificationId = ".intval($Value);
        } else {
            # add text comparison SQL for value
            $QueryInfo["Query"] .= $this->getTextComparisonSql(
                "ClassificationName",
                $Operator,
                $Value
            );
        }
        return $QueryInfo;
    }

    /**
     * Return item IDs sorted by a specified field. Items with no value for the
     *   given field are returned at the end of the list.
     * @param int $ItemType Type of item.
     * @param int|string $Field ID or name of field by which to sort.
     * @param bool $SortDescending If TRUE, sort in descending order, otherwise
     *       sort in ascending order.
     * @return array of ItemIds
     */
    public static function getItemIdsSortedByField(
        int $ItemType,
        $Field,
        bool $SortDescending
    ): array {
        $RFactory = new RecordFactory($ItemType);

        return array_merge(
            $RFactory->getRecordIdsSortedBy($Field, !$SortDescending),
            $RFactory->getRecordIdsWhereFieldIsEmpty($Field)
        );
    }

    /**
     * Queue background update for an item.
     * @param mixed $ItemOrItemId Item to update.
     * @param int $TaskPriority Priority for the task, if the default
     *         is not suitable
     * @return void
     */
    public static function queueUpdateForItem($ItemOrItemId, ?int $TaskPriority = null): void
    {
        if (is_numeric($ItemOrItemId)) {
            $ItemId = (int)$ItemOrItemId;
            $Item = new Record($ItemId);
        } else {
            $Item = $ItemOrItemId;
            $ItemId = $Item->Id();
        }

        # if no priority was provided, use the default
        if ($TaskPriority === null) {
            $TaskPriority = self::$TaskPriority;
        }

        # assemble task description
        $Title = $Item->GetMapped("Title");
        if (is_null($Title) || !strlen($Title)) {
            $Title = "Item #".$ItemId;
        }
        $TaskDescription = "Update search data for"
                ." <a href=\"r".$ItemId."\"><i>"
                .$Title."</i></a>";

        # queue update
        (ApplicationFramework::getInstance())->queueUniqueTask(
            [__CLASS__, "RunUpdateForItem"],
            [intval($ItemId)],
            $TaskPriority,
            $TaskDescription
        );
    }

    /**
     * Update search index for an item.
     * @param int $ItemId Item to update.
     * @return void
     */
    public static function runUpdateForItem(int $ItemId): void
    {
        # bail out if item no longer exists
        try {
            $Resource = new Record($ItemId);
        } catch (InvalidArgumentException $Exception) {
            return;
        }

        # bail out if item is a temporary record
        if ($Resource->isTempRecord()) {
            return;
        }

        # retrieve schema ID of item to use for item type
        $ItemType = $Resource->getSchemaId();

        # update search data for resource
        $SearchEngine = new SearchEngine();
        $SearchEngine->updateForItem($ItemId, $ItemType);

        # clear page cache of pages that may be affected by search results
        $AF = ApplicationFramework::getInstance();
        $AF->clearPageCacheForTag("SearchResults");
        $AF->clearPageCacheForTag("SearchResults".$ItemType);
    }

    /**
     * Generate a list of suggested additional search terms that can be used for
     * faceted searching.
     * @param array $SearchResults A set of results from a from which to generate facets.
     * @param User $User User to employ in permission checks.
     * @return array An array of suggestions.  Keys are the field names and
     *      values are arrays of (ValueId => SuggestedValue)
     */
    public static function getResultFacets(array $SearchResults, User $User): array
    {
        # if there were no results, then we'll have no suggestions
        if (count($SearchResults) == 0) {
            return [];
        }

        # classifications and names associated with these search results
        $SearchClasses = [];
        $SearchNames   = [];

        # make sure we're not faceting too many resources
        $SearchResults = array_slice(
            $SearchResults,
            0,
            self::$NumResourcesForFacets,
            true
        );

        # fields for which we want to extract facets from the search results
        $SchemaIds = array_unique(
            Record::getSchemasForRecords(array_keys($SearchResults))
        );

        # iterate over our schemas and build a list of the Tree and Controlled
        # Name / Option fields for which we want to generate facets
        $TreeFieldIds = [];
        $CNameFieldIds = [];
        foreach ($SchemaIds as $SchemaId) {
            $Schema = new MetadataSchema($SchemaId);

            $TreeFields = $Schema->getFields(MetadataSchema::MDFTYPE_TREE);
            foreach ($TreeFields as $Field) {
                if ($Field->includeInFacetedSearch()) {
                    $TreeFieldIds[] = $Field->id();
                }
            }

            $CNameFields = $Schema->getFields(
                MetadataSchema::MDFTYPE_OPTION |
                MetadataSchema::MDFTYPE_CONTROLLEDNAME
            );
            foreach ($CNameFields as $Field) {
                if ($Field->includeInFacetedSearch()) {
                    $CNameFieldIds[] = $Field->id();
                }
            }
        }

        # if we're not actually generating search suggestions for any fields,
        # then there's nothing else to do
        if (count($TreeFieldIds) == 0 && count($CNameFieldIds) == 0) {
            return [];
        }

        # number of resources to include in a chunk
        $ChunkSize = Database::getIntegerDataChunkSize(
            array_keys($SearchResults)
        );

        foreach (array_chunk($SearchResults, $ChunkSize, true) as $Chunk) {
            if (count($TreeFieldIds) > 0) {
                # get the ClassificationIds for this chunk of search results
                #  (returns [ClassId => [AssociatedRecordIds] , ... ])
                $ChunkClasses = ClassificationFactory::getAssociatedClassificationIdsWithParents(
                    array_keys($Chunk),
                    $TreeFieldIds
                );

                # merge this chunk of data into $SearchClasses
                array_walk(
                    $ChunkClasses,
                    function ($RecordIds, $Id) use (&$SearchClasses) {
                        $SearchClasses[$Id] = isset($SearchClasses[$Id]) ?
                            array_merge($RecordIds, $SearchClasses[$Id]) :
                            $RecordIds;
                    }
                );
            }

            if (count($CNameFieldIds) > 0) {
                # get the CNameIds associated with this chunk of search results
                # (also in [CNameId => [AssociatedRecordIds], ... ] form)
                $ChunkNames = ControlledNameFactory::getAssociatedControlledNameIds(
                    array_keys($Chunk),
                    $CNameFieldIds
                );

                # merge this chunk of data into $SearchNames
                array_walk(
                    $ChunkNames,
                    function ($RecordIds, $Id) use (&$SearchNames) {
                        $SearchNames[$Id] = isset($SearchNames[$Id]) ?
                            array_merge($RecordIds, $SearchNames[$Id]) :
                            $RecordIds;
                    }
                );
            }
        }

        # make sure we haven't double-counted resources that have
        # a classification and some of its children assigned
        $SearchClasses = array_map(
            'array_unique',
            $SearchClasses
        );

        # generate a map of FieldId -> Field Names for all of the generated facets:
        $SuggestionsById = [];

        # pull relevant Classification names out of the DB
        if (count($SearchClasses) > 0) {
            $ChunkSize = Database::getIntegerDataChunkSize(
                array_keys($SearchClasses)
            );

            $Factory = new ClassificationFactory();
            foreach (array_chunk($SearchClasses, $ChunkSize, true) as $Chunk) {
                $ClassNames = $Factory->getItemNames(
                    "ClassificationId IN (".implode(",", array_keys($Chunk)).")"
                );

                $FieldIds = ClassificationFactory::getFieldIds(array_keys($Chunk));

                foreach ($Chunk as $Id => $RecordIds) {
                    if (!isset($ClassNames[$Id])) {
                        continue;
                    }

                    $SuggestionsById[$FieldIds[$Id]][] = [
                        "Id" => $Id,
                        "Name" => $ClassNames[$Id],
                        "Count" => count($RecordIds),
                    ];
                }
            }
        }

        # pull relevant ControlledNames out of the DB
        if (count($SearchNames) > 0) {
            $ChunkSize = Database::getIntegerDataChunkSize(
                array_keys($SearchNames)
            );

            $Factory = new ControlledNameFactory();
            foreach (array_chunk($SearchNames, $ChunkSize, true) as $Chunk) {
                $CNames = $Factory->getItemNames(
                    "ControlledNameId IN (".implode(",", array_keys($Chunk)).")"
                );

                $FieldIds = ControlledNameFactory::getFieldIds(array_keys($Chunk));

                foreach ($Chunk as $Id => $RecordIds) {
                    if (!isset($CNames[$Id])) {
                        continue;
                    }
                    $SuggestionsById[$FieldIds[$Id]][] = [
                        "Id" => $Id,
                        "Name" => $CNames[$Id],
                        "Count" => count($RecordIds),
                    ];
                }
            }
        }

        # translate the suggestions that we have in terms of the
        #  FieldIds to suggestions in terms of the field names
        $SuggestionsByFieldName = [];

        # if we have suggestions to offer
        if (count($SuggestionsById) > 0) {
            # gill in an array that maps FieldNames to search links
            # which would be appropriate for that field
            foreach ($SuggestionsById as $FieldId => $FieldValues) {
                try {
                    $ThisField = MetadataField::getField((int)$FieldId);
                } catch (Exception $Exception) {
                    $ThisField = null;
                }

                # bail on fields that didn't exist and on fields that the
                #       current user cannot view, and on fields that are
                #       disabled for advanced searching
                if (is_object($ThisField) &&
                    $ThisField->status() == MetadataSchema::MDFSTAT_OK &&
                    $ThisField->includeInFacetedSearch() &&
                    $ThisField->enabled() &&
                    ($ThisField->viewingPrivileges())->meetsRequirements($User)) {
                    $SuggestionsByFieldName[$ThisField->name()] = [];

                    foreach ($FieldValues as $Value) {
                        $SuggestionsByFieldName[$ThisField->name()][$Value["Id"]] = [
                            "Name" => $Value["Name"],
                            "Count" => $Value["Count"]
                        ];
                    }
                }
            }
        }

        ksort($SuggestionsByFieldName);

        return $SuggestionsByFieldName;
    }

    /**
     * Set the default priority for background tasks.
     * @param int $NewPriority New task priority (one of
     *     ApplicationFramework::PRIORITY_*)
     * @return void
     */
    public static function setUpdatePriority(int $NewPriority): void
    {
        self::$TaskPriority = $NewPriority;
    }

    /**
     * Get/set the number of resources used for generating search facets
     *   based on the terms associated with records included in a set of
     *   search results
     * @param int $NewValue Updated value.
     * @return int current value
     */
    public static function numResourcesForFacets(?int $NewValue = null)
    {
        if (!is_null($NewValue)) {
            self::$NumResourcesForFacets = $NewValue;
        }

        return self::$NumResourcesForFacets;
    }

    /**
     * Queue background rebuild of search database for all items for
     * specified schema.
     * @param int $SchemaId ID of schema.
     * @return int Number of items queued for rebuild.
     */
    public static function queueDBRebuildForSchema(int $SchemaId): int
    {
        $SearchEngine = new self();

        $RFactory = new RecordFactory($SchemaId);
        $Ids = $RFactory->getItemIds();

        foreach ($Ids as $Id) {
            $SearchEngine->queueUpdateForItem($Id);
        }

        return count($Ids);
    }

    /**
     * Queue background rebuild of search database for all items for
     * all schemas.
     * @return int Number of items queued for rebuild.
     */
    public static function queueDBRebuildForAllSchemas(): int
    {
        $Schemas = MetadataSchema::getAllSchemas();
        $ItemsQueued = 0;

        foreach ($Schemas as $SchemaId => $Schema) {
            $ItemsQueued += self::queueDBRebuildForSchema($SchemaId);
        }

        return $ItemsQueued;
    }

    # ---- BACKWARD COMPATIBILITY --------------------------------------------

    /**
     * Perform search with logical groups of fielded searches.  This method
     * is DEPRECATED -- please use SearchEngine::Search() with a SearchParameterSet
     * object instead.
     * @param mixed $SearchGroups Search parameters as SearchParameterSet
     *       object or legacy array format.
     * @param int $StartingResult Starting index into results.  (OPTIONAL,
     *       defaults to 0)  (NO LONGER SUPPORTED - PARAMETER IS IGNORED)
     * @param int $NumberOfResults Number of results to return.  (OPTIONAL,
     *       defaults to 10)  (NO LONGER SUPPORTED - PARAMETER IS IGNORED)
     * @param string $SortByField Name of field to sort results by.  (OPTIONAL,
     *       defaults to relevance score)
     * @param bool $SortDescending If TRUE, results will be sorted in
     *       descending order, otherwise results will be sorted in
     *       ascending order.  (OPTIONAL, defaults to TRUE)
     * @return array Array of search result scores, with the IDs of items
     *       found by search as the index.
     * @see SearchEngine::Search()
     */
    public function groupedSearch(
        $SearchGroups,
        int $StartingResult = 0,
        int $NumberOfResults = 10,
        ?string $SortByField = null,
        bool $SortDescending = true
    ) {

        # check for use of deprecated parameters
        if ($StartingResult != 0) {
            throw new InvalidArgumentException("Deprecated StartingResult"
                    ." parameter used with value \"".$StartingResult."\".");
        } elseif ($NumberOfResults != 10) {
            throw new InvalidArgumentException("Deprecated NumberOfResults"
                    ." parameter used with value \"".$NumberOfResults."\".");
        }

        if ($SearchGroups instanceof SearchParameterSet) {
            # if search parameter set was passed in, use it directly
            $SearchParams = $SearchGroups;
        } else {
            # otherwise, convert legacy array into SearchParameterSet
            $SearchParams = new SearchParameterSet();
            $SearchParams->setFromLegacyArray($SearchGroups);
        }

        # add sort and item type parameters to search parameter set
        $SearchParams->sortBy($SortByField);
        $SearchParams->sortDescending($SortDescending);
        $SearchParams->itemTypes(MetadataSchema::SCHEMAID_DEFAULT);

        # perform search
        $Results = $this->search($SearchParams);

        # return the results
        return $Results;
    }

    /**
     * Filter for text display of search parameters.
     * @param string $ParamDesc Unfiltered parameter description.
     * @return string Modified parameter description.
     */
    public static function filterTextDisplay(string $ParamDesc): string
    {
        $Patterns = [
            # translate current version of 'is under'
            '%([A-Z].*) begins with <i>(.*) -- ?</i>%' =>
                '$1 begins with <i>$2</i>',
            # translate older subgroup version of 'is under'
            '%\( ?([A-Z].*) is <i>(.*)</i><br>\n(&nbsp;)+ or \1 is under <i>\2</i>\)%' =>
                '$1 begins with <i>$2</i>',
        ];
        $Result = preg_replace(
            array_keys($Patterns),
            array_values($Patterns),
            $ParamDesc
        );

        # handle term IDs in 'begins with' searches
        $ReplacementCallback = function ($Matches) {
            $FullText = $Matches[0];
            $FieldName = $Matches[1];
            $ItemId = (int)$Matches[2];

            # no change if numeric value is not a valid id
            if (!Classification::itemExists($ItemId)) {
                return $FullText;
            }

            # no change if classification specified comes from a different field
            $Classification = new Classification($ItemId);
            $Field = MetadataField::getField(
                $Classification->fieldId()
            );
            if ($Field->name() != $FieldName) {
                return $FullText;
            }

            # otherwise, substitute classification name
            return $FieldName." begins with <i>".$Classification->fullName()."</i>";
        };
        $Result = preg_replace_callback(
            '%([A-Z].*) begins with <i>([0-9]+)</i>%',
            $ReplacementCallback,
            $Result
        );

        return $Result;
    }

    /**
     * Check whether there are search index update tasks queued or running.
     * @return bool TRUE if there are update tasks queued, otherwise FALSE.
     */
    public static function thereAreIndexUpdateTasksInQueue()
    {
        $AF = ApplicationFramework::getInstance();
        $Tasks = $AF->getRunningTaskList()
                + $AF->getQueuedTaskList();
        foreach ((array)$Tasks as $TaskId => $TaskInfo) {
            if (strpos($TaskInfo["Description"], "Update search data for") === 0) {
                return true;
            }
        }
        return false;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private static $FieldTypes = [];
    private static $TaskPriority = ApplicationFramework::PRIORITY_BACKGROUND;
    private static $NumResourcesForFacets = 500;

    /**
     * Get SQL for comparison against text field.
     * @param string $DBField Name of database field.
     * @param string $Operator Operator for text comparison
     *   (One of ^, $, >, >=, =, <=, <, or !=).
     * @param string $Value Value to compare against.
     * @param string $Prefix Table prefix to use (OPTIONAL)
     * @return string SQL comparison.
     * @throws InvalidArgumentException if operator is not valid.
     */
    private function getTextComparisonSql(
        string $DBField,
        string $Operator,
        string $Value,
        string $Prefix = ""
    ): string {
        # ensure that the given operator is valid
        $ValidOps = ["^", "$", ">", ">=", "=", "<=", "<", "!="];
        if (!in_array($Operator, $ValidOps)) {
            throw new InvalidArgumentException(
                "Invalid text comparison operator: ".$Operator
            );
        }

        # if we were given a prefix, add the necessary period so we can use it
        if (strlen($Prefix)) {
            $Prefix = $Prefix.".";
        }

        switch ($Operator) {
            case "^":
                $EscapedValue = str_replace(
                    ["%", "_"],
                    ["\%", "\_"],
                    addslashes($Value)
                );
                return $Prefix."`".$DBField."` LIKE '".$EscapedValue."%' ";

            case "$":
                $EscapedValue = str_replace(
                    ["%", "_"],
                    ["\%", "\_"],
                    addslashes($Value)
                );
                return $Prefix."`".$DBField."` LIKE '%".$EscapedValue."' ";

            case "!=":
                return "(".$Prefix."`".$DBField."` != '".addslashes($Value)."'"
                    ." AND ".$Prefix."`".$DBField."` IS NOT NULL)";


            default:
                return $Prefix."`".$DBField."` "
                    .$Operator." '".addslashes($Value)."' ";
        }
    }

    /**
     * Get SQL conditional for comparison against time/date field.
     * @param MetadataField $Field Field to compare against.
     * @param string $Operator Operator for comparison.
     * @param string $Value Value to compare against.
     * @return string|null SQL conditional or NULL if no valid conditional
     *       could be generated.
     */
    private function getTimeComparisonSql(
        MetadataField $Field,
        string $Operator,
        string $Value
    ) {
        # check if this is a field modification comparison
        $ModificationComparison = ($Operator[0] == "@") ? true : false;

        # if value appears to have time component or text description
        $Conditional = null;
        if (strpos($Value, ":")
                || strstr($Value, "day")
                || strstr($Value, "week")
                || strstr($Value, "month")
                || strstr($Value, "year")
                || strstr($Value, "hour")
                || strstr($Value, "minute")) {
            # adjust operator if necessary
            if ($Operator == "@") {
                $Operator = ">=";
            } else {
                if ($ModificationComparison) {
                    $Operator = substr($Operator, 1);
                }

                if (strstr($Value, "ago")) {
                    $OperatorFlipMap = [
                        "<" => ">=",
                        ">" => "<=",
                        "<=" => ">",
                        ">=" => "<",
                    ];
                    $Operator = isset($OperatorFlipMap[$Operator])
                            ? $OperatorFlipMap[$Operator] : $Operator;
                }
            }

            # translate common words-to-numbers
            $WordsForNumbers = [
                '/^a /i'         => '1 ',
                '/^an /i'        => '1 ',
                '/^one /i'       => '1 ',
                '/^two /i'       => '2 ',
                '/^three /i'     => '3 ',
                '/^four /i'      => '4 ',
                '/^five /i'      => '5 ',
                '/^six /i'       => '6 ',
                '/^seven /i'     => '7 ',
                '/^eight /i'     => '8 ',
                '/^nine /i'      => '9 ',
                '/^ten /i'       => '10 ',
                '/^eleven /i'    => '11 ',
                '/^twelve /i'    => '12 ',
                '/^thirteen /i'  => '13 ',
                '/^fourteen /i'  => '14 ',
                '/^fifteen /i'   => '15 ',
                '/^sixteen /i'   => '16 ',
                '/^seventeen /i' => '17 ',
                '/^eighteen /i'  => '18 ',
                '/^nineteen /i'  => '19 ',
                '/^twenty /i'    => '20 ',
                '/^thirty /i'    => '30 ',
                '/^forty /i'     => '40 ',
                '/^fourty /i'    => '40 ',  # (common misspelling)
                '/^fifty /i'     => '50 ',
                '/^sixty /i'     => '60 ',
                '/^seventy /i'   => '70 ',
                '/^eighty /i'    => '80 ',
                '/^ninety /i'    => '90 '
            ];
            $Value = preg_replace(
                array_keys($WordsForNumbers),
                $WordsForNumbers,
                $Value
            );

            # use strtotime method to build condition
            $TimestampValue = strtotime($Value);
            if (($TimestampValue !== false) && ($TimestampValue != -1)) {
                if ((date("H:i:s", $TimestampValue) == "00:00:00")
                        && (strpos($Value, "00:00") === false)
                        && ($Operator == "<=")) {
                    $NormalizedValue =
                            date("Y-m-d", $TimestampValue)." 23:59:59";
                } else {
                    $NormalizedValue = date(
                        "Y-m-d H:i:s",
                        $TimestampValue
                    );
                }
            } else {
                return null;
            }

            # build SQL conditional
            if ($ModificationComparison) {
                $Conditional = " ( FieldId = ".$Field->id()
                        ." AND Timestamp ".$Operator
                                ." '".$NormalizedValue."' ) ";
            } else {
                $Conditional = " ( `".$Field->dBFieldName()."` "
                        .$Operator." '".$NormalizedValue."' ) ";
            }
        } else {
            # adjust operator if necessary
            if ($ModificationComparison) {
                $Operator = ($Operator == "@") ? ">="
                        : substr($Operator, 1);
            }

            if (!Date::isValidDate($Value)) {
                return null;
            }
            # use Date object method to build conditional
            $Date = new Date($Value);
            if ($Date->precision()) {
                if ($ModificationComparison) {
                    $Conditional = " ( FieldId = ".$Field->id()
                            ." AND ".$Date->sqlCondition(
                                "Timestamp",
                                null,
                                $Operator
                            )." ) ";
                } else {
                    $Conditional = " ( ".$Date->sqlCondition(
                        $Field->dBFieldName(),
                        null,
                        $Operator
                    )." ) ";
                }
            }
        }

        # return assembled conditional to caller
        return $Conditional;
    }

    /**
     * Get SQL for comparison against User field.
     * @param int $FieldId FieldId for comparison.
     * @param string $Operator Comparison operator ("=", "!=").
     * @param int $UserId UserId to search for.
     * @return string SQL for comparison.
     * @throws Exception If an invalid comparison type is specified.
     */
    private function getUserComparisonSql(
        int $FieldId,
        string $Operator,
        int $UserId
    ): string {

        switch ($Operator) {
            case "=":
                return "(UserId = ".intval($UserId)." AND FieldId = ".intval($FieldId).")";

            case "!=":
                return "(UserId != ".intval($UserId)." AND FieldId = ".intval($FieldId).")";

            default:
                throw new Exception("Operator ".$Operator." is not supported for User fields");
        }
    }
}
