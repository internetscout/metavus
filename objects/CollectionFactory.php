<?PHP
#
#   FILE:  CollectionFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

/**
 * Factory for collections of items.
 */
class CollectionFactory extends RecordFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    const ITEM_CLASS = "Metavus\\Collection";
    const SCHEMA_NAME = "Collections";

    /**
     * Class constructor.
     */
    public function __construct()
    {
        $SchemaId = MetadataSchema::getSchemaIdForName(static::SCHEMA_NAME);
        parent::__construct($SchemaId);
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
