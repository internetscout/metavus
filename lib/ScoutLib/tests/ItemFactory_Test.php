<?PHP
#
#   FILE:  ItemFactory_Test.php
#
#   Part of the ScoutLib application support library
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

use ScoutLib\Database;
use ScoutLib\Item;
use ScoutLib\ItemFactory;

class ItemFactory_Test extends PHPUnit\Framework\TestCase
{
    const TOADD = 4;
    const TOREMOVE = 2;
    const RECENTDATE = "2019-02-16 12:34:56";
    const OLDDATE = "1998-07-06 05:04:32";

    # ---- SETUP -------------------------------------------------------------

    /**
     * Create tables for MockItem before testing.
     */
    public static function setUpBeforeClass() : void
    {
        # need to use COLLATE in order for case sensitive comparisons to function properly
        # see: https://dev.mysql.com/doc/refman/5.5/en/case-sensitivity.html
        $DB = new Database();
        $DB->query(
            "CREATE TABLE MockFactoryItems ("
            ."  MockFactoryItemId INT NOT NULL AUTO_INCREMENT,"
            ."  MockFactoryItemName TEXT,"
            ."  DateCreated DATETIME,"
            ."  CreatedBy INT,"
            ."  DateLastModified DATETIME,"
            ."  LastModifiedBy INT,"
            ."  NextMockFactoryItemId INT,"
            ."  PreviousMockFactoryItemId INT,"
            ."  INDEX Index_I (MockFactoryItemId),"
            ."  FULLTEXT (MockFactoryItemName)"
            .") CHARACTER SET latin1 COLLATE latin1_general_cs;"
        );
    }

    /**
     * Destroy tables created for testing.
     */
    public static function tearDownAfterClass() : void
    {
        $DB = new Database();
        $DB->query("DROP TABLE MockFactoryItems");
    }

    /**
     * remove all items from database after each run
     */
    public function tearDown() : void
    {
        $DB = new Database();
        $DB->query("DELETE FROM MockFactoryItems");
    }


    # ---- TESTS -------------------------------------------------------------

    /**
     * Check constructed values to make sure they're being saved
     */
    public function testConstructor()
    {
        $MockFactory = new MockItemFactory();
        $this->assertEquals(
            $MockFactory->getItemClassName(),
            "MockFactoryItem",
            "ItemFactory failed to save class name of item in constructor."
        );

        $this->assertEquals(
            $MockFactory->getItemCount(),
            0,
            "ItemFactory failed to initialize count to zero."
        );
    }

    /**
     * Test ItemFactory's count accuracy, as well as the getItemByName function
     */
    public function testCountOnMultipleChanges()
    {
        # create item factory, one with sql conditions for testing if that works for getItemCount()
        $MockFactory = new MockItemFactory();
        $MockFactorySqlConditions = new MockItemFactory(false, "MockFactoryItemName = '1'");

        # create a temp item to make sure that's not counted unless specified
        MockFactoryItem::createTemp("Stale Item", $MockFactory->getNextTempItemId())->id();

        for ($i = 0; $i < self::TOADD; $i++) {
            MockFactoryItem::create($i);
        }
        $this->assertEquals(
            $MockFactory->getItemCount(),
            self::TOADD,
            "Creation of ".self::TOADD." items failed to result"
            ." in a count of ".self::TOADD." items."
        );

        $this->assertEquals(
            $MockFactory->getItemCount("MockFactoryItemName = '1'"),
            1,
            "getItemCount() failed to correctly count items with regard "
            ."to the parameter's SQL argument."
        );

        $this->assertEquals(
            $MockFactorySqlConditions->getItemCount(null, true),
            1,
            "getItemCount() failed to correctly count items with regard "
            ."to the constructor's SQL argument (when counting temp items as well)."
        );

        $this->assertEquals(
            $MockFactorySqlConditions->getItemCount(),
            1,
            "getItemCount() failed to correctly count items with regard "
            ."to the constructor's SQL argument."
        );

        $this->assertEquals(
            $MockFactory->getItemCount(null, true),
            self::TOADD + 1,
            "getItemCount() failed to count temp items when specified to do so."
        );

        for ($i = 0; $i < self::TOREMOVE; $i++) {
            $MockItem = $MockFactory->getItemByName($i);
            $this->assertTrue(
                !is_null($MockItem),
                "getItemByName() failed to retrieve an existing item by name (".$i.")"
            );
            $MockItem->destroy();
        }

        $this->assertEquals(
            $MockFactory->getItemCount(),
            self::TOADD - self::TOREMOVE,
            "Creation of ".self::TOADD." items and removal of ".self::TOREMOVE.
            " items failed to result in count of ".(self::TOADD - self::TOREMOVE)." items."
        );
    }

