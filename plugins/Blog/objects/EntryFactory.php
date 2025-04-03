<?PHP
#
#   FILE:  EntryFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;
use Metavus\ControlledName;
use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\RecordFactory;

/**
 * Factory for BlogEntry objects.
 */
class EntryFactory extends RecordFactory
{
    /**
     * Create an EntryFactory to manipulate entries for a particular blog.
     * @param int $BlogId BlogId for this factory.
     * @return object new EntryFactory object
     */
    public function __construct(int $BlogId)
    {
        # snag the blog plugin
        $BlogPlugin = Blog::getInstance();

        # construct a ResourceFactory for the blog schema
        parent::__construct($BlogPlugin->getSchemaId());
        # record which ControlledName corresponds with our blog
        $this->BlogCName = new ControlledName($BlogId);
    }

    /**
     * List all the ItemIds that belong to this blog.
     * @param string $Condition SQL Condition to match.
     * @param bool $IncludeTempItems TRUE to include temp items (OPTIONAL).
     * @param string $SortField Field to sort by (OPTIONAL).
     * @param bool $SortAscending TRUE to sort ascending (OPTIONAL).
     */
    public function getItemIds(
        ?string $Condition = null,
        bool $IncludeTempItems = false,
        ?string $SortField = null,
        bool $SortAscending = true
    ): array {
        return array_intersect(
            parent::getItemIds(
                $Condition,
                $IncludeTempItems,
                $SortField,
                $SortAscending
            ),
            $this->BlogCName->getAssociatedResources()
        );
    }

    /**
     * List RecordIds for this blog, sorted by a specified field.
     * @param mixed $FieldName Field ID or name to sort by.
     * @param bool $Ascending TRUE to sort ascending (OPTIONAL).
     * @param int $Limit Number of items to return (OPTIONAL).
     * @return array Record IDs.
     */
    public function getRecordIdsSortedBy(
        $FieldName,
        bool $Ascending = true,
        ?int $Limit = null
    ): array {
        $Matches = array_intersect(
            parent::getRecordIdsSortedBy($FieldName, $Ascending),
            $this->BlogCName->getAssociatedResources()
        );

        return array_slice($Matches, 0, $Limit);
    }

    /**
     * Find IDs for blog entries with values that match specified fields
     * @param array $ValuesToMatch Array of values to search for, keyed by Field
     * @param bool $AllRequired TRUE if all values must match (OPTIONAL, default TRUE)
     * @param string $Operator Match operator (OPTIONAL, default "==")
     * @return array RecordIds
     */
    public function getIdsOfMatchingRecords(
        array $ValuesToMatch,
        bool $AllRequired = true,
        string $Operator = "=="
    ) : array {
        return array_intersect(
            parent::getIdsOfMatchingRecords($ValuesToMatch, $AllRequired, $Operator),
            $this->BlogCName->getAssociatedResources()
        );
    }

    private $BlogCName;
}
