<?PHP
#
#   FILE:  OAIServer.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2009-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\OAIPMHServer;
use Metavus\MetadataSchema;
use Metavus\Qualifier;
use Metavus\QualifierFactory;
use ScoutLib\Database;

class OAIServer extends \ScoutLib\OAIServer
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Construct an OAIPMHServer OAI server for mapping qualifier/fields
     * @param array $RepDescr repository description
     * @param array $Formats supported formats to add
     * @param mixed $RetrievalSearch retrieval search parameters as array with field => value
     * @param bool $OAISQEnabled OAI-SQ supported
     */
    public function __construct($RepDescr, $Formats, $RetrievalSearch, $OAISQEnabled)
    {
        # grab our own database handle
        $this->DB = new Database();

        # create item factory object for retrieving items from DB
        $this->PItemFactory = new OAIItemFactory($RepDescr, $RetrievalSearch);

        # call parent's constructor
        parent::__construct($RepDescr, $this->PItemFactory, true, $OAISQEnabled);

        # for each defined format
        foreach ($Formats as $FormatName => $Format) {
            # add format to supported list
            $this->addFormat(
                $FormatName,
                $Format["TagName"],
                (isset($Format["SchemaNamespace"])
                            ? $Format["SchemaNamespace"] : null),
                (isset($Format["SchemaDefinition"])
                            ? $Format["SchemaDefinition"] : null),
                (isset($Format["SchemaVersion"])
                            ? $Format["SchemaVersion"] : null),
                $Format["Namespaces"],
                array_keys($Format["Elements"]),
                array_keys($Format["Qualifiers"]),
                isset($Format["Defaults"]) ? $Format["Defaults"] : []
            );

            # set element mappings
            foreach ($Format["Elements"] as $ElementName => $FieldId) {
                if ($FieldId != -1) {
                    parent::setFieldMapping($FormatName, $FieldId, $ElementName);
                }
            }

            # set qualifier mappings
            foreach ($Format["Qualifiers"] as $OAIQualifierName => $QualifierId) {
                if ($QualifierId >= 0) {
                    $Qualifier = new Qualifier($QualifierId);
                    parent::setQualifierMapping(
                        $FormatName,
                        $Qualifier->name(),
                        $OAIQualifierName
                    );
                }
            }
        }
    }

    /**
     * get mapping of local field to OAI field (overloads parent method)
     * @param string $FormatName OAI format name
     * @param string $LocalFieldName local field to fetch
     * @return array|null Array of mapped names or NULL if none exist.
     */
    public function getFieldMapping($FormatName, $LocalFieldName)
    {
        # retrieve ID for local field
        $Schema = new MetadataSchema();
        $LocalField = $Schema->getField($LocalFieldName);
        $LocalFieldId = $LocalField->id();

        # return stored value
        return parent::getFieldMapping($FormatName, (string)$LocalFieldId);
    }
    /**
     * set mapping of local field to OAI field (overloads parent method)
     * @param string $FormatName OAI format name
     * @param string $LocalFieldName local field to map
     * @param string $OAIFieldName mapped value to set
     */
    public function setFieldMapping($FormatName, $LocalFieldName, $OAIFieldName): void
    {
        # retrieve ID for local field
        $Schema = new MetadataSchema();
        $LocalField = $Schema->getField($LocalFieldName);
        $LocalFieldId = $LocalField->id();

        # call parent method
        parent::setFieldMapping($FormatName, (string)$LocalFieldId, $OAIFieldName);
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $DB;
    private $PItemFactory;
}