    /**
     * Test getItemIds() to make sure all IDs are listed,
     * also test getItemIdByName() for exceptions being thrown and use of SQL conditions
     */
    public function testGetItemIds()
    {
        $MockFactory = new MockItemFactory();
        $MockFactorySqlConditions = new MockItemFactory(false, "MockFactoryItemName = '1'");

        for ($i = 0; $i < self::TOADD; $i++) {
            $AllItemIds[] = MockFactoryItem::create($i)->Id();
        }

        # get list of all IDs, all IDs using a given sql condition, and all
        # IDs using a factory with a given condition
        $FactoryList = $MockFactory->getItemIds();
        $FactoryListSqlParam = $MockFactory->getItemIds("MockFactoryItemName = '1'");
        $FactoryListSqlFactory = $MockFactorySqlConditions->getItemIds();
        $ItemOneId = $MockFactory->getItemIdByName("1");

        $FactoryListAscending = $MockFactory->getItemIds(
            null,
            false,
            "MockFactoryItemName",
            true
        );

        $FactoryListDescending = $MockFactory->getItemIds(
            null,
            false,
            "MockFactoryItemName",
            false
        );

        # check if all existing items IDs are listed by ItemFactory::getItemIds().
        foreach ($AllItemIds as $Id) {
            $this->assertTrue(
                in_array($Id, $FactoryList),
                "getItemIds() failed to list an existing item ID."
            );
        }

        # make sure all of the item IDs are the only IDs being listed by ItemFactory.
        $this->assertEquals(
            count($AllItemIds),
            count($FactoryList),
            "getItemIds() contained ".count($FactoryList).
            " items when it should have had ".count($AllItemIds)." items."
        );

        # make sure SQL condition parameter works
        $this->assertTrue(
            (count($FactoryListSqlParam) == 1) && $FactoryListSqlParam[0] == $ItemOneId,
            "getItemIds() returned an incorrect list of IDs for the given parameter SQL condition."
        );

        # make sure SQL condition in the factory's constructor works
        $this->assertTrue(
            (count($FactoryListSqlFactory) == 1) && $FactoryListSqlFactory[0] == $ItemOneId,
            "getItemIds() returned an incorrect list of IDs "
            ."for the given constructor SQL condition."
        );

        $FactoryListSqlFactory = $MockFactorySqlConditions->getItemIds(null, true);
        # make sure SQL condition parameter works when temp items are included
        $this->assertTrue(
            (count($FactoryListSqlFactory) == 1) && $FactoryListSqlFactory[0] == $ItemOneId,
            "getItemIds() returned an incorrect list of IDs for the given "
            ."constructor SQL condition (when including temp items) ."
        );

        # make sure SortAscending = true is reverse of SortAscending = false, as it should be
        $this->assertEquals(
            $FactoryListAscending,
            array_reverse($FactoryListDescending),
            "getItemIds()'s fails to sort items correctly (using the"
            ." SortAscending field - true isn't reverse of false)."
        );

        # make sure constructor SQL argument works for getItemIdbyName
        $this->assertEquals(
            $ItemOneId,
            $MockFactorySqlConditions->getItemIdByName("1"),
            "getItemIdByName() failed to return the correct ID with regard "
            ."to the SQL condition in the constructor."
        );

        # expect exception when item name column is not specified
        $MockFactoryNameless = new MockItemFactory(false, null, null);
        $this->expectException(Exception::class);
        $MockFactoryNameless->getItemIdByName("1");
    }

