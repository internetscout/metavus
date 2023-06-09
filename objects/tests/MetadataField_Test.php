<?PHP
#
#   FILE:  MetadataField_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

use Exception;
use ScoutLib\Database;

class MetadataField_Test extends \PHPUnit\Framework\TestCase
{
    # ---- SETUP -------------------------------------------------------------

    public static function setUpBeforeClass() : void
    {
        self::$DB = new Database();
        self::$Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
    }

    protected function tearDown() : void
    {
        $FieldNames = [
            "First Test Field",
            "Second Test Field",
            "Third Test Field",
            "TextTest"
        ];
        foreach ($FieldNames as $FieldName) {
            if (self::$Schema->fieldExists($FieldName)) {
                self::$Schema->dropField(self::$Schema->getFieldIdByName($FieldName));
            }
        }
    }


    # ---- TESTS -------------------------------------------------------------

    public function testCreate()
    {
        # create paragraph
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_PARAGRAPH,
            "First Test Field",
            null,
            "Paragraph"
        );
        $TestField->isTempItem(false);

        $this->assertCreate(
            $TestField,
            MetadataSchema::MDFTYPE_PARAGRAPH,
            "Paragraph",
            "MEDIUMTEXT"
        );
        self::$Schema->dropField($TestField->id());

        # create text
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TEXT,
            "First Test Field",
            null,
            "Text"
        );
        $TestField->isTempItem(false);

        $this->assertCreate(
            $TestField,
            MetadataSchema::MDFTYPE_TEXT,
            "Text",
            "TEXT"
        );

        self::$Schema->dropField($TestField->id());

        # create number
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_NUMBER,
            "First Test Field",
            null,
            0
        );
        $TestField->isTempItem(false);

        $this->assertCreate(
            $TestField,
            MetadataSchema::MDFTYPE_NUMBER,
            0,
            "INT"
        );

        self::$Schema->dropField($TestField->id());

        # create point
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_POINT,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $this->assertCreate(
            $TestField,
            MetadataSchema::MDFTYPE_POINT
        );
        $this->assertEquals(
            "DECIMAL(8,5)",
            self::$DB->getFieldType("Records", "FirstTestFieldX")
        );
        $this->assertEquals(
            "DECIMAL(8,5)",
            self::$DB->getFieldType("Records", "FirstTestFieldY")
        );

        self::$Schema->dropField($TestField->id());

        # create flag
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_FLAG,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $this->assertCreate(
            $TestField,
            MetadataSchema::MDFTYPE_FLAG,
            null,
            "INT"
        );
        self::$Schema->dropField($TestField->id());

        # create date
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_DATE,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $this->assertCreate(
            $TestField,
            MetadataSchema::MDFTYPE_DATE
        );
        $this->assertEquals(
            "DATE",
            self::$DB->getFieldType("Records", "FirstTestFieldBegin")
        );
        $this->assertEquals(
            "DATE",
            self::$DB->getFieldType("Records", "FirstTestFieldEnd")
        );
        $this->assertEquals(
            "INT",
            self::$DB->getFieldType("Records", "FirstTestFieldPrecision")
        );

        self::$Schema->dropField($TestField->id());

        # create timestamp
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TIMESTAMP,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $this->assertCreate(
            $TestField,
            MetadataSchema::MDFTYPE_TIMESTAMP,
            null,
            "DATETIME"
        );

        self::$Schema->dropField($TestField->id());

        # create tree
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TREE,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $this->assertCreate(
            $TestField,
            MetadataSchema::MDFTYPE_TREE
        );

        # create field with invalid field type
        try {
            MetadataField::create(
                self::$Schema->id(),
                -1,
                "Second Test Field"
            );
            $this->fail("Exception not thrown on creation of MetadataField with invalid type");
        } catch (Exception $e) {
            $this->assertEquals(
                "Bad field type (-1).",
                $e->getMessage()
            );
        }

        # create duplicate field
        try {
            MetadataField::create(
                self::$Schema->id(),
                MetadataSchema::MDFTYPE_TREE,
                "First Test Field"
            );
            $this->fail("Exception not thrown on creation of MetadataField with duplicate name");
        } catch (Exception $e) {
            $this->assertEquals(
                "Duplicate field name (First Test Field).",
                $e->getMessage()
            );
        }

        # drop field after testing
        self::$Schema->dropField($TestField->id());
    }

    public function testDrop()
    {
        # drop text field
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TEXT,
            "First Test Field"
        );
        $TestField->isTempItem(false);
        $TestFieldId = $TestField->id();
        $TempRecord = Record::create(self::$Schema->id());
        $TempRecord->set($TestField, "TestTextValue", true);

        self::$Schema->dropField($TestFieldId);
        $this->assertFalse(
            self::$Schema->fieldExists("First Test Field")
        );
        $this->assertFalse(
            self::$Schema->fieldExists($TestFieldId)
        );

        self::$DB->query("SELECT * FROM MetadataFields WHERE FieldId = ".$TestFieldId);
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        $TempRecord->destroy();

        # drop point field
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_POINT,
            "First Test Field"
        );
        $TestField->isTempItem(false);
        $TestFieldId = $TestField->id();

        $TempRecord = Record::create(self::$Schema->id());
        $TempRecord->set($TestField, ["X" => 5, "Y" => 5], true);

        self::$Schema->dropField($TestFieldId);
        $this->assertFalse(
            self::$Schema->fieldExists("First Test Field")
        );
        $this->assertFalse(
            self::$Schema->fieldExists($TestFieldId)
        );

        self::$DB->query("SELECT * FROM MetadataFields WHERE FieldId = ".$TestFieldId);
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        $TempRecord->destroy();

        # drop date field
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_DATE,
            "First Test Field"
        );
        $TestField->isTempItem(false);
        $TestFieldId = $TestField->id();

        $TempRecord = Record::create(self::$Schema->id());
        $TempRecord->set($TestField, "2021-07-30", true);

        self::$Schema->dropField($TestFieldId);
        $this->assertFalse(
            self::$Schema->fieldExists("First Test Field")
        );
        $this->assertFalse(
            self::$Schema->fieldExists($TestFieldId)
        );

        self::$DB->query("SELECT * FROM MetadataFields WHERE FieldId = ".$TestFieldId);
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        $TempRecord->destroy();

        # drop tree field
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TREE,
            "First Test Field"
        );
        $TestFieldId = $TestField->id();

        $TestTree = Classification::create("TreeTest", $TestFieldId);
        self::$Schema->dropField($TestFieldId);
        $this->assertFalse(
            self::$Schema->fieldExists("First Test Field")
        );
        $this->assertFalse(
            self::$Schema->fieldExists($TestFieldId)
        );

        self::$DB->query("SELECT * FROM MetadataFields WHERE FieldId = ".$TestFieldId);
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        self::$DB->query("SELECT * FROM Classifications WHERE FieldId = ".$TestFieldId);
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        # drop controlled name field
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "First Test Field"
        );
        $TestFieldId = $TestField->id();

        $TestName = ControlledName::create("CNameTest", $TestFieldId);
        self::$Schema->dropField($TestFieldId);
        $this->assertFalse(
            self::$Schema->fieldExists("First Test Field")
        );
        $this->assertFalse(
            self::$Schema->fieldExists($TestFieldId)
        );

        self::$DB->query("SELECT * FROM MetadataFields WHERE FieldId = ".$TestFieldId);
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        # drop file field
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_FILE,
            "First Test Field"
        );
        $TestFieldId = $TestField->id();

        self::$Schema->dropField($TestFieldId);
        $this->assertFalse(
            self::$Schema->fieldExists("First Test Field")
        );
        $this->assertFalse(
            self::$Schema->fieldExists($TestFieldId)
        );

        self::$DB->query("SELECT * FROM MetadataFields WHERE FieldId = ".$TestFieldId);
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        # drop reference field
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_REFERENCE,
            "First Test Field"
        );
        $TestFieldId = $TestField->id();

        self::$Schema->dropField($TestFieldId);
        $this->assertFalse(
            self::$Schema->fieldExists("First Test Field")
        );
        $this->assertFalse(
            self::$Schema->fieldExists($TestFieldId)
        );

        self::$DB->query("SELECT * FROM MetadataFields WHERE FieldId = ".$TestFieldId);
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        # call drop() as MetadataField instead of MetadataSchema
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TREE,
            "Second Test Field"
        );
        try {
            $Line = __LINE__ + 1;
            $TestField->drop();
            $this->fail("Exception not thrown calling drop() as MetadataField");
        } catch (Exception $e) {
            $this->assertEquals(
                "Attempt to update drop Metadata Field at MetadataField_Test.php:".$Line."."
                    ." (Fields may only be dropped by MetadataSchema.)",
                $e->getMessage()
            );
        }
        self::$Schema->dropField($TestField->id());
    }

    public function testTextFieldToParagraphField()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TEXT,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $TestField->type(MetadataSchema::MDFTYPE_PARAGRAPH);
        $this->assertEquals(
            MetadataSchema::MDFTYPE_PARAGRAPH,
            $TestField->type()
        );
        $this->assertEquals(
            "MEDIUMTEXT",
            self::$DB->getFieldType("Records", "FirstTestField")
        );

        self::$Schema->dropField($TestField->Id());
    }

    public function testTextFieldToUrlField()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TEXT,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $TestField->type(MetadataSchema::MDFTYPE_URL);
        $this->assertEquals(
            MetadataSchema::MDFTYPE_URL,
            $TestField->type()
        );
        $this->assertEquals(
            "TEXT",
            self::$DB->getFieldType("Records", "FirstTestField")
        );

        self::$Schema->dropField($TestField->Id());
    }

    public function testTextFieldToNumberField()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TEXT,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $TestField->type(MetadataSchema::MDFTYPE_NUMBER);
        $this->assertEquals(
            MetadataSchema::MDFTYPE_NUMBER,
            $TestField->type()
        );
        $this->assertEquals(
            "INT",
            self::$DB->getFieldType("Records", "FirstTestField")
        );

        self::$Schema->dropField($TestField->Id());
    }

    public function testTextFieldToFlagField()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TEXT,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $TestField->type(MetadataSchema::MDFTYPE_FLAG);
        $this->assertEquals(
            MetadataSchema::MDFTYPE_FLAG,
            $TestField->type()
        );
        $this->assertEquals(
            "INT",
            self::$DB->getFieldType("Records", "FirstTestField")
        );

        self::$Schema->dropField($TestField->Id());
    }

    public function testTreeFieldToControlledNameField()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TREE,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $TestTree = Classification::create(
            "TestTree",
            $TestField->id()
        );

        $TempRecord = Record::create(self::$Schema->id());
        $TempRecord->set($TestField, $TestTree, true);

        self::$DB->query("SELECT * FROM RecordNameInts WHERE RecordId = ".$TempRecord->id());
        $NumNameInts = count(self::$DB->fetchRows());

        $TestField->type(MetadataSchema::MDFTYPE_CONTROLLEDNAME);
        $this->assertEquals(
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            $TestField->type()
        );

        self::$DB->query("SELECT * FROM Classifications WHERE ClassificationId = ".
            $TestTree->id());
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        self::$DB->query("SELECT * FROM RecordClassInts WHERE RecordId = ".
            $TempRecord->id());
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        self::$DB->query("SELECT * FROM RecordNameInts WHERE RecordId = ".
            $TempRecord->id());
        $this->assertEquals($NumNameInts + 1, count(self::$DB->fetchRows()));

        self::$Schema->dropField($TestField->Id());
        $TempRecord->destroy();
    }

    public function testControlledNameFieldToTreeField()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $TestName = ControlledName::create(
            "TestCName",
            $TestField->id()
        );

        $TempRecord = Record::create(self::$Schema->id());
        $TempRecord->set($TestField, $TestName, true);

        self::$DB->query("SELECT * FROM RecordNameInts WHERE RecordId = ".
            $TempRecord->id());
        $NumNameInts = count(self::$DB->fetchRows());

        $TestField->type(MetadataSchema::MDFTYPE_TREE);
        $this->assertEquals(
            MetadataSchema::MDFTYPE_TREE,
            $TestField->type()
        );

        self::$DB->query("SELECT * FROM ControlledNames WHERE ControlledNameId = ".
            $TestName->id());
        $this->assertEquals(0, count(self::$DB->fetchRows()));

        self::$DB->query("SELECT * FROM RecordClassInts WHERE RecordId = ".
            $TempRecord->id());
        $this->assertEquals(1, count(self::$DB->fetchRows()));

        self::$DB->query("SELECT * FROM RecordNameInts WHERE RecordId = ".
            $TempRecord->id());
        $this->assertEquals($NumNameInts - 1, count(self::$DB->fetchRows()));

        self::$Schema->dropField($TestField->Id());
        $TempRecord->destroy();
    }

    public function testTimestampFieldToDateField()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TIMESTAMP,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $TestField->type(MetadataSchema::MDFTYPE_DATE);
        $this->assertEquals(
            MetadataSchema::MDFTYPE_DATE,
            $TestField->type()
        );
        $this->assertEquals(
            "DATE",
            self::$DB->getFieldType("Records", "FirstTestFieldBegin")
        );
        $this->assertEquals(
            "DATE",
            self::$DB->getFieldType("Records", "FirstTestFieldEnd")
        );
        $this->assertEquals(
            "INT",
            self::$DB->getFieldType("Records", "FirstTestFieldPrecision")
        );

        self::$Schema->dropField($TestField->Id());
    }

    public function testDateFieldToTimestampField()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_DATE,
            "First Test Field"
        );
        $TestField->isTempItem(false);

        $TestField->type(MetadataSchema::MDFTYPE_TIMESTAMP);
        $this->assertEquals(
            MetadataSchema::MDFTYPE_TIMESTAMP,
            $TestField->type()
        );
        $this->assertEquals(
            "DATETIME",
            self::$DB->getFieldType("Records", "FirstTestField")
        );

        self::$Schema->dropField($TestField->Id());
    }

    public function testChangeFieldName()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_TEXT,
            "Second Test Field"
        );
        $TestField->isTempItem(false);

        $TestField->name("TextTest");
        $this->assertEquals(
            MetadataSchema::MDFSTAT_OK,
            $TestField->status()
        );
        $this->assertEquals(
            "TextTest",
            $TestField->name()
        );

        self::$Schema->dropField($TestField->Id());
    }

    /**
     * Verify that getPossibleValues() and getCountOfPossibleValues() works correctly.
     * covers getPossibleValues() and getCountOfPossibleValues().
     */
    public function testPossibleValues()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "First Test Field"
        );
        $TestField->isTempItem(false);
        $TestName1 = ControlledName::create(
            "TestCName1",
            $TestField->id()
        );
        $TestName2 = ControlledName::create(
            "TestCName2",
            $TestField->id()
        );

        $this->assertEquals(
            $TestField->getPossibleValues(),
            [
                $TestName1->id() => $TestName1->name(),
                $TestName2->id() => $TestName2->name()
            ]
        );
        $this->assertEquals($TestField->getCountOfPossibleValues(), 2);

        self::$Schema->dropField($TestField->Id());
    }

    /**
     * Verify that label() works correctly.
     * covers label().
     */
    public function testLabel()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "Label Test Field"
        );

        $TestField->Label("Test Label");
        $this->assertEquals($TestField->Label(), "Test Label");

        # Assert that label can be set to NULL by passing a blank string.
        $TestField->Label("");
        self::$DB->query("SELECT Label FROM MetadataFields WHERE FieldId="
            .$TestField->id());
        $this->assertNull(self::$DB->fetchRow()["Label"]);

        $TestField->Label("  ");
        self::$DB->query("SELECT Label FROM MetadataFields WHERE FieldId="
            .$TestField->id());
        $this->assertNull(self::$DB->fetchRow()["Label"]);

        self::$Schema->dropField($TestField->Id());
    }

    /**
     * Verify that instructions() works correctly.
     * covers instructions().
     */
    public function testInstructions()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "Instructions Test Field"
        );
        $this->assertEquals($TestField->Instructions(), null);

        $TestField->Instructions("Test Instructions");
        $this->assertEquals($TestField->Instructions(), "Test Instructions");

        # Assert that instructions can be set to NULL by passing a blank string.
        $TestField->Instructions("");
        self::$DB->query("SELECT Instructions FROM MetadataFields WHERE ".
            "FieldId=".$TestField->id());
        $this->assertNull(self::$DB->fetchRow()["Instructions"]);

        self::$Schema->dropField($TestField->Id());
    }

    /**
     * Verify that getIdForValue() works correctly.
     * covers getIdForValue().
     */
    public function testGetIdForValue()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "First Test Field"
        );
        $TestField->isTempItem(false);
        $TestName1 = ControlledName::create(
            "TestCName1",
            $TestField->id()
        );
        $TestName2 = ControlledName::create(
            "TestCName2",
            $TestField->id()
        );

        $this->assertEquals($TestField->getIdForValue($TestName1->name()), $TestName1->id());
        $this->assertEquals($TestField->getIdForValue($TestName2->name()), $TestName2->id());

        self::$Schema->dropField($TestField->Id());
    }

    /**
     * Verify that getValueForId() works correctly.
     * covers getValueForId().
     */
    public function testGetValueForId()
    {
        $TestField = MetadataField::create(
            self::$Schema->id(),
            MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "First Test Field"
        );
        $TestField->isTempItem(false);
        $TestName1 = ControlledName::create(
            "TestCName1",
            $TestField->id()
        );
        $TestName2 = ControlledName::create(
            "TestCName2",
            $TestField->id()
        );

        $this->assertEquals($TestField->getValueForId($TestName1->id()), $TestName1->name());
        $this->assertEquals($TestField->getValueForId($TestName2->id()), $TestName2->name());

        self::$Schema->dropField($TestField->Id());
    }


    # ---- PRIVATE -----------------------------------------------------------

    private static $DB;
    private static $Schema;

    private function assertCreate($Field, $Type, $DefaultValue = null, $DBType = null)
    {
        $this->assertInstanceOf(
            MetadataField::class,
            $Field
        );
        $this->assertEquals(
            self::$Schema->id(),
            $Field->schemaId()
        );
        $this->assertEquals(
            $Type,
            $Field->type()
        );
        $this->assertEquals(
            "First Test Field",
            $Field->name()
        );

        if ($Type == MetadataSchema::MDFTYPE_FLAG) {
            $this->assertFalse($Field->optional());
        } else {
            $this->assertTrue($Field->optional());
        }

        if ($DefaultValue !== null) {
            $this->assertEquals($DefaultValue, $Field->defaultValue());
        }
        if ($DBType !== null) {
            $this->assertEquals(
                $DBType,
                self::$DB->getFieldType("Records", "FirstTestField")
            );
        }
    }
}
