<?PHP
#
#   FILE:  SearchParameterSet_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

# NOTE: tests in this class currently assume that the Metavus sample records
# have been loaded. `setUp()` checks this and skips all tests when the
# collection is *not* just the sample records. A better solution (to be
# implemented in the future) would be to create a test schema and then load a
# set of test records from an XML file into that schema, deleting those
# records and the schema after the tests complete.

class SearchParameterSet_Test extends \PHPUnit\Framework\TestCase
{
    # ---- SETUP -------------------------------------------------------------

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


    # ---- TESTS -------------------------------------------------------------

    /**
     * Test SearchParameterSet assuming sample records in a fresh install.
     */
    public function testSearchParameterSet()
    {
        $Schema = new MetadataSchema();

        # keyword searches
        $Params = new SearchParameterSet();
        $Params->addParameter("Keyword Test");
        $Expected = ["Keyword Test"];
        $this->assertEquals($Expected, $Params->getKeywordSearchStrings());

        # Option field - Record Status
        $FieldName = "Record Status";
        $FieldId = $Schema->getFieldIdByName($FieldName);

        # test an operator with no term
        $Expected = [
            $FieldId => [
                "="
            ],
        ];
        $Params = new SearchParameterSet();
        $Params->addParameter("=", $FieldName);
        $this->assertEquals($Expected, $Params->getSearchStrings());

        # test providing a term name
        $Expected = [
            $FieldId => [ "=Published" ],
        ];

        $Params = new SearchParameterSet();
        $Params->addParameter("=Published", $FieldName);
        $this->assertEquals($Expected, $Params->getSearchStrings());

        $this->assertEquals(
            $Expected[$FieldId],
            $Params->getSearchStringsForField($FieldName)
        );

        # provide a string that is not a term
        $Expected = [
            $FieldId => ["=X-EXAMPLE-X"]
        ];
        $Params = new SearchParameterSet();
        $Params->addParameter("=X-EXAMPLE-X", $FieldName);
        $this->assertEquals($Expected, $Params->getSearchStrings());

        # provide a number that is not a Term ID
        $Expected = [
            $FieldId => ["=9999"]
        ];
        $Params = new SearchParameterSet();
        $Params->addParameter("=9999", $FieldName);
        $this->assertEquals($Expected, $Params->getSearchStrings());

        # Option field - Record status, several values
        $Expected = [
            $FieldId => ["=Published", "=Prepublication"],
        ];
        $Params = new SearchParameterSet();
        $Params->addParameter(["=Published", "=Prepublication"], $FieldName);
        $this->assertEquals($Expected, $Params->getSearchStrings());

        # remove a parameter (array format)
        $Expected = [
            $FieldId => [ "=Published" ],
        ];
        $Params->removeParameter(["=Prepublication"], $FieldName);
        $this->assertEquals($Expected, $Params->getSearchStrings());

        # remove the other parameter (scalar format)
        $Params->removeParameter("=Published", $FieldName);
        $this->assertEquals([], $Params->getSearchStrings());

        # Controlled Name field - Publisher
        $FieldName = "Publisher";
        $FieldId = $Schema->getFieldIdByName($FieldName);
        $Expected = [
            $FieldId => ["=British Library"],
        ];

        # test providing a term name
        $Params = new SearchParameterSet();
        $Params->addParameter("=British Library", $FieldName);
        $this->assertEquals($Expected, $Params->getSearchStrings());

        # Tree field - Classification
        $FieldName = "Classification";
        $FieldId = $Schema->getFieldIdByName($FieldName);
        $Expected = [
            $FieldId => ["=Biodiversity"],
        ];
        $Params = new SearchParameterSet();
        $Params->addParameter("=Biodiversity", $FieldName);
        $this->assertEquals($Expected, $Params->getSearchStrings());

        $Expected = [
            $FieldId => ["^Biodiversity"],
        ];
        $Params = new SearchParameterSet();
        $Params->addParameter("^Biodiversity", $FieldName);
        $this->assertEquals($Expected, $Params->getSearchStrings());
    }


    # ---- PRIVATE -----------------------------------------------------------
    protected static $RFactory;

    # these will need to be updated when the sample resources are updated
    # (running phpunit --verbose will show the messages detailing why a test
    # was skipped, which includes a checksum of the current set of records)
    protected const NUM_SAMPLE_RESOURCES = 43;
    protected const SAMPLE_RESOURCE_CHECKSUM =
        "d09ef27f62ddd95cac0986d40de64d544027c2883624d64f181d100460f2c3dd";
}
