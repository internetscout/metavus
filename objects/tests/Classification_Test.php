<?PHP
#
#   FILE:  Classification_Test.php
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

class Classification_Test extends \PHPUnit\Framework\TestCase
{
    /**
     * Set up data needed for tests. Creates a test schema with a couple of fields.
     */
    public static function setUpBeforeClass() : void
    {
        self::$Schema = MetadataSchema::create(
            "Classification Test"
        );

        $TestFields = [
            "Classification" => MetadataSchema::MDFTYPE_TREE,
            "Record Status" => MetadataSchema::MDFTYPE_OPTION,
        ];
        foreach ($TestFields as $FieldName => $FieldType) {
            $Field = self::$Schema->addField($FieldName, $FieldType);
            $Field->isTempItem(false);
        }

        $CNames = [
            "Record Status" => [
                "Published",
                "Unpublished",
            ]
        ];
        foreach ($CNames as $FieldName => $Terms) {
            $Field = self::$Schema->getField($FieldName);
            foreach ($Terms as $Term) {
                ControlledName::create($Term, $Field->id());
            }
        }

        $RSField = self::$Schema->getField("Record Status");
        $PublishedCNId = $RSField->getFactory()->getItemIdByName("Published");

        $ViewingPrivs = new PrivilegeSet();
        $ViewingPrivs->addPrivilege(PRIV_RESOURCEADMIN);
        $ViewingPrivs->addCondition($RSField, $PublishedCNId);

        self::$Schema->viewingPrivileges(
            $ViewingPrivs
        );
    }

    /**
    * After to running the tests, this function is
    * run. It deletes all of the test Metadata fields.
    */
    public static function tearDownAfterClass() : void
    {
        self::$Schema->delete();
    }

