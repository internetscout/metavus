<?PHP
#
#   FILE: OAIClient.php
#     Provides a client for pulling data from OAI-PMH providers
#     For protocol documentation, see:
#     http://www.openarchives.org/OAI/openarchivesprotocol.html
#
#   METHODS PROVIDED:
#       OAIClient(ServerUrl, Cache)
#           - constructor
#       ServerUrl(NewValue)
#           - Change the base url of the remote repository
#       MetadataPrefix($pfx)
#           - Set the schema we will request from remote
#       SetSpec($set)
#           - Restrict queries to a single set
#             for details, see
#             http://www.openarchives.org/OAI/openarchivesprotocol.html#Set
#       GetIdentification()
#           - Fetch identifying information about the remote repository
#       GetFormats()
#           - Fetch information about what schemas remote can serve
#       GetRecords($start,$end)
#           - Pull records in batches, optionally with date restrictions
#       GetRecord($id)
#           - Pull a single record using a unique identifier
#       MoreRecordsAvailable()
#           - Determine if a batch pull is complete or not
#       ResetRecordPointer()
#           - Restart a batch pull from the beginning
#       SetDebugLevel()
#           - Determine verbosity
#
#   Copyright 2014-2019 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#

namespace ScoutLib;

use DOMNode;
use SimpleXmlElement;

