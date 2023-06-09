<?PHP

namespace Metavus;

use Exception;
use InvalidArgumentException;

# NOTE: tests in this class currently assume that the CWIS sample records have
# been loaded. `setUp()` checks this and skips all tests when the collection
# is *not* just the sample records. A better solution (to be implemented in
# the future) would be to create a test schema and then load a set of test
# records from an XML file into that schema, deleting those records and the
# schema after the tests complete.

class RecordFactory_Test extends \PHPUnit\Framework\TestCase
{
    protected static $RFactory;

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
    public function setUp() : void
    {
        self::$RFactory = new RecordFactory();

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
    }

    /**
     * Test RecordFactory assuming sample records in a fresh install.
     */
    public function testRecordFactory()
    {
        # ensure that caches and record counts can be cleared
        # without an error
        RecordFactory::clearViewingPermsCache();
        self::$RFactory->clearCaches();
        self::$RFactory->clearVisibleRecordCount(
            new Record(2)
        );

        # test recordExists, and various count methods
        $this->assertTrue(
            RecordFactory::recordExistsInAnySchema(2),
            "Record not found when it should be"
        );

        $this->assertEquals(
            43,
            self::$RFactory->getReleasedRecordTotal(),
            "Released records count is incorrect."
        );

        $this->assertEquals(
            0,
            self::$RFactory->getRatedRecordCount(),
            "Rated record count is not zero."
        );

        $this->assertEquals(
            0,
            self::$RFactory->getRatedRecordUserCount(),
            "Rated record user count is not zero."
        );

        # test flattening and building multi-schema lists
        $FlatList = [2, 1];
        $MultiList = [
            MetadataSchema::SCHEMAID_DEFAULT => [ 2 ] ,
            MetadataSchema::SCHEMAID_USER => [ 1 ],
        ];

        $this->assertEquals(
            $MultiList,
            RecordFactory::buildMultiSchemaRecordList($FlatList),
            "Multi schema record list built incorrectly"
        );

        $this->assertEquals(
            $FlatList,
            RecordFactory::flattenMultiSchemaRecordList($MultiList),
            "Multi schema record list flattened incorrectly"
        );

        # test getting sorted recordIds
        $SortTests = $this->getSortTestData();
        foreach ($SortTests as $TestData) {
            list($Field, $Ascending, $Expected) = $TestData;
            $this->assertEquals(
                [$Expected],
                self::$RFactory->getRecordIdsSortedBy($Field, $Ascending, 1)
            );
        }

        # test getIdsOfMatchingRecords and getCountOfMatchingRecords
        $MatchTests = $this->getMatchTestData();
        foreach ($MatchTests as $TestData) {
            list($ValuesToMatch, $AllRequired, $Operator, $Expected) = $TestData;
            $this->assertEquals(
                $Expected,
                self::$RFactory->getIdsOfMatchingRecords(
                    $ValuesToMatch,
                    $AllRequired,
                    $Operator
                )
            );

            $this->assertEquals(
                count($Expected),
                self::$RFactory->getCountOfMatchingRecords(
                    $ValuesToMatch,
                    $AllRequired,
                    $Operator
                )
            );
        }

        # setup the invalid cases for REGEXP that will be tested
        $InvalidREGEXPCasesTests = [
            [
                [
                    "Title" => [
                        "[-]",
                        "[;]"
                    ],
                ],
                "Operator REGEXP is not supported for fields with multiple values"
            ],
            [
                [
                    "Title" => "",
                ],
                "Value for REGEX comparisons must be a non-empty string"
            ],
            [
                [
                    "Title" => 1997,
                ],
                "Value for REGEX comparisons must be a non-empty string"
            ],
            [
                [
                    "Date Of Record Creation" => "2022",
                ],
                "Operator REGEXP is not supported for TimeStamp fields"
            ],
        ];

        foreach ($InvalidREGEXPCasesTests as $InvalidCase) {
            list($ValuesToMatch, $ErrMsg) = $InvalidCase;
            # ensure that getIdsOfMatchingRecords() will throw exceptions when expected to
            try {
                self::$RFactory->getIdsOfMatchingRecords(
                    $ValuesToMatch,
                    true,
                    "=~"
                );
                $this->fail("Exception not thrown on invalid use of REGEXP operator.");
            } catch (Exception $e) {
                $this->assertInstanceOf(InvalidArgumentException::class, $e);
                $this->assertEquals(
                    $e->getMessage(),
                    $ErrMsg
                );
            }
            # ensure that getCountOfMatchingRecords() will throw exceptions when expected to
            try {
                self::$RFactory->getCountOfMatchingRecords(
                    $ValuesToMatch,
                    true,
                    "=~"
                );
                $this->fail("Exception not thrown on invalid use of REGEXP operator.");
            } catch (Exception $e) {
                $this->assertInstanceOf(InvalidArgumentException::class, $e);
                $this->assertEquals(
                    $e->getMessage(),
                    $ErrMsg
                );
            }
        }

        $MatchingRecIds = array_filter(
            self::$RFactory->getIdsOfMatchingRecords(
                ["Title" => "NULL"]
            ),
            function ($Id) {
                return ($Id > 0);
            }
        );
        $this->assertEquals(
            [],
            $MatchingRecIds
        );

        # test associatedVisibleRecordCount()
        $CNId = (new MetadataSchema())
            ->getField("Record Status")
            ->getFactory()
            ->getItemIdByName("Published");

        $this->assertEquals(
            43,
            self::$RFactory->associatedVisibleRecordCount(
                $CNId,
                User::getAnonymousUser(),
                true
            ),
            "Incorrect visible record count."
        );

        # ensure exceptions are thrown where they should be
        try {
            self::$RFactory->getIdsOfMatchingRecords(
                ["Title" => "NULL"],
                true,
                "!="
            );
            $this->assertFalse(
                true,
                "Exception not calling getIdsOfMatchingRecords() and searching "
                ."for != NULL in a text field"
            );
        } catch (Exception $e) {
            ;
        }

        try {
            self::$RFactory->clearVisibleRecordCountForValues([]);
            $this->assertFalse(
                true,
                "Exception not thrown calling clearVisibleRecordCountForValues() "
                ."without providing any values"
            );
        } catch (Exception $e) {
            ;
        }

        try {
            self::$RFactory->getRecordIdsSortedBy("Record Status");
            $this->assertFalse(
                true,
                "Exception not thrown calling getRecordIdsSortedBy() with unsupported field"
            );
        } catch (Exception $e) {
            ;
        }

        try {
            self::$RFactory->getIdsOfMatchingRecords(
                ["Record Status" => "Published"]
            );
            $this->assertFalse(
                true,
                "Exception not thrown calling getMatchingRecordIds with unsupported field"
            );
        } catch (Exception $e) {
            ;
        }

        try {
            self::$RFactory->getIdsOfMatchingRecords(
                ["Title" => "foo"],
                true,
                ">="
            );
            $this->assertFalse(
                true,
                "Exception not thrown calling getIdsOfMatchingRecords with "
                ."invalid operator for field"
            );
        } catch (Exception $e) {
            ;
        }

        try {
            self::$RFactory->getIdsOfMatchingRecords(
                ["Added By Id" => "Strings Are Not Valid"]
            );
            $this->assertFalse(
                true,
                "Exception not thrown calling getIdsOfMatchingRecords with "
                ."invalid value for user field"
            );
        } catch (Exception $e) {
            ;
        }

        $FieldsNeverEmpty = [
            "Title", # text
            "Date Last Modified", # timestamp
            "Classification", # tree
            "Language", # controlled name
            "Added By Id", # user
        ];
        foreach ($FieldsNeverEmpty as $FieldName) {
            $this->assertEquals(
                [],
                self::$RFactory->getRecordIdsWhereFieldIsEmpty($FieldName),
                "Found records where ".$FieldName." is empty when there should be none."
            );
        }

        $RecsWithDateIssued = array_values(
            array_diff(
                self::$RFactory->getItemIds(),
                self::$RFactory->getRecordIdsWhereFieldIsEmpty("Date Issued")
            )
        );

        $this->assertEquals(
            [2, 3, 7, 10, 17, 21, 26, 31],
            $RecsWithDateIssued,
            "Found records where Date Issued is empty and it should not be."
        );
    }

