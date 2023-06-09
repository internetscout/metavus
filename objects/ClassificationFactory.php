<?PHP
#
#   FILE:  ClassificationFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\ItemFactory;

/**
 * Factory for producing and manipulating Classification objects.
 * @see ItemFactory
 */
class ClassificationFactory extends ItemFactory
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param int $FieldId ID of metadata field for classification.
     */
    public function __construct(int $FieldId = null)
    {
        # set up item factory base class
        parent::__construct(
            "Metavus\\Classification",
            "Classifications",
            "ClassificationId",
            "ClassificationName",
            false,
            ($FieldId ? "FieldId = ".intval($FieldId) : null)
        );
        $this->FieldId = (!is_null($FieldId)) ? intval($FieldId) : null;
    }

    /**
     * Get IDs of all children of specified classification.
     * @param int $ClassId ID of classification.
     * @return array IDs of all child classifications.
     */
    public function getChildIds(int $ClassId): array
    {
        static $DB;

        # retrieve IDs of all children
        if (!isset($DB)) {
            $DB = new Database();
        }
        $DB->query("SELECT ClassificationId FROM Classifications"
                ." WHERE ParentId = ".intval($ClassId));
        $ChildIds = $DB->fetchColumn("ClassificationId");

        # for each child
        $Ids = $ChildIds;
        foreach ($Ids as $Id) {
            # retrieve IDs of any children of child
            $ChildChildIds = $this->getChildIds($Id);

            # add retrieved IDs to child ID list
            $ChildIds = array_merge($ChildIds, $ChildChildIds);
        }

        # return child ID list to caller
        return $ChildIds;
    }

    /**
     * Recalculate resource counts for all classifications associated with
     * this field.
     * @param bool $Foreground TRUE to recalculate in the foreground
     *   (OPTIONAL, default FALSE)
     */
    public function recalculateAllResourceCounts($Foreground = false)
    {
        # select all the leaf classifications, ordering by depth
        # so that children will be computed first
        $ItemIds = $this->getItemIds(
            "ClassificationId NOT IN "
            ."(SELECT ParentId FROM Classifications"
            .(!is_null($this->FieldId) ? " WHERE FieldId = ".$this->FieldId : "").")",
            false,
            "Depth",
            false
        );

        # queue a task to recalculate the resource counts for each
        # leaf classification
        foreach ($ItemIds as $Id) {
            $Class = new Classification($Id);
            if ($Foreground) {
                $Class->recalcResourceCount();
            } else {
                (ApplicationFramework::getInstance())->queueUniqueTask(
                    "\\Metavus\\Classification::callMethod",
                    [$Id, "recalcResourceCount"],
                    ApplicationFramework::PRIORITY_BACKGROUND,
                    "Recalculate the resource counts for a classification"
                );
            }
        }
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

        $IdExclusionSql = (count($IdExclusions) > 0) ?
            "AND ClassificationId NOT IN ("
            . implode(',', array_map('intval', $IdExclusions)) . ")" :
            "" ;

        $ValueExclusionSql = (count($ValueExclusions) > 0)
                ?  "AND ClassificationName NOT IN ("
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
                "SELECT ClassificationId, ClassificationName"
                ." FROM Classifications"
                ." WHERE LastAssigned IS NOT NULL"
                .(($this->FieldId !== null) ? " AND FieldId = "
                        .intval($this->FieldId) : "")
                ." AND MATCH(ClassificationName) AGAINST ('"
                        .addslashes(trim($SearchString))."' IN BOOLEAN MODE)"
                ." ".$IdExclusionSql
                ." ".$ValueExclusionSql
                ." ORDER BY LastAssigned DESC LIMIT ".$NumberOfResults;

        $this->DB->query($QueryString);

        $Names = $this->DB->fetchColumn("ClassificationName", "ClassificationId");

        return $Names;
    }

    /**
     * Get a list of the Classification IDs associated with a given set of records.
     * @param array $RecordIds Records to look up
     * @param array $FieldIds Fields to consider (OPTIONAL, defaults to all fields)
     * @return array Array keyed by classification ID where values give the
     *   RecordIds associated with the given term
     */
    public static function getAssociatedClassificationIds(
        array $RecordIds,
        array $FieldIds = []
    ) : array {
        $Result = [];
        $DB = new Database();

        $FieldClause = "";
        if (!empty($FieldIds)) {
            $FieldClause = " AND ClassificationId IN "
                ."(SELECT ClassificationId FROM Classifications "
                ."WHERE FieldId IN (".implode(",", $FieldIds)."))";
        }

        $ChunkSize = Database::getIntegerDataChunkSize($RecordIds);
        foreach (array_chunk($RecordIds, $ChunkSize) as $ChunkIds) {
            $DB->query(
                "SELECT RecordId,ClassificationId FROM RecordClassInts "
                ."WHERE RecordId IN (".implode(",", $ChunkIds).")"
                .$FieldClause
            );
            $Rows = $DB->fetchRows();
            foreach ($Rows as $Row) {
                $Result[$Row["ClassificationId"]][] = $Row["RecordId"];
            }
        }

        return $Result;
    }

    /**
     * Get a list of the Classification IDs associated with a given set of
     *   records including all parent classifications.
     * @param array $RecordIds Records to look up
     * @param array $FieldIds Fields to consider (OPTIONAL, defaults to all fields)
     * @return array Array keyed by ClassificationId where values give the
     *   RecordIds associated with the given term
     */
    public static function getAssociatedClassificationIdsWithParents(
        array $RecordIds,
        array $FieldIds = []
    ) : array {
        $Result = [];
        $DB = new Database();

        $FieldClause = "";
        if (!empty($FieldIds)) {
            $FieldClause = " AND C.FieldId IN "
                ."(".implode(",", $FieldIds).")";
        }

        $ParentMap = [];

        $ChunkSize = Database::getIntegerDataChunkSize($RecordIds);
        foreach (array_chunk($RecordIds, $ChunkSize) as $ChunkIds) {
            $DB->query(
                "SELECT"
                ." RC.RecordId AS RecordId,"
                ." C.ClassificationId AS ClassificationId "
                ." FROM"
                ." Classifications C, RecordClassInts RC"
                ." WHERE C.ClassificationId = RC.ClassificationId "
                .$FieldClause
                ." AND RecordId IN (".implode(",", $ChunkIds).")"
            );
            $Rows = $DB->fetchRows();

            # build the list of classifications associated with these records
            $FlippedClassIds = [];
            foreach ($Rows as $Row) {
                $FlippedClassIds[$Row["ClassificationId"]] = true;
            }

            # add their ancestors to our parent map
            $ParentMap += self::getAncestorMap(
                array_diff(
                    array_keys($FlippedClassIds),
                    array_keys($ParentMap)
                )
            );

            # iterate over the parent map to be sure that it was complete
            foreach ($ParentMap as $Id) {
                if ($Id == Classification::NOPARENT) {
                    continue;
                }

                do {
                    if (!isset($ParentMap[$Id])) {
                        $ParentMap[$Id] = (new Classification($Id))->parentId();
                    }
                    $Id = $ParentMap[$Id];
                } while ($Id != Classification::NOPARENT);
            }

            # construct the mapping of classifications to associated records
            foreach ($Rows as $Row) {
                $CurId = $Row["ClassificationId"];

                do {
                    $Result[$CurId][] = $Row["RecordId"];
                    $CurId = $ParentMap[$CurId];
                } while ($CurId != Classification::NOPARENT);
            }
        }

        return $Result;
    }

    /**
     * Get a lookup table listing the ancesters of a given set of
     *   classifications. This function will attempt so build a map all the
     *   way to the root, but if more than 20 iterations of queries against
     *   the database fail to do so it will terminate to prevent an infinite
     *   loop.
     * @param array $ClassificationIds Classification IDs to look up
     * @return array Array of parent information keyed by ClassificationId
     *   with values given the parent of each classification.
     */
    public static function getAncestorMap(array $ClassificationIds)
    {
        $Result = [];
        $IterationCount = 0;
        $MaxIterations = 20;

        $DB = new Database();

        while (count($ClassificationIds) > 0 &&
               $IterationCount < $MaxIterations) {
            $ChunkSize = Database::getIntegerDataChunkSize($ClassificationIds);
            foreach (array_chunk($ClassificationIds, $ChunkSize) as $ChunkIds) {
                $DB->query(
                    "SELECT ClassificationId, ParentId "
                    ."FROM Classifications "
                    ."WHERE ClassificationId IN (".implode(",", $ChunkIds).")"
                );
                $Result += $DB->fetchColumn(
                    "ParentId",
                    "ClassificationId"
                );
            }

            # update the list of ClassificationIds we need to fetch
            # (Remember that values of $Result give the parent ID (with -1 for
            #  top-level classifications) and keys give the classification ID. So
            #  the parent info we still need to fetch are the values in $Result
            #  that do not also have a corresponding key that aren't -1)
            $ClassificationIds = array_unique(
                array_diff(
                    array_values($Result),
                    array_keys($Result),
                    [ -1 ]
                )
            );
            $IterationCount++;
        }

        return $Result;
    }

    /**
     * Get the list of Field Ids associated with a given list of Classification Ids.
     * @param array $ClassificationIds Classification Ids to look up
     * @return array Array keyed by Classification Id where values give the
     *   associated Field Id
     */
    public static function getFieldIds(array $ClassificationIds)
    {
        $DB = new Database();

        $Result = [];

        $ChunkSize = Database::getIntegerDataChunkSize($ClassificationIds);
        foreach (array_chunk($ClassificationIds, $ChunkSize) as $ChunkIds) {
            $DB->query(
                "SELECT ClassificationId, FieldId FROM Classifications "
                ."WHERE ClassificationId IN (".implode(",", $ChunkIds).")"
            );
            $Result += $DB->fetchColumn("FieldId", "ClassificationId");
        }

        return $Result;
    }

    /**
     * Prune list of classifications back to just their top level.
     * @param array $Classes Array to prune, with classification IDs for
     *       the index.
     * @return array Pruned list of full classification names with the
     *       corresponding classificaton IDs for the index.
     */
    public static function pruneClassificationsToTopLevel(array $Classes): array
    {
        # for each area
        $ClassNames = [];
        foreach ($Classes as $Id => $Value) {
            # pare down area so that it is top-level
            $Class = new Classification($Id);
            while ($Class->depth() > 0) {
                $Class = new Classification($Class->parentId());
            }

            # add area to list
            $ClassNames[$Class->id()] = $Class->fullName();
        }

        # return list of subject area names to caller
        return $ClassNames;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $FieldId;
}
