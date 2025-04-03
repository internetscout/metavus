<?PHP
#
#   FILE:  ConfigureMappings.php (Exif Tags plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# VALUES PROVIDED to INTERFACE (REQUIRED):
#   $H_PotentialFields - Array indexed by schema ID. The value for each schema
#           ID is an array for each schema contains an array for each its
#           metadata fields that is one of the types that the plugin supports
#           for mapping EXIF tags to. The array for each field has the type (as
#           a MDFTYPE_ constant) and display name of the field. The types
#           supported for mapping are Text, Paragraph, Date, Timestamp, Number,
#           Controlled Name, Option, Flag, and Point.
# VALUES PROVIDED to INTERFACE (OPTIONAL):
#   $H_ErrorMessages - List of error messages if any were produced by the most
#           recent form submission. These are produced by invalid mappings that
#           are not allowed to be saved.
#   $H_WarningMessages - List of warning messages if any were produced by the
#           last form submission. Mappings that raise warnings *can* be saved.
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\ExifTags;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

/**
 * Organize POST data from the form that configures mappings to schemas'
 * metadata fields from EXIF tag data prior to validation, applying deletions,
 * and saving. Reflect deletions entered on the form in the returned array as
 * mappings to a metadata field with id -1.
 * @return array Mappings from EXIF tags metadata field IDs. The array is keyed
 *         by schema ID, then by the numeric index of the row on the form the
 *         mapping is shown on. The value for each numbered row is an array that
 *         represents a mapping that has the EXIF tag and the ID of the metadata
 *         field tag's data is mapped to.
 */
function collectMappingInfo(): array
{
    $TagInfoFromForm = [];

    # exclude rows with -N suffix; these are rows shown at the bottom of the table
    # of mappings for each schema that are used to add new mappings
    $ExifPickers = array_filter($_POST, function ($k) {
        return ( strpos($k, "F_ExifPicker") > -1 && strpos($k, "-N") == false);
    }, ARRAY_FILTER_USE_KEY);

    foreach ($ExifPickers as $PickerName => $Tag) {
        $PickerParts = explode("-", $PickerName);
        $SchemaId = (int) $PickerParts[1];
        $RowIndex = (int) $PickerParts[2];

        $DeletedFieldInput = "F_Deleted"."-".$SchemaId."-".$RowIndex;
        $Deleted = $_POST[$DeletedFieldInput];

        if ($Deleted == "true") {
            $TagInfoFromForm[$SchemaId][$RowIndex]["FieldId"] = -1;
            $TagInfoFromForm[$SchemaId][$RowIndex]["Tag"] = $Tag;
            continue;
        }

        $LocalFieldInput = "F_LocalField"."-".$SchemaId."-".$RowIndex;
        $LocalFieldSelection = $_POST[$LocalFieldInput];
        $TagInfoFromForm[$SchemaId][$RowIndex]["FieldId"] =
            (int) $LocalFieldSelection;
        $TagInfoFromForm[$SchemaId][$RowIndex]["Tag"] = $Tag;
    }

    return $TagInfoFromForm;
}

/**
 * Return an array of message(s) describing why a schema's GPS mappings are
 * invalid if invalid mappings are found.
 * If either GPS latitude or longitude tags are mapped in a schema,
 * they must both be; and must be mapped to the the same field(s).
 * GPS timestamp contains only time data, and GPS datestamp contains only date
 * information. Prevent GPS timestamp from being mapped to a timestamp without
 * having the GPS datestamp tag mapped to the same field.
 * @param array $Mappings Array of mappings with keys "Tag" and "FieldId".
 * @param int $SchemaId ID of schema whose mappings are being validated.
 * @return array Array that is empty if no invalid GPS mappings are found, and
 *         contains messages describing why GPS EXIF tag to metadata field
 *         mappings are invalid if invalid mappings are present.
 */
