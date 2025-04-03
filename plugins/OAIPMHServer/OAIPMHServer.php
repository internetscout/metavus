<?PHP
#
#   FILE:  OAIPMHServer.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Metavus\HtmlButton;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\QualifierFactory;
use Metavus\InterfaceConfiguration;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\StdLib;
use Exception;

/**
* Plugin to provide support for serving resource metadata up via OAI-PMH.
*/
class OAIPMHServer extends Plugin
{

    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set the plugin attributes.  At minimum this method MUST set $this->Name
     * and $this->Version.  This is called when the plugin is initially loaded.
     */
    public function register(): void
    {
        $this->Name = "OAI-PMH Server";
        $this->Version = "1.0.7";
        $this->Description = "Provides support for"
                ." serving up resource records using version 2.0 of the <a"
                ." href=\"http://www.openarchives.org/OAI/openarchivesprotocol.html\""
                ." target=\"_blank\">Open Archives"
                ." Initiative Protocol for Metadata Harvesting</a> (OAI-PMH).";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = true;

        $this->CfgSetup["AutomaticRequestDetection"] = [
            "Label" => "Automatic OAI-PMH Request Detection",
            "Type" => "Flag",
            "Default" => true,
            "Help" => "When enabled, the plugin will attempt to"
                        ." automatically detect harvest requests, regardless"
                        ." of the invoking URL."
                        ." This may need to be disabled when working with a"
                        ." harvester that imposes strict validation checks."
        ];
        $this->addAdminMenuEntry(
            "EditConfig",
            "OAI Server Configuration",
            [ PRIV_SYSADMIN ]
        );
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return string|null NULL if installation succeeded, otherwise a string containing
     *       an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        # set up configuration defaults
        $IntConfig = InterfaceConfiguration::getInstance();
        $RepDescr["Name"] = $IntConfig->getString("PortalName");
        $RepDescr["AdminEmail"] = [$IntConfig->getString("AdminEmail")];
        $ServerName = ($_SERVER["SERVER_NAME"] != "127.0.0.1")
                        ? $_SERVER["SERVER_NAME"]
                        : $_SERVER["HTTP_HOST"];
        $ServerName = str_replace('/', '', $ServerName);
        $ServerName = ($ServerName == "localhost")
                ? gethostname() : $ServerName;
        $RepDescr["BaseURL"] = ApplicationFramework::baseUrl()."OAI";
        $RepDescr["IDDomain"] = $ServerName;
        $RepDescr["IDPrefix"] = $ServerName;
        $RepDescr["DateGranularity"] = "DATE";
        $RepDescr["EarliestDate"] = "1990-01-01";
        $this->setConfigSetting("RepositoryDescr", $RepDescr);
        $this->setConfigSetting("SQEnabled", true);

        # copy over old configuration info (if any)
        $this->transferLegacyConfiguration();

        # look for format outline files in install directory
        $this->FormatFileLocation = dirname(__FILE__)."/install";

        # initialize/expand formats from format outlines
        $this->loadFormatsFromOutlines();

        # build and add native format
        $NativeFormatSuffix = substr(strtolower(preg_replace(
            "/[^a-zA-Z0-9]/",
            "",
            $RepDescr["Name"]
        )), 0, 8);
        $this->addNativeFormat($NativeFormatSuffix, $RepDescr["BaseURL"]);

        # report installation error if no oai_dc format found
        $Formats = $this->getConfigSetting("Formats");
        if (!isset($Formats["oai_dc"])) {
            return "Required oai_dc format not found.";
        }

        return null;
    }

    /**
     * Initialize the plugin.  This is called after all plugins have been
     * loaded but before any methods for this plugin (other than Register()
     * or Initialize()) have been called.
     * @return string|null NULL if initialization was successful, otherwise a string
     *       containing an error message indicating why initialization failed.
     */
    public function initialize(): ?string
    {
        $AF = ApplicationFramework::getInstance();

        $this->addToIncludePath();

        # add clean URL for harvest request
        $AF->addCleanUrl("%^OAI$%", "P_OAIPMHServer_OAI");

        # report success to caller
        return null;
    }

