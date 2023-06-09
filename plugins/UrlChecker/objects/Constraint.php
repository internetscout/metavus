<?PHP
#
#   FILE:  Constraint.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

/**
 * Encapsulate a constraint tuple, which consists of a key, a relation, and a
 * value.
 */
class Constraint
{

    /**
     * Object constructor. Encapsulate the key, value, and relation of the
     * constraint.
     * @param mixed $Key Key to provide context for the constraint.
     * @param mixed $Value Value used to determine if the constraint is met.
     * @param mixed $Relation Relation between the key and value.
     */
    public function __construct($Key = null, $Value = null, $Relation = null)
    {
        $this->Key = $Key;
        $this->Value = $Value;
        $this->Relation = $Relation;
    }

    public $Key;
    public $Value;
    public $Relation;
}
