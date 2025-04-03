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
class ConstraintList
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     * @param array $Constraints Initial list of constraints.
     */
    public function __construct(array $Constraints = [])
    {
        $this->addConstraints($Constraints);
    }

    /**
     * Add a list of constraints.
     * @param array $Constraints Array of Constraint objects to add
     */
    public function addConstraints(array $Constraints): void
    {
        foreach ($Constraints as $Constraint) {
            $this->addConstraint($Constraint);
        }
    }

    /**
     * Add a constraint.
     * @param Constraint $Constraint Constraint to add.
     * @return void
     */
    public function addConstraint(Constraint $Constraint): void
    {
        $this->List[] = $Constraint;
    }

    /**
     * Get SQL representing a constraint list to include in a
     * query. Assumes that the Records table was aliased as R and the
     * UrlChecker_UrlHistory table was aliased as URH.
     * @return string SQL fragment.
     */
    public function toSql(): string
    {
        $Conditions = [];
        foreach ($this->List as $Constraint) {
            # (conditions from individual constraints are always single
            # relationships in COL RELATION VALUE form (e.g., 'SchemaId = 0'))
            $Conditions[] = $Constraint->toSql();
        }

        # (because the conditions are so simple, they needn't be wrapped in parens)
        return implode(" AND ", $Conditions);
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $List = [];
}