    /**
     * Test getHighestItemId() for accuracy
     */
    public function testGetHighestItemId()
    {
        $MockFactory = new MockItemFactory();
        $MockSqlFactory = new MockItemFactory(false, "MockFactoryItemName = 'E'");

        $HighestEId = MockFactoryItem::create("E")->id();

        for ($i = 0; $i < self::TOADD; $i++) {
            $HighestId = MockFactoryItem::create($i)->id();
        }

        $this->assertEquals(
            $HighestId,
            $MockFactory->getHighestItemId(),
            "getHighestItemId() failed to return correct ID."
        );

        $this->assertEquals(
            $HighestEId,
            $MockSqlFactory->getHighestItemId(),
            "getHighestItemId() failed to return correct ID with regard "
            ."to the constructor's SQL condition."
        );

        $this->assertEquals(
            $HighestId,
            $MockSqlFactory->getHighestItemId(true),
            "getHighestItemId() failed to return correct ID, disregarding "
            ."constructor's SQL condition."
        );
    }

    /**
     * Test getItemNames() to make sure all names are listed.
     */
    public function testGetItemNames()
    {
        $MockFactory = new MockItemFactory();
        $MockFactorySqlConditions = new MockItemFactory(false, "MockFactoryItemName = '1'");
        $MockFactoryNameless = new MockItemFactory(false, null, null);

        for ($i = 0; $i < self::TOADD; $i++) {
            $AllItemNames[] = MockFactoryItem::create($i)->name();
        }
        $FactoryList = $MockFactory->getItemNames();
        $FactoryListSqlFactory = $MockFactorySqlConditions->getItemNames();

        # check if all existing items namess are listed by ItemFactory::getItemNames().
        foreach ($AllItemNames as $Name) {
            $this->assertTrue(
                in_array($Name, $FactoryList),
                "getItemNames() failed to list an existing item name."
            );
        }

        # make sure all of the item names are the only names being listed by ItemFactory.
        $this->assertEquals(
            count($AllItemNames),
            count($FactoryList),
            "getItemNames() contained ".count($FactoryList).
            " items when it should have had ".count($AllItemNames)." items."
        );

        $this->assertTrue(
            count($FactoryListSqlFactory) == 1 && $FactoryListSqlFactory[0] = "1",
            "getItemNames() failed to properly get the list of names with regards to the ".
            "constructor's SQL condition."
        );

        $FactoryListSqlFactory = $MockFactorySqlConditions->getItemNames(
            "MockFactoryItemName ='2'"
        );
        $this->assertTrue(
            count($FactoryListSqlFactory) == 0,
            "getItemNames() returned a list of names despite excluding all items through "
            ."parameter and constructor SQL conditions."
        );

        $this->expectException(Exception::class);
        $MockFactoryNameless->getItemNames();
    }

    /**
     * Test getItems() to make sure all items are listed.
     */
    public function testGetItems()
    {
        $MockFactory = new MockItemFactory();
        for ($i = 0; $i < self::TOADD; $i++) {
            $AllItems[] = MockFactoryItem::create($i);
        }
        $FactoryList = $MockFactory->getItems();
        # convert list of items into list of IDs (in_array($Item,
        # $ListOfItems) doesn't appear to work).

        foreach ($FactoryList as $FactoryItem) {
            $FactoryIds[] = $FactoryItem->id();
        }
        # check if all existing items are listed by getItems() (using IDs).
        foreach ($AllItems as $Item) {
            $this->assertTrue(
                in_array($Item->id(), $FactoryIds),
                "getItems() failed to list an existing item."
            );
        }
        # make sure all of the items are the only items being listed by ItemFactory.
        $this->assertEquals(
            count($AllItems),
            count($FactoryList),
            "getItems() contained ".count($FactoryList).
            " items when it should have had ".count($AllItems)." items."
        );
    }

