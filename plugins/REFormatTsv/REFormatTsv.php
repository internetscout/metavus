<?PHP
#
#   FILE:  REFormatTSV.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2018-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

namespace Metavus\Plugins;

use Exception;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Record;
use ScoutLib\Plugin;

/**
 * Resource Export format implementing Tab Seperated Value (TSV) files.
 */
class REFormatTsv extends Plugin
{

    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set plugin attributes.
     */
    public function register()
    {
        $this->Name = "Resource Export Format: TSV";
        $this->Version = "1.0.0";
        $this->Description = "Add support to Resource Exporter for exporting "
            ."resources in Tab Separated Value (TSV) format.";
        $this->Author = "Internet Scout";
        $this->Url = "http://scout.wisc.edu/cwis/";
        $this->Email = "scout@scout.wisc.edu";
        $this->Requires = [
            "MetavusCore" => "1.0.0",
            "ResourceExporter" => "1.0.0"
        ];
        $this->EnabledByDefault = true;
    }

    /**
     * Initialize the plugin.  This is called (if the plugin is enabled) after
     * all plugins have been loaded but before any methods for this plugin
     * (other than Register()) have been called.
     * @return NULL if initialization was successful, otherwise a string containing
     *       an error message indicating why initialization failed.
     */
    public function initialize()
    {
        $ExportedDataTypes = [
            MetadataSchema::MDFTYPE_TEXT,
            MetadataSchema::MDFTYPE_PARAGRAPH,
            MetadataSchema::MDFTYPE_NUMBER,
            MetadataSchema::MDFTYPE_TIMESTAMP,
            MetadataSchema::MDFTYPE_URL,
            MetadataSchema::MDFTYPE_DATE,
            MetadataSchema::MDFTYPE_FLAG,
            MetadataSchema::MDFTYPE_TREE,
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            MetadataSchema::MDFTYPE_OPTION,
            MetadataSchema::MDFTYPE_POINT,
            MetadataSchema::MDFTYPE_USER,
            MetadataSchema::MDFTYPE_REFERENCE,
        ];

        $ConfigParams = [];
        foreach (MetadataSchema::getAllSchemaNames() as $Id => $SchemaName) {
            $ConfigParams["UniqueFields".$Id] = [
                "Type" => "MetadataField",
                "Label" => "Unique Fields for ".$SchemaName,
                "Help" => "Field or fields used to uniquely identify "
                    ."a resource.  If not specified, defaults to "
                    ."the mapped Title, URL, and Description fields.  "
                    ."All fields selected here will automatically be "
                    ."added to the exported data, even if not checked above",
                "FieldTypes" => MetadataSchema::MDFTYPE_TEXT |
                    MetadataSchema::MDFTYPE_PARAGRAPH |
                    MetadataSchema::MDFTYPE_URL,
                "SchemaId" => $Id,
                "AllowMultiple" => true
            ];
        }
        $ConfigParams["Delimiter"] = [
            "Type" => \Metavus\FormUI::FTYPE_TEXT,
            "Label" => "Delimiter for Fields with Multiple Values",
            "Help" => "Fields that allow multiple values to be selected "
                ."will normally display the values on different rows, specifying a delimiter "
                ."puts the values on the same row, separated by the given delimiter"
        ];

        $GLOBALS["G_PluginManager"]->GetPlugin("ResourceExporter")->
            RegisterFormat(
                "TSV",
                "tsv",
                [$this, "Export"],
                $ExportedDataTypes,
                $ConfigParams
            );

        return null;
    }

    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * HOOKED METHOD:
     */


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Export resource metadata to TSV format.
     * @param array $ResourceIds Array of Resource IDs.
     * @param array $FieldIds Array of IDs of metadata fields to export.
     *       (NULL to export all enabled fields)
     * @param string $FileName Name of file to export to with leading path.
     * @param array $ParamSettings Settings for any export parameters, with
     *       parameter names for the array index and parameter settings for
     *       the array values.
     * @return int Number of resources exported, or NULL if export failed.
     */
    public function export($ResourceIds, $FieldIds, $FileName, $ParamSettings)
    {
        # create file and open a filehandle
        touch($FileName);
        $fp = fopen($FileName, "w");

        # iterate over the resources, extracting data on which schemas they belong to
        $Schemas = [];
        foreach ($ResourceIds as $Id) {
            $Resource = new Record($Id);
            if (!isset($Schemas[$Resource->getSchemaId()])) {
                $Schemas[$Resource->getSchemaId()] = new MetadataSchema(
                    $Resource->getSchemaId()
                );
            }
        }

        # extract settings about unique fields, defaulting to
        # mapped Title, Url, and Description fields for the schema if
        # nothing was selected
        $UniqueFields = [];
        foreach ($Schemas as $Id => $Schema) {
            if (isset($ParamSettings["UniqueFields".$Id]) &&
                count($ParamSettings["UniqueFields".$Id])) {
                foreach ($ParamSettings["UniqueFields".$Id] as $FieldId) {
                    $Field = new MetadataField($FieldId);
                    if ($Field->enabled()) {
                        $UniqueFields[$FieldId] = true;
                    }
                }
            } else {
                # if no unique fields were configured, use all configured
                # mapped fields for this schema
                foreach (["Title", "Url", "Description"] as $MappedName) {
                    $Field = $Schema->GetFieldByMappedName($MappedName);

                    if (!is_null($Field)) {
                        $UniqueFields[$Field->Id()] = true;
                    }
                }
            }
        }

        # iterate over the fields, pulling out those that are both
        # enabled and apply to resources we are exporting
        $Fields = [];
        foreach ($FieldIds as $FieldId) {
            $Field = new MetadataField($FieldId);
            if ($Field->enabled() && isset($Schemas[$Field->schemaId()])) {
                $Fields[$FieldId] = $Field;
            }
        }

        # iterate over selected unique fields, being sure that we're
        # exporting those as well
        foreach ($UniqueFields as $FieldId => $Flag) {
            if (!isset($Fields[$FieldId])) {
                $Fields[$FieldId] = new MetadataField((int)$FieldId);
            }
        }

        # construct an array of field names, prefixed with the schema
        # name if we're exporting multiple schemas
        $OutputData = [];
        foreach ($Fields as $FieldId => $Field) {
            $OutputData[] = (count($Schemas) > 1 ?
                    $Schemas[$Field->schemaId()]->Name().": " : "").
                    $Field->Name();
        }

        # output a header line giving the field names
        fwrite($fp, implode("\t", $OutputData)."\n");

        # foreach resource
        $ExportedResourceCount = 0;
        foreach ($ResourceIds as $ResourceId) {
            # load resource
            $Resource = new Record($ResourceId);

            # create an array representing the values we'll need to output,
            # with field names as keys that point to arrays of values
            $OutputData = [];

            # foreach metadata field
            foreach ($Fields as $FieldId => $Field) {
                # start off assuming no content for this field
                $OutputValue = "";

                if ($Field->schemaId() == $Resource->getSchemaId()) {
                    # pull out the value(s) in the field
                    $Value = $Resource->get($Field, true);
                    if ((is_array($Value) && count($Value))
                        || (!is_array($Value) && ($Value !== null))) {
                        # handle output of field based on field type
                        switch ($Field->Type()) {
                            case MetadataSchema::MDFTYPE_TEXT:
                            case MetadataSchema::MDFTYPE_PARAGRAPH:
                            case MetadataSchema::MDFTYPE_NUMBER:
                            case MetadataSchema::MDFTYPE_TIMESTAMP:
                            case MetadataSchema::MDFTYPE_URL:
                                if (strlen($Value)) {
                                    $OutputValue = str_replace(
                                        ["\r","\n","\t"],
                                        " ",
                                        $Value
                                    );
                                }
                                break;

                            case MetadataSchema::MDFTYPE_DATE:
                                if (strlen($Value->Formatted())) {
                                    $OutputValue = $Value->Formatted();
                                }
                                break;

                            case MetadataSchema::MDFTYPE_FLAG:
                                $OutputValue =  ($Value ? "TRUE" : "FALSE");
                                break;

                            case MetadataSchema::MDFTYPE_TREE:
                            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                            case MetadataSchema::MDFTYPE_OPTION:
                                $OutputValue = [];
                                foreach ($Value as $Item) {
                                    $OutputValue[] = $Item->Name();
                                }
                                break;

                            case MetadataSchema::MDFTYPE_POINT:
                                $OutputValue = $Value["X"].",".$Value["Y"] ;
                                break;

                            case MetadataSchema::MDFTYPE_USER:
                                $OutputValue = [];

                                foreach ($Value as $Item) {
                                    if (strlen($Item->Get("UserName"))) {
                                        $OutputValue[] = $Item->Get("UserName");
                                    }
                                }
                                break;
                            case MetadataSchema::MDFTYPE_REFERENCE:
                                $OutputValue = [];
                                foreach ($Value as $Record) {
                                    $TitleField = $Record->getSchema()->
                                            getFieldByMappedName("Title");
                                    $OutputValue[] = is_null($TitleField) ?
                                        $Record->id() : $Record->get($TitleField);
                                }
                                break;

                            default:
                                throw new Exception(
                                    "Export of unsupported metadata field type ("
                                    .MetadataSchema::getConstantName(
                                        $Field->Type(),
                                        "MDFTYPE"
                                    )
                                    .") requested."
                                );
                        }
                    }

                    $Delimiter = $ParamSettings["Delimiter"];

                    # combine arrays of values into a single string
                    # separated by a delimiter if provided
                    if (strlen($Delimiter) && is_array($OutputValue)) {
                        foreach ($OutputValue as $Id => $Value) {
                            # go through and escape the delimiter if it exists in any of the values
                            $OutputValue[$Id] = str_replace($Delimiter, "\\".$Delimiter, $Value);
                        }
                        $OutputValue = implode($Delimiter, $OutputValue);
                    }

                    # add the value(s) to our OutputData
                    if (is_array($OutputValue)) {
                        $OutputData[$Field->Id()] = $OutputValue;
                    } else {
                        $OutputData[$Field->Id()][] = $OutputValue;
                    }
                }
            }

            # iterate over our OutputData, generating as many rows as
            # we need in the output file in order to encode all the
            # multi-value fields
            do {
                $Done = true;
                $Row = [];

                # iterate over the fields adding them to the output for this row
                foreach ($OutputData as $FieldId => &$FieldContent) {
                    # repeat key fields in every row
                    if (isset($UniqueFields[$FieldId])) {
                        $Row[] = $FieldContent[0];
                    } else {
                        # for non-key fields, shift a value off of the array
                        $Value = array_shift($FieldContent);

                        if (!is_null($Value)) {
                            # if there was a value, stick it into our output row
                            $Row[] = $Value;

                            # and if there are more values left, note that
                            # we're not finished
                            if (count($FieldContent) > 0) {
                                $Done = false;
                            }
                        } else {
                            # otherwise (when there is no value), then put a
                            # blank placeholder in our output row for this field
                            $Row[] = "";
                        }
                    }
                }
                # output the row we've constructed as a TSV
                fwrite($fp, implode("\t", $Row)."\n");
            } while (!$Done);

            $ExportedResourceCount++;
        }

        fclose($fp);

        # return number of exported resources to caller
        return $ExportedResourceCount;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