function validateGPSTagMappings(array $Mappings, int $SchemaId): array
{
    $Lat = [];
    $Lon = [];
    $Timestamp = [];
    $Datestamp = [];

    foreach ($Mappings as $Mapping) {
        if ($Mapping["Tag"] == "GPS.GPSLatitude") {
            $Lat [] = $Mapping["FieldId"];
        }

        if ($Mapping["Tag"] == "GPS.GPSLongitude") {
            $Lon [] = $Mapping["FieldId"];
        }

        if ($Mapping["Tag"] == "GPS.GPSTimeStamp") {
            $Timestamp [] = $Mapping["FieldId"];
        }

        if ($Mapping["Tag"] == "GPS.GPSDateStamp") {
            $Datestamp [] = $Mapping["FieldId"];
        }
    }

    $ErrorMessages = [];

    # latitude and longitude can be mapped to multiple, matching fields
    sort($Lat);
    sort($Lon);
    if ($Lat != $Lon) {
        $Schema  = new MetadataSchema($SchemaId);
        $ErrorMessages [] = "GPS Latitude and Longitude (in "
                            . $Schema->name() . " schema"
                            . ") must be mapped to the same field(s).";
    }

    $GpsTimesWithoutDates = array_diff($Timestamp, $Datestamp);
    foreach ($GpsTimesWithoutDates as $FieldMappedWithoutDate) {
        $FieldWasDeleted = false;
        $Mapped = null;
        if ($FieldMappedWithoutDate == -1) {
            $FieldWasDeleted = true;
        } else {
            $Mapped = MetadataField::getField($FieldMappedWithoutDate);
        }
        if (($FieldWasDeleted) || ($Mapped->type() ==
                MetadataSchema::MDFTYPE_TIMESTAMP)) {
            $ErrorMessages [] =
                    "GPS TimeStamp needs to be mapped to the same field(s) as "
                    . "GPS DateStamp.";
        }
    }
    #  array is empty if no invalid mappings are found
    return $ErrorMessages;
}

/**
 * Report invalid mappings if > 1 tag is mapped to a field in a schema that has
 * a type that does not support multiple values being mapped to it. These types
 * are Number, Flag, and Point.
 * The exception is the tag GPS.GPSTimeStamp, which, if mapped to a timestamp
 * field, must also have the tag GPS.GPSDateStamp mapped to it.
 * @param array $Mappings Array of mappings with keys "Tag" and "FieldId".
 * @param int $SchemaId ID of schema to validate mappings for.
 * @return array Array that is empty if no invalid mappings are found, and
 *         which contains descriptions of why invalid mappings are invalid if
 *         any invalid mappings are found.
 */
function validateMultiplyMappedFields(array $Mappings, int $SchemaId): array
{
    $MappingsPerField = [];
    $GpsTimeStampMappings = []; # metadata field IDs
    $GpsDateStampMappings = [];

    foreach ($Mappings as $Mapping) {
        $FieldId = $Mapping["FieldId"];
        if (!array_key_exists($FieldId, $MappingsPerField)) {
            $MappingsPerField[$FieldId] = 0;
        }
        $Tag = $Mapping["Tag"];
        if ($Tag == "GPS.GPSDateStamp") {
            $GpsDateStampMappings [] = $FieldId;
        }
        if ($Tag == "GPS.GPSTimeStamp") {
            $GpsTimeStampMappings [] = $FieldId;
        }
        $MappingsPerField[$FieldId] += 1;
    }

    $GpsDateAndTimeMappings = array_intersect(
        $GpsTimeStampMappings,
        $GpsDateStampMappings
    );

    foreach ($GpsDateAndTimeMappings as $MappedField) {
        $FieldId = $MappedField;
        if ($FieldId == -1) {
            continue; # mapping was deleted
        }
        $Field = MetadataField::getField((int) $FieldId);
        if ($Field->type() == MetadataSchema::MDFTYPE_TIMESTAMP) {
            # count GPS DateStamp and TimeStamp as only 1 mapped tag
            # (> 1 tag value cannot otherwise be mapped to a time stamp field)
            $MappingsPerField[$FieldId]--;
        }
    }

    $MultiplyMappedFields = array_filter($MappingsPerField, function ($v, $k) {
        return $v > 1;
    }, ARRAY_FILTER_USE_BOTH);

    $ErrorMessages = [];
    # while text fields cannot be imported to if already set, they can have
    # multiple EXIF tag values mapped to them
    $CanHaveMultipleValues =
            [
                MetadataSchema::MDFTYPE_TEXT,
                MetadataSchema::MDFTYPE_PARAGRAPH,
                MetadataSchema::MDFTYPE_POINT,
                MetadataSchema::MDFTYPE_CONTROLLEDNAME,
                MetadataSchema::MDFTYPE_OPTION,
            ];
    foreach ($MultiplyMappedFields as $MdFieldId => $MappedFieldCount) {
        if ($MdFieldId == -1) {
            continue;
        }
        $Field = MetadataField::getField((int) $MdFieldId);
        if (!in_array($Field->Type(), $CanHaveMultipleValues)) {
               $Schema  = new MetadataSchema($SchemaId);
               $ErrorMessage = "Field: ".$Field->Name(). " of type: "
                         . $Field->typeAsName(). " (in "
                         . $Schema->name() . " schema)"
                         . " may not be mapped to > 1 tag.";
            $ErrorMessages [] = $ErrorMessage;
        }
    }
    return $ErrorMessages;
}

