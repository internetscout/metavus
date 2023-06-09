<?PHP
#
#   FILE:  OAIItemFactory.php
#
#   Part of the ScoutLib application support library
#   Copyright 2009-2019 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#

namespace ScoutLib;

interface OAIItemFactory
{
    /**
     * Get an Item.
     * @param int $ItemId ItemId to fetch.
     * @return mixed Requested Items.
     */
    public function getItem(int $ItemId);

    /**
     * Get a list of items, optionally restricted by creation date.
     * @param string $StartingDate Starting date for list (OPTIONAL).
     * @param string $EndingDate Ending date for list.
     * @return array Requested Items.
     */
    public function getItems(
        string $StartingDate = null,
        string $EndingDate = null
    ): array;

    /**
     * Get array of Items in a specified OAI set (if supported).
     * @param string $Set OAI set specification.
     * @param string $StartingDate Starting date (OPTIONAL).
     * @param string $EndingDate Ending date (OPTIONAL).
     * @return array Requested items.
     */
    public function getItemsInSet(
        string $Set,
        string $StartingDate = null,
        string $EndingDate = null
    ): array;

    /**
     * Get the list of supported OAI sets.
     * @return array List of supported sets, with human-readable set names for index..
     */
    public function getListOfSets(): array;

    /**
     * Retrieve IDs of items that match search paramters when OAI-SQ is supported.
     * @param mixed $SearchParams Search parameters to use.
     * @param mixed|null $StartingDate Starting date for search.
     * @param mixed|null $EndingDate Ending date for search.
     */
    public function searchForItems(
        $SearchParams,
        $StartingDate = null,
        $EndingDate = null
    );
}