    /**
     * Test getCountForItemNames() and searchForItemNames()'s accuracy
     */
    public function testGetCountAndSearchForItemNames()
    {
        # create item factory
        $MockFactory = new MockItemFactory();
        $MockFactoryNameless = new MockItemFactory(false, null, null);

        for ($i = 0; $i < self::TOADD; $i++) {
            MockFactoryItem::create("IdenticalName");
        }

        $this->assertEquals(
            0,
            $MockFactory->getCountForItemNames(""),
            "getCountForItemNames() failed to return zero "
            ."when empty search string included."
        );

        $this->assertEquals(
            0,
            count($MockFactory->searchForItemNames("")),
            "searchForItemNames() failed to return an empty array "
            ."when empty search string included."
        );

        $this->assertEquals(
            self::TOADD,
            $MockFactory->getCountForItemNames("IdenticalName"),
            "getCountForItemNames() failed to correctly count "
            ."the number of items with the same name."
        );

        # get item ids to remove some number of items (with
        # name="IdenticalName" since that should be all items)
        $Ids = $MockFactory->getItemIds();
        for ($i = 0; $i < self::TOREMOVE; $i++) {
            $MockItem = new MockFactoryItem($Ids[$i]);
            $MockItem->destroy();
        }

        $this->assertEquals(
            (self::TOADD - self::TOREMOVE),
            $MockFactory->getCountForItemNames("IdenticalName"),
            "Creation of ".self::TOADD." items and removal of "
            .self::TOREMOVE." items with identical names ".
            "failed to result in count of "
            .(self::TOADD - self::TOREMOVE)." items with getcountForItemNames()."
        );

        # get all IDs for exclusion to make sure nothing is returned when excluding all IDs
        $ToExclude = $MockFactory->getItemIds();

        $this->assertEquals(
            0,
            $MockFactory->getCountForItemNames(
                "IdenticalName",
                false,
                true,
                $ToExclude
            ),
            "getCountForItemNames() failed to return 0 when excluding all existing item IDs."
        );

        $this->assertEquals(
            0,
            count($MockFactory->searchForItemNames(
                "IdenticalName",
                100,
                false,
                true,
                0,
                $ToExclude
            )),
            "searchForItemNames() returned results despite excluding all existing item IDs."
        );

        $this->assertEquals(
            0,
            $MockFactory->getCountForItemNames(
                "IdenticalName",
                false,
                true,
                [],
                ["IdenticalName"]
            ),
            "getCountForItemNames() failed to return 0 when excluding the name it was counting for."
        );

        $this->assertEquals(
            0,
            count($MockFactory->searchForItemNames(
                "IdenticalName",
                100,
                false,
                true,
                0,
                [],
                ["IdenticalName"]
            )),
            "searchForItemNames() returned results when excluding the name it was searching for."
        );

        $this->assertEquals(
            1,
            count($MockFactory->searchForItemNames(
                "IdenticalName",
                1
            )),
            "searchForItemNames() returned different results than expected "
            ."when only requesting 1 names."
        );

        $this->expectException(Exception::class);
        $MockFactoryNameless->searchForItemNames("IdenticalName");
    }

    /**
     * Test getItemByName()'s case sensitivity
     */
    public function testGetItemByNameCaseSensitivity()
    {
        MockFactoryItem::create("TestName");
        $MockFactory = new MockItemFactory();
        $CaseSensitive = $MockFactory->getItemByName("testname", false);

        $this->assertTrue(
            is_null($CaseSensitive),
            "Case sensitive getItemByName() called, retrieved item "
            ."despite lack of identical casing."
        );
        $CaseInsensitive = $MockFactory->getItemByName("testname", true);

        $this->assertTrue(
            !is_null($CaseInsensitive),
            "Case insensitive getItemByName() called, failed to retrieve ".
            "item despite item existing with different casing."
        );
    }

    /**
     * Test itemExists() for accuracy
     */
    public function testItemExists()
    {
        $MockFactory = new MockItemFactory();
        $MockSqlFactory = new MockItemFactory(false, "MockFactoryItemName = 'E'");

        $EID = MockFactoryItem::create("E")->id();
        $PID = MockFactoryItem::create("P")->id();

        $this->assertTrue(
            !$MockFactory->itemExists(Database::INT_MAX_VALUE),
            "itemExists() returned true when looking for an invalid ID."
        );

        $this->assertTrue(
            $MockFactory->itemExists($PID),
            "itemExists() failed to return true when looking for an existing item."
        );

        $this->assertTrue(
            !$MockSqlFactory->itemExists($PID, false),
            "itemExists() returned true when looking for an existing item that "
            ."has been excluded by SQL condition."
        );

        $this->assertTrue(
            $MockSqlFactory->itemExists($EID, false),
            "itemExists() failed to return true when looking for an ".
            "existing item that has been included by SQL condition."
        );

        $this->assertTrue(
            $MockSqlFactory->itemExists($PID, true),
            "itemExists() failed to return true when looking for an ".
            "existing item that has been excluded by disregarded SQL condition."
        );
    }