    /**
     * Get arguments for xxMatchingRecords() function tests
     * @return array [ [ValuesToMatch, AllRequired, Operator, ExpectedResult] , ...]
     *  where the first three elements are arguments for the function being tested.
     */
    private function getMatchTestData() : array
    {
        $MatchTests = [
            [
                [
                    "Title" => "Weather Spark"
                ],
                true,
                "==",
                [44]
            ],
            [
                [
                    "Title" => "Refugee Flow",
                    "Added By Id" => 1,
                ],
                true,
                "==",
                [42]
            ],
            [
                [
                    "Title" => "Refugee Flow",
                    "Added By Id" => new User(1),
                ],
                true,
                "==",
                [42]
            ],
            [
                [
                    "Added By Id" => [],
                ],
                true,
                "==",
                []
            ],
            [
                [],
                true,
                "==",
                []
            ],
            [
                [
                    "Title" => "[-]",
                ],
                true,
                "=~",
                [31, 37]
            ],
            [
                [
                    "Title" => "[;]",
                ],
                true,
                "=~",
                []
            ],
        ];

        return $MatchTests;
    }

    /**
     * Get arguments for getRecordIdsSortedBy()  tests
     * @return array [ [SortField, SortAscending, ExpectedFirstRecordId] , ...]
     *  where the first two elements are arguments for the function being tested.
     */
    private function getSortTestData() : array
    {
        $SortTests = [
            ["Title", true, 25],
            ["Title", false, 26],
            ["Date Issued", true, 26 ],
            ["Added By Id", true, 2 ],
        ];
        return $SortTests;
    }
}
