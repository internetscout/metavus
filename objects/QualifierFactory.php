<?PHP
#
#   FILE:  QualifierFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2003-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use InvalidArgumentException;
use ScoutLib\ItemFactory;
use XMLReader;

/**
 * Factory class for Qualifier.
 */
class QualifierFactory extends ItemFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     */
    public function __construct()
    {
        parent::__construct(
            "Metavus\\Qualifier",
            "Qualifiers",
            "QualifierId",
            "QualifierName"
        );
    }

    /**
    * Import qualifier entries from XML file.
    * @param string $FileName Name of XML file.
    * @return array IDs of any new qualifiers that were added.
    * @throws InvalidArgumentException If unable to open file.
    */
    public function importQualifiersFromXmlFile(string $FileName)
    {
        $In = new XMLReader();
        $Result = $In->open($FileName);
        if ($Result === false) {
            throw new InvalidArgumentException(
                "Unable to open qualifier XML file \"".$FileName."\"."
            );
        }

        # while XML left to read
        $NewQualifierIds = [];
        while ($In->read()) {
            # if new element
            if ($In->nodeType == XMLReader::ELEMENT) {
                # if node indicates start of entry
                if ($In->name === "Qualifier") {
                    # create a new qualifier
                    $Qualifier = Qualifier::create("Placeholder Name");
                    $NewQualifierIds[] = $Qualifier->id();
                } else {
                    # if we have a current qualifier
                    if (isset($Qualifier)) {
                        # retrieve tag and value
                        $Tag = $In->name;
                        $In->read();
                        $Value = $In->value;
                        $In->read();

                        # set attribute of qualifier based on tag
                        switch ($Tag) {
                            case "Name":
                                $Qualifier->name($Value);
                                break;

                            case "Namespace":
                                $Qualifier->nSpace($Value);
                                break;

                            case "Url":
                                $Qualifier->url($Value);
                                break;

                            default:
                                break;
                        }
                    }
                }
            }
        }

        $In->close();

        return $NewQualifierIds;
    }
}