class OAIClient
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param string $ServerUrl URL of target OAI repository server.
     * @param string $Cache Name of directory to use to store cached content.
     *       (OPTIONAL)
     */
    public function __construct($ServerUrl, $Cache = null)
    {
        # set default debug level
        $this->DebugLevel = 0;

        # save OAI server URL
        $this->ServerUrl = $ServerUrl;

        # set default metadata prefix
        $this->MetadataPrefix = "oai_dc";

        # set default set specification for queries
        $this->SetSpec = null;

        $this->CacheSequenceNumber = 0;
        if ($Cache !== null) {
            $this->Cache = $Cache;
            if (!is_dir($Cache)) {
                mkdir($Cache);
            }
        }
    }

    /**
     * Get or set URL of target OAI repository server.
     * @param string $NewValue New URL of target OAI repository server. (OPTIONAL)
     * @return current URL of target OAI repository server
     */
    public function serverUrl($NewValue = null)
    {
        if ($NewValue != null) {
            $this->ServerUrl = $NewValue;
        }
        return $this->ServerUrl;
    }

    /**
     * Get or set metadata schema for records being retrieved.
     * @param string $NewValue New metadata prefix.  (OPTIONAL)
     * @return current metadata prefix
     */
    public function metadataPrefix($NewValue = null)
    {
        if ($NewValue != null) {
            $this->MetadataPrefix = $NewValue;
        }
        return $this->MetadataPrefix;
    }

    /**
     * Get or set specification of subset of records to be retrieved.
     * @param string $NewValue New set specification.  (OPTIONAL)
     * @return current set specification
     */
    public function setSpec($NewValue = "X-NOSETSPECVALUE-X")
    {
        if ($NewValue != "X-NOSETSPECVALUE-X") {
            $this->SetSpec = $NewValue;
        }
        return $this->SetSpec;
    }

    /**
     * Retrieve identification information from repository server.
     * Information is returned as associative array with the following
     * indexes:  "Name", "Email", "URL".
     *
     * @return array containing identification info
     */
    public function getIdentification()
    {
        # query server for XML text
        $XmlText = $this->PerformQuery("Identify");
        $this->DebugOutVar(8, __METHOD__, "XmlText", htmlspecialchars($XmlText));

        # convert XML text into object
        $Xml = simplexml_load_string($XmlText);
        $this->DebugOutVar(9, __METHOD__, "Xml", $Xml);

        # if identification info was found
        $Info = array();
        if (isset($Xml->Identify)) {
            # extract info
            $Ident = $Xml->Identify;
            $this->GetValFromXml($Ident, "repositoryName", "Name", $Info);
            $this->GetValFromXml($Ident, "adminEmail", "Email", $Info);
            $this->GetValFromXml($Ident, "baseURL", "URL", $Info);
        }

        # return info to caller
        return $Info;
    }

    /**
     * Retrieve list of available metadata formats from repository server.
     *
     * @return array containing list of available metadata formats
     */
    public function getFormats()
    {
        # query server for XML text
        $XmlText = $this->PerformQuery("ListMetadataFormats");
        $this->DebugOutVar(8, __METHOD__, "XmlText", htmlspecialchars($XmlText));

        # convert XML text into object
        $Xml = simplexml_load_string($XmlText);
        $this->DebugOutVar(9, __METHOD__, "Xml", $Xml);

        # if format info was found
        $Formats = array();
        if (isset($Xml->ListMetadataFormats->metadataFormat)) {
            # extract info
            $Index = 0;
            foreach ($Xml->ListMetadataFormats->metadataFormat as $Format) {
                $this->GetValFromXml(
                    $Format,
                    "metadataPrefix",
                    "Name",
                    $Formats[$Index]
                );
                $this->GetValFromXml(
                    $Format,
                    "schema",
                    "Schema",
                    $Formats[$Index]
                );
                $this->GetValFromXml(
                    $Format,
                    "metadataNamespace",
                    "Namespace",
                    $Formats[$Index]
                );
                $Index++;
            }
        }

        # return info to caller
        return $Formats;
    }

    /**
     * Retrieve records from repository server.
     *
     * @param string $StartDate Start of date range for retrieval  (optional)
     * @param string $EndDate End of date range for retrieval (optional)
     * @return array of records returned from repository
     */
    public function getRecords($StartDate = null, $EndDate = null)
    {
        # if we're using a cache directory, figure out which file
        # should contain this set of records
        if ($this->Cache !== null) {
            $cache_fname = sprintf(
                "%s/%010x",
                $this->Cache,
                $this->CacheSequenceNumber
            );
            $this->CacheSequenceNumber++;
        }

        # when we're not using a cache or don't have a cached copy of
        # this set of records, query the OAI provider to get it
        if ($this->Cache === null || !file_exists($cache_fname)) {
            # if we have resumption token from prior query
            if (isset($this->ResumptionToken)) {
                # use resumption token as sole argument
                $Args["resumptionToken"] = $this->ResumptionToken;
            } else {
                # set up arguments for query
                $Args["metadataPrefix"] = $this->MetadataPrefix;
                if ($StartDate) {
                    $Args["from"] = $StartDate;
                }
                if ($EndDate) {
                    $Args["until"] = $EndDate;
                }
                if ($this->SetSpec) {
                    $Args["set"] = $this->SetSpec;
                }
            }

            # query server for XML text
            $XmlText = $this->PerformQuery("ListRecords", $Args);

            # if a cache is in use, save this chunk of XML into it
            if ($this->Cache !== null) {
                file_put_contents($cache_fname, $XmlText);
            }
        } else {
            # get XML text from the cache
            $XmlText = file_get_contents($cache_fname);
        }

        $this->DebugOutVar(8, __METHOD__, "XmlText", htmlspecialchars($XmlText));

        return $this->GetRecordsFromXML($XmlText, "ListRecords");
    }

    /**
     * Get a single record from a repositry server
     *
     * NOTE: due to the history and politics involved, it is generally
     * preferable to use GetRecords() to pull a full dump from the
     * remote provider and then filter that to get a subset.  The
     * thinking here is that pulling in batches will result in fewer
     * queries to the remote, which is kinder to their hardware.  Pull
     * single records with caution, when only a small number of them
     * are required.
     *
     * @param mixed $Id The unique identifier of the desired record
     * @return array of records (zero or one entries) returned
     */
    public function getRecord($Id)
    {
        $Args["metadataPrefix"] = $this->MetadataPrefix;
        $Args["identifier"] = $Id;

        # query server for XML text
        $XmlText = $this->PerformQuery("GetRecord", $Args);
        $this->DebugOutVar(8, __METHOD__, "XmlText", htmlspecialchars($XmlText));

        return $this->GetRecordsFromXML($XmlText, "GetRecord");
    }

    /**
     * Check whether more records are available after last GetRecords().
     *
     * @return TRUE if more records are available, otherwise FALSE
     */
    public function moreRecordsAvailable()
    {
        return isset($this->ResumptionToken) ? true : false;
    }

    /**
     * Clear any additional records available after last GetRecords().
     */
    public function resetRecordPointer()
    {
        unset($this->ResumptionToken);
        $this->CacheSequenceNumber = 0;
    }

    /**
     * Set current debug output level.
     *
     * @param int $NewLevel Numerical debugging output level (0-9)
     */
    public function setDebugLevel($NewLevel)
    {
        $this->DebugLevel = $NewLevel;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $ServerUrl;
    private $MetadataPrefix;
    private $SetSpec;
    private $DebugLevel;
    private $ResumptionToken;
    private $Cache;
    private $CacheSequenceNumber;

    /**
     * Perform OAI query and return resulting data to caller.
     * @param string $QueryVerb OAI query command (verb).
     * @param array $Args Arguments for query, with argument names for the
     *       index.  (OPTIONAL)
     */
    private function performQuery($QueryVerb, $Args = null)
    {
        # open stream to OAI server

        if (strpos($this->ServerUrl, "?") === false) {
            $QueryUrl = $this->ServerUrl . "?verb=" . $QueryVerb;
        } else {
            $QueryUrl = $this->ServerUrl . "&verb=" . $QueryVerb;
        }

        if ($Args) {
            foreach ($Args as $ArgName => $ArgValue) {
                $QueryUrl .= "&" . urlencode($ArgName) . "=" . urlencode($ArgValue);
            }
        }
        $FHndl = fopen($QueryUrl, "r");

        # if stream was successfully opened
        $Text = "";
        if ($FHndl !== false) {
            # while lines left in response
            while (!feof($FHndl)) {
                # read line from server and add it to text to be parsed
                $Text .= fread($FHndl, 10000000);
            }
        }

        # close OAI server stream
        fclose($FHndl);

        # return query result data to caller
        return $Text;
    }

    /**
     * Set array value if available in simplexml object.
     * @param object $Xml XML stream.
     * @param string $SrcName Name of source element.
     * @param string $DstName Name of destination element.
     * @param array $Results Array to set.
     */
    private function getValFromXml($Xml, $SrcName, $DstName, &$Results)
    {
        if (isset($Xml->$SrcName)) {
            $Results[$DstName] = trim($Xml->$SrcName);
        }
    }

    /**
     * Print variable contents if debug is above specified level.
     * @param int $Level Debug level.
     * @param string $MethodName Name of method.
     * @param string $VarName Name of variable.
     * @param mixed $VarValue Value of variable.
     */
    private function debugOutVar($Level, $MethodName, $VarName, $VarValue)
    {
        if ($this->DebugLevel >= $Level) {
            print("\n<pre>" . $MethodName . "()  " . $VarName . " = \n");
            print_r($VarValue);
            print("</pre>\n");
        }
    }

    // @codingStandardsIgnoreStart
    /*
    * Pull records out of an XML DOMNode.
    *
    * Data converted from XML will be added to
    * $Records[$Index][$Section], with the XML from the DOM node
    * flattened.  For example, if we were to call
    * ExtractDataFromXml($Records, 0, $dom, "metadata") with $dom
    * pointing to XML like this and $Records initially empty:
    *
    * @code
    * <record xmlns="http://ns.nsdl.org/ncs/lar"
    *         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    *        xsi:schemaLocation="http://ns.nsdl.org/ncs/lar http://ns.nsdl.org/ncs/lar/1.00/schemas/lar.xsd">
    *   <recordID>2200/20121012134026795T</recordID>
    *   <recordDate>2012-07-24</recordDate>
    *   <identifier>http://chemteacher.chemeddl.org/services/chemteacher/index.php?option=com_content&amp;view=article&amp;id=77</identifier>
    *   <title>ChemTeacher: Periodic Table Resource Pak</title>
    *   <license>
    *     <name URL="http://creativecommons.org/licenses/by-sa/3.0/">Creative commons:Attribution share alike (by-sa)</name>
    *     <property>Attribution required</property>
    *     <property>Educational use only</property>
    *     <property>Share alike required</property>
    *   </license>
    * </record>
    * @endcode
    *
    * After the call, print_r($Records) would produce something like:
    * @code
    * Array
    *   (
    *     [0] => Array
    *       (
    *         [metadata] => Array
    *           (
    *             [recordID] => Array ( [0] => 2200/20121012134026795T )
    *             [recordDate] => Array ( [0] => 2012-07-24 )
    *             [identifier] => Array
    *               (
    *                 [0] => http://chemteacher.chemeddl.org/services/chemteacher/index.php?option=com_content&view=article&id=77
    *               )
    *             [title] => Array ( [0] => ChemTeacher: Periodic Table Resource Pak )
    *             [license/name] => Array ( [0] => Creative commons:Attribution share alike (by-sa) )
    *             [license/property] => Array
    *               (
    *                 [0] => Attribution required
    *                 [1] => Educational use only
    *                 [2] => Share alike required
    *               )
    *           )
    *      )
    *  )
    * @endcode
    *
    * @param array $Records to place data in.
    * @param int $Index record number to populate
    * @param DOMNode $dom to extract data from
    * @param string $Section section of the record to populate (e.g.,
    *     metadata, about)
    * @param string $ParentTagName parent tag or null for the root of
    *     this record, should only be non-null when called recurisvely
    *     (OPTIONAL, default NULL)
    */
    private function extractDataFromXml(&$Records, $Index, DOMNode $dom,
                                        $Section, $ParentTagName = NULL)
    {
        foreach ($dom->childNodes as $node) {
            # for DOM children that are elements (rather than comments, text,
            #       or something else)
            if ($node->nodeType == XML_ELEMENT_NODE) {
                # compute a tag name to use
                $StorageTagName =
                    (($ParentTagName !== NULL) ? $ParentTagName . "/" : "")
                    . $node->nodeName;

                # Glue together the contents of the 'text' children of this node
                $Value = "";
                foreach ($node->childNodes as $child) {
                    if ($child->nodeType == XML_TEXT_NODE) {
                        $Value .= $child->nodeValue;
                    }
                }

                # if we had a non-empty value, add it to the results
                if (strlen(trim($Value)) > 0) {
                    $Records[$Index][$Section][$StorageTagName][] = $Value;
                }

                # and process our children
                $this->ExtractDataFromXml($Records, $Index,
                    $node, $Section, $StorageTagName);
            }
        }
    }
    // @codingStandardsIgnoreEnd

    /**
     * Find and return the first child of a DOMNode that is an Element.
     * @param object $dom Node of interest
     * @return DOMNode Node for the first child that was an XML Element, or
     *       NULL if one was not found.
     */
    private function getFirstElement(DOMNode $dom)
    {
        foreach ($dom->childNodes as $child) {
            if ($child->nodeType == XML_ELEMENT_NODE) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Extract records from OAI-PMH XML data.
     *
     * In the result, each record is represented as an array having
     * elements "identifier", "datestamp", "format", and "metadata".
     * It may also have an "about" element, if the remote provider
     * gives that information.  Contents of "metadata" and "about" are
     * both flattened representations of the OAI-PMH XML data, in the
     * format output by ExtractDataFromXml().
     *
     * @param string $XmlText XML response from the OAI provider.
     * @param string $ParseTo Name of the tag for records.
     * @return array Array of records on success or NULL if the provided XML
     *   could not be parsed.
     * @see ExtractDataFromXml()
     */
    private function getRecordsFromXML($XmlText, $ParseTo)
    {
        # create XML parser and pass it text
        $Xml = simplexml_load_string($XmlText);

        # if text could not be parsed, return NULL
        if (!$Xml instanceof SimpleXmlElement) {
            return null;
        }

        # set up vars to hold our results
        $Records = array();
        $Index = 0;

        # we'll want to find our records with XPath, so we need to
        # register a prefix for the oai elements
        $Xml->registerXPathNamespace('oai', "http://www.openarchives.org/OAI/2.0/");

        # extract records, iterate over them
        $RecordXML = $Xml->xpath("oai:" . $ParseTo . "//oai:record");
        foreach ($RecordXML as $Record) {
            # pull relevant information out of the header
            #
            # Note that SimpleXMLElement objects map elements onto PHP
            # object properties, and will return a SimpleXMLElement w/o
            # any associated XML for non-existent elements.  So,
            # nothing explodes when we ask the Record for an element it
            # did not contain.
            #
            # However, SimpleXMLElements w/o associated XML return
            # 'NULL' for all properties.  Therefore, if we tried to
            # look at the grandchild of a non-existent element it would
            # be problematic.  In the cases below, we get empty
            # strings when the children of 'header' &c are empty, which
            # is what we want anyway.

            $Records[$Index]["identifier"] = (string)$Record->header->identifier;
            $Records[$Index]["datestamp"] = (string)$Record->header->datestamp;

            # grab associated meadata (if there is any)
            if ($Record->metadata->count() > 0) {
                # to avoid frustrations with namespaces and SimpleXML, use
                # DOMDocument to parse  the record data
                $doc = dom_import_simplexml($Record->metadata);

                # get the 'record' element
                $doc = $this->GetFirstElement($doc);

                # record the format used for this record
                $Records[$Index]["format"] = $doc->nodeName;

                # extract data for this record
                $this->ExtractDataFromXml($Records, $Index, $doc, "metadata");
            }

            # if there is additional information available, snag that too
            if ($Record->about->count() > 0) {
                $doc = dom_import_simplexml($Record->about);
                $this->ExtractDataFromXml($Records, $Index, $doc, "about");
            }

            # move along to the next record
            $Index++;
        }

        # look for resumption token and save if found (as above, we'll
        # get an empty string if either ListRecords or resumptionToken
        # are absent)
        $Token = (string)$Xml->ListRecords->resumptionToken;

        if (strlen($Token) > 0) {
            $this->ResumptionToken = $Token;
        } else {
            unset($this->ResumptionToken);
        }

        # return records to caller
        return $Records;
    }
}