     /**
      * Test nameIsInUse() for accuracy
      */
    public function testNameIsInUse()
    {
        $MockFactory = new MockItemFactory();
        $MockFactorySqlConditions = new MockItemFactory(false, "MockFactoryItemName = '1'");

        MockFactoryItem::create("E");

        $this->assertTrue(
            !$MockFactory->nameIsInUse("W"),
            "nameIsInUse() returned true when checking if a non-existent name was in use."
        );

        $this->assertTrue(
            !$MockFactory->nameIsInUse("e", false),
            "nameIsInUse() returned true when performing a case sensitive search ".
            "with an incorrectly-cased existing name."
        );

        $this->assertTrue(
            $MockFactory->nameIsInUse("E", false),
            "nameIsInUse() failed to return true when performing a case sensitive ".
            "search with a correctly-cased existing name."
        );

        $this->assertTrue(
            $MockFactory->nameIsInUse("e", true),
            "nameIsInUse() failed to return true when performing a case insensitive ".
            "search with an incorrectly-cased existing name."
        );

        $this->assertTrue(
            !$MockFactorySqlConditions->nameIsInUse("E", false),
            "nameIsInUse() failed to return false when performing a search for an ".
            "existing name excluded by contructor SQL conditions."
        );
    }

    /**
     * Test getLatestModificationDate() for accuracy
     */
    public function testGetLatestModificationDate()
    {
        $MockFactory = new MockItemFactory();
        $MockFactorySqlConditions = new MockItemFactory(false, "MockFactoryItemName = 'Old'");

        MockFactoryItem::create("Old");
        MockFactoryItem::createRecent("New");

        $this->assertEquals(
            $this::OLDDATE,
            $MockFactorySqlConditions->getLatestModificationDate(),
            "getLatestModificationDate() failed to return the most recent modification date ".
            "matching the given SQL condition in the constructor."
        );

        $this->assertEquals(
            null,
            $MockFactorySqlConditions->getLatestModificationDate("MockFactoryItemName = 'New'"),
            "getLatestModification() returned an item despite all items being excluded ".
            "by both the parameter and constructor SQL condition."
        );

        $this->assertEquals(
            $this::RECENTDATE,
            $MockFactory->getLatestModificationDate(),
            "getLatestModificationDate() failed to return the most recent modification date."
        );

        $this->assertEquals(
            $this::OLDDATE,
            $MockFactory->getLatestModificationDate("MockFactoryItemName = 'Old'"),
            "getLatestModificationDate() failed to return the most recent ".
            "modification date matching the given SQL condition."
        );
    }

    /**
     * Test reindexByItemIds() and getItemIdsByNames() for accuracy
     */
    public function testReindexByItemIds()
    {
        $MockFactory = new MockItemFactory();

        # construct arrays for testing
        for ($i = 0; $i < self::TOADD; $i++) {
            $ItemId = MockFactoryItem::create("Name".$i)->id();
            $Names[] = "Name".$i;
            $NameValueArray["Name".$i] = $ItemId;
            $IdValueArray[$ItemId] = $ItemId;
        }

        $this->assertEquals(
            $IdValueArray,
            $MockFactory->reindexByItemIds($NameValueArray),
            "reindexByItemIds() failed to correctly reindex passed associative array ".
            "(replacing names indexes with IDs)."
        );

        $this->assertEquals(
            $NameValueArray,
            $MockFactory->getItemIdsByNames($Names),
            "getItemIdsByName() failed to return an associated array containing "
            ."the correct item IDs indexed by name."
        );

        $this->expectException(InvalidArgumentException::class);
        $MockFactory->reindexByItemIds(["Invalid name" => "Value"]);
    }

