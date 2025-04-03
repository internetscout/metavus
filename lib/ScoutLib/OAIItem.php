<?PHP
#
#   FILE:  OAIItem.php
#
#   Part of the ScoutLib application support library
#   Copyright 2009-2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#

namespace ScoutLib;

interface OAIItem
{
    /**
     * Get item id.
     * @return int ItemId.
     */
    public function getId();

    /**
     * Get datestamp for item.
     * @return string Datestamp.
     */
    public function getDatestamp();

    /**
     * Get value for a specified Element of an Item.
     * @param string $ElementName Element to fetch.
     * @return string Requested value
     */
    public function getValue($ElementName);

    /**
     * Get qualifier for a specified Element of an Item.
     * @param string $ElementName Element to fetch.
     * @return string Requested qualifier.
     */
    public function getQualifier($ElementName);

    /**
     * Get OAI sets an item belongs to.
     * @return array List of sets.
     */
    public function getSets();

    /**
     * Get search information for item.
     * @return array Search info.
     */
    public function getSearchInfo();
}
