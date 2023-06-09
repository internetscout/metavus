<?PHP
#
#   FILE:  ControlledNameFactory_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

class ControlledNameFactory_Test extends \PHPUnit\Framework\TestCase
{
    protected static $TestFieldIds;
    protected static $TestFields;
    protected static $TestName;

    public static function setUpBeforeClass() : void
    {
        # construct the schema object
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);

        self::$TestFieldIds = [];

        # outline fields to be created
        self::$TestFields = ["ControlledNameTestField" => MetadataSchema::MDFTYPE_CONTROLLEDNAME];

        # create the fields
        foreach (self::$TestFields as $FieldName => $FieldType) {
            $TmpField = $Schema->GetItemByName($FieldName);
            if ($TmpField === null) {
                $TmpField = $Schema->AddField($FieldName, $FieldType);
            }
            $TmpField->IsTempItem(false);
            self::$TestFieldIds[$FieldName] = $TmpField->Id();
        }
    }

    public static function tearDownAfterClass() : void
    {
        # construct the schema object
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);

        # drop all of the test fields
        foreach (self::$TestFieldIds as $FieldId) {
            $Schema->DropField($FieldId);
        }

        if (self::$TestName !== null) {
            self::$TestName->destroy(true);
        }
    }

    public function testControlledNameFactory()
    {
        $MyId = self::$TestFieldIds['ControlledNameTestField'];

        $Factory = new ControlledNameFactory($MyId);

        $this->assertEquals(
            0,
            $Factory->GetUsageCount(),
            "Zero usage for newly created CName field"
        );

        $this->assertEquals(
            [],
            $Factory->ControlledNameSearch("*"),
            "No results from empty field"
        );

        self::$TestName = ControlledName::Create(
            "TestName",
            $MyId
        );

        $Expected = [ (string)self::$TestName->Id() ];

        $this->assertEquals(
            $Expected,
            $Factory->ControlledNameSearch("*"),
            "Correct results for wildcard search"
        );

        $this->assertEquals(
            $Expected,
            $Factory->ControlledNameSearch("*"),
            "Correct results for exact search"
        );

        $this->assertEquals(
            [],
            $Factory->FindMatchingRecentlyUsedValues(""),
            "No recently used values for empty string"
        );

        $this->assertEquals(
            [],
            $Factory->FindMatchingRecentlyUsedValues("TestName"),
            "No recently used values for exact search"
        );

        $this->assertEquals(
            [],
            $Factory->FindMatchingRecentlyUsedValues(
                "TestName",
                5,
                [self::$TestName->Id()],
                [self::$TestName->Name()]
            ),
            "No recently used values for exact search with value and id exclusions"
        );

        # Create test records
        $Record1 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $Record1->set("Title", "ControlledNameFactory_Test1");
        $Record2 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $Record2->set("Title", "ControlledNameFactory_Test2");

        # Associate the test records to the the test controlled name
        $Record1->set(self::$TestName->fieldId(), "TestName");
        $Record2->set(self::$TestName->fieldId(), "TestName");

        $AssociatedControlledNameIds = $Factory->getAssociatedControlledNameIds([
            $Record1->id(),
            $Record2->id()
        ]);

        $ActualControlledNameId = array_key_first($AssociatedControlledNameIds);

        # Test that the controlled name id returned is correct and has the correct ids of the
        # test records associated with it
        $this->assertEquals(count($AssociatedControlledNameIds), 1);
        $this->assertEquals($ActualControlledNameId, self::$TestName->id());
        $this->assertEquals(
            $AssociatedControlledNameIds[$ActualControlledNameId][0],
            $Record2->id()
        );
        $this->assertEquals(
            $AssociatedControlledNameIds[$ActualControlledNameId][1],
            $Record1->id()
        );

        # Test that the returned field id is the correct id that corresponds
        # with the given controlled name id
        $this->assertEquals(
            array_values($Factory->getFieldIds([self::$TestName->id()]))[0],
            self::$TestName->fieldId()
        );

        # Destroy test records
        $Record1->destroy();
        $Record2->destroy();
    }
}