    /**
     * Test getNextItemId()'s accuracy
     */
    public function testGetNextItemId()
    {
        $MockFactory = new MockItemFactory();
        MockFactoryItem::createTemp("Temp Name", $MockFactory->getNextTempItemId());

        $this->assertEquals(
            1,
            $MockFactory->getNextItemId(),
            "getNextItemId() failed to return 1 when only temp items exist."
        );

        $MockItem = MockFactoryItem::create("HighestId");
        $NextId = $MockItem->id() + 1;

        $this->assertEquals(
            $NextId,
            $MockFactory->getNextItemId(),
            "getNextItemId() failed to return the ID after the item that was previously entered."
        );
        MockFactoryItem::create("NextItem");
        $NewItem = $MockFactory->getItemByName("NextItem");

        $this->assertEquals(
            $NextId,
            $NewItem->id(),
            "getNextItemId() failed to return the correct next ID."
        );
    }

    /**
     * Test append() order operation function
     */
    public function testAppend()
    {
        $ItemOne = MockFactoryItem::create("One");
        $ItemTwo = MockFactoryItem::create("Two");

        $MockFactory = new MockItemFactory(true);
        $MockFactory->append($ItemOne);
        $MockFactory->append($ItemTwo);
        $ItemIds = $MockFactory->getItemIdsInOrder();

        $this->assertTrue(
            ($ItemIds[1] == $ItemTwo->id()),
            "append() failed to correctly append specified item to the list."
        );

        $MockFactoryNoOps = new MockItemFactory(false);
        $this->expectException(Exception::class);
        $MockFactoryNoOps->append($ItemOne);
    }

    /**
     * Test prepend() order operation function
     */
    public function testPrepend()
    {
        $ItemOne = MockFactoryItem::create("One");
        $ItemTwo = MockFactoryItem::create("Two");

        $MockFactory = new MockItemFactory(true);
        $MockFactory->append($ItemOne);
        $MockFactory->prepend($ItemTwo);
        $ItemIds = $MockFactory->getItemIdsInOrder();
        $this->assertTrue(
            ($ItemIds[0] == $ItemTwo->id()),
            "prepend() failed to correctly prepend specified item to the list."
        );

        $MockFactoryNoOps = new MockItemFactory(false);
        $this->expectException(Exception::class);
        $MockFactoryNoOps->prepend($ItemOne);
    }

    /**
     * Test insertBefore() order operation function
     */
    public function testInsertBefore()
    {
        $ItemOne = MockFactoryItem::create("One");
        $ItemTwo = MockFactoryItem::create("Two");

        $MockFactory = new MockItemFactory(true);
        $MockFactory->append($ItemOne);
        $MockFactory->insertBefore($ItemOne, $ItemTwo);
        $ItemIds = $MockFactory->getItemIdsInOrder();

        $this->assertTrue(
            ($ItemIds[0] == $ItemTwo->id()),
            "insertBefore() failed to correctly insert item two before item one."
        );

        $MockFactoryNoOps = new MockItemFactory(false);
        $this->expectException(Exception::class);
        $MockFactoryNoOps->insertBefore(null, $ItemOne);
    }

    /**
     * Test insertAfter() order operation function
     */
    public function testInsertAfter()
    {
        $ItemOne = MockFactoryItem::create("One");
        $ItemTwo = MockFactoryItem::create("Two");

        $MockFactory = new MockItemFactory(true);
        $MockFactory->append($ItemOne);
        $MockFactory->insertAfter($ItemOne, $ItemTwo);
        $ItemIds = $MockFactory->getItemIdsInOrder();

        $this->assertTrue(
            ($ItemIds[1] == $ItemTwo->id()),
            "insertAfter() failed to correctly insert item two after item one."
        );

        $MockFactoryNoOps = new MockItemFactory(false);
        $this->expectException(Exception::class);
        $MockFactoryNoOps->insertAfter(null, $ItemOne);
    }

