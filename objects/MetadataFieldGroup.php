<?PHP
#
#   FILE:  MetadataFieldGroup.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use InvalidArgumentException;

/**
 * Class that builds on the foldering functionality to provide groups of
 * metadata fields.
 */
class MetadataFieldGroup extends Folder
{

    /**
     * Get the items of the metadata field group as objects instead of IDs.
     * @return array Returns an array of metadata field objects.
     */
    public function getFields(): array
    {
        $ItemIds = $this->getItemIds();
        $Items = [];

        foreach ($ItemIds as $Info) {
            try {
                if ($Info["Type"] == 'Metavus\MetadataField') {
                    $Items[] = MetadataField::getField($Info["ID"]);
                } else {
                    $Items[] = new $Info["Type"]($Info["ID"]);
                }
            # skip invalid fields
            } catch (InvalidArgumentException $Exception) {
                continue;
            }
        }

        return $Items;
    }

    /**
     * Get the number of metadata fields this group holds.
     * @return int Returns the number of metadata fields this group holds.
     */
    public function getFieldCount(): int
    {
        if (!isset($this->fieldCount)) {
            $this->FieldCount = count($this->getItemIds());
        }

        return $this->FieldCount;
    }

    /**
     * The number of metadata fields the group contains.
     */
    protected $FieldCount;
}
