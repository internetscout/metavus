<?PHP
#
#   FILE:  ConstraintList.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\UrlChecker;

/**
* Encapsulates a list of constraints.
*/
class ConstraintList implements \IteratorAggregate
{
    /**
    * Add a constraint to the list.
    * @param Constraint $Constraint Constraint to add.
    */
    public function addConstraint(Constraint $Constraint)
    {
        $this->List[] = $Constraint;
    }

    /**
    * Get an iterator object to allow iterating over the constraints.
    * @return \ArrayIterator An ArrayIterator object to allow iterating over the
    *      constraints.
    */
    public function getIterator() : \ArrayIterator
    {
        return new \ArrayIterator($this->List);
    }

    private $List;
}
