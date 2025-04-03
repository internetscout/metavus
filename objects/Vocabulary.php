<?PHP
#
#   FILE:  Vocabulary.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2007-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use SimpleXMLElement;

/**
 * Controlled vocabulary.
 */
class Vocabulary
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     * @param string $FileName Name of .voc file containing vocabulary to load.
     * @note Check status() to determine if constructor succeeded
     */
    public function __construct(string $FileName)
    {
        # if provided filename is not found
        if (!file_exists($FileName)) {
            # look in configured search paths
            foreach (self::$SearchPaths as $Path) {
                $TestPath = $Path."/".$FileName;
                if (file_exists($TestPath)) {
                    $FileName = $TestPath;
                    break;
                }
            }
        }

        # save file name
        $this->FileName = $FileName;

        # attempt to load vocabulary from file
        $this->Xml = simplexml_load_file($FileName);

        # set error code if load failed
        if ($this->Xml === false) {
            throw new Exception("Failed to load XML");
        }
        $this->Xml = isset($this->Xml->vocabulary) ? $this->Xml->vocabulary : $this->Xml;
    }

    /**
     * Get hash string for vocabulary (generated from file name).
     * @return string 32-character hash string.
     */
    public function hash(): string
    {
        return self::hashForFile($this->FileName);
    }

    /**
     * Get hash string for specified vocabulary file name.
     * @param string $FileName Name of .voc file containing vocabulary.
     * @return string 32-character hash string.
     */
    public static function hashForFile(?string $FileName = null): string
    {
        return strtoupper(md5($FileName));
    }

    /**
     * Get vocabulary name.
     * @return string Vocabulary name.
     */
    public function name(): string
    {
        return $this->xmlVal("name");
    }

    /**
     * Get vocabulary description.
     * @return string Vocabulary description.
     */
    public function description(): string
    {
        return $this->xmlVal("description");
    }

    /**
     * Get URL attached to vocabulary.
     * @return string URL associated with vocabulary.
     */
    public function url(): string
    {
        return $this->xmlVal("url");
    }

    /**
     * Get version number for vocabulary.
     * @return string Vocabulary version.
     */
    public function version(): string
    {
        return $this->xmlVal("version");
    }

    /**
     * Get whether vocabulary has associated qualifier.
     * @return bool TRUE if vocabulary has qualifier, otherwise FALSE.
     */
    public function hasQualifier(): bool
    {
        return (strlen($this->qualifierName())
                && (strlen($this->qualifierNamespace())
                        || strlen($this->qualifierUrl()))) ? true : false;
    }

    /**
     * Get qualifier name.
     * @return string Qualifier name, or empty string if no qualifier name
     *       available or no qualifier associated with vocabulary.
     */
    public function qualifierName(): string
    {
        return isset($this->Xml->qualifier->name)
                ? (string)$this->Xml->qualifier->name : "";
    }

    /**
     * Get qualifier namespace.
     * @return string Qualifier namespace, or empty string if no qualifier
     *       namespace available or no qualifier associated with vocabulary.
     */
    public function qualifierNamespace(): string
    {
        return isset($this->Xml->qualifier->namespace)
                ? (string)$this->Xml->qualifier->namespace : "";
    }

    /**
     * Get qualifier URL.
     * @return string Qualifier URL, or empty string if no qualifier
     *       URL available or no qualifier associated with vocabulary.
     */
    public function qualifierUrl(): string
    {
        return isset($this->Xml->qualifier->url)
                ? (string)$this->Xml->qualifier->url : "";
    }

    /**
     * Get name of owning (maintaining) organization.
     * @return string Name of owner or empty string if no owner name available.
     */
    public function ownerName(): string
    {
        return isset($this->Xml->owner->name)
                ? (string)$this->Xml->owner->name : "";
    }

    /**
     * Get primary URL for owning (maintaining) organization.
     * @return string URL for owner or empty string if no owner URL available.
     */
    public function ownerUrl(): string
    {
        return isset($this->Xml->owner->url)
                ? (string)$this->Xml->owner->url : "";
    }

    /**
     * Get vocabulary terms as multi-dimensional array.
     * @return array Associative hierarchical array with terms for index.
     */
    public function termArray(): array
    {
        $Terms = $this->extractTermSet($this->Xml);

        # return array of terms to caller
        return $Terms;
    }

    /**
     * Get vocabulary terms as flat array with double-dash separators.
     * @return array Array of terms.
     */
    public function termList(): array
    {
        $TermTree = $this->termArray();
        $Terms = $this->buildTermList("", $TermTree);
        return $Terms;
    }

    /**
     * Get/set the list of paths where vocabulary files will be searched for.
     * @param array $NewValue Array of paths to search (OPTIONAL)
     * @return array Current search paths.
     */
    public static function fileSearchPaths(?array $NewValue = null): array
    {
        if ($NewValue !== null) {
            self::$SearchPaths = $NewValue;
        }
        return self::$SearchPaths;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $FileName;
    private $Xml;
    private static $SearchPaths = [ "data/Vocabularies" ];

    /**
     * Get value from stored parsed XML.
     * @param string $ValueName Name of value to retrieve.
     * @return string Retrieved value.
     */
    private function xmlVal(string $ValueName): string
    {
        return isset($this->Xml->{$ValueName}) ? (string)$this->Xml->{$ValueName} : "";
    }

    /**
     * Get terms from parsed XML as multi-dimensional array.
     * @param mixed $Tree Parsed XML as SimpleXMLElement object or a term from a parsed XML object.
     * @return array Associative hierarchical array with terms for index.
     */
    private function extractTermSet($Tree): array
    {
        # make sure a valid SimpleXMLElement was given and return an empty
        # array if not
        if (!($Tree instanceof SimpleXMLElement)) {
            return [];
        }

        $Terms = [];
        foreach ($Tree->term as $Term) {
            if (isset($Term->value)) {
                $Terms[(string)$Term->value] = $this->extractTermSet($Term);
            } else {
                $Terms[(string)$Term] = [];
            }
        }
        return $Terms;
    }

    /**
     * Build double-dash separated term list from hierarchical array.
     * @param string $Prefix Prefix for current point in hierarchical array.
     * @param array $TermTree Hierarchical array.
     * @return array Term list.
     */
    private function buildTermList(string $Prefix, array $TermTree): array
    {
        $Terms = [];
        foreach ($TermTree as $Term => $Children) {
            $Term = trim($Term);
            $NewTerm = strlen($Prefix) ? $Prefix." -- ".$Term : $Term;
            $Terms[] = $NewTerm;
            $Terms = array_merge($Terms, $this->buildTermList($NewTerm, $Children));
        }
        return $Terms;
    }
}
