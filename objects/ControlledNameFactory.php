<?PHP
#
#   FILE:  ControlledNameFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use ScoutLib\Database;
use ScoutLib\ItemFactory;

/**
 * Factory for manipulating ControlledName objects.
 */
class ControlledNameFactory extends ItemFactory
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Constructor for ControlledNameFactory class.
     * @param int $FieldId ID of Controlled Name metadata field.  (OPTIONAL)
     */
    public function __construct(?int $FieldId = null)
    {
        # save field ID for our later use
        $this->FieldId = $FieldId;

        # set up item factory base class
        parent::__construct(
            "Metavus\\ControlledName",
            "ControlledNames",
            "ControlledNameId",
            "ControlledName",
            false,
            ($FieldId ? "FieldId = ".intval($FieldId) : null)
        );
    }

    /**
     * Determine how many resources have controlled names (associated with this
     *   metadata field) assigned to them.
     * @return int Count of resources with names assigned.
     **/
    public function getUsageCount(): int
    {
        return $this->DB->query(
            "SELECT COUNT(DISTINCT RNI.RecordId) AS ResourceCount"
                ." FROM RecordNameInts RNI, ControlledNames CN"
                ." WHERE CN.FieldId = ".intval($this->FieldId)
                ." AND RNI.ControlledNameId = CN.ControlledNameId"
                ." AND RNI.RecordId >= 0",
            "ResourceCount"
        );
    }

    /**
     * Retrieve recently used items matching a search string.
     * @param string $SearchString String to match
     * @param int $NumberOfResults Number of results to return.  (OPTIONAL,
     *       defaults to 5)
     * @param array $IdExclusions List of IDs of items to exclude.
     * @param array $ValueExclusions List of names of items to exclude.
     * @return array List of item names, with item IDs for index.
     */
    public function findMatchingRecentlyUsedValues(
        string $SearchString,
        int $NumberOfResults = 5,
        array $IdExclusions = [],
        array $ValueExclusions = []
    ): array {

        # return no results if empty search string passed in
        if (!strlen(trim($SearchString))) {
            return [];
        }

        $IdExclusionSql = (count($IdExclusions) > 0)
                ? "AND ControlledNameId NOT IN ("
                        .implode(',', array_map('intval', $IdExclusions)).")"
                : "";

        $ValueExclusionSql = (count($ValueExclusions) > 0)
                ? "AND ControlledName NOT IN ("
                        .implode(',', array_map(
                            function ($v) {
                                return "'".addslashes($v)."'";
                            },
                            $ValueExclusions
                        )).")"
                : "";

        # mark all search elements as required
        $SearchString = preg_replace("%\S+%", "+\$0", $SearchString);

        $QueryString =
            "SELECT ControlledNameId, ControlledName FROM ControlledNames "
            ."WHERE FieldId=".$this->FieldId
            ." AND LastAssigned IS NOT NULL"
            ." AND MATCH(ControlledName) AGAINST ('"
                    .addslashes(trim($SearchString))."' IN BOOLEAN MODE)"
            ." ".$IdExclusionSql
            ." ".$ValueExclusionSql
            ." ORDER BY LastAssigned DESC LIMIT ".$NumberOfResults;

        $this->DB->query($QueryString);

        $Names = $this->DB->fetchColumn("ControlledName", "ControlledNameId");

        return $Names;
    }

    /**
     * Search for ControlledNames or variants that match a search string.
     * @param string $SearchString String to search for. Supports * as
     *   a wildcard character but no other special characters are
     *   allowed.
     * @return array of ControlledNameIds that match the string.
     */
    public function controlledNameSearch(string $SearchString): array
    {
        # escape special chars in the regex
        $CNRegex = preg_quote($SearchString);

        # replace * and space with wild cards
        $CNRegex = str_replace("\\*", ".*.", $CNRegex);
        $CNRegex = str_replace(" ", ".*.", $CNRegex);

        # add escaping for sql
        $CNRegex = addslashes($CNRegex);

        # construct and execute our SQL query
        $Query = "SELECT C.ControlledNameId AS ControlledNameId ".
            "FROM ControlledNames AS C LEFT JOIN VariantNames AS V ON ".
            "C.ControlledNameId = V.ControlledNameId WHERE (".
            "ControlledName REGEXP \"".$CNRegex."\" OR ".
            "VariantName REGEXP \"".$CNRegex."\") ".
            "AND C.FieldId = ".$this->FieldId." ".
            "ORDER BY ControlledName";
        $this->DB->query($Query);

        # return the matching CNIDs
        return $this->DB->fetchColumn("ControlledNameId");
    }

    /**
     * Get the list of Controlled Name IDs associated with a specified list of Record Ids.
     * @param array $RecordIds Records to look up
     * @param array $FieldIds Fields to consider (OPTIONAL, defaults to all fields)
     * @return array Array where the keys give ControlledNameIds and the
     *   values give an array of the RecordIds associated with each Controlled
     *   Name
     */
    public static function getAssociatedControlledNameIds(
        array $RecordIds,
        array $FieldIds = []
    ) : array {
        $Result = [];
        $DB = new Database();
        if (!empty($FieldIds)) {
            $QueryBase = "SELECT"
                ." RN.RecordId AS RecordId,"
                ." RN.ControlledNameId AS ControlledNameId"
                ." FROM RecordNameInts RN, ControlledNames N"
                ." WHERE"
                ." RN.ControlledNameId = N.ControlledNameId"
                ." AND N.FieldId IN (".implode(",", $FieldIds).")"
                ." AND RN.RecordId IN ";
        } else {
            $QueryBase = "SELECT RecordId,ControlledNameId FROM RecordNameInts "
                ."WHERE RecordId IN ";
        }

        $ChunkSize = Database::getIntegerDataChunkSize(
            $RecordIds,
            strlen($QueryBase) + 2
        );
        foreach (array_chunk($RecordIds, $ChunkSize) as $ChunkIds) {
            $DB->query(
                $QueryBase."(".implode(",", $ChunkIds).")"
            );

            $Rows = $DB->fetchRows();
            foreach ($Rows as $Row) {
                $Result[$Row["ControlledNameId"]][] = $Row["RecordId"];
            }
        }

        return $Result;
    }

    /**
     * Get the list of Field Ids associated with a given list of Controlled Name Ids.
     * @param array $CNameIds Controlled Name Ids to look up
     * @return array Array keyed by Controlled Name Id where values give the
     *   associated Field Id
     */
    public static function getFieldIds(array $CNameIds)
    {
        $DB = new Database();

        $Result = [];

        $ChunkSize = Database::getIntegerDataChunkSize($CNameIds);
        foreach (array_chunk($CNameIds, $ChunkSize) as $ChunkIds) {
            $DB->query(
                "SELECT ControlledNameId, FieldId FROM ControlledNames "
                ."WHERE ControlledNameId IN (".implode(",", $ChunkIds).")"
            );
            $Result += $DB->fetchColumn("FieldId", "ControlledNameId");
        }

        return $Result;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $FieldId;
}