/**
 *  Report invalid mappings related to GPS tags and to mapping more than
 *  one EXIF tag to the a field that does not allow multiple values to be
 *  imported.
 *  @param array $FieldMappings Array of mappings from EXIF tags to metadata
 *          fields, indexed by EIXF tags.
 *  @return array Array that is empty if no invalid mappings are found, and
 *          contains descriptions of why mappings are invalid if invalid
 *          mappings are found.
 */
function validateTagToFieldMappings(array $FieldMappings): array
{
    $ErrorMessages = [];
    foreach ($FieldMappings as $SchemaId => $Mappings) {
        $GpsMappingErrors = validateGPSTagMappings($Mappings, $SchemaId);
        $ErrorMessages = array_merge($ErrorMessages, $GpsMappingErrors);

        $MultipleMappingErrors =
            validateMultiplyMappedFields($Mappings, $SchemaId);
        $ErrorMessages = array_merge($ErrorMessages, $MultipleMappingErrors);
    }
    return $ErrorMessages;
}

/**
 * Return an array of all options for each schema's local metadata fields
 * that have types which are able to have values assigned from EXIF tags,
 * and their display names and types.
 * @return array $PossibleFields Array keyed on schema ID, then by metadata
 *         field ID. Values have a metadata field's type and name.
 */
function getAllMappableFields(): array
{
    $Fields = [];
    $MappableFields = [];
    foreach (MetadataSchema::GetAllSchemas() as $Schema) {
        $SchemaFields = $Schema->getFields(ExifTags::MDFTYPES_TO_INCLUDE);
        $FieldEntriesForSchema = [];
        foreach ($SchemaFields as $MDField) {
                $FieldEntriesForSchema[$MDField->id()] = [
                    "Type" => $MDField->type(),
                    "Name" => $MDField->getDisplayName()
                ];
        }
        $MappableFields[$Schema->id()] = $FieldEntriesForSchema;
    }
    return $MappableFields;
}

/**
 * Remove any mappings that were marked for deletion by having -1 set as the
 * metadata field ID.
 * @param array $MappingsFromForm Array with a key for each schema ID. The
 *         The value for each schema ID is an array of mappings for that
 *         schema, each key is an EXIF tags, each value is the ID of a mapped
 *         metadata field in that schema, excluding mappings marked for
 *         deletion, which have the value -1. If a mapping had the metadata
 *         field blanked out on the form, also treat that as a deletion (the
 *         corresponding value for this case is 0, indicating "empty").
 * @return array Array with a key for each schema ID. The
 *         The value for each schema ID is an array of mappings for that
 *         schema, each key is an EXIF tags, each value is the ID of a mapped
 *         metadata field in that schema. Any mappings marked for deletion have
 *         been unset.
 */
