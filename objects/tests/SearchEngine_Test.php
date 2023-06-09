<?PHP
#
#   FILE:  SearchEngine_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

class SearchEngine_Test extends \PHPUnit\Framework\TestCase
{
    protected static $Schema;
    protected static $RFactory;
    protected static $Engine;

    # these will need to be updated when the sample resources are updated
    # (running phpunit --verbose will show the messages detailing why a test
    # was skipped)
    protected const NUM_SAMPLE_RESOURCES = 43;
    protected const SAMPLE_RESOURCE_CHECKSUM =
        "d09ef27f62ddd95cac0986d40de64d544027c2883624d64f181d100460f2c3dd";

    /**
     * Perform setup tasks, then check that this sandbox contains the sample
     * resources, marking this test as skipped if not.
     */
    public function setUp(): void
    {
        self::$Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
        self::$RFactory = new RecordFactory();
        self::$Engine = new SearchEngine();

        if (self::$RFactory->getItemCount() != self::NUM_SAMPLE_RESOURCES) {
            $this->markTestSkipped(
                "Wrong number of resources to be a fresh install"
            );
            return;
        }

        $Summary = [];
        $ItemIds = self::$RFactory->getItemIds();
        foreach ($ItemIds as $ItemId) {
            $Resource = new Record($ItemId);
            $Summary[$Resource->getMapped("Url")] = $Resource->getMapped("Title")."\n"
                .$Resource->getMapped("Description");
        }
        asort($Summary);

        $Checksum = hash("sha256", serialize($Summary));

        if ($Checksum != self::SAMPLE_RESOURCE_CHECKSUM) {
            $this->markTestSkipped(
                "Wrong checksum for sample resources (".$Checksum.")"
            );
        }

        if (SearchEngine::thereAreIndexUpdateTasksInQueue()) {
            $this->markTestSkipped("There are search index update tasks pending.");
        }
    }

    /**
     * Exercise SearchEngine and the underlying SearchEngine with a variety
     * of test searches. Expected results are based on a fresh install
     * containing the default sample records.
     */
    public function testSearchEngine()
    {
        self::$Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
        self::$RFactory = new RecordFactory();
        self::$Engine = new SearchEngine();

        # rebuild search index
        self::$Engine->updateForItems(
            0,
            self::NUM_SAMPLE_RESOURCES
        );

        # Keyword search for 'weather'
        $Expected = [
            44 => 9,
        ];
        $Results = $this->performSearch("weather", [], $Expected);

        $this->assertEquals(
            count($Expected),
            self::$Engine->numberOfResults(),
            "Result count incorrect"
        );

        $this->assertEquals(
            ["weather"],
            self::$Engine->searchTerms(),
            "Incorrect return value for searchTerms."
        );

        $Results = self::$Engine->search("weather");
        $this->assertEquals(
            $Expected,
            $Results,
            "Incorrect results for string 'weather'"
        );

        # perform a series of keyword searches that test exclusions, phrases, and synonyms
        $TestKeywordSearches = [
            'bird' => [
                31 => 3,
                28 => 1,
            ],
            'birds' => [
                31 => 9,
                28 => 2,
            ],
            'bird -london' => [
                31 => 3,
            ],
            'bird +london' => [
                28 => 10,
            ],
            'bird ~london' => [
                31 => 3,
                28 => 10,
            ],
            'bird "modern london"' => [
                28 => 13,
            ],
            'bird -"modern london"' => [
                31 => 3,
            ],
            "ten" => [
                8  => 1,
                10 => 6,
                3  => 1,
                6  => 1,
                20 => 1,
                45 => 1,

            ]
        ];
        foreach ($TestKeywordSearches as $SearchString => $Expected) {
            $this->performSearch($SearchString, [], $Expected);
        }

        # fielded searches to test prefix / suffix matches

        # Title: ^bird
        $Expected = [
            31 => 1,
        ];
        $this->performSearch([], ["Title" => "^bird"], $Expected);

        # Title: $project
        $Expected = [
            31 => 1,
            36 => 1,
        ];
        $this->performSearch([], ["Title" => '$project'], $Expected);

        # narrow the results of a keyword search by adding fielded searches

        # bird, Title != Birds of North America
        $Expected = [
            31 => 3,
        ];
        $this->performSearch(
            "bird",
            ["Title" => "!=MoEML: The Map of Early Modern London"],
            $Expected
        );

        # bird, Title = Birds of North America
        $Expected = [
            28 => 1,
        ];
        $this->performSearch(
            "bird",
            ["Title" => "=MoEML: The Map of Early Modern London"],
            $Expected
        );

        # Record Status contains Published
        $Expected = array_fill_keys(self::$RFactory->GetItemIds(), 2);
        $this->performSearch([], ["Record Status" => "Published"], $Expected);

        # Record Status contains "Published" (a phrase search)
        $Expected = array_fill_keys(self::$RFactory->GetItemIds(), 3);
        $this->performSearch([], ["Record Status" => '"Published"'], $Expected);

        # Record status is published (a comparison search)
        $Expected = array_fill_keys(self::$RFactory->GetItemIds(), 1);
        $this->performSearch([], ["Record Status" => "=Published"], $Expected);


        # Date of record Creation is after 2000-01-01 (comparison on a Timestamp)
        $Expected = array_fill_keys(self::$RFactory->GetItemIds(), 1);
        $this->performSearch([], ["Date Of Record Creation" => ">=2000-01-01"], $Expected);

        # Date Issued is after 2000-01-01 (comparison on a Date)
        $Expected = [
            2  => 1,
            3  => 1,
            7  => 1,
            10 => 1,
            17 => 1,
            21 => 1,
            26 => 1,
            31 => 1,
        ];
        $this->performSearch([], ["Date Issued" => ">=2000-01-01"], $Expected);

        # Description -is (exclusion in a field)
        $Expected = [
            3 => 1,
            13 => 1,
            27 => 1,
            28 => 1,
            31 => 1,
            37 => 1,
            40 => 1,
        ];
        $this->performSearch([], ["Description" => "-is"], $Expected);

        # publisher is British Library (equality in a CName)
        $Expected = [
            37 => 1,
        ];
        $this->performSearch([], ["Publisher" => "=British Library"], $Expected);

        # classification is under World history
        $Expected = [
            27 => 1,
            18 => 1,
        ];
        $this->performSearch([], ["Classification" => "^Science --"], $Expected);

        # classification is World history -- Information resources
        $Expected = [
        ];
        $this->performSearch(
            [],
            ["Classification" => "=Science -- Social aspects"],
            $Expected
        );

        # test searching with subgroups
        $Params = $this->getTestParamsWithSubgroup();
        $Expected = [
            44 => 10,
        ];
        $Results = self::$Engine->Search($Params);
        $this->assertEquals(
            $Expected,
            $Results,
            "Incorrect results for ".$Params->TextDescription(false, false)
        );

        # add an item type restriction
        $Params->ItemTypes(MetadataSchema::SCHEMAID_DEFAULT);
        $Results = self::$Engine->Search($Params);
        $this->assertEquals(
            $Expected,
            $Results,
            "Incorrect results for ".$Params->TextDescription(false, false)
        );

        # change to OR logic
        $Params->Logic("OR");
        $Expected = [
            44 => 10,
        ];
        $Results = self::$Engine->Search($Params);
        $this->assertEquals(
            $Expected,
            $Results,
            "Incorrect results for ".$Params->TextDescription(false, false)
        );

        # add a sortBy
        $Params->sortBy(self::$Schema->getField("Title")->id());
        $Results = self::$Engine->Search($Params);
        $this->assertEquals(
            $Expected,
            $Results,
            "Incorrect results for ".$Params->TextDescription(false, false)
        );

        # test AND searching with subgroups where one subgroup is empty
        $Params = $this->getTestParamsWithEmptySubgroup();
        $Expected = [ ];
        $Results = self::$Engine->Search($Params);
        $this->assertEquals(
            $Expected,
            $Results,
            "Incorrect results for ".$Params->TextDescription(false, false)
        );

        # toggle logic
        $Params->Logic("OR");

        $Expected = array_fill_keys(self::$RFactory->GetItemIds(), 1);
        $Results = self::$Engine->Search($Params);
        $this->assertEquals(
            $Expected,
            $Results,
            "Incorrect results for ".$Params->TextDescription(false, false)
        );
    }