    /**
     * Hook the events into the application framework.
     * @return array Returns an array of events to be hooked into the application
     *      framework.
     */
    public function hookEvents(): array
    {
        $Hooks = [
            "EVENT_SYSTEM_INFO_LIST" => "AddSystemInfoListItems",
        ];
        if ($this->getConfigSetting("AutomaticRequestDetection")) {
            $Hooks["EVENT_PAGE_LOAD"] = "CheckForOaiRequest";
        }
        return $Hooks;
    }

    /**
     * Declare events defined by this plugin.  This is used when a plugin defines
     * new events that it signals or responds to.  Names of these events should
     * begin with the plugin base name, followed by "_EVENT_" and the event name
     * in all caps (for example "MyPlugin_EVENT_MY_EVENT").
     * @return Array with event names for the index and event types for the values.
     */
    public function declareEvents(): array
    {
        return [
            "OAIPMHServer_EVENT_MODIFY_RESOURCE_SEARCH_PARAMETERS"
                => ApplicationFramework::EVENTTYPE_CHAIN,
            "OAIPMHServer_EVENT_FILTER_RESULTS"
                => ApplicationFramework::EVENTTYPE_CHAIN,
        ];
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Add items to system admin menu.
     * @return array Items to add.
     */
    public function addSystemInfoListItems(): array
    {
        $RepDescr = $this->getConfigSetting("RepositoryDescr");
        $ServerUrl = $RepDescr["BaseURL"];
        $OaiExplorerUrl = "http://re.cs.uct.ac.za";
        $OaiTestButton = new HtmlButton("TEST");
        $OaiTestButton->setSize(HtmlButton::SIZE_SMALL);
        $OaiTestButton->setLink(htmlspecialchars($OaiExplorerUrl));
        $OaiTestButton->makeOpenNewTab();

        return ["OAI-PMH Server Base URL" => "<a href=\"".htmlspecialchars($ServerUrl)
            ."\" target=\"_blank\">"
            .htmlspecialchars($ServerUrl)."</a>"
            ."&nbsp;&nbsp;&nbsp;&nbsp;"
            .$OaiTestButton->getHtml()
        ];
    }

    /**
     * Check GET parameters for OAI-PMH request and redirect to our OAI server
     * page if found.  (HOOKED to EVENT_PAGE_LOAD)
     * @param string $PageName PHP file name.
     * @return array PHP file name, possibly changed to load our page.
     */
    public function checkForOaiRequest($PageName): array
    {
        # if valid harvesting command appears to be present
        $ValidVerbs = [
            "GetRecord",
            "Identify",
            "ListIdentifiers",
            "ListMetadataFormats",
            "ListRecords",
            "ListSets",
        ];
        $Verb = StdLib::getFormValue("verb");
        if (in_array($Verb, $ValidVerbs)) {
            # redirect to our page if required arguments for command are present
            switch ($Verb) {
                case "GetRecord":
                    if (StdLib::getFormValue("identifier")
                            && StdLib::getFormValue("metadataPrefix")) {
                        $PageName = "P_OAIPMHServer_OAI";
                    }
                    break;

                case "ListIdentifiers":
                case "ListRecords":
                    if (StdLib::getFormValue("metadataPrefix")
                            || StdLib::getFormValue("resumptionToken")) {
                        $PageName = "P_OAIPMHServer_OAI";
                    }
                    break;

                default:
                    $PageName = "P_OAIPMHServer_OAI";
                    break;
            }
        }
        return ["PageName" => $PageName];
    }


    # ----- PRIVATE METHODS ------------------------------------------------

    private $FormatFileLocation;

    /**
     * Add the plugin include directory to the include path.
     */
    private function addToIncludePath(): void
    {
        # add the include path
        set_include_path(get_include_path().PATH_SEPARATOR.dirname(__FILE__)."/include");
    }

    /**
     * Load XML format outline files.
     * @return array Array containing format information.
     */
    private function loadFormatOutlines(): array
    {
        # for all files in format file location
        $Formats = [];
        $FileList = scandir($this->FormatFileLocation);

        if ($FileList === false) {
            throw new Exception(
                "\"".$this->FormatFileLocation."\" does not exist or is not readable."
            );
        }

        foreach ($FileList as $FileName) {
            # if file looks like a format file
            if (preg_match("/^Format--[a-zA-Z0-9_-]+\\.xml\$/i", $FileName)) {
                # read in format from file
                $FullFileName = realpath($this->FormatFileLocation."/".$FileName);

                if ($FullFileName === false) {
                    throw new Exception(
                        "Failed to get canonicalized absolute pathname for \"".$FileName."\"."
                    );
                }

                $Xml = simplexml_load_file($FullFileName);

                if ($Xml === false) {
                    throw new Exception(
                        "Failed to load \"".$FullFileName."\"."
                    );
                }

                if (isset($Xml->formatName)) {
                    $Formats[(string)$Xml->formatName] = $this->simpleXMLToArray($Xml);
                }
            }
        }

        # return loaded formats to caller
        return $Formats;
    }

    /**
     * Converts a simpleXML element into an array. Preserves attributes and
     * everything. You can choose to get your elements either flattened, or stored
     * in a custom index that you define.
     * For example, for a given element
     * <field name="someName" type="someType"/>
     * if you choose to flatten attributes, you would get:
     * $array['field']['name'] = 'someName';
     * $array['field']['type'] = 'someType';
     * If you choose not to flatten, you get:
     * $array['field']['@attributes']['name'] = 'someName';
     * _____________________________________
     * Repeating fields are stored in indexed arrays. so for a markup such as:
     * <parent>
     * <child>a</child>
     * <child>b</child>
     * <child>c</child>
     * </parent>
     * you array would be:
     * $array['parent']['child'][0] = 'a';
     * $array['parent']['child'][1] = 'b';
     * ...And so on.
     * _____________________________________
     * @param \SimpleXMLElement $Xml XML to convert
     * @param boolean $FlattenValues Whether to flatten values
     *       or to set them under a particular index.  Defaults to TRUE;
     * @param boolean $FlattenAttributes Whether to flatten attributes
     *       or to set them under a particular index. Defaults to TRUE;
     * @param boolean $FlattenChildren Whether to flatten children
     *       or to set them under a particular index. Defaults to TRUE;
     * @param string $ValueKey Index for values, in case $FlattenValues was
     *       set to FALSE. Defaults to "@value"
     * @param string $AttributesKey Index for attributes, in case
     *       $FlattenAttributes was set to FALSE. Defaults to "@attributes"
     * @param string $ChildrenKey Index for children, in case $FlattenChildren
     *       was set to FALSE. Defaults to "@children"
     * @return string|array The resulting array. Only returns string on internal
     *       recursion.
     */
    private function simpleXMLToArray(
        \SimpleXMLElement $Xml,
        bool $FlattenValues = true,
        bool $FlattenAttributes = true,
        bool $FlattenChildren = true,
        string $ValueKey = "@values",
        string $AttributesKey = "@attributes",
        string $ChildrenKey = "@children"
    ) {
        $Array = [];
        $XMLClassName = "SimpleXMLElement";
        if (!($Xml instanceof $XMLClassName)) {
            return $Array;
        }

        $Value = trim((string)$Xml);
        if (!strlen($Value)) {
            $Value = null;
        }

        if ($Value !== null) {
            if ($FlattenValues) {
                $Array = $Value;
            } else {
                $Array[$ValueKey] = $Value;
            }
        }

        $Children = [];
        $MultipleMembers = [];
        foreach ($Xml->children() as $ElementName => $Child) {
            $Value = $this->simpleXMLToArray(
                $Child,
                $FlattenValues,
                $FlattenAttributes,
                $FlattenChildren,
                $ValueKey,
                $AttributesKey,
                $ChildrenKey
            );

            if (isset($Children[$ElementName]) && is_array($Children[$ElementName])) {
                if (!isset($MultipleMembers[$ElementName])) {
                    $Temp = $Children[$ElementName];
                    unset($Children[$ElementName]);
                    $Children[$ElementName] = [$Temp];
                    $MultipleMembers[$ElementName] = true;
                }
                $Children[$ElementName][] = $Value;
            } else {
                $Children[$ElementName] = $Value;
            }
        }
        if (count($Children) && is_array($Array)) {
            # if there are children, $Array should always be an array
            # check for it anyway to satisfy PHPStan
            if ($FlattenChildren) {
                $Array = array_merge($Array, $Children);
            } else {
                $Array[$ChildrenKey] = $Children;
            }
        }

        $Attribs = [];
        foreach ($Xml->attributes() as $Name => $Value) {
            $Attribs[$Name] = trim($Value);
        }
        if (count($Attribs) && is_array($Array)) {
            # if there are attributes, $Array should always be an array
            # check for it anyway to satisfy PHPStan
            if (!$FlattenAttributes) {
                $Array[$AttributesKey] = $Attribs;
            } else {
                $Array = array_merge($Array, $Attribs);
            }
        }

        return $Array;
    }

    /**
     * Transfer over legacy OAI-PMH server configuration.
     */
    private function transferLegacyConfiguration(): void
    {
        # if old OAI-PMH configuration is available
        $DB = new Database();
        if ($DB->columnExists("SystemConfiguration", "OaiIdDomain")) {
            # copy base configuration from legacy OAI-PMH server support values
            $RepDescr = $this->getConfigSetting("RepositoryDescr");

            # load old configuration from the database
            $Columns = ["OAISQEnabled", "OaiIdDomain", "OaiIdPrefix",
                "OaiEarliestDate", "OaiDateGranularity"
            ];
            $DB->query(
                "SELECT ".implode(", ", $Columns)." FROM SystemConfiguration"
            );
            $OldConfig = $DB->fetchRow();

            if ($OldConfig === false) {
                throw new Exception("Failed to read legacy OAI configuration from the database.");
            }

            if (strlen(trim($OldConfig["OaiIdDomain"]))) {
                $RepDescr["IDDomain"] = $OldConfig["OaiIdDomain"];
            }
            if (strlen(trim($OldConfig["OaiIdPrefix"]))) {
                $RepDescr["IDPrefix"] = $OldConfig["OaiIdPrefix"];
            }
            if (($OldConfig["OaiDateGranularity"] == "DATE")
                    || ($OldConfig["OaiDateGranularity"] == "DATETIME")) {
                $RepDescr["DateGranularity"] = $OldConfig["OaiDateGranularity"];
            }
            if (strlen(trim($OldConfig["OaiEarliestDate"]))) {
                $RepDescr["EarliestDate"] = $OldConfig["OaiEarliestDate"];
            }
            $this->setConfigSetting("RepositoryDescr", $RepDescr);
            $this->setConfigSetting("SQEnabled", $OldConfig["OAISQEnabled"]);

            $Formats = [];

            # copy existing field mappings
            $DB->query("SELECT * FROM OAIFieldMappings");
            while ($Record = $DB->fetchRow()) {
                if ($Record["OAIFieldName"] != "Unmapped") {
                    $Formats[$Record["FormatName"]]["Elements"][$Record["OAIFieldName"]]
                            = $Record["SPTFieldId"];
                }
            }

            # copy existing qualifier mappings
            $DB->query("SELECT * FROM OAIQualifierMappings");
            while ($Record = $DB->fetchRow()) {
                if ($Record["OAIQualifierName"] != "Unmapped") {
                    // @codingStandardsIgnoreStart
                    $Formats[$Record["FormatName"]]["Qualifiers"][$Record["OAIQualifierName"]]
                            = $Record["SPTQualifierId"];
                    // @codingStandardsIgnoreEnd
                }
            }


            $this->setConfigSetting("Formats", $Formats);

            # delete legacy config from database
            foreach ($Columns as $Col) {
                $DB->query("ALTER TABLE SystemConfiguration DROP COLUMN ".$Col);
            }
            $DB->query("DROP TABLE OAIFieldMappings");
            $DB->query("DROP TABLE OAIQualifierMappings");
        }
    }

    /**
     * Load new formats from format outline files.
     */
    private function loadFormatsFromOutlines(): void
    {
        # load current formats
        $Formats = $this->getConfigSetting("Formats");

        # load format outlines from files
        $FormatOutlines = $this->loadFormatOutlines();

        # for each loaded format outline
        foreach ($FormatOutlines as $FormatName => $Outline) {
            # save any needed basic format info
            if (!isset($Formats[$FormatName]["TagName"])) {
                $Formats[$FormatName]["TagName"] = $Outline["tagName"];
            }
            if (!isset($Formats[$FormatName]["SchemaNamespace"])) {
                $Formats[$FormatName]["SchemaNamespace"]
                    = $Outline["schema"]["namespace"];
            }
            if (!isset($Formats[$FormatName]["SchemaDefinition"])) {
                $Formats[$FormatName]["SchemaDefinition"]
                    = $Outline["schema"]["definition"];
            }
            if (!isset($Formats[$FormatName]["SchemaVersion"])
                    && is_string($Outline["schema"]["version"])) {
                $Formats[$FormatName]["SchemaVersion"]
                    = $Outline["schema"]["version"];
            }

            # if there are no namespaces set for this format
            if (!isset($Formats[$FormatName]["Namespaces"])
                    || !count($Formats[$FormatName]["Namespaces"])) {
                # if there are namespaces for this format outline
                $Formats[$FormatName]["Namespaces"] = [];
                if (isset($Outline["namespace"])) {
                    # convert namespace to array if necessary
                    if (!isset($Outline["namespace"][0])) {
                        $Outline["namespace"] = [$Outline["namespace"]];
                    }

                    # for each namespace
                    foreach ($Outline["namespace"] as $Namespace) {
                        # if mapping looks viable
                        if (isset($Namespace["name"]) && strlen($Namespace["name"])
                                && isset($Namespace["uri"])
                                && strlen($Namespace["uri"])) {
                            # map namespace
                            $Formats[$FormatName]["Namespaces"][$Namespace["name"]]
                                    = $Namespace["uri"];
                        }
                    }
                }
            }

            # if there are no elements set for this format
            if (!isset($Formats[$FormatName]["Elements"])
                    || !count($Formats[$FormatName]["Elements"])) {
                # if there are element mappings for this format outline
                $Formats[$FormatName]["Elements"] = [];
                if (isset($Outline["element"])) {
                    # convert element mapping to array if necessary
                    if (!isset($Outline["element"][0])) {
                        $Outline["element"] = [$Outline["element"]];
                    }

                    # for each element mapping
                    $Schema = new MetadataSchema();
                    foreach ($Outline["element"] as $Element) {
                        # if mapping looks viable
                        if (isset($Element["name"]) && strlen($Element["name"])) {
                            # map element
                            $Formats[$FormatName]["Elements"][$Element["name"]]
                                    = $this->mapOutlineNameForField($Element, $Schema);
                        }
                    }
                }
            }

            # if there are no qualifiers set for this format
            if (!isset($Formats[$FormatName]["Qualifiers"])
                    || !count($Formats[$FormatName]["Qualifiers"])) {
                # if there are qualifier mappings for this format outline
                $Formats[$FormatName]["Qualifiers"] = [];
                if (isset($Outline["qualifier"])) {
                    # convert qualifier mapping to array if necessary
                    if (!isset($Outline["qualifier"][0])) {
                        $Outline["qualifier"] = [$Outline["qualifier"]];
                    }

                    # for each qualifier mapping
                    $QFactory = new QualifierFactory();
                    foreach ($Outline["qualifier"] as $Qualifier) {
                        # if mapping looks viable
                        if (isset($Qualifier["name"]) && strlen($Qualifier["name"])) {
                            # map qualifier
                            $Formats[$FormatName]["Qualifiers"][$Qualifier["name"]]
                                    = $this->mapOutlineNameForQualifier($Qualifier, $QFactory);
                        }
                    }
                }
            }

            if (!isset($Formats[$FormatName]["Defaults"])
                || !count($Formats[$FormatName]["Defaults"])) {
                $Formats[$FormatName]["Defaults"] = [];
            }
        }

        # save updated formats
        $this->setConfigSetting("Formats", $Formats);
    }

    /**
     * Add format built off of native schema.
     * @param string $FormatNameSuffix Format name suffix.
     * @param string $BaseUrl OAI Base Url.
     */
    private function addNativeFormat($FormatNameSuffix, $BaseUrl): void
    {
        # set up format description
        $FormatNameSuffix = trim($FormatNameSuffix);
        if (!strlen($FormatNameSuffix)) {
            $FormatNameSuffix = "xxx";
        }
        $Format["FormatName"] = "native_".$FormatNameSuffix;
        $Format["TagName"] = $FormatNameSuffix;
        $Format["SchemaNamespace"] = $BaseUrl."/".$FormatNameSuffix;
        $Format["SchemaDefinition"] = $BaseUrl."/"."XSD/file/path/goes/here.xsd";
        $Format["SchemaVersion"] = "1.0.0";

        # swipe namespaces and qualifiers from nsdl_dc format
        $Formats = $this->getConfigSetting("Formats");
        $Format["Namespaces"] = $Formats["nsdl_dc"]["Namespaces"];
        $Format["Qualifiers"] = $Formats["nsdl_dc"]["Qualifiers"];

        # for each currently enabled metadata field
        $Format["Elements"] = [];
        $Schema = new MetadataSchema();
        $Fields = $Schema->getFields();
        foreach ($Fields as $FieldId => $Field) {
            # normalize metadata field name to create OAI element name
            $ElementName = preg_replace("/[^a-zA-Z0-9]/", "", $Field->Name());
            $ElementName = lcfirst($ElementName);

            # add element mapping to format
            $Format["Elements"][$ElementName] = $FieldId;
        }

        $Format["Defaults"] = [];

        # save new format
        $Formats[$Format["FormatName"]] = $Format;
        $this->setConfigSetting("Formats", $Formats);
    }

    /**
     * Get recommended mappings (from format outline file) for a given
     * metadata field or qualifier.
     * @param array $Element Associative array with element info.
     * @return array Recommended mappings.
     */
    private function getRecommendedMappingForElement($Element) : array
    {
        if (!isset($Element["recommendedMapping"])) {
            return [];
        }

        # build list of recommended mappings
        $Recommendations = [$Element["recommendedMapping"]];
        if (isset($Element["alternateMapping"])) {
            if (is_array($Element["alternateMapping"])) {
                $Recommendations = array_merge(
                    $Recommendations,
                    $Element["alternateMapping"]
                );
            } else {
                $Recommendations[] = $Element["alternateMapping"];
            }
        }
        return $Recommendations;
    }

    /**
     * Map recommended qualifier name (from format outline file) to qualifier ID.
     * @param array $Element Associative array with element info.
     * @param QualifierFactory $Factory Factory to query for mappable items.
     * @return int Metadata field ID or -1 if no appropriate field found.
     */
    private function mapOutlineNameForQualifier(
        array $Element,
        QualifierFactory $Factory
    ) : int {
        $Recommendations = $this->getRecommendedMappingForElement($Element);
        if (count($Recommendations) == 0) {
            return -1;
        }

        foreach ($Recommendations as $Name) {
            # look for field with supplied name
            $FieldId = $Factory->getItemIdByName($Name, true);
            if ($FieldId !== false) {
                return $FieldId;
            }
        }

        return -1;
    }

    /**
     * Map recommended field name (from format outline file) to metadata field ID.
     * @param array $Element Associative array with element info.
     * @param MetadataSchema $Factory Schema to query for mappable items.
     * @return int Metadata field ID or -1 if no appropriate field found.
     */
    private function mapOutlineNameForField(
        array $Element,
        MetadataSchema $Factory
    ) : int {
        $Recommendations = $this->getRecommendedMappingForElement($Element);
        if (count($Recommendations) == 0) {
            return -1;
        }

        foreach ($Recommendations as $Name) {
            # look for field with supplied name
            $FieldId = $Factory->getItemIdByName($Name, true);
            if ($FieldId !== false) {
                return $FieldId;
            }

            # look for field mapped to supplied standard name
            $FieldId = $Factory->stdNameToFieldMapping($Name);
            if ($FieldId !== null) {
                return $FieldId;
            }
        }

        return -1;
    }
}
