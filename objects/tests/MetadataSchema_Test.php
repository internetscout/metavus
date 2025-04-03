<?PHP
#
#   FILE:  MetadataSchema_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

use ScoutLib\Database;

class MetadataSchema_Test extends \PHPUnit\Framework\TestCase
{
    # ---- SETUP -------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        self::$DB = new Database();
    }

    protected function tearDown(): void
    {
    }


    # ---- TESTS -------------------------------------------------------------

    /**
     * Verify that create() works correctly.
     * covers create().
     */
    public function testCreate()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");
        $this->assertCreate($TestSchema);
        $TestSchema->delete();
    }

    /**
     * Verify that delete() works correctly.
     * covers delete().
     */
    public function testDelete()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");
        $TestSchemaId = $TestSchema->id();
        $TestSchema->delete();
        $this->assertFalse(MetadataSchema::schemaExistsWithId($TestSchemaId));
    }

    /**
     * Verify that schemaExistsWithId() works correctly.
     * covers schemaExistsWithId().
     */
    public function testSchemaExistsWithId()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");
        $TestSchemaId = $TestSchema->id();
        $this->assertTrue(MetadataSchema::schemaExistsWithId($TestSchemaId));
        # delete the metadata schema
        $TestSchema->delete();
        $this->assertFalse(MetadataSchema::schemaExistsWithId($TestSchemaId));
    }

    /**
     * Verify that name() works correctly.
     * covers name().
     */
    public function testName()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");
        # test getting the name
        $this->assertEquals($TestSchema->name(), "Test_MetadataSchema");
        # test updating the name
        $this->assertEquals(
            $TestSchema->name("Test_MetadataSchema_New"),
            "Test_MetadataSchema_New"
        );
        # delete the metadata schema
        $TestSchema->delete();
    }

    /**
     * Verify that abbreviatedName() works correctly.
     * covers abbreviatedName().
     */
    public function testAbbreviatedName()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");
        # test getting the abbreviated name: expected to be the first letter of the name
        $this->assertEquals($TestSchema->abbreviatedName(), "T");
        # test updating the abbreviated name
        $this->assertEquals($TestSchema->abbreviatedName("Z"), "Z");
        # delete the metadata schema
        $TestSchema->delete();
    }

    /**
     * Verify that resourceName() works correctly.
     * covers resourceName().
     */
    public function testResourceName()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");
        # test getting the resource name:
        # expected to match the name as no resource name was specified
        $this->assertEquals($TestSchema->resourceName(), "Test_MetadataSchema");
        # test updating the resource name
        $this->assertEquals($TestSchema->resourceName("Resource"), "Resource");
        # delete the metadata schema
        $TestSchema->delete();
    }

    /**
     * Verify that getViewPage() and setViewPage() work correctly.
     * covers getViewPage().
     * covers setViewPage().
     */
    public function testViewPage()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");
        # test getting the view page: expected to be empty as no view page was specified
        $this->assertEmpty($TestSchema->getViewPage());
        # test updating the view page
        $TestSchema->setViewPage("Test_Page");
        $this->assertEquals($TestSchema->getViewPage(), "Test_Page");
        # delete the metadata schema
        $TestSchema->delete();
    }

    /**
     * Verify that commentsEnabled() works correctly.
     * covers commentsEnabled().
     */
    public function testCommentsEnabled()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");
        # test getting whether the comments are enabled
        # we expect it to be true as it's the default
        $this->assertTrue($TestSchema->commentsEnabled());
        # test updating comments enabled
        $this->assertFalse($TestSchema->commentsEnabled(false));
        # delete the metadata schema
        $TestSchema->delete();
    }

    /**
     * Verify that addField() works correctly.
     * covers addField().
     */
    public function testAddField()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");
        # add a new field
        $TestField = $TestSchema->addField("Test_Field", MetadataSchema::MDFTYPE_TEXT);
        $this->assertInstanceOf(MetadataField::class, $TestField);
        $this->assertEquals($TestField->name(), "Test_Field");
        $this->assertEquals($TestField->type(), MetadataSchema::MDFTYPE_TEXT);
        # delete the metadata schema
        $TestSchema->delete();
    }

    /**
     * Verify that getFieldNames() works correctly.
     * covers getFieldNames().
     */
    public function testGetFieldNames()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");

        # add new fields
        $TestField1 = $TestSchema->addField("Test_Field1", MetadataSchema::MDFTYPE_TEXT);
        $TestField2 = $TestSchema->addField("Test_Field2", MetadataSchema::MDFTYPE_PARAGRAPH);
        $TestField1->isTempItem(false);
        $TestField2->isTempItem(false);

        # test getting all the field names
        $Expected = [
            $TestField2->id() => $TestField2->name(),
            $TestField1->id() => $TestField1->name()
        ];
        $this->assertEquals($TestSchema->getFieldNames(), $Expected);

        # test getting field names for a given field type
        $Expected = [
            $TestField1->id() => $TestField1->name()
        ];
        $this->assertEquals($TestSchema->getFieldNames(MetadataSchema::MDFTYPE_TEXT), $Expected);

        # delete the metadata schema
        $TestSchema->delete();
    }

    /**
     * Verify that getConstantName() works correctly.
     * covers getConstantName().
     */
    public function testGetConstantName()
    {
        # create a metadata schema
        $TestSchema = MetadataSchema::create("Test_MetadataSchema");

        # test getting a constant name that has a unique value
        $this->assertEquals(
            $TestSchema->getConstantName(MetadataSchema::MDFTYPE_SEARCHPARAMETERSET),
            "MDFTYPE_SEARCHPARAMETERSET"
        );

        # test getting a constant name that has a duplicate value
        $this->assertEquals(
            $TestSchema->getConstantName(1, "MDFTYPE"),
            "MDFTYPE_TEXT"
        );

        # delete test schema
        $TestSchema->delete();
    }

    # ---- PRIVATE -----------------------------------------------------------

    private static $DB;

    private function assertCreate($Schema)
    {
        $this->assertInstanceOf(
            MetadataSchema::class,
            $Schema
        );
        $this->assertTrue(MetadataSchema::schemaExistsWithId($Schema->id()));
        $this->assertEquals(
            $Schema->name(),
            "Test_MetadataSchema"
        );
    }
}
