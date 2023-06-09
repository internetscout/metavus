<?PHP
#
#   FILE: InvalidUrl.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

use Exception;
use Metavus\MetadataSchema;
use Metavus\MetadataField;

class InvalidUrl
{
    /**
     * Constructor: set data members from the given array.
     * @param array $Data Name/value array
     */
    public function __construct(array $Data)
    {
        foreach ($Data as $Key => $Value) {
            # some basic validation
            if (isset($this->$Key)) {
                $this->$Key = $Value;
            }
        }

        if (!Record::itemExists($this->RecordId)) {
            throw new Exception(
                "InvalidUrl constructed for a RecordId "
                ."(".$this->RecordId.") that does not exist."
            );
        }

        if (!MetadataSchema::fieldExistsInAnySchema($this->FieldId)) {
            throw new Exception(
                "InvalidUrl constructed for a FieldId "
                ."(".$this->FieldId.") that does not exist."
            );
        }

        $this->SchemaId = $this->getAssociatedResource()->getSchemaId();

        $Field = new MetadataField($this->FieldId);
        if ($Field->schemaId() != $this->SchemaId) {
            throw new Exception(
                "InvalidUrl constructed for FieldId ".$this->FieldId
                ." and RecordId ".$this->RecordId." that are from"
                ." different schemas (".$Field->schemaId()." vs "
                .$this->SchemaId.")"
            );
        }
    }

    /**
     * Return a resource with the ID specified by the invalid URL.
     * @return Record Associated resource.
     */
    public function getAssociatedResource()
    {
        return new Record($this->RecordId);
    }

    /**
     * Return a metadata field with the ID specified by the invalid URL.
     * @return MetadataField Associated metadata field.
     */
    public function getAssociatedField()
    {
        return new MetadataField($this->FieldId);
    }

    public $RecordId = -1;
    public $FieldId = -1;
    public $Hidden = 0;
    public $CheckDate = "";
    public $TimesInvalid = 0;
    public $Url = "";
    public $StatusCode = -1;
    public $ReasonPhrase = "";
    public $IsFinalUrlInvalid = 0;
    public $FinalUrl = "";
    public $FinalStatusCode = -1;
    public $FinalReasonPhrase = "";
    public $Time = 0;

    private $SchemaId = null;
}