    /**
     * Test removeItemFromOrder() order operation function
     */
    public function testRemoveItemFromOrder()
    {
        $ItemOne = MockFactoryItem::create("One");

        $MockFactory = new MockItemFactory(true);
        $MockFactory->append($ItemOne);

        $MockFactory->removeItemFromOrder($ItemOne->id());
        $ItemIds = $MockFactory->getItemIdsInOrder();

        $this->assertTrue(
            !in_array($ItemOne->id(), $ItemIds),
            "removeItemFromOrder() failed to correctly remove specified item from the list."
        );

        $MockFactoryNoOps = new MockItemFactory(false);
        $this->expectException(Exception::class);
        $MockFactoryNoOps->removeItemFromOrder($ItemOne->id());
    }

    /**
     * Test getNextTempItemId()
     */
    public function testGetNextTempItemId()
    {
        $MockFactory = new MockItemFactory();

        $this->assertTrue(
            $MockFactory->getNextTempItemId() < 0,
            "getNextTempItemId() returned an invalid temp item ID."
        );
        # check that this item doesn't exist
        $this->expectException(Exception::class);
        $MockItem = new MockFactoryItem($MockFactory->getNextTempItemId());
    }

    /**
     * test cleanOutStaleTempItems()
     */
    public function testCleanOutStaleTempItems()
    {
        $MockFactory = new MockItemFactory();
        $MockFactorySqlConditions = new MockItemFactory(false, "MockFactoryItemName = 'Non-stale'");
        # Preemptively clean out stale temp items to ensure that there are none before the next call
        $MockFactory->cleanOutStaleTempItems();
        MockFactoryItem::create("Non-stale Item");

        $this->assertEquals(
            0,
            $MockFactory->cleanOutStaleTempItems(),
            "cleanOutStaleTempItems() removed a nonexistent stale temp item (returned > 0)."
        );

        $this->assertTrue(
            !is_null(new MockFactoryItem($MockFactory->getItemIdByName("Non-stale Item"))),
            "cleanOutStaleTempItems() removed a non-stale item."
        );

        $TempId = MockFactoryItem::createTemp(
            "Stale Item",
            $MockFactory->getNextTempItemId()
        )->id();

        $this->assertEquals(
            0,
            $MockFactorySqlConditions->cleanOutStaleTempItems(),
            "cleanOutStaleTempItems() removed a stale item not matching "
            ."given constructor SQL conditions."
        );

        $this->assertEquals(
            1,
            $MockFactory->cleanOutStaleTempItems(),
            "cleanOutStaleTempItems() failed to remove a stale temp item."
        );

        $this->expectException(Exception::class);
        new MockFactoryItem($TempId);
    }

    /**
     * make sure an exception is thrown when OrderOps is not allowed & getItemIdsInOrder() is called
     */
    public function testGetItemIdsInOrder()
    {
        $MockFactory = new MockItemFactory(false);
        $this->expectException(Exception::class);
        $MockFactory->getItemIdsInOrder();
    }

    /**
     * test searching for items by name.
     */
    public function testSearchForItemNames()
    {
        $Factory = new MockItemFactory();

        # load up test data
        $ItemsToCreate = [
            "a",
            "b",
            "a b",
            "where",
            "twilight",
            "twilight sparkle",
            "dude",
            "the dude",
            "chocolate",
            "mushrooms",
            "chocolate-covered mushrooms",
        ];

        $Items = [];
        foreach ($ItemsToCreate as $ItemName) {
            $Items[$ItemName] = MockFactoryItem::create($ItemName);
        }

        # tests for boolean mode
        $TestSearches = [
            "a" => ["a", "a b"],
            "a -b" => ["a"],
            "a b" => ["a b"],
            "b" => ["b", "a b"],
            "b -a" => ["b"],
            "where" => ["where"],
            "the" => ["the dude"],
            "dude" => ["dude", "the dude"],
            "dude -the" => ["dude"],
            "twilight" => ["twilight", "twilight sparkle"],
            "twilight -sparkle" => ["twilight"],
            "\"twilight sparkle\"" => ["twilight sparkle"],
            "\"twilight sparkle" => ["twilight sparkle"],
            "mushrooms" => ["mushrooms", "chocolate-covered mushrooms"],
            "chocolate" => ["chocolate", "chocolate-covered mushrooms"],
            "chocolate-covered" => ["chocolate-covered mushrooms"],
            "mushrooms -chocolate-covered" => ["mushrooms"],
        ];

        foreach ($TestSearches as $SearchString => $ExpectedItemNames) {
            $ExpectedResult = [];
            foreach ($ExpectedItemNames as $ItemName) {
                $ExpectedResult[$Items[$ItemName]->id()] = $ItemName;
            }

            $ActualResult = $Factory->searchForItemNames($SearchString);
            $this->assertEquals(
                $ExpectedResult,
                $ActualResult,
                "searchForItemNames('".$SearchString."') was incorrect."
            );
        }

        # and one test for non-boolean mode
        $TestSearches = [
            "twilight" => ["twilight", "twilight sparkle"],
        ];

        foreach ($TestSearches as $SearchString => $ExpectedItemNames) {
            $ExpectedResult = [];
            foreach ($ExpectedItemNames as $ItemName) {
                $ExpectedResult[$Items[$ItemName]->id()] = $ItemName;
            }

            $ActualResult = $Factory->searchForItemNames(
                $SearchString,
                100,
                false,
                false
            );
            $this->assertEquals(
                $ExpectedResult,
                $ActualResult,
                "searchForItemNames('".$SearchString."') was incorrect in non-boolean mode."
            );
        }
    }
}

