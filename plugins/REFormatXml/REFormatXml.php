<?PHP
#
#   FILE:  REFormatXml.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2018-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
#   @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\MetadataSchema;
use Metavus\Plugins\ResourceExporter;
use Metavus\Record;
use ScoutLib\Plugin;
use ScoutLib\StdLib;
use XMLWriter;

/**
*
*/
class REFormatXml extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set plugin attributes.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Resource Export Format: XML";
        $this->Version = "1.0.0";
        $this->Description = "Add support to Resource Exporter for exporting "
            ."resources in eXtensible Markup Language (XML) format.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
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
    public function initialize(): ?string
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
        ResourceExporter::getInstance()->
                registerFormat(
                    "XML",
                    "xml",
                    [$this, "Export"],
                    $ExportedDataTypes
                );
        return null;
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * HOOKED METHOD:
     */


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Export resource metadata to XML format.
     * @param array $ResourceIds Array of Resource IDs.
     * @param array $FieldIds Array of IDs of metadata fields to export.
     *       (NULL to export all enabled fields)
     * @param string $FileName Name of file to export to with leading path.
     * @param array $ParamSettings Settings for any export parameters, with
     *       parameter names for the array index and parameter settings for
     *       the array values.
     * @return int Number of resources exported, or NULL if export failed.
     */
    public function export($ResourceIds, $FieldIds, $FileName, $ParamSettings): int
    {
        # start XML output
        $Out = new XMLWriter();
        touch($FileName);
        $Out->openUri($FileName);
        $Out->setIndent(true);
        $Out->setIndentString("    ");
        $Out->startDocument("1.0", "UTF-8");
        $Out->startElement("ResourceCollection");

        # for each resource
        $ExportedResourceCount = 0;
        foreach ($ResourceIds as $ResourceId) {
            # load resource
            $Resource = new Record($ResourceId);

            $Schema = $Resource->getSchema();
            $Fields = $Schema->getFields(null, MetadataSchema::MDFORDER_EDITING);

            # start new resource entry, with an element name
            # constructed from the alphanumerics in the schema name
            # (removing spaces and punctuation)
            $Out->startElement(preg_replace(
                "/[^A-Za-z0-9]/",
                "",
                StdLib::singularize($Schema->name())
            ));

            # for each metadata field
            foreach ($Fields as $Field) {
                # if field is enabled
                if ($Field->Enabled()
                        && (($FieldIds == null)
                                || in_array($Field->Id(), $FieldIds))) {
                    # if field has content
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
                                    $Out->writeElement($Field->DBFieldName(), $Value);
                                }
                                break;

                            case MetadataSchema::MDFTYPE_DATE:
                                if (strlen($Value->Formatted())) {
                                    $Out->writeElement(
                                        $Field->DBFieldName(),
                                        $Value->Formatted()
                                    );
                                }
                                break;

                            case MetadataSchema::MDFTYPE_FLAG:
                                $Out->writeElement(
                                    $Field->DBFieldName(),
                                    ($Value ? "TRUE" : "FALSE")
                                );
                                break;

                            case MetadataSchema::MDFTYPE_TREE:
                            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                            case MetadataSchema::MDFTYPE_OPTION:
                                foreach ($Value as $Item) {
                                    $Out->writeElement(
                                        $Field->DBFieldName(),
                                        $Item->Name()
                                    );
                                }
                                break;

                            case MetadataSchema::MDFTYPE_POINT:
                                $Out->writeElement(
                                    $Field->DBFieldName(),
                                    $Value["X"].",".$Value["Y"]
                                );
                                break;

                            case MetadataSchema::MDFTYPE_USER:
                                foreach ($Value as $Item) {
                                    if (strlen($Item->Get("UserName"))) {
                                        $Out->writeElement(
                                            $Field->DBFieldName(),
                                            $Item->Get("UserName")
                                        );
                                    }
                                }
                                break;

                            case MetadataSchema::MDFTYPE_REFERENCE:
                                foreach ($Value as $Record) {
                                    $TitleField = $Record->getSchema()->
                                            getFieldByMappedName("Title");
                                    if ($TitleField !== null) {
                                        $Out->writeElement(
                                            $Field->DBFieldName(),
                                            $Record->get($TitleField)
                                        );
                                    } else {
                                        $Out->writeElement(
                                            $Field->DBFieldName(),
                                            $Record->id()
                                        );
                                    }
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
                }
            }

            # end resource entry
            $Out->endElement();
            $ExportedResourceCount++;
        }

        # end XML output
        $Out->endDocument();

        # return number of exported resources to caller
        return $ExportedResourceCount;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
