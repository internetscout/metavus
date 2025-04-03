<?PHP
#
#   FILE:  ExifTags.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2023-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\ControlledName;
use Metavus\Folder;
use Metavus\Image;
use Metavus\Plugin;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\Date;
use ScoutLib\PluginManager;

/**
 * Populate metadata fields on records with EXIF tag values from images
 * uploaded to records.
 */
class ExifTags extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

   /**
    * Set the plugin attributes.
    * @return void
    */
    public function register(): void
    {
        $this->Name = "EXIF Tag Data Importer";
        $this->Version = "1.0.0";
        $this->Description = "Extracts information from uploaded image files"
                ." and places it into metadata fields in the associated record.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
        ];
        $this->EnabledByDefault = false;
    }

    # ---- CALLABLE METHODS --------------------------------------------------

   /**
    * Install the plugin.
    * @NULL|string NULL if successful; otherwise, an error message.
    */
    public function install(): ?string
    {
        $this->setConfigSetting("ExifTags", $this->SupportedExifTags);

        # creates empty arrays for tag mappings per schema
        $Mappings = $this->createInitialMappings();
        $this->setConfigSetting("FieldMappings", $Mappings);

        return null;
    }

   /**
    * Register an observer on all image fields to extract EXIF tag values
    * from images saved to those fields and assign the tag data to
    * the associated record's metadata fields based on mappings configured
    * for the record's schema.
    * @return NULL if initialization is successful or an error message
    *         describing why if it was not.
    */
    public function initialize(): ?string
    {
        foreach (MetadataSchema::getAllSchemas() as $Schema) {
            $ImageFields = $Schema->getFields(MetadataSchema::MDFTYPE_IMAGE);

            foreach ($ImageFields as $Index => $ImageField) {
                MetadataField::registerObserver(
                    MetadataField::EVENT_ADD,
                    [$this, "observeImageAdditions"],
                    $ImageField->id()
                );
            }
        }
        $this->addAdminMenuEntry(
            "ConfigureMappings",
            "Configure EXIF Tag Import",
            [ PRIV_COLLECTIONADMIN ]
        );
        return null;
    }

    const MDFTYPES_TO_INCLUDE = [
        MetadataSchema::MDFTYPE_NUMBER,
        MetadataSchema::MDFTYPE_TEXT,
        MetadataSchema::MDFTYPE_DATE,
        MetadataSchema::MDFTYPE_TIMESTAMP,
        MetadataSchema::MDFTYPE_FLAG,
        MetadataSchema::MDFTYPE_CONTROLLEDNAME,
        MetadataSchema::MDFTYPE_OPTION,
        MetadataSchema::MDFTYPE_POINT,
        MetadataSchema::MDFTYPE_PARAGRAPH
    ];

   /**
    * Add a post processing call to a callback method to extract EXIF tag
    * values from the specified image and add it to the specified record.
    * @param int $Events MetadataField::EVENT* values OR'd together.
    * @param int $RecordId ID of the record to assign mapped EXIF tag values to.
    * @param MetadataField $Field Metadata field the image was saved to.
    * @param array $ImageIds ID(s) of image(s) saved to $Field.
    */
    public function observeImageAdditions(
        int $Events,
        int $RecordId,
        MetadataField $Field,
        array $ImageIds
    ): void {
        $AF = ApplicationFramework::getInstance();

        foreach (array_values($ImageIds) as $ImageId) {
            # uses ApplicationFramework::addPostProcessingCall() to apply tag
            # values *after* all other values from the record edit form have
            # been saved to prevent imported values from being overwritten by
            # blank form fields above the image field on the form

            $AF->addPostProcessingCall(
                [$this, "extractExifDataAndAddToRecord"],
                $ImageId,
                $RecordId
            );
        }
    }

   /**
    * Check that the mappings configured from EXIF GPS Tags to metadata fields
    * are valid. Throw an exception if invalid mappings are found.
    * @param string $GpsTimeIndex String specifying which EXIF tag contains the
    *         value of the GPS timestamp.
    * @param string $GpsDateIndex String specifying which EXIF tag contains the
    *         value of the GPS datestamp.
    * @param string $LatTagIndex String specifying which EXIF tag contains the
    *         value of the GPS latitude .
    * @param string $LngTagIndex String specifying which EXIF tag contains the
    *         value of the GPS longitude.
    * @throws Exception describing a problem with mappings configured for GPS
    *         tags.
    */
    public function checkThatMappingConfigurationIsValid(
        string $GpsTimeIndex,
        string $GpsDateIndex,
        string $LatTagIndex,
        string $LngTagIndex
    ): void {
        # GPS timestamp tag value mapped to a timestamp field requires GPS
        # datestamp be mapped to the same field
        # (GPS datestamp is valid on its own as a date)
        $GpsTimeFieldIds = $this->getMetadataFieldIdsForExifTag($GpsTimeIndex);
        $GpsDateFieldIds = $this->getMetadataFieldIdsForExifTag($GpsDateIndex);

        # if any fields that the GPS timestamp tag is mapped to *without* the
        # GPS datestamp tag, are timestamp fields, throw an exception
        foreach (array_diff($GpsTimeFieldIds, $GpsDateFieldIds) as $FieldId) {
            $MetadataField = MetadataField::getField($FieldId);
            if ($MetadataField->type() == MetadataSchema::MDFTYPE_TIMESTAMP) {
                throw new Exception("GPS Time Stamp requires that GPS Date"
                        ." Stamp be mapped to the same field(s)"
                        ." for use as a timestamp value.");
            }
        }
        if ($this->getMetadataFieldIdsForExifTag($LatTagIndex)
                != $this->getMetadataFieldIdsForExifTag($LngTagIndex)) {
            throw new Exception("GPS latitude and longitude tags"
                    . " are not mapped to the same field(s).");
        }
    }

   /**
    * Extract EXIF tag data from images on records in a specified folder, and
    * assign the EXIF tag data to metadata fields on the records based on
    * configured mappings from EXIF tags to metadata fields.
    * @param int $FolderId Items in the folder with this ID have EXIF tag data
    *         extracted from images and saved to metadata fields of the records
    *         with those images.
    * @throws Exception if a folder with the specified ID does not exist.
    */
    public function extractExifDataAndAddToRecordsInFolder(int $FolderId): void
    {
        if (!Folder::itemExists($FolderId)) {
            throw new Exception("Folder with ID: ".$FolderId
                    . " does not exist.");
        }

        $Folder = new Folder($FolderId);
        $ImageFields = [];
        foreach ($Folder->getItemIds() as $RecordId) {
            $Record = Record::getRecord($RecordId);
            $Schema = $Record->getSchema();
            if (!isset($ImageFields[$Schema->Id()])) {
                $ImageFields[$Schema->Id()] =
                        $Schema->getFields(MetadataSchema::MDFTYPE_IMAGE);
            }
            foreach ($ImageFields[$Schema->Id()] as $ImageField) {
                if (!$Record->fieldIsSet($ImageField)) {
                    continue;
                }
                $ImageId = $Record->get($ImageField)[0];
                $this->extractExifDataAndAddToRecord($ImageId, $RecordId);
            }
        }
    }

   /**
    * Extract EXIF tag data from an image and assign any EXIF tag values in the
    * configured mappings for the record's schema to metadata fields on the
    * record.
    * @param int $ImageId ID of image to extract EXIF tag data from.
    * @param int $RecordId ID of record to assign EXIF tag data to.
    */
    public function extractExifDataAndAddToRecord(
        int $ImageId,
        int $RecordId
    ): void {
        $this->checkThatMappingConfigurationIsValid(
            "GPS.GPSTimeStamp",
            "GPS.GPSDateStamp",
            "GPS.GPSLatitude",
            "GPS.GPSLongitude"
        );
        $ExifData = $this->extractExifTagsFromImage($ImageId);
        $DataToImport =
                $this->convertExifTagsToMetadataValues($ExifData);
        $this->saveValuesToRecord($DataToImport, $RecordId);
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

   /**
    * Extract an array of EXIF tag values indexed by EXIF tags.
    * @param int $ImageId ID of the image to extract EXIF tags from.
    * @return array Array of EXIF tag values indexed by EXIF tags, empty
    *         if no tags are found.
    */
    private function extractExifTagsFromImage(int $ImageId): array
    {
        $Image = new Image($ImageId);
        $ImageFilePath = $Image->getFullPathForOriginalImage();
        $ExifData = [];
        $ExifDataSections = @exif_read_data($ImageFilePath, null, true);
        if ($ExifDataSections === false) {
            return [];
        }

        # EXIF data is in an array keyed by Section.TagName

        foreach ($ExifDataSections as $SectionName => $Section) {
            foreach ($Section as $Key => $TagValue) {
                if ($TagValue === null) {
                    # NULL tag values exist and should not be imported
                    continue;
                }
                $TagName = $SectionName . "." . $Key;
                $ExifData[$TagName] = $TagValue;
            }
        }
        return $ExifData;
    }

   /**
    * Convert array of EXIF tag values indexed by EXIF tag name into array
    * containing EXIF tag values indexed by metadata field IDs and normalized
    * based on metadata field type.
    * @param array $ExifData Array of EXIF tag data., indexed by EXIF tag.
    * @return array Normalized EXIF tag values indexed by metadata field IDs.
    */
    private function convertExifTagsToMetadataValues(array $ExifData): array
    {
        $NormalizedValues = [];
        foreach ($ExifData as $Tag => $Value) {
            # tags' string values can consist of empty strings, white space,
            # and sequences of unprintable characters
            # these should not be imported from any tag
            if (is_string($Value)) {
                $Value = trim($Value);
                if (!ctype_print($Value)) {
                    continue;
                }
                # value for Tag in the array of EXIF data is reassigned so the
                # trimmed value is normalized, because the whole array of EXIF
                # data is used for normalizing each value
                $ExifData[$Tag] = $Value;
            }

            $FieldIds = $this->getMetadataFieldIdsForExifTag($Tag);
            foreach ($FieldIds as $FieldId) {
                $NormalizedValue =
                        $this->normalizeExifValueToMetadataValue(
                            $Tag,
                            $FieldId,
                            $ExifData
                        );
                # do not add the normalized value if it is already
                # found in the array
                if (!in_array(
                    $NormalizedValue,
                    $NormalizedValues[$FieldId] ?? []
                )) {
                       $NormalizedValues[$FieldId][] = $NormalizedValue;
                }
            }
        }
        return $NormalizedValues;
    }

   /**
    * Convert an EXIF tag value to a normalized metadata field value.
    * @param string $Tag EXIF tag associated with value to normalize.
    * @param int $FieldId ID of metadata field to normalize tag value for.
    * @param array $AllTagValues Full set of EXIF tags and their values present
    *         in the extracted EXIF data. Keyed on the EXIF tag, with EXIF tag
    *         values as values.
    * @return string|int|array Normalized tag value for use in the metadata
    *         field.
    * @throws Exception if the type of field a tag is mapped to is does not
    *         have normalization defined in this method, or if the value to
    *         normalize has an unexpected type for the type of field it is
    *         being normalized for.
    */
    private function normalizeExifValueToMetadataValue(
        string $Tag,
        int $FieldId,
        array $AllTagValues
    ) {
        $Value = $AllTagValues[$Tag];
        $Field = MetadataField::getField($FieldId);
        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
                if (in_array($Tag, ["GPS.GPSLatitude", "GPS.GPSLongitude"])) {
                    $PointValue = $this->convertExifLatLngToPointValue(
                        "GPS.GPSLatitude",
                        "GPS.GPSLongitude",
                        $AllTagValues
                    );
                    return "Latitude: " .$PointValue["X"]
                            . ", Longitude: " . $PointValue["Y"];
                }
                if (in_array($Tag, ["GPS.GPSDateStamp", "GPS.GPSTimeStamp"])) {
                    # if GPS DateStamp and GPS TimeStamp are mapped to the same
                    # text field, combine and normalize them together
                    $GpsDateIsMappedToField = in_array(
                        $FieldId,
                        $this->getMetadataFieldIdsForExifTag("GPS.GPSDateStamp")
                    );
                    $GpsTimeIsMappedToField = in_array(
                        $FieldId,
                        $this->getMetadataFieldIdsForExifTag("GPS.GPSTimeStamp")
                    );
                    if ($GpsDateIsMappedToField && $GpsTimeIsMappedToField) {
                         return $this->convertGpsDateTimeToNormalizedTimestamp(
                             "GPS.GPSDateStamp",
                             "GPS.GPSTimeStamp",
                             $AllTagValues
                         );
                    }
                }
                # tag is GPS.GPSDateStamp or GPS.GPSTimeStamp, but one of them
                # is not mapped to this field
                # GPS.GPSDateStamp is a meaningful string without further
                # manipulation
                # GPS.GPSTimeStamp is an array of 3 fractions
                if ($Tag == "GPS.GPSTimeStamp") {
                    return $this->convertGpsTimestampToString($Value);
                }
                if (is_numeric($Value)) {
                    return strval($Value);
                }
                return $Value;
            # logic for option and controlled name fields is the same:
            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            # fall through intended
            case MetadataSchema::MDFTYPE_OPTION:
                if (is_array($Value)) {
                    throw new Exception("Array value is not valid for option field.");
                }
                # create returns the ID of any existing controlled name
                # or option for field with the provided value or creates a new
                # one if one matching the provided value does not currently
                # exist, then returns the ID of the newly created controlled name
                $CName = ControlledName::create(strval($Value), $FieldId);
                return $CName->id();
            case MetadataSchema::MDFTYPE_FLAG:
                if (!(is_int($Value)) || !(in_array($Value, [0,1]))) {
                    # valid flag value will be either 1 or 0
                    throw new Exception("Invalid tag value for flag field. ".
                            "Flag values must be integers, 1 or 0");
                }
                return $Value;
            case MetadataSchema::MDFTYPE_DATE:
                if (is_array($Value)) {
                    throw new Exception("Array value is not valid for date fields.");
                }
                # GPS datestamp uses ":" as delimiter, Metavus dates use "-"
                $Value = str_replace(":", "-", strval($Value));
                if (!Date::isValidDate($Value)) {
                    throw new Exception("Tag value is not a valid date.");
                }
                return $Value;
            case MetadataSchema::MDFTYPE_TIMESTAMP:
                if (in_array($Tag, ["GPS.GPSDateStamp", "GPS.GPSTimeStamp"])) {
                    return $this->convertGpsDateTimeToNormalizedTimestamp(
                        "GPS.GPSDateStamp",
                        "GPS.GPSTimeStamp",
                        $AllTagValues
                    );
                }
                if (is_array($Value)) {
                    throw new Exception("Array value is not valid for timestamp
                            fields other than GPS TimeStamp.");
                }
                if (!strtotime(strval($Value))) {
                    throw new Exception("Tag value is not a valid timestamp.");
                }
                return $Value;
            case MetadataSchema::MDFTYPE_NUMBER:
                if (is_string($Value)) {
                    # numbers can be stored as a fraction in a string
                    return intval($this->convertFractionToFloat($Value));
                }
                return $Value;
            case MetadataSchema::MDFTYPE_POINT:
                return $this->convertExifLatLngToPointValue(
                    "GPS.GPSLatitude",
                    "GPS.GPSLongitude",
                    $AllTagValues
                );
        }
        throw new Exception("Field type ".$Field->typeAsName(). " is not "
                ."recognized.");
    }

   /**
    * Combine and convert EXIF GPS datestamp and GPS timestamp tag values to a
    * normalized Metavus timestamp, which includes date and time.
    * @param string $GpsDateIndex Index of GPS datestamp tag in array of EXIF
    *         tag values.
    * @param string $GpsTimeIndex Index of GPS timestamp tag in array of EXIF
    *         tag values.
    * @param array $TagValues Array of EXIF tag values, indexed by EXIF tags.
    * @return string Normalized timestamp value from combining arguments.
    * @throws Exception if the GPS datestamp is missing.
    */
    private function convertGpsDateTimeToNormalizedTimestamp(
        string $GpsDateIndex,
        string $GpsTimeIndex,
        array $TagValues
    ): string {
        # GPS timestamp must be combined with GPS datestamp for use as timestamp
        # field value
        if (!array_key_exists($GpsDateIndex, $TagValues)) {
            throw new Exception("GPS date is missing, cannot produce valid ".
                "timestamp with GPS time alone.");
        }

        # make the tag value a valid date by replacing ":" with "-"
        $DateValue = str_replace(":", "-", $TagValues[$GpsDateIndex]);

        # GPS datestamp tag mapped to a timestamp field without GPS timestamp
        # produces a timestamp for midnight on the specified date
        if (!array_key_exists($GpsTimeIndex, $TagValues)) {
            return $DateValue;
        }
        $TimeValue =
                $this->convertGpsTimestampToString($TagValues[$GpsTimeIndex]);
        return $DateValue . " " . $TimeValue;
    }

   /**
    * Convert value of the GPS timestamp EXIF tag from an array of 3 fractions
    * to a string that represents a time.
    * (Unlike other timestamps in EXIF data, the value for GPS.TimeStamp really
    * *is* stored as 3 fractions specifying hour, minute and seconds.)
    * @param array $GpsTimestampValue EXIF tag value containing GPS Timestamp.
    * @return string String containing a valid time.
    */
    private function convertGpsTimestampToString(
        array $GpsTimestampValue
    ): string {
        $NumericHms = [];
        for ($I = 0; $I < 3; $I++) {
            # round to the nearest integer
            $NumericTimeValue =
                    $this->convertFractionToFloat($GpsTimestampValue[$I]);
            $Number = round($NumericTimeValue);
            # valid DD/MM numbers for dates have exactly 2 digits
            $NumericHms[] =
                    str_pad(strval($Number), 2, "0", STR_PAD_LEFT);
        }
        return  implode(":", $NumericHms);
    }

   /**
    * Get the ID(s) for metadata field(s) that an EXIF tag is mapped to.
    * @param string $Tag EXIF tag to get the IDs of metadata fields it is
    *         mapped to.
    * @return array IDs of metadata fields that an EXIF tag is mapped to.
    */
    private function getMetadataFieldIdsForExifTag(string $Tag): array
    {
        $Mappings = $this->getConfigSetting("FieldMappings");
        $FieldIds = [];
        foreach ($Mappings as $SchemaMappings) {
            foreach ($SchemaMappings as $Mapping) {
                if ($Mapping["Tag"] == $Tag) {
                    $FieldIds [] = $Mapping["FieldId"];
                }
            }
        }
        sort($FieldIds);
        return $FieldIds;
    }

   /**
    * Create empty arrays to store mappings from EXIF tag values to metadata
    * fields for each schema.
    * @return array An array indexed by schema ID with one empty array for each
    *         schema.
    */
    private function createInitialMappings(): array
    {
        # no field mappings out of the box
        $Mappings = [];
        $AllSchemas = MetadataSchema::getAllSchemas();
        foreach ($AllSchemas as $Schema) {
            $SchemaId = $Schema->Id();
            $Mappings[$SchemaId] = [];
        }
        return $Mappings;
    }

   /**
    * Convert a string formatted as "int/int" into a floating point number.
    * @param string $Fraction Fractional number in a string, formatted as "X/Y"
    *         where X and Y are integers.
    * @return float Argument converted to a decimal number.
    */
    private function convertFractionToFloat(string $Fraction): float
    {
        $Parts = explode("/", $Fraction);
        return (int) $Parts[0] / (int) $Parts[1];
    }

   /**
    * Convert an array representing an EXIF GPS latitude OR longitude tag
    * value (represented as degrees, minutes, and seconds) into a floating
    * point number.
    * @param array $DegreesMinutesSeconds Array of strings that
    *         contain fractions representing, in order, degrees, minutes,
    *         and seconds of latitude or longitude.
    * @return float Floating point representation of provided latitude
    *         or longitude value.
    */
    private function convertDmsToFloat(array $DegreesMinutesSeconds): float
    {
        $Total = 0.0;
        for ($I = 0; $I < 3; $I++) {
            $Factor = pow(60, -$I);
            $Number =
                    $this->convertFractionToFloat($DegreesMinutesSeconds[$I]);
            $Total += ($Number * $Factor);
        }
        return $Total;
    }

   /**
    * Convert GPS EXIF latitude and longitude values from arrays of strings
    * to an array of float values indexed with "X" and "Y".
    * The array of GPS coordinates that is returned is formatted to set the
    * value of a point field.
    * @param string $LatTagIndex Index of GPS latitude tag in array of tag
    *         values.
    * @param string $LngTagIndex Index of GPS longitude tag in array of tag
    *         values.
    * @param array $TagValues Array of EXIF tag values indexed by EXIF tag.
    * @return array Array with Latitude as "X" value and Longitude as "Y" value.
    * @throws Exception is thrown if either latitude and longitude tag is not
    *          available.
    */
    private function convertExifLatLngToPointValue(
        string $LatTagIndex,
        string $LngTagIndex,
        array $TagValues
    ): array {
        if (!array_key_exists($LatTagIndex, $TagValues)
                || !array_key_exists($LngTagIndex, $TagValues)) {
            throw new Exception("Point value requires both GPS latitude and ".
                   " longitude tags");
        }
        $XYCoordinates =
                [ "X" => $this->convertDmsToFloat($TagValues[$LatTagIndex]),
                    "Y" => $this->convertDmsToFloat($TagValues[$LngTagIndex])
                ];
        return $XYCoordinates;
    }

   /**
    *  Save EXIF tag values onto the record specified by RecordId.
    *  according to mappings configured for the record's schema.
    *  @param array $Values EXIF tag values, keyed on metadata field ID
    *          and normalized based on metadata field type.
    *  @param int $RecordId ID of record to save values on.
    */
    private function saveValuesToRecord(array $Values, int $RecordId): void
    {
        $Record = new Record($RecordId);
        foreach ($Values as $MetadataFieldId => $NormalizedValues) {
            $Field = MetadataField::getField($MetadataFieldId);
            if ($Field->schemaId() != Record::getSchemaForRecord($RecordId)) {
                continue;
                # only apply mappings for this record's schema
            }
            # date, flag, timestamp, number, point, and text fields will not
            # be assigned to if a value for the field is already set on the
            # record
            # however, text fields *can* receive multiple tag values from a
            # single import
            $FieldCanBeAppended = false;
            switch ($Field->type()) {
                case MetadataSchema::MDFTYPE_TEXT:
                    $NormalizedValues = implode(", ", $NormalizedValues);
                    $FieldCanBeAppended = false;
                    break;
                case MetadataSchema::MDFTYPE_PARAGRAPH:
                    $NormalizedValues = implode(", ", $NormalizedValues);
                    # append any pre-existing content on paragraph fields with a
                    # newline, followed by the imported EXIF tag value(s)
                    # separated by a comma and a space
                    if ($Record->fieldIsSet($Field, true)) {
                        $NormalizedValues =
                                $Record->get($Field)."\r\n".$NormalizedValues;
                    }
                    $FieldCanBeAppended = true;
                    break;
                case MetadataSchema::MDFTYPE_DATE:
                case MetadataSchema::MDFTYPE_FLAG:
                case MetadataSchema::MDFTYPE_TIMESTAMP:
                case MetadataSchema::MDFTYPE_NUMBER:
                case MetadataSchema::MDFTYPE_POINT:
                    # field types listed above can not contain > 1
                    # value, use only the first
                    $NormalizedValues = $NormalizedValues[0];
                    break;
                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                    # fall through intended
                case MetadataSchema::MDFTYPE_OPTION:
                    # for controlled name and option fields, use the
                    # array with the IDs of the controlled names or options to set
                    $FieldCanBeAppended = $Field->allowMultiple();
                    if (!$FieldCanBeAppended) {
                        # if the field can't contain multiple values, use only
                        # the
                        $NormalizedValues = $NormalizedValues[0];
                    } elseif ($Record->fieldIsSet($Field, true)) {
                        # Record::get returns an array indexed by metadata
                        # field ID; prepend only the field IDs of the existing
                        # values on the controlled vocabulary field to the array of
                        # IDs of the controlled names to set from imported EXIF
                        # tag data
                        # normalized values should be an array of the IDs of
                        # already existing controlled names or options as well as
                        # those being imported from EXIF tag data
                        $NormalizedValues = array_merge(
                            array_keys($Record->get($Field)),
                            $NormalizedValues
                        );
                    }
            }
            # any existing data is already appended for appendable field types
            if ($FieldCanBeAppended || !$Record->fieldIsSet($Field, true)) {
                $Record->set($MetadataFieldId, $NormalizedValues);
            }
        }
    }

    # values loaded by install() into ExifTags configuration setting
    private $SupportedExifTags = [
        "EXIF.ImageUniqueID" => [
            "Label" => "Unique Identifier",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "EXIF.DateTimeOriginal" => [
            "Label" => "Original Datetime",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_DATE,
                MetadataSchema::MDFTYPE_TIMESTAMP,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "EXIF.DateTimeDigitized" => [
            "Label" => "Digitization Datetime",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_DATE,
                MetadataSchema::MDFTYPE_TIMESTAMP,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "EXIF.UserComment" => [
            "Label" => "User Comment",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "EXIF.WhiteBalance" => [
            "Label" => "White Balance",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_FLAG,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "GPS.GPSAltitude" => [
            "Label" => "GPS Altitude",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_NUMBER,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "GPS.GPSDateStamp" => [
            "Label" => "GPS Date Stamp",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_DATE,
                MetadataSchema::MDFTYPE_TIMESTAMP,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "GPS.GPSDestBearing" => [
            "Label" => "GPS Destination Bearing",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH,
                MetadataSchema::MDFTYPE_NUMBER
            ]
        ],
        "GPS.GPSImgDirection" => [
            "Label" => "GPS Image Direction",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH,
                MetadataSchema::MDFTYPE_NUMBER
            ]
        ],
        "GPS.GPSLatitude" => [
            "Label" => "GPS Latitude",
            "AllowableTypes" =>  [
                MetadataSchema::MDFTYPE_POINT,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "GPS.GPSLongitude" => [
            "Label" => "GPS Longitude",
            "AllowableTypes" =>  [ MetadataSchema::MDFTYPE_POINT,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "GPS.GPSSpeed" => [
            "Label" => "GPS Speed",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_NUMBER,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "GPS.GPSTimeStamp" => [
            "Label" => "GPS Time Stamp",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_TIMESTAMP,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "IFD0.Artist" => [
            "Label" => "Artist",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
                MetadataSchema::MDFTYPE_OPTION,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "IFD0.Copyright" => [
            "Label" => "Copyright",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
                MetadataSchema::MDFTYPE_OPTION,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "IFD0.DateTime" => [
            "Label" => "IFD0 Date Time",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_DATE,
                MetadataSchema::MDFTYPE_TIMESTAMP,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "IFD0.ImageDescription" => [
            "Label" => "Image Description",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "IFD0.Make" => [
            "Label" => "Camera Make",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
                MetadataSchema::MDFTYPE_OPTION,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "IFD0.Model" => [
            "Label" => "Camera Model",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
                MetadataSchema::MDFTYPE_OPTION,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
        "IFD0.Software" => [
            "Label" => "Camera Software",
            "AllowableTypes" => [
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
                MetadataSchema::MDFTYPE_OPTION,
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH
            ]
        ],
    ];
}