/**
 * Need a MockFactoryItem class extending Item because Item is an abstract class
 * (For MockItemFactory to handle)
 */
class MockFactoryItem extends Item
{
    /**
     * Create a new item using the given name and default other values
     * @param string $Name to be used for new item
     * @return MockFactoryItem newly created item
     */
    public static function create(string $Name): MockFactoryItem
    {
        $MockItemValues = [
            "MockFactoryItemName" => $Name,
            "DateCreated" => "1989-01-23 12:34:56",
            "CreatedBy" => 1,
            "DateLastModified" => ItemFactory_Test::OLDDATE,
            "LastModifiedBy" => 1
        ];

        $MockItem = parent::CreateWithValues($MockItemValues);
        return $MockItem;
    }

    /**
     * Create new item using the given name and default other values,
     * with date created being relatively recent (2019)
     * @param string $Name to be used for new item
     * @return MockFactoryItem newly created item
     */
    public static function createRecent(string $Name): MockFactoryItem
    {
        $MockItemValues = [
            "MockFactoryItemName" => $Name,
            "DateCreated" => "2019-02-15 12:34:56",
            "CreatedBy" => 1,
            "DateLastModified" => ItemFactory_Test::RECENTDATE,
            "LastModifiedBy" => 1
        ];

        $MockItem = parent::CreateWithValues($MockItemValues);
        return $MockItem;
    }

    /**
     * Create new item using given name and temp ID, as well as other default values,
     * @param string $Name to be used for new item
     * @param int|string $TempId to be used as the ID
     * @return MockFactoryItem newly created item
     */
    public static function createTemp(string $Name, $TempId): MockFactoryItem
    {
        $DB = new Database();

        $DB->query(
            "INSERT INTO MockFactoryItems
            SET `MockFactoryItemId` = '".intval($TempId)."',
            `MockFactoryItemName` = '".$Name."',
            `DateCreated` = '1989-01-23 12:34:56',
            `DateLastModified` = '1989-01-23 12:34:56' "
        );

        return new MockFactoryItem(intval($TempId));
    }
}

/**
 * Need MockItemFactory to extend ItemFactory since ItemFactory is abstract
 * Use information about MockFactoryItem for this constructor
 */
class MockItemFactory extends ItemFactory
{
    /**
     * Constructor for MockItemFactory
     * @param boolean $OrderOpsAllowed whether or not order of operations is
     *   allowed (for PDLL tests)
     * @param string $SqlCondition SQL condition to apply to specific tests
     */
    public function __construct(
        $OrderOpsAllowed = false,
        $SqlCondition = null,
        $ItemNameColumnName = "MockFactoryItemName"
    ) {
        # set up item factory base class
        parent::__construct(
            "MockFactoryItem",
            "MockFactoryItems",
            "MockFactoryItemId",
            $ItemNameColumnName,
            $OrderOpsAllowed,
            $SqlCondition
        );
    }
}
