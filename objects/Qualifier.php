<?PHP
#
#   FILE:  Qualifier.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use InvalidArgumentException;
use ScoutLib\Item;

class Qualifier extends Item
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Add a new qualifier.
     * @param string $Name Name of qualifier to create.
     * @return Qualifier Newly created qualifier.
     * @throws InvalidArgumentException If qualifier name is empty.
     */
    public static function create(string $Name): Qualifier
    {
        if (strlen($Name) == 0) {
            throw new InvalidArgumentException(
                "Qualifier names cannot be empty."
            );
        }

        return self::createWithValues([
            "QualifierName" => $Name,
        ]);
    }

    /**
     * Get or set the qualifier namespace.
     * @param string $NewValue Optional new qualfier namespace.
     * @return string The current qualifier namespace.
     */
    public function nSpace(?string $NewValue = null): string
    {
        return $this->DB->updateValue("QualifierNamespace", $NewValue);
    }

    /**
     * Get or set the qualifier URL.
     * @param string $NewValue Optional new qualifier URL.
     * @return string The current qualifier URL.
     */
    public function url(?string $NewValue = null): string
    {
        return $this->DB->updateValue("QualifierUrl", $NewValue);
    }
}