    public function testClassification()
    {
        $Field = self::$Schema->getField("Classification");

        $TestParent = Classification::create("TestParent", $Field->id());

        $this->assertInstanceOf(Classification::class, $TestParent);
        $this->assertEquals($TestParent->FieldId(), $Field->id());

        # attempt to create a classification with a duplicate name
        try {
            Classification::create("TestParent", $Field->id());
            $this->fail(
                "Exception not thrown on creation of classification with duplicate name."
            );
        } catch (Exception $e) {
            $this->assertEquals(
                "Duplicate name specified for new classification (TestParent).",
                $e->getMessage()
            );
        }

        # attempt to create a duplicate top-level class
        try {
            Classification::Create("TestParent", $Field->id(), Classification::NOPARENT);
            $this->fail(
                "Exception not thrown on creation of explicitily top-level classification "
                ."with duplicate name."
            );
        } catch (Exception $e) {
            $this->assertEquals(
                "Duplicate name specified for new classification (TestParent).",
                $e->getMessage()
            );
        }

        # attempt to create a child with an invalid parent
        try {
            Classification::Create("TestBadChild", $Field->id(), PHP_INT_MAX);
            $this->fail("Exception not thrown when creating with invalid ParentId");
        } catch (Exception $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertEquals(
                "Invalid parent ID specified (".PHP_INT_MAX.").",
                $e->getMessage()
            );
        }

        # verify that name() cannot be used to set the name
        try {
            $TestParent->name("Bogus!");
            $this->fail(
                "Exception not thrown when attempting to set classification "
                ."name with name()."
            );
        } catch (Exception $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertEquals(
                "Illegal argument supplied.",
                $e->getMessage()
            );
        }

        # attempt to create a child, specifying the parent
        $TestFirstChild = Classification::create(
            "FirstChild",
            $Field->id(),
            $TestParent->id()
        );
        $this->assertInstanceOf(
            Classification::class,
            $TestFirstChild
        );
        $this->assertEquals(
            $TestParent->id(),
            $TestFirstChild->parentId()
        );

        # attempt to create a child, not specifying the parent
        $TestSecondChild = Classification::create(
            "TestParent -- SecondChild",
            $Field->id()
        );
        $this->assertInstanceOf(
            Classification::class,
            $TestSecondChild
        );
        $this->assertEquals(
            1,
            Classification::segmentsCreated()
        );
        $this->assertEquals(
            $TestParent->id(),
            $TestSecondChild->parentId()
        );

        # attempt to create a grand child, not specifying the parent
        $TestGrandChild = Classification::create(
            "TestParent -- SecondChild -- Grandchild",
            $Field->id()
        );
        $this->assertInstanceOf(
            Classification::class,
            $TestGrandChild
        );
        $this->assertEquals(
            1,
            Classification::segmentsCreated()
        );
        $this->assertEquals(
            $TestSecondChild->id(),
            $TestGrandChild->parentId()
        );

        # test the various ways to retrieve our name
        $this->assertEquals(
            "TestParent -- SecondChild",
            $TestSecondChild->Name()
        );

        $this->assertEquals(
            "TestParent -- SecondChild",
            $TestSecondChild->fullName()
        );

        $this->assertEquals(
            "SecondChild",
            $TestSecondChild->segmentName()
        );

        $this->assertFalse($TestSecondChild->variantName());

        # test recalculating
        $IdsUpdated = $TestSecondChild->recalcResourceCount();

        $Expected = [
            $TestSecondChild->id(),
            $TestParent->id()
        ];

        sort($IdsUpdated);
        sort($Expected);

        $this->assertEquals($Expected, $IdsUpdated);

        # Test initial Depth values
        $this->assertEquals(1, $TestSecondChild->depth());
        $this->assertEquals(0, $TestParent->depth());


        # test recalculating depth
        $TestParent->recalcDepthAndFullName();

        # verify that names and depths remain correct after recalc
        $this->assertEquals(
            "TestParent -- SecondChild",
            $TestSecondChild->fullName()
        );
        $this->assertEquals(
            "SecondChild",
            $TestSecondChild->segmentName()
        );

        $this->assertEquals(1, $TestSecondChild->depth());
        $this->assertEquals(0, $TestParent->depth());
        $this->assertEquals(0, $TestSecondChild->fullResourceCount());

        # test listing children
        $ChildIds = $TestParent->childList();
        $Expected = [
            $TestFirstChild->id(),
            $TestSecondChild->id(),
            $TestGrandChild->id(),
        ];
        sort($ChildIds);
        sort($Expected);
        $this->assertEquals($Expected, $ChildIds);

        # test associating with records

        # helper for repeated checks
        $CheckCounts = function (
            $FirstCount,
            $FirstFullCount,
            $ParentCount,
            $ParentFullCount
        ) use (
            $TestFirstChild,
            $TestParent
        ) {
            $this->assertEquals($FirstCount, $TestFirstChild->resourceCount());
            $this->assertEquals($FirstFullCount, $TestFirstChild->fullResourceCount());
            $this->assertEquals($ParentCount, $TestParent->resourceCount());
            $this->assertEquals($ParentFullCount, $TestParent->fullResourceCount());
        };

        # create a testing record
        $Rec1 = Record::create(self::$Schema->id());

        # get CNames used to control visibility
        $RSFactory = $Rec1
            ->getSchema()
            ->getField("Record Status")
            ->getFactory();
        $PublishedCN = $RSFactory->getItemByName("Published");
        $UnpubCN = $RSFactory->getItemByName("Unpublished");

        # set our test, temp record prepublishd, check that temps don't
        # appear in counts
        $Rec1->set($Field, $TestFirstChild);
        $Rec1->set("Record Status", $UnpubCN);
        $CheckCounts(0, 0, 0, 0);

        # toggle to perm and see that it shows up in full counts
        $Rec1->isTempRecord(false);
        $CheckCounts(0, 1, 0, 1);

        # publish it and see that it shows up everywhere
        $Rec1->set("Record Status", $PublishedCN);
        $Rec1->updateAutoupdateFields(
            MetadataField::UPDATEMETHOD_ONRECORDRELEASE
        );
        $CheckCounts(1, 1, 1, 1);

        # ensure that duplicates don't show up when temp
        $Rec2 = Record::duplicate($Rec1->id());
        $CheckCounts(1, 1, 1, 1);

        # but do after toggling to perm
        $Rec2->isTempRecord(false);
        $CheckCounts(2, 2, 2, 2);

        # ensure that counts are updated after clearing the field
        $Rec2->clear($Field);
        $CheckCounts(1, 1, 1, 1);

        # and after providing a new value
        $Rec2->set($Field, $TestParent);
        $CheckCounts(1, 1, 2, 2);

        # associate a third record with the grandchild
        $Rec3 = Record::duplicate($Rec2->id());
        $Rec3->isTempRecord(false);
        $Rec3->set($Field, $TestGrandChild);

        $this->assertEquals(1, $TestGrandChild->resourceCount());
        $this->assertEquals(1, $TestGrandChild->fullResourceCount());

        # clean up some test records
        $Rec1->destroy();
        $Rec2->destroy();

        # test deleting

        # try to delete when we have a child
        $DelCount = $TestSecondChild->destroy();
        $this->assertEquals(0, $DelCount);

        # delete grandchild, even though it has a record
        $DelCount = $TestGrandChild->destroy(false, true);
        $this->assertEquals(1, $DelCount);

        # delete last test record
        $Rec3->destroy();

        # retry delete of second child now
        $DelCount = $TestSecondChild->destroy();
        $this->assertEquals(1, $DelCount);

        # try deleting parent when it still has a child but no records
        $DelCount = $TestParent->destroy();
        $this->assertEquals(0, $DelCount);

        # test deletions that eat our parents
        $DelCount = $TestFirstChild->destroy(true);
        $this->assertEquals(2, $DelCount);
    }

    protected static $Schema = null;
}
