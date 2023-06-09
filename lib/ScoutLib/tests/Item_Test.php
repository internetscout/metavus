<?PHP
#
#   FILE:  Item--Test.php
#
#   Part of the ScoutLib application support library
#   Copyright 2021 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

use ScoutLib\Database;
use ScoutLib\Item;
use ScoutLib\StdLib;

/**
 * Create a MockItem extending Item for testing because Item is an abstract
 * class.
 */
class MockItem extends Item
{
    public function __construct($MockItemId)
    {
        parent::__construct($MockItemId);
    }

    public static function create()
    {
        $MockItemValues = array(
            "MockItemName" => "Fake Item",
            "DateCreated" => "1989-01-23 12:34:56",
            "CreatedBy" => 1,
            "DateLastModified" => "1998-07-06 05:04:32",
            "LastModifiedBy" => 1);

        $MockItem = parent::CreateWithValues($MockItemValues);

        return $MockItem;
    }
}

/**
 * Dummy Item child class.
 */
class MockItemTwo extends Item
{

}

class Item_Test extends \PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        # construct MockItem with invalid Id
        try {
            $Mock = new MockItem(Database::INT_MAX_VALUE);
            $this->assertTrue(false, "Exception not thrown on invalid Id");
        } catch (Exception $E) {
            $this->assertEquals(
                get_class($E),
                "InvalidArgumentException",
                "Testing constructing MockItem with invalid Id"
            );
        }

        $Mock = MockItem::create();

        $this->assertEquals($Mock->Id(), 1, "Testing Id()");
        $this->assertEquals(
            $Mock->getCanonicalId($Mock->Id()),
            1,
            "Testing GetCanonicalId()"
        );

        $this->assertEquals($Mock->Name(), "Fake Item", "Testing getting Name()");
        $this->assertEquals(
            $Mock->Name("New Name"),
            "New Name",
            "Testing setting Name()"
        );

        $this->assertEquals(
            $Mock->DateCreated(),
            "1989-01-23 12:34:56",
            "Testing getting DateCreated()"
        );
        $this->assertEquals(
            $Mock->DateCreated("1901-12-31 12:59:45"),
            "1901-12-31 12:59:45",
            "Testing setting DateCreated()"
        );

        $this->assertEquals($Mock->CreatedBy(), 1, "Testing getting CreatedBy()");
        $this->assertEquals($Mock->CreatedBy(2), 2, "Testing setting CreatedBy()");

        $DateNow = date(StdLib::SQL_DATE_FORMAT);
        $this->assertEquals(
            $Mock->DateLastModified(),
            "1998-07-06 05:04:32",
            "Testing getting DateLastModified()"
        );
        $this->assertEquals(
            $Mock->DateLastModified($DateNow),
            $DateNow,
            "Testing setting DateLastModified()"
        );

        $this->assertEquals(
            $Mock->LastModifiedBy(),
            1,
            "Testing getting LastModifiedBy()"
        );
        $this->assertEquals(
            $Mock->LastModifiedBy(2),
            2,
            "Testing setting LastModifiedBy()"
        );

        $this->assertEquals(
            $Mock->ItemExists(null),
            false,
            "Testing ItemExists() with input NULL"
        );
        $this->assertEquals(
            $Mock->ItemExists($Mock->Id()),
            true,
            "Testing ItemExists() with valid Id"
        );
        $this->assertEquals(
            $Mock->ItemExists($Mock->Id()." junk"),
            false,
            "Testing ItemExists() with invalid Id"
        );
        $this->assertEquals(
            $Mock->ItemExists($Mock->Id() + 1),
            false,
            "Testing ItemExists() with invalid Id"
        );
        $this->assertEquals(
            $Mock->ItemExists($Mock),
            true,
            "Testing ItemExists() with ItemMock object"
        );

        # test ItemExists() for a non-Item object
        try {
            $Directory = dir("objects/tests");
            MockItem::ItemExists($Directory);
            $this->assertTrue(
                false,
                "Exception not thrown on invalid ItemExists() call"
            );
        } catch (Exception $E) {
            $this->assertEquals(
                get_class($E),
                "Exception",
                "Testing ItemExists() in ItemMock with Directory object"
            );
        }

        # test ItemExists() for another Item child type
        try {
            MockItemTwo::ItemExists($Mock);
            $this->assertTrue(
                false,
                "Exception not thrown on invalid ItemExists() call"
            );
        } catch (Exception $E) {
            $this->assertEquals(
                get_class($E),
                "Exception",
                "Testing ItemExists() in ItemMockTwo with ItemMock object"
            );
        }

        # test calling item method via callMethod()
        $TestDate = "2001-03-04 05:06:07";
        $IdOfExistingItem = $Mock->id();
        MockItem::callMethod($IdOfExistingItem, "dateCreated", $TestDate);
        $MockReloaded = new MockItem($IdOfExistingItem);
        $this->assertEquals(
            $TestDate,
            $MockReloaded->dateCreated(),
            "Testing using callMethod() to set item creation date"
        );

        # test item destruction
        $MockItemId = $Mock->Id();
        $Mock->destroy();
        $this->assertEquals(
            $Mock->ItemExists($MockItemId),
            false,
            "Testing ItemExists() with deleted object"
        );
    }

    /**
     * Create tables for MockItem before testing.
     */
    public static function setUpBeforeClass() : void
    {
        $DB = new Database();
        $DB->Query("CREATE TABLE MockItems (
            MockItemId INT NOT NULL AUTO_INCREMENT,
            MockItemName TEXT,
            DateCreated DATETIME,
            CreatedBy INT,
            DateLastModified DATETIME,
            LastModifiedBy INT,
            INDEX Index_I (MockItemId)
            );");

        $DB->Query("CREATE TABLE MockItemTwos (
            MockItemTwoId INT NOT NULL AUTO_INCREMENT,
            MockItemTwoName TEXT,
            DateCreated DATETIME,
            CreatedBy INT,
            DateLastModified DATETIME,
            LastModifiedBy INT,
            INDEX Index_I (MockItemTwoId)
            );");
    }

    /**
     * Destroy tables created for testing.
     */
    public static function tearDownAfterClass() : void
    {
        $DB = new Database();
        $DB->Query("DROP TABLE MockItems");
        $DB->Query("DROP TABLE MockItemTwos");
    }
}
