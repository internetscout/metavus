<?PHP

use ScoutLib\Database;
use ScoutLib\PersistentDoublyLinkedList;

/**
* Tests for the PersistentlyDoublyLinkedList.  Does not currently test
* lists that specify an SQL Condition. Also, the current set of tests
* don't mix Append/Prepend calls, just doing a sequence of either and
* verifying the correctness of the result.
*/
class PersistentDoublyLinkedList_Test extends PHPUnit\Framework\TestCase
{
    /**
    * Create necessary database tables for testing the PDLL class.
    */
    static function setUpBeforeClass() : void
    {
        $DB = new Database();
        $DB->Query("CREATE TABLE ListTestNoTypes ("
                   ."ItemId INT NOT NULL, "
                   ."PreviousItemId INT DEFAULT -1, "
                   ."NextItemId INT DEFAULT -1)");
        $DB->Query("CREATE TABLE ListTest ("
                   ."ItemId INT NOT NULL, "
                   ."PreviousItemId INT DEFAULT -1, "
                   ."NextItemId INT DEFAULT -1, "
                   ."ItemType INT DEFAULT NULL, "
                   ."NextItemType INT DEFAULT NULL, "
                   ."PreviousItemType INT DEFAULT NULL)");
    }

    /**
    * Destroy tables created for testing.
    */
    static function tearDownAfterClass() : void
    {
        $DB = new Database();
        $DB->Query("DROP TABLE ListTest");
        $DB->Query("DROP TABLE ListTestNoTypes");
    }

    /**
    * Prior to each test, ensure that tables are empty.
    */
    function setUp() : void
    {
        static $DB;
        if (!isset($DB))
        {
            $DB = new Database();
        }

        $DB->Query("DELETE FROM ListTest");
        $DB->Query("DELETE FROM ListTestNoTypes");

        $DB->Query(
            "INSERT INTO ListTestNoTypes (ItemId) VALUES ".
            implode(",", array_map(
                        function($x){ return "(".$x.")"; },
                        array(1,2,3,4,5))) );
        $DB->Query(
            "INSERT INTO ListTest (ItemId, ItemType) VALUES ".
            implode(",", array_map(
                        function($x){ return "(".$x.",1)"; },
                        array(1,2,3,4,5))) );
    }

    /**
    * Create an untyped list, append elements to it.
    * Covers: Append(), GetCount(), GetIds()
    */
    function testAppendNoTypes()
    {
        $MyList = new PersistentDoublyLinkedList(
            "ListTestNoTypes", "ItemId");

        # list is initially empty
        $this->CheckUntyped($MyList, array());

        try
        {
            $MyList->Append( 1, 1 );
            # this should not succeed
            $this->assertTrue(FALSE);
        }
        catch (Exception $e)
        {
            ; // empty on purpose
        }

        # add a single element to it, we see that element
        $MyList->Append( 1 );
        $this->CheckUntyped( $MyList, array(1) );

        # attempt to add that element again, no change
        $MyList->Append( 1 );
        $this->CheckUntyped( $MyList, array(1) );

        # add a different element, it appears at the end
        $MyList->Append( 2 );
        $this->CheckUntyped( $MyList, array(1,2) );

        # attempt to repeat the addition, no change
        $MyList->Append( 2 );
        $this->CheckUntyped( $MyList, array(1,2) );

        # add the first element again, it should be moved to the end
        $MyList->Append( 1 );
        $this->CheckUntyped( $MyList, array(2,1) );

        # move the second element to the end
        $MyList->Append( 2 );
        $this->checkUntyped( $MyList, array(1,2) );

        # add a third element
        $MyList->Append( 3 );
        $this->CheckUntyped( $MyList, array(1,2,3) );

        # shuffle the first element to the end
        $MyList->Append( 1 );
        $this->CheckUntyped( $MyList, array(2,3,1) );

        # shuffle the middle element to the end
        $MyList->Append( 3 );
        $this->CheckUntyped( $MyList, array(2,1,3) );
    }

    /**
    * Create an untyped list, prepend elements to it.
    * Covers: Prepend(), GetCount(), GetIds()
    */
    function testPrependNoTypes()
    {
        $MyList = new PersistentDoublyLinkedList(
            "ListTestNoTypes", "ItemId");

        # list is initially empty
        $this->CheckUntyped( $MyList, array()  );

        # add a single element to it, we see that element
        $MyList->Prepend( 1 );
        $this->CheckUntyped( $MyList, array(1) );

        # attempt to add that element again, no change
        $MyList->Prepend( 1 );
        $this->CheckUntyped( $MyList, array(1) );

        # add a different element, it appears at the beginning
        $MyList->Prepend( 2 );
        $this->CheckUntyped( $MyList, array(2,1) );

        # attempt to repeat the addition, no change
        $MyList->Prepend( 2 );
        $this->CheckUntyped( $MyList, array(2,1) );

        # add the first element again, it should be moved to the beginning
        $MyList->Prepend( 1 );
        $this->CheckUntyped( $MyList, array(1,2) );

        # move the second element to the beginning
        $MyList->Prepend( 2 );
        $this->CheckUntyped( $MyList, array(2,1) );

        # add a third element
        $MyList->Prepend( 3 );
        $this->CheckUntyped( $MyList, array(3,2,1) );

        # shuffle the first element to the beginning
        $MyList->Prepend( 1 );
        $this->CheckUntyped( $MyList, array(1,3,2) );

        # shuffle the middle element to the beginning
        $MyList->Prepend( 3 );
        $this->CheckUntyped( $MyList, array(3,1,2) );
    }

