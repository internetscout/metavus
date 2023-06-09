<?PHP

namespace ScoutLib;

use ReflectionObject;
use ScoutLib\SearchEngine;

/**
* Test cases for SearchEngine.
*/
class SearchEngine_Test extends \PHPUnit\Framework\TestCase
{
    /**
    * Test parseSearchStringForWords
    */
    public function testParseSearchStringForWords()
    {
        # grab an Engine
        $Engine = new TestSearchEngine(
            "Records",
            "RecordId",
            "SchemaId"
        );

        # pull out a Reflector
        $Reflector = new ReflectionObject($Engine);

        # get a ReflectionMethod for PSSFW and make it accessible
        $Parse = $Reflector->getMethod(
            'parseSearchStringForWords'
        );
        $Parse->setAccessible(true);

        # pull out class constants for easier use
        $Present = SearchEngine::WORD_PRESENT;
        $Excluded = SearchEngine::WORD_EXCLUDED;
        $Required = SearchEngine::WORD_REQUIRED;

        # with AND logic, all terms are required
        $this->assertEquals(
            $Parse->invoke($Engine, "THIS IS A TEST", "AND"),
            array(
                "this" => $Present | $Required,
                "is" => $Present | $Required,
                "a" => $Present | $Required,
                "test" => $Present | $Required,
            )
        );

        # with OR logic, no terms are required
        $this->assertEquals(
            $Parse->invoke($Engine, "THIS IS A TEST", "OR"),
            array(
                "this" => $Present,
                "is" => $Present,
                "a" => $Present,
                "test" => $Present,
            )
        );

        # command characters (plus, minus, and tilde) override Logic
        foreach (array("AND", "OR") as $Logic) {
            $this->assertEquals(
                $Parse->invoke($Engine, "-WORD ~EXCLUSION +TEST", $Logic),
                array(
                    "word" => $Present | $Excluded,
                    "exclusion" => $Present,
                    "test" => $Present | $Required,
                )
            );
        }

        # possessive plurals are stripped from search words
        $this->assertEquals(
            $Parse->invoke($Engine, "Children's toys", "AND"),
            array(
                "children" => $Present | $Required,
                "toys" => $Present | $Required,
            )
        );

        # single quotes are stripped from search string
        $this->assertEquals(
            $Parse->invoke($Engine, "Children's 'toys'", "AND"),
            array(
                "children" => $Present | $Required,
                "toys" => $Present | $Required,
            )
        );

        # phrases are stripped from search words
        $this->assertEquals(
            $Parse->invoke($Engine, "\"test kitten\"", "AND"),
            array()
        );

        # groups are stripped from search words
        $this->assertEquals(
            $Parse->invoke($Engine, "(test kitten)", "AND"),
            array()
        );

        # phrases treated as regular words when IgnoreGroups is TRUE
        $this->assertEquals(
            $Parse->invoke($Engine, "\"test kitten\"", "AND", true),
            array(
                "test" => $Present | $Required,
                "kitten" => $Present | $Required,
            )
        );

        # groups treated as regular words when IgnoreGroups is TRUE
        $this->assertEquals(
            $Parse->invoke($Engine, "(test kitten)", "AND", true),
            array(
                "test" => $Present | $Required,
                "kitten" => $Present | $Required,
            )
        );

        # punctuation is treated as a word separator
        $this->assertEquals(
            $Parse->invoke($Engine, "test...kitten", "AND"),
            array(
                "test" => $Present | $Required,
                "kitten" => $Present | $Required,
            )
        );

        # hyphenated words are considered as separate parts and also
        # as one combined word
        $this->assertEquals(
            $Parse->invoke($Engine, "pre-process", "AND"),
            array(
                "pre" => $Present | $Required,
                "process" => $Present | $Required,
                "preprocess" => $Present | $Required,
            )
        );

        # repeated runs of hyphens in the middle of a word are
        # squeezed to a single hyphen and then processed normally
        $this->assertEquals(
            $Parse->invoke($Engine, "pre---process", "AND"),
            array(
                "pre" => $Present | $Required,
                "process" => $Present | $Required,
                "preprocess" => $Present | $Required,
            )
        );

        # single non-hyphen command characters (plus and tilde) in the
        # middle of words are treated as word separators
        foreach (array("+", "~") as $Sep) {
            $this->assertEquals(
                $Parse->invoke($Engine, "pre".$Sep."process", "AND"),
                array(
                    "pre" => $Present | $Required,
                    "process" => $Present | $Required,
                )
            );
        }

        # runs of non-hyphen command characters (plus and tilde) in
        # the middle of words are treated as word separators
        foreach (array("+", "~") as $Sep) {
            $this->assertEquals(
                $Parse->invoke($Engine, "pre".$Sep.$Sep.$Sep."process", "AND"),
                array(
                    "pre" => $Present | $Required,
                    "process" => $Present | $Required,
                )
            );
        }

        # command characters (plus, minus, tilde) at the end of a word are
        # ignored
        foreach (array("+", "~", "-") as $Tail) {
            $this->assertEquals(
                $Parse->invoke($Engine, "test".$Tail." kitten", "AND"),
                array(
                    "test" => $Present | $Required,
                    "kitten" => $Present | $Required,
                )
            );
        }

        # runs of command characters (plus, minus, tilde) at the end
        # of a word are ignored
        foreach (array("+", "~", "-") as $Tail) {
            $this->assertEquals(
                $Parse->invoke($Engine, "test".$Tail.$Tail.$Tail." kitten", "AND"),
                array(
                    "test" => $Present | $Required,
                    "kitten" => $Present | $Required,
                )
            );
        }
    }
}

/**
* Create a concrete class extending SearchEngine (which is abstract) for testing
*/
class TestSearchEngine extends SearchEngine
{
    protected function searchFieldForPhrases(int $FieldId, string $Phrase)
    {
        throw Exception("Abstract method placeholder unexpectedly invoked.");
    }

    protected function searchFieldsForComparisonMatches(
        array $FieldIds,
        array $Operators,
        array $Values,
        string $Logic
    ): array {
        throw Exception("Abstract method placeholder unexpectedly invoked.");
    }

    public static function getItemIdsSortedByField(
        int $ItemType,
        $Field,
        bool $SortDescending
    ): array {
        throw Exception("Abstract method placeholder unexpectedly invoked.");
    }

    protected function getFieldContent(int $ItemId, string  $FieldId)
    {
        throw Exception("Abstract method placeholder unexpectedly invoked.");
    }
}
