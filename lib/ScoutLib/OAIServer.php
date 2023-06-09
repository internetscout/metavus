<?PHP
#
#   FILE:  OAIServer.php
#
#   Part of the ScoutLib application support library
#   Copyright 2009-2023 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#

namespace ScoutLib;

class OAIServer
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Construct an OAI server object.
     * @param array $RepDescr Repository Description.
     * @param mixed $ItemFactory Item Factory that this repository uses
     *     to get data.
     * @param bool $SetsSupported OAI sets supported (OPTIONAL, default FALSE).
     * @param bool $OaisqSupported OAI-SQ supported (OPTIONAL, default FALSE).
     */
    public function __construct(
        $RepDescr,
        &$ItemFactory,
        $SetsSupported = false,
        $OaisqSupported = false
    ) {

        # save repository description
        $this->RepDescr = $RepDescr;

        # save supported option settings
        $this->SetsSupported = $SetsSupported;
        $this->OaisqSupported = $OaisqSupported;

        # normalize repository description values
        $this->RepDescr["IDPrefix"] =
            preg_replace("/[^0-9a-z]/i", "", $this->RepDescr["IDPrefix"]);

        # save item factory
        $this->ItemFactory =& $ItemFactory;

        # load OAI request type and arguments
        $this->LoadArguments();

        # set default indent size
        $this->IndentSize = 4;

        # start with empty list of formats
        $this->FormatDescrs = array();
    }

    /**
     * Add a new metadata format.
     * @param string $Name Format name.
     * @param string $TagName XML tag to use for format.
     * @param string $SchemaNamespace XML namespace for the format.
     * @param string $SchemaDefinition Schema definition URL.
     * @param string $SchemaVersion Schema version number.
     * @param array $NamespaceList List of namespaces in format.
     * @param array $ElementList List of elements in format.
     * @param array $QualifierList List of qualifiers in format.
     * @param array $DefaultMap Default values for format.
     */
    public function addFormat(
        $Name,
        $TagName,
        $SchemaNamespace,
        $SchemaDefinition,
        $SchemaVersion,
        $NamespaceList,
        $ElementList,
        $QualifierList,
        $DefaultMap
    ) {

        # find highest current format ID
        $HighestFormatId = 0;
        foreach ($this->FormatDescrs as $FormatName => $FormatDescr) {
            if ($FormatDescr["FormatId"] > $HighestFormatId) {
                $HighestFormatId = $FormatDescr["FormatId"];
            }
        }

        # set new format ID to next value
        $this->FormatDescrs[$Name]["FormatId"] = $HighestFormatId + 1;

        # store values
        $this->FormatDescrs[$Name]["TagName"] = $TagName;
        $this->FormatDescrs[$Name]["SchemaNamespace"] = $SchemaNamespace;
        $this->FormatDescrs[$Name]["SchemaDefinition"] = $SchemaDefinition;
        $this->FormatDescrs[$Name]["SchemaVersion"] = $SchemaVersion;
        $this->FormatDescrs[$Name]["ElementList"] = $ElementList;
        $this->FormatDescrs[$Name]["QualifierList"] = $QualifierList;
        $this->FormatDescrs[$Name]["NamespaceList"] = $NamespaceList;
        $this->FormatDescrs[$Name]["DefaultMap"] = $DefaultMap;

        # start out with empty mappings list
        if (!isset($this->FieldMappings[$Name])) {
            $this->FieldMappings[$Name] = array();
        }
    }

    /**
     * Get the list of formats.
     * @return array of supported format names, keyed by FormatId.
     */
    public function formatList()
    {
        $FList = array();
        foreach ($this->FormatDescrs as $FormatName => $FormatDescr) {
            $FList[$FormatDescr["FormatId"]] = $FormatName;
        }
        return $FList;
    }

    /**
     * Get list of elements for a specified format.
     * @param string $FormatName OAI format name.
     * @return array List of elements.
     */
    public function formatElementList($FormatName)
    {
        return $this->FormatDescrs[$FormatName]["ElementList"];
    }


    /**
     * Get the list of qualifiers for a specified format.
     * @param string $FormatName OAI format name.
     * @return array List of qualifiers
     */
    public function formatQualifierList($FormatName)
    {
        return $this->FormatDescrs[$FormatName]["QualifierList"];
    }

    /**
     * Get mapped name for a field.
     * @param string $FormatName OAI format name.
     * @param string $LocalFieldName Local field to fetch.
     * @return array|null Array of mapped names or NULL if none exist.
     */
    public function getFieldMapping($FormatName, $LocalFieldName)
    {
        # return stored value
        if (isset($this->FieldMappings[$FormatName][$LocalFieldName])) {
            return $this->FieldMappings[$FormatName][$LocalFieldName];
        } else {
            return null;
        }
    }

    /**
     * Set mapping for a field.
     * @param string $FormatName OAI format name.
     * @param string $LocalFieldName Local field to map.
     * @param string $OAIFieldName Mapped value to set.
     */
    public function setFieldMapping($FormatName, $LocalFieldName, $OAIFieldName)
    {
        $this->FieldMappings[$FormatName][$LocalFieldName][] = $OAIFieldName;
    }

    /**
     * Get mapping for a qualifier.
     * @param string $FormatName OAI format name.
     * @param string $LocalQualifierName Local qualifier to fetch.
     * @return string|null Mapped value or NULL if none exists.
     */
    public function getQualifierMapping($FormatName, $LocalQualifierName)
    {
        # return stored value
        if (isset($this->QualifierMappings[$FormatName][$LocalQualifierName])) {
            return $this->QualifierMappings[$FormatName][$LocalQualifierName];
        } else {
            return null;
        }
    }

    /**
     * Set mapping for a qualifier.
     * @param string $FormatName OAI format name.
     * @param string $LocalQualifierName Local name to map.
     * @param string $OAIQualifierName Mapped value to set.
     */
    public function setQualifierMapping(
        $FormatName,
        $LocalQualifierName,
        $OAIQualifierName
    ) {

        $this->QualifierMappings[$FormatName][$LocalQualifierName] =
            $OAIQualifierName;
    }

    /**
     * Get OAI response.
     * @return string XML response data.
     */
    public function getResponse()
    {
        # call appropriate method based on request type
        switch (strtoupper($this->Args["verb"] ?? "")) {
            case "IDENTIFY":
                $Response = $this->ProcessIdentify();
                break;

            case "GETRECORD":
                $Response = $this->ProcessGetRecord();
                break;

            case "LISTIDENTIFIERS":
                $Response = $this->ProcessListRecords(false);
                break;

            case "LISTRECORDS":
                $Response = $this->ProcessListRecords(true);
                break;

            case "LISTMETADATAFORMATS":
                $Response = $this->ProcessListMetadataFormats();
                break;

            case "LISTSETS":
                $Response = $this->ProcessListSets();
                break;

            default:
                # return "bad argument" response
                $ErrorMessage = isset($this->Args["verb"]) ?
                    "Bad or unknown request type." :
                    "No request provided.";
                $Response = $this->GetResponseBeginTags();
                $Response .= $this->GetRequestTag();
                $Response .= $this->GetErrorTag(
                    "badVerb",
                    $ErrorMessage
                );
                $Response .= $this->GetResponseEndTags();
                break;
        }

        # return generated response to caller
        return $Response;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $Args;
    private $RepDescr;
    private $ItemFactory;
    private $FormatDescrs;
    private $FormatFields;
    private $FieldMappings;
    private $QualifierMappings;
    private $IndentSize;
    private $SetsSupported;
    private $OaisqSupported;


    # ---- response generation methods

    /**
     * Process Identify request.
     * @return string XML data.
     */
    private function processIdentify()
    {
        # initialize response
        $Response = $this->GetResponseBeginTags();

        # add request info tag
        $Response .= $this->GetRequestTag("Identify");

        # open response type tag
        $Response .= $this->FormatTag("Identify");

        # add repository info tags
        $Response .= $this->FormatTag("repositoryName", $this->RepDescr["Name"]);
        $Response .= $this->FormatTag("baseURL", $this->RepDescr["BaseURL"]);
        $Response .= $this->FormatTag("protocolVersion", "2.0");
        foreach ($this->RepDescr["AdminEmail"] as $AdminEmail) {
            $Response .= $this->FormatTag("adminEmail", $AdminEmail);
        }
        $Response .= $this->FormatTag(
            "earliestDatestamp",
            $this->RepDescr["EarliestDate"]
        );
        $Response .= $this->FormatTag(
            "deletedRecord",
            "no"
        );
        $Response .= $this->FormatTag(
            "granularity",
            (strtoupper($this->RepDescr["DateGranularity"]) == "DATETIME")
                ? "YYYY-MM-DDThh:mm:ssZ" : "YYYY-MM-DD"
        );

        # add repository description section
        $Response .= $this->FormatTag("description");
        $Attribs = array(
            "xmlns" => "http://www.openarchives.org/OAI/2.0/oai-identifier",
            "xmlns:xsi" => "http://www.w3.org/2001/XMLSchema-instance",
            "xsi:schemaLocation" =>
                "http://www.openarchives.org/OAI/2.0/oai-identifier "
                . "http://www.openarchives.org/OAI/2.0/oai-identifier.xsd",
        );
        $Response .= $this->FormatTag(
            "oai-identifier",
            null,
            $Attribs
        );
        $Response .= $this->FormatTag(
            "scheme",
            "oai"
        );
        $Response .= $this->FormatTag(
            "repositoryIdentifier",
            $this->RepDescr["IDDomain"]
        );
        $Response .= $this->FormatTag(
            "delimiter",
            ":"
        );
        $Response .= $this->FormatTag(
            "sampleIdentifier",
            $this->EncodeIdentifier("12345")
        );
        $Response .= $this->FormatTag();
        $Response .= $this->FormatTag();

        # close response type tag
        $Response .= $this->FormatTag();

        # close out response
        $Response .= $this->GetResponseEndTags();

        # return response to caller
        return $Response;
    }

    /**
     * Process a GetRecord request.
     * @return string XML data.
     */
    private function processGetRecord()
    {
        # initialize response
        $Response = $this->GetResponseBeginTags();

        # if arguments were bad
        if (isset($this->Args["identifier"])) {
            $ItemId = $this->DecodeIdentifier($this->Args["identifier"]);
        } else {
            $ItemId = null;
        }
        if (isset($this->Args["metadataPrefix"])) {
            $MetadataFormat = $this->Args["metadataPrefix"];
        } else {
            $MetadataFormat = null;
        }
        if (($ItemId == null) || ($MetadataFormat == null) ||
            !is_array($this->FieldMappings[$MetadataFormat])) {
            # add request info tag with no attributes
            $Response .= $this->GetRequestTag("GetRecord");

            # add error tag
            $Response .= $this->GetErrorTag("badArgument", "Bad argument found.");
        } else {
            # add request info tag
            $ReqArgList = array("identifier", "metadataPrefix");
            $Response .= $this->GetRequestTag("GetRecord", $ReqArgList);

            # attempt to load item corresponding to record
            $Item = $this->ItemFactory->GetItem($ItemId);

            # if no item found
            if ($Item == null) {
                # add error tag
                $Response .= $this->GetErrorTag(
                    "idDoesNotExist",
                    "No item found for specified ID."
                );
            } else {
                # open response type tag
                $Response .= $this->FormatTag("GetRecord");

                # add tags for record
                $Response .= $this->GetRecordTags($Item, $MetadataFormat);

                # close response type tag
                $Response .= $this->FormatTag();
            }
        }

        # close out response
        $Response .= $this->GetResponseEndTags();

        # return response to caller
        return $Response;
    }

    /**
     * Process a ListRecords request.
     * @param bool $IncludeMetadata TRUE to include metadata, FALSE to
     *     just get record identifiers.
     * @return string XML data.
     */
    private function processListRecords($IncludeMetadata)
    {
        # set request type
        if ($IncludeMetadata) {
            $Request = "ListRecords";
        } else {
            $Request = "ListIdentifiers";
        }

        # initialize response
        $Response = $this->GetResponseBeginTags();

        # if resumption token supplied
        if (isset($this->Args["resumptionToken"])) {
            # set expected argument lists
            $ReqArgList = array("resumptionToken");
            $OptArgList = null;

            # parse into list parameters
            $Args = $this->DecodeResumptionToken($this->Args["resumptionToken"]);
        } else {
            # set expected argument lists
            $ReqArgList = array("metadataPrefix");
            $OptArgList = array("from", "until", "set");

            # get list parameters from incoming arguments
            $Args = $this->Args;

            # set list starting point to beginning
            $Args["ListStartPoint"] = 0;
        }

        # if resumption token was supplied and was bad
        if ($Args == null) {
            # add request info tag
            $Response .= $this->GetRequestTag($Request, $ReqArgList, $OptArgList);

            # add error tag indicating bad resumption token
            $Response .= $this->GetErrorTag(
                "badResumptionToken",
                "Bad resumption token."
            );

            # if other parameter also supplied
            if (count($this->Args) > 2) {
                # add error tag indicating exclusive argument error
                $Response .= $this->GetErrorTag(
                    "badArgument",
                    "Resumption token is exclusive argument."
                );
            }
            # else if resumption token supplied and other arguments also supplied
        } elseif (isset($this->Args["resumptionToken"]) && (count($this->Args) > 2)) {
            # add error tag indicating exclusive argument error
            $Response .= $this->GetRequestTag();
            $Response .= $this->GetErrorTag(
                "badArgument",
                "Resumption token is exclusive argument."
            );
            # else if metadata format was not specified
        } elseif (empty($Args["metadataPrefix"])) {
            # add request info tag with no attributes
            $Response .= $this->GetRequestTag($Request);

            # add error tag indicating bad argument
            $Response .= $this->GetErrorTag(
                "badArgument",
                "No metadata format specified."
            );
            # else if from or until date is specified but bad
        } elseif ((isset($Args["from"]) && $this->DateIsInvalid($Args["from"]))
            || (isset($Args["until"]) && $this->DateIsInvalid($Args["until"]))) {
            # add request info tag with no attributes
            $Response .= $this->GetRequestTag($Request);

            # add error tag indicating bad argument
            $Response .= $this->GetErrorTag("badArgument", "Bad date format.");
        } else {
            # add request info tag
            $Response .= $this->GetRequestTag($Request, $ReqArgList, $OptArgList);

            # if set requested and we do not support sets
            if (isset($Args["set"]) && ($this->SetsSupported != true)) {
                # add error tag indicating that we don't support sets
                $Response .= $this->GetErrorTag(
                    "noSetHierarchy",
                    "This repository does not support sets."
                );
                # else if requested metadata format is not supported
            } elseif (empty($this->FormatDescrs[$Args["metadataPrefix"]])) {
                # add error tag indicating that format is not supported
                $Response .= $this->GetErrorTag(
                    "cannotDisseminateFormat",
                    "Metadata format \"" . $Args["metadataPrefix"]
                    . "\" not supported by this repository."
                );
            } else {
                # if set requested
                if (isset($Args["set"])) {
                    # if OAI-SQ supported and set represents OAI-SQ query
                    if ($this->OaisqSupported && $this->IsOaisqQuery($Args["set"])) {
                        # parse OAI-SQ search parameters out of set name
                        $SearchParams = $this->ParseOaisqQuery(
                            $Args["set"],
                            $Args["metadataPrefix"]
                        );

                        # if search parameters found
                        if ($SearchParams->parameterCount()) {
                            # perform search for items that match OAI-SQ request
                            $ItemIds = $this->ItemFactory->SearchForItems(
                                $SearchParams,
                                (isset($Args["from"]) ? $Args["from"] : null),
                                (isset($Args["until"]) ? $Args["until"] : null)
                            );
                        } else {
                            # no items match
                            $ItemIds = array();
                        }
                    } else {
                        # get list of items in set that matches incoming criteria
                        $ItemIds = $this->ItemFactory->GetItemsInSet(
                            $Args["set"],
                            (isset($Args["from"]) ? $Args["from"] : null),
                            (isset($Args["until"]) ? $Args["until"] : null)
                        );
                    }
                } else {
                    # get list of items that matches incoming criteria
                    $ItemIds = $this->ItemFactory->GetItems(
                        (isset($Args["from"]) ? $Args["from"] : null),
                        (isset($Args["until"]) ? $Args["until"] : null)
                    );
                }

                # if no items found
                if (count($ItemIds) == 0) {
                    # add error tag indicating that no records found that match spec
                    $Response .= $this->GetErrorTag(
                        "noRecordsMatch",
                        "No records were found that match the specified parameters."
                    );
                } else {
                    # open response type tag
                    $Response .= $this->FormatTag($Request);

                    # initialize count of processed items
                    $ListIndex = 0;

                    # for each item
                    foreach ($ItemIds as $ItemId) {
                        # if item is within range
                        if ($ListIndex >= $Args["ListStartPoint"]) {
                            # retrieve item
                            $Item = $this->ItemFactory->GetItem($ItemId);

                            # add record for item
                            $Response .= $this->GetRecordTags(
                                $Item,
                                $Args["metadataPrefix"],
                                $IncludeMetadata
                            );
                        }

                        # increment count of processed items
                        $ListIndex++;

                        # stop processing if we have processed max number of items
                        $MaxItemsPerPass = 20;
                        if (($ListIndex - $Args["ListStartPoint"]) >= $MaxItemsPerPass) {
                            break;
                        }
                    }

                    # if items left unprocessed
                    if ($ListIndex < count($ItemIds)) {
                        # add resumption token tag
                        $Token = $this->EncodeResumptionToken(
                            (isset($Args["from"]) ? $Args["from"] : null),
                            (isset($Args["until"]) ? $Args["until"] : null),
                            (isset($Args["metadataPrefix"]) ?
                                $Args["metadataPrefix"] : null),
                            (isset($Args["set"]) ? $Args["set"] : null),
                            $ListIndex
                        );
                        $Response .= $this->FormatTag("resumptionToken", $Token);
                    } else {
                        # if we started with a resumption token tag
                        if (isset($this->Args["resumptionToken"])) {
                            # add empty resumption token tag to indicate end of set
                            $Response .= $this->FormatTag("resumptionToken", "");
                        }
                    }

                    # close response type tag
                    $Response .= $this->FormatTag();
                }
            }
        }

        # close out response
        $Response .= $this->GetResponseEndTags();

        # return response to caller
        return $Response;
    }

    /**
     * Handle a ListMetadataFormats request.
     * @return string XML response.
     */
    private function processListMetadataFormats()
    {
        # initialize response
        $Response = $this->GetResponseBeginTags();

        # if arguments were bad
        $Arg = isset($this->Args["identifier"]) ? $this->Args["identifier"] : null;
        $ItemId = $this->DecodeIdentifier($Arg);
        if (isset($this->Args["identifier"]) && ($ItemId == null)) {
            # add error tag
            $Response .= $this->GetRequestTag();
            $Response .= $this->GetErrorTag(
                "idDoesNotExist",
                "Identifier unknown or illegal."
            );
        } else {
            # add request info tag
            $OptArgList = array("identifier");
            $Response .= $this->GetRequestTag("ListMetadataFormats", null, $OptArgList);

            # open response type tag
            $Response .= $this->FormatTag("ListMetadataFormats");

            # for each supported format
            foreach ($this->FormatDescrs as $FormatName => $FormatDescr) {
                # open format tag
                $Response .= $this->FormatTag("metadataFormat");

                # add tags describing format
                $Response .= $this->FormatTag("metadataPrefix", $FormatName);
                if (isset($FormatDescr["SchemaDefinition"])) {
                    $Response .= $this->FormatTag(
                        "schema",
                        $FormatDescr["SchemaDefinition"]
                    );
                }
                if (isset($FormatDescr["SchemaNamespace"])) {
                    $Response .= $this->FormatTag(
                        "metadataNamespace",
                        $FormatDescr["SchemaNamespace"]
                    );
                }

                # close format tag
                $Response .= $this->FormatTag();
            }

            # close response type tag
            $Response .= $this->FormatTag();
        }

        # close out response
        $Response .= $this->GetResponseEndTags();

        # return response to caller
        return $Response;
    }

    /**
     * Handle a ListSets request.
     * @return string XML response.
     */
    private function processListSets()
    {
        # initialize response
        $Response = $this->GetResponseBeginTags();

        # add request info tag
        $OptArgList = array("resumptionToken");
        $Response .= $this->GetRequestTag("ListSets", null, $OptArgList);

        # retrieve list of supported sets
        $SetList = $this->SetsSupported ? $this->ItemFactory->GetListOfSets() : array();

        # if sets not supported or we have no sets
        if ((!$this->SetsSupported) || (!count($SetList) && !$this->OaisqSupported)) {
            # add error tag indicating that we do not support sets
            $Response .= $this->GetErrorTag(
                "noSetHierarchy",
                "This repository does not support sets."
            );
        } else {
            # open response type tag
            $Response .= $this->FormatTag("ListSets");

            # if OAI-SQ is enabled
            if ($this->OaisqSupported) {
                # add OAI-SQ to list of sets
                $SetList["OAI-SQ"] = "OAI-SQ";
                $SetList["OAI-SQ-F"] = "OAI-SQ-F";
            }

            # for each supported set
            foreach ($SetList as $SetName => $SetSpec) {
                # open set tag
                $Response .= $this->FormatTag("set");

                # add set spec and set name
                $Response .= $this->FormatTag("setSpec", $SetSpec);
                $Response .= $this->FormatTag("setName", $SetName);

                # close set tag
                $Response .= $this->FormatTag();
            }

            # close response type tag
            $Response .= $this->FormatTag();
        }

        # close out response
        $Response .= $this->GetResponseEndTags();

        # return response to caller
        return $Response;
    }


    # ---- common private methods

    /**
     * Get the tags that begin an OAI response.
     * @return string XML tags.
     */
    private function getResponseBeginTags()
    {
        # start with XML declaration
        $Tags = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?" . ">\n";

        # add OAI-PMH root element begin tag
        $Tags .= "<OAI-PMH xmlns=\"http://www.openarchives.org/OAI/2.0/\"\n"
            . "        xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n"
            . "        xsi:schemaLocation=\"http://www.openarchives.org/OAI/2.0/\n"
            . "            http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd\">\n";

        # add response timestamp
        $Tags .= "    <responseDate>" . date("Y-m-d\\TH:i:s\\Z") . "</responseDate>\n";

        # return tags to caller
        return $Tags;
    }

    /**
     * Get the tag to end an OAI response.
     * @return string OAI ending tag
     */
    private function getResponseEndTags()
    {
        # close out OAI-PMH root element
        $Tags = "</OAI-PMH>\n";

        # return tags to caller
        return $Tags;
    }

    /**
     * Generate XML tag corresponding to a specified request.
     * @param mixed $RequestType OAI Request we're responding to (OPTIONAL).
     * @param mixed $ReqArgList Required arguments for request (OPTIONAL).
     * @param mixed $OptArgList Optional arguments for request (OPTIONAL).
     * @return string XML tag for this request.
     */
    private function getRequestTag(
        $RequestType = null,
        $ReqArgList = null,
        $OptArgList = null
    ) {

        # build attribute array
        $AttributeList = array();
        if ($RequestType !== null) {
            $AttributeList["verb"] = $RequestType;
        }
        if ($ReqArgList != null) {
            foreach ($ReqArgList as $ArgName) {
                if (isset($this->Args[$ArgName])) {
                    $AttributeList[$ArgName] = $this->Args[$ArgName];
                }
            }
        }
        if ($OptArgList != null) {
            foreach ($OptArgList as $ArgName) {
                if (isset($this->Args[$ArgName])) {
                    $AttributeList[$ArgName] = $this->Args[$ArgName];
                }
            }
        }

        # generate formatted tag
        $Tag = $this->FormatTag(
            "request",
            $this->RepDescr["BaseURL"],
            $AttributeList
        );

        # return tag to caller
        return $Tag;
    }

    /**
     * Output an error tag.
     * @param string $ErrorCode Error code to output.
     * @param string $ErrorMessage Error message to output.
     * @return string XML error message.
     */
    private function getErrorTag($ErrorCode, $ErrorMessage)
    {
        return $this->FormatTag("error", $ErrorMessage, array("code" => $ErrorCode));
    }

    /**
     * Get XML block for a specified record.
     * @param mixed $Item Item to display.
     * @param string $MetadataFormat OAI schema to use.
     * @param bool $IncludeMetadata TRUE to output item content, FALSE
     *     to just get ItemIds (OPTIONAL, default TRUE).
     * @return string XML data.
     */
    private function getRecordTags($Item, $MetadataFormat, $IncludeMetadata = true)
    {
        # if more than identifiers requested
        if ($IncludeMetadata) {
            # open record tag
            $Tags = $this->FormatTag("record");
        } else {
            # just initialize tag string with empty value
            $Tags = "";
        }

        # add header with identifier, datestamp, and set tags
        $Tags .= $this->FormatTag("header");
        $Tags .= $this->FormatTag(
            "identifier",
            $this->EncodeIdentifier($Item->GetId())
        );
        $Tags .= $this->FormatTag("datestamp", $Item->GetDatestamp());
        $Sets = $Item->GetSets();
        foreach ($Sets as $Set) {
            $Tags .= $this->FormatTag("setSpec", $Set);
        }
        $Tags .= $this->FormatTag();

        # if more than identifiers requested
        if ($IncludeMetadata) {
            # open metadata tag
            $Tags .= $this->FormatTag("metadata");

            # set up attributes for metadata format tag
            $MFAttribs["xsi:schemaLocation"] =
                $this->FormatDescrs[$MetadataFormat]["SchemaNamespace"] . " \n"
                . $this->FormatDescrs[$MetadataFormat]["SchemaDefinition"];
            $MFAttribs["xmlns"] = $this->FormatDescrs[$MetadataFormat]["SchemaNamespace"];
            if (strlen($this->FormatDescrs[$MetadataFormat]["SchemaVersion"]) > 0) {
                $MFAttribs["schemaVersion"] =
                    $this->FormatDescrs[$MetadataFormat]["SchemaVersion"];
            }
            $MFAttribs["xmlns:xsi"] = "http://www.w3.org/2001/XMLSchema-instance";
            foreach ($this->FormatDescrs[$MetadataFormat]["NamespaceList"] as $Namespace => $URI) {
                $MFAttribs["xmlns:" . $Namespace] = $URI;
            }

            # open metadata format tag
            $Tags .= $this->FormatTag(
                $this->FormatDescrs[$MetadataFormat]["TagName"],
                null,
                $MFAttribs
            );

            # for each field mapping for this metadata format
            foreach ($this->FieldMappings[$MetadataFormat] as $LocalFieldName => $OAIFieldNames) {
                foreach ($OAIFieldNames as $OAIFieldName) {
                    # if field looks like it has been mapped
                    if (strlen($OAIFieldName) > 0 && strlen($LocalFieldName) > 0) {
                        $Tags .= $this->FormatItemContent(
                            $Item,
                            $MetadataFormat,
                            $LocalFieldName,
                            $OAIFieldName
                        );
                    }
                }
            }

            # close metadata format tag
            $Tags .= $this->FormatTag();

            # close metadata tag
            $Tags .= $this->FormatTag();

            # if there is additional search info about this item
            $SearchInfo = $Item->GetSearchInfo();
            if (count($SearchInfo)) {
                # open about and search info tags
                $Tags .= $this->FormatTag("about");
                $Attribs = array(
                    "xmlns" => "http://scout.wisc.edu/XML/searchInfo/",
                    "xmlns:xsi" => "http://www.w3.org/2001/XMLSchema-instance",
                    "xsi:schemaLocation" => "http://scout.wisc.edu/XML/searchInfo/ "
                        . "http://scout.wisc.edu/XML/searchInfo.xsd",
                );
                $Tags .= $this->FormatTag("searchInfo", null, $Attribs);

                # for each piece of additional info
                foreach ($SearchInfo as $InfoName => $InfoValue) {
                    # add tag for info
                    $Tags .= $this->FormatTag(
                        $InfoName,
                        mb_convert_encoding(htmlspecialchars(
                            preg_replace("/[\\x00-\\x1F]+/", "", $InfoValue)
                        ), 'UTF-8', 'ISO-8859-1')
                    );
                }

                # close about and search info tags
                $Tags .= $this->FormatTag();
                $Tags .= $this->FormatTag();
            }
        }

        # if more than identifiers requested
        if ($IncludeMetadata) {
            # close record tag
            $Tags .= $this->FormatTag();
        }

        # return tags to caller
        return $Tags;
    }

    /**
     * Convert an ItemId into an OAI Unique Identifier.
     * @param string $ItemId ItemId to encode.
     * @return string ItemId value
     */
    private function encodeIdentifier($ItemId)
    {
        # return encoded value to caller
        return "oai:" . $this->RepDescr["IDDomain"]
            . ":" . $this->RepDescr["IDPrefix"] . "-" . $ItemId;
    }

    /**
     * Decode an OAI identifier (i.e., a Unique Id) to find the
     *     corresponding ItemId.
     * @param string $Identifier Identifier to decode.
     * @return string Decoded ItemId.
     */
    private function decodeIdentifier($Identifier)
    {
        # assume that decode will fail
        $Id = null;

        # split ID into component pieces
        $Pieces = explode(":", $Identifier);

        # if pieces look okay
        if (($Pieces[0] == "oai") && ($Pieces[1] == $this->RepDescr["IDDomain"])) {
            # split final piece
            $Pieces = explode("-", $Pieces[2]);

            # if identifier prefix looks okay
            if ($Pieces[0] == $this->RepDescr["IDPrefix"]) {
                # decoded value is final piece
                $Id = $Pieces[1];
            }
        }

        # return decoded value to caller
        return $Id;
    }

    /**
     * Construct a resumption token.
     * @param string $StartingDate Starting date to encode.
     * @param string $EndingDate Ending date to encode.
     * @param string $MetadataFormat Metadata format to encode.
     * @param string $SetSpec OAI Set to encode.
     * @param string $ListStartPoint Offset into results.
     */
    private function encodeResumptionToken(
        $StartingDate,
        $EndingDate,
        $MetadataFormat,
        $SetSpec,
        $ListStartPoint
    ) {

        # concatenate values to create token
        $Token = $StartingDate . "-_-" . $EndingDate . "-_-" . $MetadataFormat . "-_-"
            . $SetSpec . "-_-" . $ListStartPoint;

        # return token to caller
        return $Token;
    }

    /**
     * Compute array of Args based on a provided resumption token.
     * @param string $ResumptionToken Provided token value.
     * @return array Args based on provided token.
     */
    private function decodeResumptionToken($ResumptionToken)
    {
        # split into component pieces
        $Pieces = preg_split("/-_-/", $ResumptionToken);

        # if we were unable to split token
        if (count($Pieces) != 5) {
            # return NULL list
            $Args = null;
        } else {
            # assign component pieces to list parameters
            if (strlen($Pieces[0]) > 0) {
                $Args["from"] = $Pieces[0];
            }
            if (strlen($Pieces[1]) > 0) {
                $Args["until"] = $Pieces[1];
            }
            if (strlen($Pieces[2]) > 0) {
                $Args["metadataPrefix"] = $Pieces[2];
            }
            if (strlen($Pieces[3]) > 0) {
                $Args["set"] = $Pieces[3];
            }
            if (strlen($Pieces[4]) > 0) {
                $Args["ListStartPoint"] = $Pieces[4];
            }
        }

        # return list parameter array to caller
        return $Args;
    }

    /**
     * Determine if a string claiming to be a date is in an invalid format.
     * @param string $Date String to test.
     * @return bool TRUE if format is invalid, FALSE otherwise
     */
    private function dateIsInvalid($Date)
    {
        # if date is null or matches required format
        if (empty($Date) || preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/", $Date)) {
            # date is okay
            return false;
        } else {
            # date is not okay
            return true;
        }
    }

    /**
     * Construct an XML tag.
     * @param string|null $Name Name of tag to open, or NULL to close the most
     *     recently opened tag.
     * @param string $Content Content to include (OPTIONAL).
     * @param array|null $Attributes XML Attributes to include on tag.
     * @param int $NewIndentLevel New indentation level (OPTIONAL).
     * @return string constructed tag.
     */
    private function formatTag(
        $Name = null,
        $Content = null,
        $Attributes = null,
        $NewIndentLevel = null
    ) {

        static $IndentLevel = 1;
        static $OpenTagStack = array();

        # reset indent level if requested
        if ($NewIndentLevel !== null) {
            $IndentLevel = $NewIndentLevel;
        }

        # if tag name supplied
        if ($Name !== null) {
            # start out with appropriate indent
            $Tag = str_repeat(" ", ($IndentLevel * $this->IndentSize));

            # open begin tag
            $Tag .= "<" . $Name;

            # if attributes supplied
            if ($Attributes !== null) {
                # add attributes
                foreach ($Attributes as $AttributeName => $AttributeValue) {
                    $Tag .= " " . $AttributeName . "=\"" . $AttributeValue . "\"";
                }
            }

            # if content supplied
            if ($Content !== null) {
                # close begin tag
                $Tag .= ">";

                # add content
                $Tag .= htmlspecialchars($Content);

                # add end tag
                $Tag .= "</" . $Name . ">\n";
            } else {
                # close begin tag
                $Tag .= ">\n";

                # increase indent level
                $IndentLevel++;

                # add tag to open tag stack
                array_push($OpenTagStack, $Name);
            }
        } else {
            # decrease indent level
            if ($IndentLevel > 0) {
                $IndentLevel--;
            }

            # pop last entry off of open tag stack
            $LastName = array_pop($OpenTagStack);

            # start out with appropriate indent
            $Tag = str_repeat(" ", ($IndentLevel * $this->IndentSize));

            # add end tag to match last open tag
            $Tag .= "</" . $LastName . ">\n";
        }

        # return formatted tag to caller
        return $Tag;
    }

    /**
     * Generate XML tags for a field of an item.
     * @param mixed $Item Item to format.
     * @param string $MetadataFormat OAI Schema to use.
     * @param string $LocalFieldName Local field to output.
     * @param string $OAIFieldName Field name to use in output.
     * return string XML formatted content
     */
    private function formatItemContent(
        $Item,
        $MetadataFormat,
        $LocalFieldName,
        $OAIFieldName
    ) {

        # retrieve content for field
        $Content = $Item->GetValue($LocalFieldName);

        # retrieve qualifiers for content
        $Qualifier = $Item->GetQualifier($LocalFieldName);

        # get qualifier maps, if any exists for our format
        $QualifierMaps = isset($this->QualifierMappings[$MetadataFormat]) ?
            $this->QualifierMappings[$MetadataFormat] :
            array();

        # get defaults, if any exist for our format
        $DefaultMaps = isset($this->FormatDescrs[$MetadataFormat]["DefaultMap"]) ?
            $this->FormatDescrs[$MetadataFormat]["DefaultMap"] :
            array();

        $Tags = "";
        # if content is array
        if (is_array($Content)) {
            # for each element of array
            foreach ($Content as $ContentIndex => $ContentValue) {
                # if element has content
                if (strlen($ContentValue) > 0) {
                    # determine if we have a mapped qualifier for this item
                    $ContentAttribs = null;
                    if (isset($Qualifier[$ContentIndex]) &&
                        strlen($Qualifier[$ContentIndex]) &&
                        isset($QualifierMaps[$Qualifier[$ContentIndex]]) &&
                        strlen($QualifierMaps[$Qualifier[$ContentIndex]])) {
                        # if so, add the appropriate attribute
                        $ContentAttribs["xsi:type"] =
                            $QualifierMaps[$Qualifier[$ContentIndex]];
                    }

                    # generate tags for this field, append them to our list
                    $Tags .= $this->FormatTag(
                        $OAIFieldName,
                        mb_convert_encoding(htmlspecialchars(preg_replace(
                            "/[\\x00-\\x1F]+/",
                            "",
                            $ContentValue
                        )), 'UTF-8', 'ISO-8859-1'),
                        $ContentAttribs
                    );
                }
            }
        } else {
            # check for a default value, fill it in if there was one
            if (is_string($Content) && strlen($Content) == 0 &&
                isset($DefaultMap[$OAIFieldName])) {
                $Content = $DefaultMap[$OAIFieldName];
            }

            # if field has content
            if (is_string($Content) && strlen($Content) > 0) {
                # generate tag for field
                $ContentAttribs = null;
                if (is_string($Qualifier) &&
                    strlen($Qualifier) > 0 &&
                    isset($QualifierMaps[$Qualifier]) &&
                    strlen($QualifierMaps[$Qualifier])) {
                    $ContentAttribs["xsi:type"] =
                        $QualifierMaps[$Qualifier];
                }

                $Tags .= $this->FormatTag(
                    $OAIFieldName,
                    mb_convert_encoding(htmlspecialchars(preg_replace(
                        "/[\\x00-\\x1F]+/",
                        "",
                        $Content
                    )), 'UTF-8', 'ISO-8859-1'),
                    $ContentAttribs
                );
            }
        }

        return $Tags;
    }


    /**
     * Load internal Args array from _POST or _GET parameters.
     */
    private function loadArguments()
    {
        # if request type available via POST variables
        if (isset($_POST["verb"])) {
            # retrieve arguments from POST variables
            $this->Args = $_POST;
            # else if request type available via GET variables
        } elseif (isset($_GET["verb"])) {
            # retrieve arguments from GET variables
            $this->Args = $_GET;
        } else {
            # ERROR OUT
            return;
        }

        # clear out ApplicationFramework  page specifier if set
        if (isset($this->Args["P"])) {
            unset($this->Args["P"]);
        }
    }

    # ---- methods to support OAI-SQ

    /**
     * Determine if a query uses OAI-SQ.
     * @param string $SetString OAI set requested.
     * @return bool TRUE for OAI-SQ queries, FALSE otherwise.
     */
    private function isOaisqQuery($SetString)
    {
        return ((strpos($SetString, "OAI-SQ|") === 0)
            || (strpos($SetString, "OAI-SQ!") === 0)
            || (strpos($SetString, "OAI-SQ-F|") === 0)
            || (strpos($SetString, "OAI-SQ-F!") === 0)
        ) ? true : false;
    }

    /**
     * Translate OAI-SQ escape characters back to the characters they
     * represent.
     * @param array $Pieces Array of strings containing escapes.
     * @return array translated version of input.
     */
    private function translateOaisqEscapes($Pieces)
    {
        $EscFunc = function ($Matches) {
            for ($Index = 0; $Index < count($Matches); $Index++) {
                $Replacements = chr(intval(substr($Matches[$Index], 1, 2), 16));
            }
            return $Replacements;
        };

        # for each piece
        $N = count($Pieces);
        for ($Index = 0; $Index < $N; $Index++) {
            # replace escaped chars with equivalents
            $Pieces[$Index] = preg_replace_callback(
                "/~[a-fA-F0-9]{2,2}/",
                $EscFunc,
                $Pieces[$Index]
            );
        }

        # return translated array of pieces to caller
        return $Pieces;
    }

    /**
     * Parse OAI-SQ string to generate search parameters.
     * @param string $SetString OAI set in use.
     * @param string $FormatName OAI metadataPrefix in use.
     * @return SearchParameterSet with parameters from OAI-SQ string
     */
    private function parseOaisqQuery($SetString, $FormatName)
    {
        # create SearchParameterSet to add parameters to
        $SearchParams = new SearchParameterSet();

        # if OAI-SQ fielded search requested
        if (strpos($SetString, "OAI-SQ-F") === 0) {
            # split set string into field names and values
            $Pieces = explode(substr($SetString, 8, 1), $SetString);

            # discard first piece (OAI-SQ designator)
            array_shift($Pieces);

            # if set string contains escaped characters
            if (preg_match("/~[a-fA-F0-9]{2,2}/", $SetString)) {
                $Pieces = $this->TranslateOaisqEscapes($Pieces);
            }

            # for every two pieces
            $NumPairedPieces = round(count($Pieces) / 2) * 2;
            for ($Index = 0; $Index < $NumPairedPieces; $Index += 2) {
                # retrieve local field mapping
                foreach ($this->FieldMappings[$FormatName] as $LocalFieldName => $OAIFieldNames) {
                    if (array_search($Pieces[$Index], $OAIFieldNames) !== false) {
                        $AddField = ($LocalFieldName == "XXXKeywordXXX") ? null : $LocalFieldName;
                        $SearchParams->addParameter($Pieces[$Index + 1], $AddField);
                    }
                }
            }
        } else {
            # split set string to trim off query designator
            $Pieces = explode(substr($SetString, 6, 1), $SetString, 2);

            # if set string contains escaped characters
            if (preg_match("/~[a-fA-F0-9]{2,2}/", $SetString)) {
                $Pieces = $this->TranslateOaisqEscapes($Pieces);
            }

            # remainder of set string is keyword search string
            $SearchParams->addParameter($Pieces[1]);
        }

        # return array of search parameters to caller
        return $SearchParams;
    }
}