    /**
    * Create an untyped list, test InsertBefore on it.
    * covers InsertBefore(), GetCount(), GetIds()
    */
    function testInsertBeforeNoTypes()
    {
        $MyList = new PersistentDoublyLinkedList(
            "ListTestNoTypes", "ItemId");

        # InsertBefore onto an emtpy list
        $MyList->InsertBefore( 100, 2 );
        $this->CheckUntyped( $MyList, array(2) );

        # InsertBefore w/ a nonexistent item
        $MyList->InsertBefore( 100, 1 );
        $this->CheckUntyped( $MyList, array(1,2) );

        # InsertBefore at the beginning
        $MyList->InsertBefore(1, 3);
        $this->CheckUntyped( $MyList, array(3,1,2) );

        # InsertBefore at the end
        $MyList->InsertBefore(2, 4);
        $this->CheckUntyped( $MyList, array(3,1,4,2) );

        # InsertBefore in the middle
        $MyList->InsertBefore(4, 5);
        $this->CheckUntyped( $MyList, array(3,1,5,4,2) );
    }

    /**
    * Create an untyped list, test InsertAfter on it.
    * covers InsertAfter(), GetCount(), GetIds()
    */
    function testInsertAfterNoTypes()
    {
        $MyList = new PersistentDoublyLinkedList(
            "ListTestNoTypes", "ItemId");

        # InsertAfter onto an emtpy list
        $MyList->InsertAfter( 100, 2 );
        $this->CheckUntyped( $MyList, array(2) );

        # InsertAfter w/ a nonexistent item
        $MyList->InsertAfter( 100, 1 );
        $this->CheckUntyped( $MyList, array(2,1) );

        # InsertAfter at the beginning
        $MyList->InsertAfter(2, 3);
        $this->CheckUntyped($MyList, array(2,3,1) );

        # InsertAfter at the end
        $MyList->InsertAfter(1, 4);
        $this->CheckUntyped($MyList, array(2,3,1,4) );

        # InsertAfter in the middle
        $MyList->InsertAfter(3, 5);
        $this->CheckUntyped($MyList, array(2,3,5,1,4) );
    }

    /**
    * Create a typed list, test Append()ing to it.
    * Covers Append(), GetCount(), GetIds()
    */
    function testAppendTypes()
    {
        $MyList = new PersistentDoublyLinkedList(
            "ListTest", "ItemId", NULL, "ItemType");

        # list is initially empty
        $this->assertSame(
            array(), $MyList->GetIds() );

        try
        {
            $MyList->Append( 1 );
            # this should not succeed
            $this->assertTrue(FALSE);
        }
        catch (Exception $e)
        {
            ; // empty on purpose
        }

        # add a single element to it, we see that element
        $MyList->Append( 1, 1 );
        $this->CheckTypedList($MyList, array(1));

        # attempt to add that element again, no change
        $MyList->Append( 1, 1 );
        $this->CheckTypedList($MyList, array(1));

        # add a different element, it appears at the end
        $MyList->Append( 2, 1 );
        $this->CheckTypedList($MyList, array(1,2) );

        # attempt to repeat the addition, no change
        $MyList->Append( 2, 1 );
        $this->CheckTypedList($MyList, array(1,2) );

        # add the first element again, it should be moved to the end
        $MyList->Append( 1, 1 );
        $this->CheckTypedList($MyList, array(2,1) );

        # move the second element to the end
        $MyList->Append( 2, 1 );
        $this->CheckTypedList($MyList, array(1,2) );

        # add a third element
        $MyList->Append( 3, 1 );
        $this->CheckTypedList($MyList, array(1,2,3) );

        # shuffle the first element to the end
        $MyList->Append( 1, 1 );
        $this->CheckTypedList($MyList, array(2,3,1) );

        # shuffle the middle element to the end
        $MyList->Append( 3, 1 );
        $this->CheckTypedList($MyList, array(2,1,3) );
    }