function removeMappingsMarkedForDeletion(array $MappingsFromForm): array
{
    foreach ($MappingsFromForm as $SchemaId => $Mappings) {
        foreach ($Mappings as $RowIndex => $Mapping) {
            if ($Mapping["FieldId"] == -1 || $Mapping["FieldId"] == 0) {
                unset($MappingsFromForm[$SchemaId][$RowIndex]);
            }
        }
    }
    return $MappingsFromForm;
}

/**
 * Save changes to mappings from EXIF tags to metadata fields if no invalid
 * mappings are found.
 * @return NULL|array NULL if no problems are found with mappings from EXIF
 * tags to metadata fields to save from the form, otherwise an array of
 *         messages that describe why mappings are invalid if any invalid
 *         mappings are found.
 */
function saveChanges(): ?array
{
    $Plugin = ExifTags::getInstance();

    $MappingInfoFromForm = collectMappingInfo();
    $MappingErrors = validateTagToFieldMappings($MappingInfoFromForm);

    if (count($MappingErrors) > 0) {
        return $MappingErrors;
    }

    # if no invalid mappings, delete any mappings marked for deletion
    $FieldMappings = removeMappingsMarkedForDeletion($MappingInfoFromForm);

    $ReIndexed = [];
    # mappings are re-indexed so indices on form rows are in sequential order
    foreach ($FieldMappings as $SchemaId => $Mappings) {
        $Values = array_values($Mappings);
        $ReIndexed[$SchemaId] = $Values;
    }
    $FieldMappings = $ReIndexed;
    $Plugin->setConfigSetting("FieldMappings", $FieldMappings);
    return null;
}

/**
 * Check saved mappings for cases to warn about.
 * If > 1 tag is mapped to an option field that does not allow multiple
 * values to be set, report this as a warning.
 * @return NULL|array NULL if there are no warnings to report, otherwise
 *         an array of messages describing why there are warnings about
 *         saved mappings.
 */
function reportWarningsFromMappings(): ?array
{
    $Plugin = ExifTags::getInstance();

    $Mappings = $Plugin->getConfigSetting("FieldMappings");
    # array of mappings from EXIF tags to metadata fields indexed on schema ID,
    # there is an array of arrays for each schema ID that represent mappings,
    # containing the EXIF tag and the ID of the metadata field that tag's value
    # is mapped to

    $SingleOptionFields = [];
    $SingleOptionFieldsWithMultipleMappings = [];
    foreach ($Mappings as $SchemaMappings) {
        foreach ($SchemaMappings as $Mapping) {
            $FieldId = $Mapping["FieldId"];
            $Field = MetadataField::getField($FieldId);
            if (($Field->type() == MetadataSchema::MDFTYPE_OPTION) &&
             (!$Field->allowMultiple()) ) {
                if (in_array($FieldId, $SingleOptionFields)) {
                    $SingleOptionFieldsWithMultipleMappings [] = $FieldId;
                } else {
                    $SingleOptionFields [] = $FieldId;
                }
            }
        }
    }
    # report only one warning per field
    $WarningMessages = [];
    $WarningFields = array_unique($SingleOptionFieldsWithMultipleMappings);
    foreach ($WarningFields as $FieldId) {
        $Field = MetadataField::getField($FieldId);
        $WarningMessages [] =
           "Option field ".$Field->name()." has multiple tags mapped to it,"
           . " but does not allow multiple options to be set.";
    }
    if (count($WarningMessages) == 0) {
        return null;
    }
    return $WarningMessages;
}

# ----- MAIN -----------------------------------------------------------------

$Action = $_POST["Submit"] ?? false;

switch ($Action) {
    case "Save Changes":
        $H_ErrorMessages = saveChanges();
        # warning(s) do not prevent mappings from being saved
        $H_WarningMessages = reportWarningsFromMappings();
        break;
}

$Plugin = ExifTags::getInstance();
$Plugin->checkThatMappingConfigurationIsValid(
    "GPS.GPSTimeStamp",
    "GPS.GPSDateStamp",
    "GPS.GPSLatitude",
    "GPS.GPSLongitude"
);

# fields in each schema that have one of the metadata field types the plugin can
# import data from EXIF tags to
$H_PotentialFields = getAllMappableFields();
