<?PHP
#
#   FILE:  OAIItem.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\OAIPMHServer;
use InvalidArgumentException;
use Metavus\Image;
use Metavus\MetadataSchema;
use Metavus\Record;
use Metavus\Qualifier;
use Metavus\InterfaceConfiguration;
use ScoutLib\ApplicationFramework;
use ScoutLib\Date;

/**
 * Class for items served via the OAI-PMH Server plugin.
 */
class OAIItem implements \ScoutLib\OAIItem
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     * @param int $ItemId ID of item.
     * @param array $RepDescr Repository description info.
     * @param array $SearchInfo Additional search data to be included with
     *       item metadata.  (OPTIONAL)
     * @throws InvalidArgumentException in case Item ID is invalid
     */
    public function __construct($ItemId, array $RepDescr, $SearchInfo = null)
    {
        # save ID for later use
        $this->Id = $ItemId;

        # save any search info supplied
        $this->SearchInfo = $SearchInfo;

        # save the repository description
        $this->RepDescr = $RepDescr;

        # if resource ID was invalid
        if (!Record::itemExists($ItemId)) {
            # throw exception with reason to indicate constructor failure
            throw new InvalidArgumentException("Item ID is invalid.");
        } else {
            # create resource object
            $this->Resource = new Record($ItemId);

            # if cumulative rating data is available for this resource
            if (InterfaceConfiguration::getInstance()->getBool("ResourceRatingsEnabled")
                    && $this->Resource->cumulativeRating()) {
                # add cumulative rating data to search info
                $this->SearchInfo["cumulativeRating"] =
                        $this->Resource->cumulativeRating();
                $this->SearchInfo["cumulativeRatingScale"] = 100;
            }
        }
    }

    /**
     * Retrieve item ID.
     * @return int Item ID.
     */
    public function getId()
    {
        return $this->Id;
    }

    /**
     * Retrieve date stamp associated with item.
     * @return string Date stamp in ISO-8601 format.
     */
    public function getDatestamp()
    {
        $DateString = $this->Resource->get("Date Of Record Creation");
        if (!isset($DateString) ||
            $DateString == "0000-00-00 00:00:00") {
            $DateString = date("Y-m-d");
        }
        $Date = new Date($DateString);
        return $Date->formattedISO8601();
    }

    /**
     * Retrieve value for specified element.
     * @param string $ElementName Name of element.
     * @return mixed Value for element (string or may be an array if
     *       element is for a Reference field).
     */
    public function getValue($ElementName)
    {
        $AF = ApplicationFramework::getInstance();

        # if requested value is a preferred link value
        if ($ElementName == -3) {
            $ReturnValue = $this->getPreferredLinkValueForResource($this->Resource);
        # if requested value is full record page URL
        } elseif ($ElementName == -2) {
            # set value to full record page URL
            $ReturnValue = $this->getFullRecordUrlForResource($this->Resource->id());
        # if requested value is fixed default
        } elseif ($ElementName == -3) {
            $ReturnValue = null;
        } else {
            # retrieve value
            $ReturnValue = $this->Resource->get($ElementName);

            # this isn't technically necessary for the checks below, but it
            # reduces some overhead when the field obviously isn't a reference
            if (is_array($ReturnValue)) {
                $Schema = new MetadataSchema();
                $Field = $Schema->getField($ElementName);

                # if the field is a reference field
                if ($Field->type() == MetadataSchema::MDFTYPE_REFERENCE) {
                    # translate each resource ID to an OAI identifier
                    foreach ($ReturnValue as $Key => $Value) {
                        $ReturnValue[$Key] = $this->getOaiIdentifierForResource(
                            $Value
                        );
                    }
                }

                if ($Field->type() == MetadataSchema::MDFTYPE_IMAGE) {
                    foreach ($ReturnValue as $Key => $Value) {
                        $Image = new Image($Value);
                        $ReturnValue[$Key] = $AF->baseUrl()
                            .$Image->url('mv-image-large');
                    }
                }
            }

            # strip out any HTML tags if text value
            if (is_string($ReturnValue)) {
                $ReturnValue = strip_tags($ReturnValue);
            }

            # format correctly if standardized date
            if ($this->getQualifier($ElementName) == "W3C-DTF") {
                if (!is_null($ReturnValue)) {
                    $Timestamp = strtotime($ReturnValue);
                    $ReturnValue = date('Y-m-d\TH:i:s', $Timestamp)
                        .substr_replace(date('O', $Timestamp), ':', 3, 0);
                }
            }
        }

        # return value to caller
        return $ReturnValue;
    }

    /**
     * Retrieve qualifier for specified element.
     * @param string $ElementName Name of element.
     * @return string|array|null Qualifier or array of qualifiers for element,
     *      or NULL if invalid qualifier name supplied.
     */
    public function getQualifier($ElementName)
    {
        if (is_numeric($ElementName) && $ElementName < 0) {
            return null;
        }

        $ReturnValue = null;
        $Qualifier = $this->Resource->getQualifier($ElementName, true);
        if (is_array($Qualifier)) {
            foreach ($Qualifier as $ItemId => $QualObj) {
                if ($QualObj instanceof Qualifier) {
                    $ReturnValue[$ItemId] = $QualObj->name();
                }
            }
        } else {
            if (isset($Qualifier) && ($Qualifier instanceof Qualifier)) {
                $ReturnValue = $Qualifier->name();
            }
        }
        return $ReturnValue;
    }

    /**
     * Retrieve list of sets to which this item belongs.
     * @return array List of sets (strings).
     */
    public function getSets()
    {
        # start out with empty list
        $Sets = [];

        # for each possible metadata field
        $Schema = new MetadataSchema();
        $Fields = $Schema->getFields(MetadataSchema::MDFTYPE_TREE
                | MetadataSchema::MDFTYPE_CONTROLLEDNAME
                | MetadataSchema::MDFTYPE_OPTION);
        foreach ($Fields as $Field) {
            # if field is flagged for use for OAI sets
            if ($Field->useForOaiSets()) {
                # retrieve values for resource for this field and add to set list
                $FieldName = $Field->name();
                $Values = $this->Resource->get($FieldName);
                if (!is_null($Values)) {
                    $NormalizedFieldName = $this->normalizeForSetSpec($FieldName);
                    if (is_array($Values)) {
                        if (count($Values) > 0) {
                            foreach ($Values as $Value) {
                                $Sets[] = $NormalizedFieldName.":"
                                    .$this->normalizeForSetSpec($Value);
                            }
                        }
                    } else {
                        $Sets[] = $NormalizedFieldName.":"
                            .$this->normalizeForSetSpec($Values);
                    }
                }
            }
        }

        # return list of sets to caller
        return $Sets;
    }

    /**
     * Retrieve additional search info, if any.  (Passed in via constructor.)
     * @return array Search info.
     */
    public function getSearchInfo()
    {
        return $this->SearchInfo;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $Id;
    private $Resource;
    private $RepDescr;
    private $SearchInfo;

    /**
     * Normalize value for use as an OAI set spec.
     * @param string $Name Value to normalize.
     * @return string Normalized value.
     */
    protected function normalizeForSetSpec($Name)
    {
        return preg_replace("/[^a-zA-Z0-9\-_.!~*'()]/", "", $Name);
    }

    /**
     * Get the URL to the full record of a resource.
     * @param int $ResourceId Resource ID.
     * @return string The URL to the full record of the resource.
     * @see GetOaiIdentifierForResource()
     */
    protected function getFullRecordUrlForResource($ResourceId)
    {
        $AF = ApplicationFramework::getInstance();
        $SafeResourceId = urlencode((string)$ResourceId);
        return ApplicationFramework::baseUrl() .
            $AF->getCleanRelativeUrlForPath(
                "index.php?P=FullRecord&ID=" . $SafeResourceId
            );
    }

    /**
     * Get the Preferred Link Value for a resource.
     * @param Record $Resource Resource to use.
     * @return string URL corresponding to the pref. link value.
     */
    protected function getPreferredLinkValueForResource($Resource)
    {
        $AF = ApplicationFramework::getInstance();
        $IntConfig = InterfaceConfiguration::getInstance();

        # start off assuming no result
        $Result = "";

        # pull the mapped URL and File fields from the schema
        $Schema = new MetadataSchema();
        $UrlField = $Schema->getFieldByMappedName("Url");
        $FileField = $Schema->getFieldByMappedName("File");

        $Url = ( !is_null($UrlField) &&
                 $UrlField->status() === MetadataSchema::MDFSTAT_OK )
            ? $Resource->getForDisplay($UrlField) : "";
        $Files = ( !is_null($FileField) &&
                   $FileField->status() === MetadataSchema::MDFSTAT_OK )
            ? $Resource->getForDisplay($FileField) : [];

        # figure out what the preferred link should be
        if (is_array($Files) && count($Files) > 0
            && ($IntConfig->getString("PreferredLinkValue") == "FILE"
                || $IntConfig->getString("PreferredLinkValue") == "")) {
            # if we prefer files, use the first one
            $LinkFile = array_shift($Files);
            $Result =  ApplicationFramework::baseUrl() .
                $AF->getCleanRelativeUrlForPath(
                    $LinkFile->GetLink()
                );
        } elseif (is_string($Url) && strlen($Url) > 0) {
            # otherwise, if there's a sane-looking URL, use that
            if (preg_match('/^\s*[a-zA-Z]+:\/\//', $Url)) {
                $Result = $Url;
            }
        }

        return $Result;
    }

    /**
     * Get the OAI identifier of a resource.
     * @param int $ResourceId Resource ID.
     * @return string The OAI identifier of a resource.
     * @see GetFullRecordUrlForResource()
     */
    protected function getOaiIdentifierForResource($ResourceId)
    {
        # return encoded value to caller
        return "oai:".$this->RepDescr["IDDomain"]
                .":".$this->RepDescr["IDPrefix"]."-".$ResourceId;
    }
}
