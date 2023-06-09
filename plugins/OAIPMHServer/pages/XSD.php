<?PHP
#
#   FILE:  XSD.php (OAI-PMH Server plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#

use Metavus\MetadataSchema;
use Metavus\Plugins\OAIPMHServer\OAIServer;
use ScoutLib\PluginManager;

$AF->SuppressHTMLOutput();

$SelectedFormat = isset($_POST["FN"]) ? $_POST["FN"] :
    (isset($_GET["FN"]) ? $_GET["FN"] : "");

$Plugin = PluginManager::getInstance()
    ->getPlugin("OAIPMHServer");
$Server = new OAIServer(
    $Plugin->ConfigSetting("RepositoryDescr"),
    $Plugin->ConfigSetting("Formats"),
    null,
    $Plugin->ConfigSetting("SQEnabled")
);
$Formats = $Plugin->ConfigSetting("Formats");

# check that format is valid, displaying an error if not
if (!isset($Formats[$SelectedFormat])) {
    print("ERROR: Invalid format '".$SelectedFormat."'");
    return;
}
$ThisFormat = $Formats[$SelectedFormat];

$Schema = new MetadataSchema();
$Fields = $Schema->getFields();

require_once("NamespaceToXSDMap.php");

header("Content-type: text/xml");

# start XML tag, and begin the schema
print("<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
      ."<xs:schema elementFormDefault=\"qualified\" "
      ."\n  xmlns:xs=\"http://www.w3.org/2001/XMLSchema\" "
      ."\n  xmlns:myns=\"".$ThisFormat["SchemaNamespace"]."\" ");

# import the namespaces specified by the user
foreach ($ThisFormat["Namespaces"] as $Name => $Url) {
    print("\n  xmlns:".$Name."=\"".$Url."\"");
}

print("  targetNamespace=\"".$ThisFormat["SchemaNamespace"]."\">\n"
      ."<xs:import namespace=\"http://www.openarchives.org/OAI/2.0/\""
      ."  schemaLocation=\"http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd\" />\n"
      ."<xs:import namespace=\"http://scout.wisc.edu/XML/searchInfo/\""
      ."  schemaLocation=\"http://scout.wisc.edu/XML/searchInfo.xsd\" />\n ");

foreach ($ThisFormat["Namespaces"] as $Name => $Url) {
    if (isset($NamespaceToXSDMap[$Url])) {
        print("<xs:import namespace=\"".$Url."\" "
              ."schemaLocation=\"http://ns.nsdl.org/schemas/".$NamespaceToXSDMap[$Url]."\"/>\n");
    }
}

# define types
#  a type for point fields
print("<xs:simpleType name=\"cwis-point\"><xs:restriction base=\"xs:string\">\n"
      ."<xs:pattern value=\"[0-9.]+,[0-9.]+\"/>\n"
      ."</xs:restriction></xs:simpleType>\n");

# iterate over all the fields, defining unions for those which have
#   more than one mapped qualifier. Note that these union definitions
#   are somewhat brittle as they depend on all the component qualifiers
#   to be simpleTypes, and not all of the qualifiers will be (W3CDTF
#   isn't a simpleType, for example).
# for fields which have a single qualifier mapped, just use that as
#   the base type for the field

$TypeMapping = [];
foreach ($Fields as $Field) {
     $Names = $Server->GetFieldMapping($SelectedFormat, $Field->Name());

    if ($Names === null) {
        continue;
    }

    foreach ($Names as $Name) {
        switch ($Field->Type()) {
            case MetadataSchema::MDFTYPE_NUMBER:
                $DefaultXSType = "xs:decimal";
                break;
            case MetadataSchema::MDFTYPE_DATE:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
                $DefaultXSType = "xs:dateTime";
                break;
            case MetadataSchema::MDFTYPE_POINT:
                $DefaultXSType = "cwis-point";
                break;
            default:
                $DefaultXSType = "xs:string";
                break;
        }

        $MappedQualifiers = [];
        foreach ($Field->AssociatedQualifierList() as $Id => $QualifierName) {
            $RemoteQualifier = $Server->GetQualifierMapping($SelectedFormat, $QualifierName);
            $MappedQualifiers [] = ($RemoteQualifier === null) ? $DefaultXSType : $RemoteQualifier;
        }
        $MappedQualifiers = array_unique($MappedQualifiers);

        if (empty($MappedQualifiers)) {
            $MappedQualifiers [] = $DefaultXSType;
        }

        if (count($MappedQualifiers) > 1) {
            print("<xs:simpleType name=\"".$Name."-type\">\n"
                  ."<xs:union memberTypes=\"".implode(" ", $MappedQualifiers)."\"/>\n"
                  ."</xs:simpleType>\n");
            $TypeMapping[$Name] = "myns:".$Name."-type";
        } else {
            $TypeMapping[$Name] = array_pop($MappedQualifiers);
        }
    }
}

# next, define the structure of the elements
print("<xs:element name=\"".$ThisFormat["TagName"]."\"><xs:complexType>"
      ."<xs:choice minOccurs=\"0\" maxOccurs=\"unbounded\">\n");

foreach ($Fields as $Field) {
    $Names = $Server->GetFieldMapping($SelectedFormat, $Field->Name());
    if ($Names === null) {
        continue;
    }

    foreach ($Names as $Name) {
        $CanHaveManyValues =
                           ($Field->Type() == MetadataSchema::MDFTYPE_OPTION &&
                            $Field->AllowMultiple() == true)
                           || $Field->Type() == MetadataSchema::MDFTYPE_CONTROLLEDNAME
                           || $Field->Type() == MetadataSchema::MDFTYPE_TREE;

        print("<xs:element name=\"".$Name."\" "
              ."type=\"".$TypeMapping[$Name]."\" "
              ."minOccurs=\"".($Field->Optional() ? "0" : "1")."\" "
              ."maxOccurs=\"".($CanHaveManyValues ? "unbounded" : "1")."\">\n"
              ."<xs:annotation><xs:documentation xml:lang=\"en\">\n"
              .'<![CDATA['.$Field->Description()."]]>\n"
              ."</xs:documentation></xs:annotation>\n"
              ."</xs:element>\n");
    }
}

print("</xs:choice>"
      ."<xs:attribute name=\"schemaVersion\" type=\"xs:string\" />"
      ."</xs:complexType></xs:element>"
      ."</xs:schema>");