    /**
     * Perform a simple search (one w/o subgroups) and assert that the results
     * are correct.
     * @param array|string $Keywords Keyword parameters to include.
     * @param array $Fields Fielded search parameters to include, keyed by
     *   field name with values giving a parameter or array of parameters for
     *   the given field.
     * @param array $Expected Correct result for the sample records, in the
     *   format returned by SearchEngine::Search()
     */
    private function performSearch($Keywords, $Fields, $Expected)
    {
        if (!is_array($Keywords)) {
            $Keywords = [$Keywords];
        }

        $Params = new SearchParameterSet();

        foreach ($Keywords as $Keyword) {
            $Params->addParameter($Keyword);
        }

        foreach ($Fields as $FieldName => $SearchTerms) {
            $Params->addParameter(
                $SearchTerms,
                self::$Schema->getField($FieldName)
            );
        }

        $Results = self::$Engine->Search($Params);

        $this->assertEquals(
            $Expected,
            $Results,
            "Incorrect results for ".$Params->TextDescription(false, false)
        );

        return $Results;
    }

    /**
     * Construct a SearchParameterSet with a per-field subgroup.
     * @return SearchParameterSet Test search.
     */
    private function getTestParamsWithSubgroup()
    {
        $Params = new SearchParameterSet();
        $Params->addParameter("weather");
        $SubParams = new SearchParameterSet();
        $SubParams->addParameter(
            "=Weather Spark",
            self::$Schema->getField("Title")
        );
        $Params->addSet($SubParams);

        return $Params;
    }

    /**
     * Construct a SearchParameterSet with several per-field subgroups where
     * one of them produces no results.
     * @return SearchParameterSet Test search.
     */
    private function getTestParamsWithEmptySubgroup()
    {
        $Params = new SearchParameterSet();

        $SubParams = new SearchParameterSet();
        $SubParams->addParameter(
            "=Extinct",
            self::$Schema->getField("Record Status")
        );
        $Params->addSet($SubParams);

        $SubParams = new SearchParameterSet();
        $SubParams->addParameter(
            "=1",
            self::$Schema->getField("Added By Id")
        );
        $Params->addSet($SubParams);

        return $Params;
    }
}
