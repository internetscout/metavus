<?PHP
#
#   FILE:  SearchParameterSet.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;

/**
 * Set of parameters used to perform a search, with additional
 * Metavus-specific parameter processing.
 */
class SearchParameterSet extends \ScoutLib\SearchParameterSet
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Add search parameter to set.  If a canonical field function is set,
     * the field to search can be anything accepted by that function, otherwise
     * the $Field argument must be a type usable as an array index (e.g. an
     * integer or a string).
     * @param string|array $SearchStrings String or array of strings to search for.
     * @param mixed $Field Field to search.  (OPTIONAL – defaults to
     *       keyword search if no field specified)
     * @see SearchParameterSet::canonicalFieldFunction()
     */
    public function addParameter($SearchStrings, $Field = null): void
    {
        if (self::isFieldRequiringEncoding($Field)) {
            if (is_array($SearchStrings)) {
                foreach ($SearchStrings as $Index => $SearchString) {
                    $SearchStrings[$Index] = self::encodeValue($SearchString, $Field);
                }
            } else {
                $SearchStrings = self::encodeValue($SearchStrings, $Field);
            }
        }
        parent::addParameter($SearchStrings, $Field);
    }

    /**
     * Remove search parameter from set.
     * @param string|array|null $SearchStrings String or array of strings to
     *       match, or NULL to remove all entries that match the specified field.
     * @param mixed $Field Field to match.  (OPTIONAL - defaults to keyword
     *       search match if no field specified)
     * @see SearchParameterSet::canonicalFieldFunction()
     */
    public function removeParameter($SearchStrings, $Field = null): void
    {
        if (($SearchStrings !== null) && self::isFieldRequiringEncoding($Field)) {
            if (is_array($SearchStrings)) {
                foreach ($SearchStrings as $Index => $SearchString) {
                    $SearchStrings[$Index] = self::encodeValue($SearchString, $Field);
                }
            } else {
                $SearchStrings = self::encodeValue($SearchStrings, $Field);
            }
        }
        parent::removeParameter($SearchStrings, $Field);
    }

    /**
     * Get search strings in set.
     * @param bool $IncludeSubgroups If TRUE, include search strings from
     *       any parameter subgroups.  (OPTIONAL, defaults to FALSE)
     * @return array Array of arrays of search strings, with canonical field
     *       identifiers for the root index.
     */
    public function getSearchStrings(bool $IncludeSubgroups = false): array
    {
        $SearchStrings = parent::getSearchStrings($IncludeSubgroups);
        foreach ($SearchStrings as $Field => $FieldSearchStrings) {
            if (self::isFieldRequiringEncoding($Field)) {
                foreach ($FieldSearchStrings as $Index => $FieldSearchString) {
                    $SearchStrings[$Field][$Index] =
                            self::decodeValue($FieldSearchString, $Field);
                }
            }
        }
        return $SearchStrings;
    }

    /**
     * Get search strings for specified field.
     * @param mixed $Field Field identifier.
     * @param bool $IncludeSubgroups If TRUE, search strings for subgroups
     *       will be returned as well.  (OPTIONAL, defaults to TRUE)
     * @return array Search strings.
     * @see SearchParameterSet::canonicalFieldFunction()
     */
    public function getSearchStringsForField($Field, bool $IncludeSubgroups = true): array
    {
        $SearchStrings = parent::getSearchStringsForField($Field, $IncludeSubgroups);
        if (self::isFieldRequiringEncoding($Field)) {
            foreach ($SearchStrings as $Index => $SearchString) {
                $SearchStrings[$Index] = self::decodeValue($SearchString, $Field);
            }
        }
        return $SearchStrings;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Get canonical identifier value for field.
     * @param mixed $Field Field identifier.
     * @return int Canonical identifier.
     */
    protected static function getCanonicalFieldId($Field): int
    {
        return MetadataSchema::getCanonicalFieldIdentifier($Field);
    }

    /**
     * Get metadata field instance for supplied field identifier.
     * @param mixed $Field Field identifier.
     * @return MetadataField Field instance.
     */
    protected static function getField($Field): MetadataField
    {
        static $MFields = [];
        $FieldId = self::getCanonicalFieldId($Field);
        if (!isset($MFields[$FieldId])) {
            $MFields[$FieldId] = new MetadataField($FieldId);
        }
        return $MFields[$FieldId];
    }

    /**
     * Parse search string into operator (if any) and term.
     * @param string $SearchString Search string to parse.
     * @return array Array with first element being operator and second
     *      element being term.  (If no operator was found, first element
     *      will be an empty string.)
     */
    protected static function parseSearchString(string $SearchString): array
    {
        preg_match('%^([=~^]?)(.*)$%', $SearchString, $Matches);
        $Operator = $Matches[1] ?? "";
        $Term = $Matches[2] ?? "";
        return [ $Operator, $Term ];
    }

    /**
     * Report whether values for specified field require encoding/decoding.
     * @param mixed $Field Field identifier.
     * @return bool TRUE if values should be translated, otherwise FALSE.
     */
    protected static function isFieldRequiringEncoding($Field): bool
    {
        # (field value of NULL means a keyword search, which does not require encoding)
        if ($Field === null) {
            return false;
        }

        $TypesRequiringEncoding = [
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            MetadataSchema::MDFTYPE_OPTION,
            MetadataSchema::MDFTYPE_TREE,
        ];
        return in_array(self::getField($Field)->type(), $TypesRequiringEncoding)
                ? true : false;
    }

    /**
     * Encode value for specified field.
     * @param string $Value Value to be encoded.
     * @param mixed $Field Field identifier.
     * @return string Encoded value.
     */
    protected static function encodeValue(string $Value, $Field): string
    {
        # separate out operator and term
        list($Operator, $Term) = self::parseSearchString($Value);

        # if no term found, return value unchanged
        if (!strlen($Term)) {
            return $Value;
        }

        # look up ID for term
        $TFactory = self::getField($Field)->getFactory();
        $TermId = $TFactory->getItemIdByName($Term);

        # if ID was found for term
        if ($TermId !== false) {
            # replace term with ID
            $EncodedValue = $Operator.$TermId;
        } else {
            # use value unchanged
            $EncodedValue = $Value;
        }

        return $EncodedValue;
    }

    /**
     * Decode value for specified field.
     * @param string $Value Value to be decoded.
     * @param mixed $Field Field identifier.
     * @return string Decoded value.
     */
    protected static function decodeValue(string $Value, $Field): string
    {
        # separate out operator and ID
        list($Operator, $Id) = self::parseSearchString($Value);

        # if nothing resembling and ID was found, return value unchanged
        if (!is_numeric($Id)) {
            return $Value;
        }

        # if ID does not appear to be valid for field, return value unchanged
        $TFactory = self::getField($Field)->getFactory();
        if (!$TFactory->itemExists($Id)) {
            return $Value;
        }

        # if term does not appear valid, return value unchanged
        $Term = $TFactory->getItem($Id)->name();
        if (!strlen($Term)) {
            return $Value;
        }

        # assemble new value with term and return it to caller
        return $Operator.$Term;
    }
}
