<?PHP
#
#   FILE:  ControlledName_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

use Exception;
use InvalidArgumentException;

class ControlledName_Test extends \PHPUnit\Framework\TestCase
{
    protected static $TestFieldIds;
    protected static $TestFields;

    /**
    * Prior to running any of the tests, this function is
    * run. It creates all of the test Metadata fields and adds
    * them to class variables $TestFieldIds and $TestFields
    * so each function may use them.
    */
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

    /**
    * After to running the tests, this function is
    * run. It deletes all of the test Metadata fields.
    */
    public static function tearDownAfterClass() : void
    {
        # construct the schema object
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);

        # drop all of the test fields
        foreach (self::$TestFieldIds as $FieldId) {
            $Schema->DropField($FieldId);
        }
    }


    public function testControlledName()
    {
        $MyId = self::$TestFieldIds['ControlledNameTestField'];

        # create a new name
        $TestName = ControlledName::Create("TestName", $MyId);
        $this->assertInstanceOf(ControlledName::class, $TestName);
        $this->assertEquals($TestName->FieldId(), $MyId);
        $this->assertEquals($TestName->Name(), "TestName");
        $this->assertEquals($TestName->InUse(), 0);
        $this->assertEquals($TestName->GetAssociatedResources(), []);
        $this->assertEquals($TestName->VariantName(), null);
        $this->assertEquals($TestName->Qualifier(), null);

        # test setting / updating / clearing variants
        $this->assertEquals($TestName->VariantName("TestVariant"), "TestVariant");
        $this->assertEquals($TestName->VariantName(), "TestVariant");
        $this->assertEquals($TestName->VariantName("ChangedVariant"), "ChangedVariant");
        $this->assertEquals($TestName->VariantName(), "ChangedVariant");
        $this->assertEquals($TestName->VariantName(false), null);
        $this->assertEquals($TestName->VariantName(), null);

        # test setting / clearing Qualifiers
        $MyQual = Qualifier::Create("TestQual");
        $this->assertEquals($TestName->Qualifier($MyQual)->Id(), $MyQual->Id());
        $this->assertEquals($TestName->Qualifier()->Id(), $MyQual->Id());

        $this->assertEquals($TestName->QualifierId(false), false);
        $this->assertEquals($TestName->Qualifier(), false);

        # test creating an invalid qualifier
        try {
            Qualifier::create("");
            $this->fail(
                "InvalidArgumentException not thrown on creation of qualifier with empty name."
            );
        } catch (Exception $e) {
            $this->assertEquals(
                $e->getMessage(),
                "Qualifier names cannot be empty."
            );
            $this->assertEquals(get_class($e), "InvalidArgumentException");
        }

        $MyQual->Destroy();

        # test controlled name remapping
        $Record = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $Record->set($MyId, "TestName");
        # Assert that the new record got associated with the controlled name
        $this->assertEquals(
            $TestName->getAssociatedResources(true)[0],
            $Record->id()
        );

        # Create the new controlled name to remap to
        $NewTestName = ControlledName::Create("NewTestName", $MyId);
        # Remap the controlled name associated resources
        # then, test that the old controlled name doesn't
        # have any resources associated to it anymore
        # then, test that the new controlled name has the remapped resources associated to it
        $TestName->remapTo($NewTestName->id());
        $this->assertEquals($TestName->getAssociatedResourceCount(true), 0);
        $this->assertEquals(
            $NewTestName->getAssociatedResources(true)[0],
            $Record->id()
        );

        # destroy a record
        $Record->destroy();

        # Create a duplicate of the name
        $this->assertEquals(ControlledName::ControlledNameExists("TestName", $MyId), true);
        $TestDup = ControlledName::Create("TestName", $MyId);
        $this->assertEquals($TestDup->Id(), $TestName->Id());

        # load an invalid name
        try {
            $ExpIsThrown = false;
            $TestInv = new ControlledName(-5000);
        } catch (Exception $E) {
            $ExpIsThrown = true;
            $this->assertEquals(get_class($E), "InvalidArgumentException");
        }
        $this->assertEquals($ExpIsThrown, true);

        # delete names
        $TestName->destroy(true);
        $NewTestName->destroy(true);
    }
}