    /**
    * Create a typed list, test Prepend()ing to it.
    * Covers Append(), GetCount(), GetIds()
    */
    function testPrependTypes()
    {
        $MyList = new PersistentDoublyLinkedList(
            "ListTest", "ItemId", NULL, "ItemType");

        # list is initially empty
        $this->assertSame(
            array(), $MyList->GetIds() );

        # add a single element to it, we see that element
        $MyList->Prepend( 1, 1 );
        $this->CheckTypedList( $MyList, array(1) );

        # attempt to add that element again, no change
        $MyList->Prepend( 1, 1 );
        $this->CheckTypedList( $MyList, array(1) );

        # add a different element, it appears at the beginning
        $MyList->Prepend( 2, 1 );
        $this->CheckTypedList($MyList, array(2,1) );

        # attempt to repeat the addition, no change
        $MyList->Prepend( 2, 1 );
        $this->CheckTypedList($MyList, array(2,1) );

        # add the first element again, it should be moved to the beginning
        $MyList->Prepend( 1, 1 );
        $this->CheckTypedList($MyList, array(1,2) );

        # move the second element to the beginning
        $MyList->Prepend( 2, 1 );
        $this->CheckTypedList($MyList, array(2,1) );

        # add a third element
        $MyList->Prepend( 3, 1 );
        $this->CheckTypedList($MyList, array(3,2,1) );

        # shuffle the first element to the end
        $MyList->Prepend( 1, 1 );
        $this->CheckTypedList($MyList, array(1,3,2) );

        # shuffle the middle element to the end
        $MyList->Prepend( 3, 1 );
        $this->CheckTypedList($MyList, array(3,1,2) );
    }

    /**
    * Create a typed list, test InsertBefore() on it.
    * Covers InsertBefore(), GetCount(), GetIds()
    */
    function testInsertBeforeTypes()
    {
        $MyList = new PersistentDoublyLinkedList(
            "ListTest", "ItemId", NULL, "ItemType");

        # InsertBefore onto an emtpy list
        $MyList->InsertBefore( 100, 2, 1, 1 );
        $this->CheckTypedList($MyList, array(2) );

        # InsertBefore w/ a nonexistent item
        $MyList->InsertBefore( 100, 1, 1, 1);
        $this->CheckTypedList($MyList, array(1,2) );

        # InsertBefore at the beginning
        $MyList->InsertBefore(1, 3, 1, 1);
        $this->CheckTypedList($MyList, array(3,1,2) );

        # InsertBefore at the end
        $MyList->InsertBefore(2, 4, 1, 1);
        $this->CheckTypedList($MyList, array(3,1,4,2) );

        # InsertBefore in the middle
        $MyList->InsertBefore(4, 5, 1,1);
        $this->CheckTypedList($MyList, array(3,1,5,4,2));
    }

    /**
    * Create a typed list, test InsertAfter() on it.
    * Covers InsertBefore(), GetCount(), GetIds()
    */
    function testInsertAfterTypes()
    {
        $MyList = new PersistentDoublyLinkedList(
            "ListTest", "ItemId", NULL, "ItemType");

        # InsertAfter onto an emtpy list
        $MyList->InsertAfter( 100, 2, 1, 1);
        $this->CheckTypedList($MyList, array(2) );

        # InsertAfter w/ a nonexistent item
        $MyList->InsertAfter( 100, 1, 1, 1);
        $this->CheckTypedList($MyList, array(2,1) );

        # InsertAfter at the beginning
        $MyList->InsertAfter(2, 3, 1, 1);
        $this->CheckTypedList($MyList, array(2,3,1) );

        # InsertAfter at the end
        $MyList->InsertAfter(1, 4, 1, 1);
        $this->CheckTypedList($MyList, array(2,3,1,4) );

        # InsertAfter in the middle
        $MyList->InsertAfter(3, 5, 1, 1);
        $this->CheckTypedList($MyList, array(2,3,5,1,4) );
    }

    /**
    * Create an untyped list, verify that we can add an SqlCondition to it.
    * Covers SqlCondition()
    */
    function testSqlCondition()
    {
        $MyList = new PersistentDoublyLinkedList(
            "ListTestNoTypes", "ItemId");

        $this->assertSame(
            $MyList->SqlCondition(), NULL);

        $MyList->SqlCondition("ItemId=1");
        $this->assertSame(
            $MyList->SqlCondition(), "ItemId=1");
    }

    /**
    * Check an untyped list to verify the correct number and order of
    * elements.
    * @param PersistentDoublyLinkedList $List List to check.
    * @param array $Values Expected values.
    */
    private function CheckUntyped($List, $Values)
    {
        $this->assertSame(
            count($Values), $List->GetCount() );
        $this->assertSame(
            $Values, $List->GetIds() );
    }

    /**
    * Check an untyped list to verify the correct number and order of
    * elements.
    * @param PersistentDoublyLinkedList $List List to check.
    * @param array $Values Expected values.
    */
    private function CheckTypedList($List, $Values)
    {
        $Expected = array();
        foreach ($Values as $Value)
        {
            $Expected["1:".$Value] = array("Type"=>1, "ID"=> $Value);
        }

        $this->assertSame(
            count($Values), $List->GetCount() );
        $this->assertSame(
            $Expected, $List->GetIds() );
    }
}
