<?PHP
#
#   FILE:  Collection.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\StdLib;

/**
 * Collection of items.
 */
class Collection extends Record
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    const DEFAULT_COLOR = "#666666";

    /**
     * Get IDs for all items in collection.
     * @return array Item IDs.
     */
    public function getItemIds(): array
    {
        $SEngine = new SearchEngine();
        $SearchParams = $this->get("Selection Criteria");
        $SearchResults = $SEngine->search($SearchParams);
        return array_keys($SearchResults);
    }

    /**
     * Get collection size (number of items currently in collection).
     * @return int Item count.
     */
    public function getSize(): int
    {
        return count($this->getItemIds());
    }

    /**
     * Get interface color associated with collection.
     * @return string Color as a RGB triplet (e.g. "#1A2B3C").
     */
    public function getColor(): string
    {
        $Color = $this->get("Color");
        if (is_null($Color) || strlen(trim($Color)) == 0) {
            $Color = $this->getUnusedHexColor();
            $this->set("Color", $Color);
        }
        return $Color;
    }

    /**
     * Get monogram letter(s) for collection.
     * @return string Letter or letters.
     */
    public function getMonogram(): string
    {
        $Monogram = $this->get("Monogram");
        if (is_null($Monogram) || strlen($Monogram) == 0) {
            $Name = $this->get("Name");
            $WordsToSkip = [
                "a ",
                "all ",
                "an ",
                "at ",
                "by ",
                "for ",
                "some ",
                "the ",
            ];
            $Name = str_ireplace($WordsToSkip, "", $Name);
            $Name = ucfirst($Name);
            $Monogram = $Name[0];
        }
        return $Monogram;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Generate and return hexadecimal RGB triplet, suitable for use in CSS,
     * different from any other currently in use in the "Color" field..
     * @return string RGB hex triplet, with leading "#".
     */
    protected function getUnusedHexColor(): string
    {
        $MaxNumberOfTries = 100;
        $TryCounter = 0;
        $CFactory = new CollectionFactory();
        while (!isset($Color) && ($TryCounter < $MaxNumberOfTries)) {
            $PossibleColor = $this->getRandomHexColor(18);
            $ValuesToMatch = ["Color" => $PossibleColor];
            if ($CFactory->getCountOfMatchingRecords($ValuesToMatch) == 0) {
                $Color = $PossibleColor;
            }
            $TryCounter++;
        }
        if (!isset($Color)) {
            $Color = static::DEFAULT_COLOR;
        }
        return $Color;
    }

    /**
     * Generate and return hexadecimal RGB triplet, suitable for use in CSS.
     * @param int $NumberOfPossibleColors This number is used to divide the
     *      color spectrum into bins, to help ensure the colors returned are
     *      distinct from one another.  For optimal results, this number
     *      should be evenly divisible into 360.
     * @return string RGB hex triplet, with leading "#".
     */
    protected function getRandomHexColor(int $NumberOfPossibleColors): string
    {
        $Seed = rand(0, ($NumberOfPossibleColors - 1));
        $Hue = $Seed * (360 / $NumberOfPossibleColors);
        $HexColor = StdLib::hslToHexColor($Hue, 50, 50);
        return $HexColor;
    }
}
