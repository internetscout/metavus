<?PHP
#
#   FILE:  Folder_Test.php
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
use ScoutLib\StdLib;

class Folder_Test extends \PHPUnit\Framework\TestCase
{
    const TYPE_NAME = "XX-Test-XX";

    /**
     * Delete XX-Test-XX folder conte type that was created for
     * testing.
     */
    public static function tearDownAfterClass() : void
    {
        $DB = new Database();
        $DB->Query("DELETE FROM FolderContentTypes WHERE "."TypeName='".self::TYPE_NAME."'");
    }

    /**
     * Verify that items can be appended and appear in the correct order.
     * covers AppendItem()
     */
    public function testAppendItem()
    {
        $Factory = new FolderFactory();
        $TestFolder = $Factory->CreateFolder("Resource");

        $TestFolder->AppendItem(1);
        $this->CheckContents($TestFolder, [1]);

        $TestFolder->AppendItem(2);
        $this->CheckContents($TestFolder, [1, 2]);

        $TestFolder->Delete();
    }

    /**
     * Verify that items can be prepended and appear in the correct order.
     * covers PrependItem()
     */
    public function testPrependItem()
    {
        $Factory = new FolderFactory();
        $TestFolder = $Factory->CreateFolder("Resource");

        $TestFolder->PrependItem(1);
        $this->CheckContents($TestFolder, [1]);

        $TestFolder->PrependItem(2);
        $this->CheckContents($TestFolder, [2, 1]);

        $TestFolder->Delete();
    }

    /**
     * Verify that InsertItemBefore() works correctly on empty lists,
     * when the tgt is nonexistent, and at the beginning, middle, and
     * end of a list.
     * covers InsertItemBefore()
     */
    public function testInsertItemBefore()
    {
        $Factory = new FolderFactory();
        $TestFolder = $Factory->CreateFolder("Resource");

        # insert to empty list
        $TestFolder->InsertItemBefore(100, 1);
        $this->CheckContents($TestFolder, [1]);

        # insert before non-existent item
        $TestFolder->InsertItemBefore(100, 2);
        $this->CheckContents($TestFolder, [2, 1]);

        # insert before beginning
        $TestFolder->InsertItemBefore(2, 3);
        $this->CheckContents($TestFolder, [3, 2, 1]);

        # insert before end
        $TestFolder->InsertItemBefore(1, 4);
        $this->CheckContents($TestFolder, [3, 2, 4, 1]);

        # insert in the middle
        $TestFolder->InsertItemBefore(4, 5);
        $this->CheckContents($TestFolder, [3, 2, 5, 4, 1]);

        $TestFolder->Delete();
    }

    /**
     * Verify that InsertItemAfter() works correctly on empty lists,
     * when the tgt is nonexistent, and at the beginning, middle, and
     * end of a list.
     * covers InsertItemAfter()
     */
    public function testInsertItemAfter()
    {
        $Factory = new FolderFactory();
        $TestFolder = $Factory->CreateFolder("Resource");

        # insert to empty list
        $TestFolder->InsertItemAfter(100, 1);
        $this->CheckContents($TestFolder, [1]);

        # insert before non-existent item
        $TestFolder->InsertItemAfter(100, 2);
        $this->CheckContents($TestFolder, [1, 2]);

        # insert after beginning
        $TestFolder->InsertItemAfter(1, 3);
        $this->CheckContents($TestFolder, [1, 3, 2]);

        # insert after end
        $TestFolder->InsertItemAfter(2, 4);
        $this->CheckContents($TestFolder, [1, 3, 2, 4]);

        # insert in the middle
        $TestFolder->InsertItemAfter(3, 5);
        $this->CheckContents($TestFolder, [1,3,5,2,4]);

        $TestFolder->Delete();
    }

    /**
     * Verify that itemExists() works correctly for both existing
     * and non-existing folder ids.
     * covers itemExists()
     */
    public function testItemExists()
    {
        $Factory = new FolderFactory();
        $TestFolder1 = $Factory->createFolder("Resource");
        $TestFolder2 = $Factory->createFolder("Resource");
        $RemovedId = $TestFolder2->id();
        $TestFolder2->delete();

        $this->assertTrue(Folder::itemExists($TestFolder1->id()));
        $this->assertFalse(Folder::itemExists($RemovedId));

        $TestFolder1->delete();
    }

    /**
     * Verify that create() and contentType()
     * works correctly in different scenarios.
     * covers create() and contentType()
     */
    public function testCreateAndContentType()
    {
        # Create a test user to assign as the test folders owner.
        $TestUser = User::create("Folder_Test_User");

        # Test Case 1: Create a folder without an owner and with default item type
        $TestFolder1 = Folder::create();
        $this->assertEquals($TestFolder1->ownerId(), 0);
        $this->assertEquals($TestFolder1->contentType(), Folder::MIXEDCONTENT);

        # Test Case 2: Create a folder with an owner and with default item type
        $TestFolder2 = Folder::create($TestUser->id());
        $this->assertEquals($TestFolder2->ownerId(), $TestUser->id());
        $this->assertEquals($TestFolder2->contentType(), Folder::MIXEDCONTENT);


        # Test Case 3: Create a folder without an owner and with defined item type
        $TestFolder3 = Folder::create(null, "Resource");
        $this->assertEquals($TestFolder3->ownerId(), 0);
        $this->assertEquals($TestFolder3->contentType(), "Resource");

        # Test Case 4: Create a folder with an owner and with defined item type
        $TestFolder4 = Folder::create($TestUser->id(), "Resource");
        $this->assertEquals($TestFolder4->ownerId(), $TestUser->id());
        $this->assertEquals($TestFolder4->contentType(), "Resource");

        # Delete the test folders and user
        $TestFolder1->delete();
        $TestFolder2->delete();
        $TestFolder3->delete();
        $TestFolder4->delete();
        $TestUser->delete();
    }

    /**
     * Verify that sort() works correctly in different scenarios.
     * covers sort().
     */
    public function testSort()
    {
        # Create test folder
        $TestFolder = Folder::create(null, "Resource");

        # Create and add test records to the test folder
        $TestRecord1 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $TestRecord1->isTempRecord(false);

        $TestRecord2 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $TestRecord2->isTempRecord(false);

        $TestRecord3 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $TestRecord3->isTempRecord(false);

        $TestRecord4 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $TestRecord4->isTempRecord(false);

        $TestFolder->appendItems([
            $TestRecord1->id(),
            $TestRecord2->id(),
            $TestRecord3->id(),
            $TestRecord4->id(),
        ], MetadataSchema::SCHEMAID_RESOURCES);

        # Test Case 1: Sorting items in ascending order
        $TestRecord1->set("Title", "a");
        $TestRecord2->set("Title", "c");
        $TestRecord3->set("Title", "d");
        $TestRecord4->set("Title", "b");
        $TestFolder->sort([$this, "ascFolderSortingCallback"]);
        $this->checkContents($TestFolder, [
            $TestRecord1->id(),
            $TestRecord4->id(),
            $TestRecord2->id(),
            $TestRecord3->id(),
        ]);

        # Test Case 2: Sorting items in descending order
        $TestRecord1->set("Title", "d");
        $TestRecord2->set("Title", "a");
        $TestRecord3->set("Title", "b");
        $TestRecord4->set("Title", "c");
        $TestFolder->sort([$this, "descFolderSortingCallback"]);
        $this->checkContents($TestFolder, [
            $TestRecord1->id(),
            $TestRecord4->id(),
            $TestRecord3->id(),
            $TestRecord2->id(),
        ]);

        # Delete the test folder and records
        $TestRecord1->destroy();
        $TestRecord2->destroy();
        $TestRecord3->destroy();
        $TestRecord4->destroy();
        $TestFolder->delete();
    }

    /**
     * Verify that duplicate() works correctly in different scenarios.
     */
    public function testDuplicate()
    {
        # Create test folder
        $TestFolder = Folder::create(null, "Resource");
        $TestFolder->name("Folder_Test");

        # Create and add test records to the test folder
        $TestRecord1 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $TestRecord1->isTempRecord(false);

        $TestRecord2 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $TestRecord2->isTempRecord(false);

        $TestRecord3 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $TestRecord3->isTempRecord(false);

        $TestRecord4 = Record::create(MetadataSchema::SCHEMAID_RESOURCES);
        $TestRecord4->isTempRecord(false);

        $TestFolder->appendItems([
            $TestRecord1->id(),
            $TestRecord2->id(),
            $TestRecord3->id(),
            $TestRecord4->id(),
        ], MetadataSchema::SCHEMAID_RESOURCES);

        # Duplicate the test folder and check that it has the same name, type, and contents.
        $ClonedTestFolder = $TestFolder->duplicate();

        $this->assertEquals($TestFolder->name(), $ClonedTestFolder->name());
        $this->assertEquals($TestFolder->contentType(), $ClonedTestFolder->contentType());
        $this->assertEquals($TestFolder->getItemIds(), $ClonedTestFolder->getItemIds());

        # Delete the test folder and records
        $TestRecord1->destroy();
        $TestRecord2->destroy();
        $TestRecord3->destroy();
        $TestRecord4->destroy();
        $TestFolder->delete();
        $ClonedTestFolder->delete();
    }

    /**
     * Test creation of a mixed content folder with AppendItems()
     * inserting some data into it.  Note that with mixed content
     * folders, we're only exercising the code paths in Folder that
     * aren't otherwise used.  This presumes that the unit tests for
     * PersistentDoublyLinkedList will find any issues with typed
     * lists.
     * covers FolderFactory::CreateMixedFolder(), AppendItems()
     */
    public function testMixedContent()
    {
        $Factory = new FolderFactory();
        $TestFolder = $Factory->CreateMixedFolder();

        $TestFolder->AppendItems([1, 2, 3], self::TYPE_NAME);
        $this->CheckContentsMixed($TestFolder, [1, 2, 3]);

        $TestFolder->Delete();
    }

    /**
     * Test all remaining misc functions.
     * covers AppendItems(), GetItemIds() when Offset and Limit are
     * specified, Remove Item(), Id(), Name(), NormalizedName(),
     * OwnerId(), Note(), IsShared(), NoteForItem(), and untested paths
     * in the constructor.
     */
    public function testMiscRemaining()
    {
        $Factory = new FolderFactory();
        $TestFolder = $Factory->CreateFolder("Resource");

        # preload the folder wtih 1:5
        $TestFolder->AppendItems([1,2,3,4,5]);
        $this->CheckContents($TestFolder, [1,2,3,4,5]);

        # test the offset and limit of GetItemIds()
        $this->assertSame(
            [3,4,5],
            $TestFolder->GetItemIds(2)
        );
        $this->assertSame(
            [3,4],
            $TestFolder->GetItemIds(2, 2)
        );

        # test removing items from the beginning, end, and middle of the folder
        $TestFolder->RemoveItem(1);
        $this->CheckContents($TestFolder, [2,3,4,5]);

        $TestFolder->RemoveItem(5);
        $this->CheckContents($TestFolder, [2,3,4]);

        $TestFolder->RemoveItem(3);
        $this->CheckContents($TestFolder, [2,4]);

        # make sure that the FolderId is an int
        $this->assertTrue(
            is_int($TestFolder->Id())
        );

        # test the setter/getter methods for this folder
        $TestFolder->Name("My Test Folder");
        $this->assertSame(
            "My Test Folder",
            $TestFolder->Name()
        );

        # check the autogenerated normalized name
        $this->assertSame(
            "mytestfolder",
            $TestFolder->Normalizedname()
        );

        $TestFolder->NormalizedName("mynewname");
        $this->assertSame(
            "mynewname",
            $TestFolder->NormalizedName()
        );

        # blank the normalized name so it will reset
        $TestFolder->NormalizedName('');
        $this->assertSame(
            "mytestfolder",
            $TestFolder->NormalizedName()
        );

        $TestFolder->OwnerId(5);
        $this->assertSame(
            5,
            $TestFolder->OwnerId()
        );

        $TestFolder->Note("Test folder note");
        $this->assertSame(
            "Test folder note",
            $TestFolder->Note()
        );

        $TestFolder->IsShared(1);
        $this->assertSame(
            true,
            $TestFolder->IsShared()
        );

        # and test folder item notes
        $TestFolder->NoteForItem(4, "Test Item Note");
        $this->assertSame(
            "Test Item Note",
            $TestFolder->NoteForItem(4)
        );

        # test getting an existing folder
        $TestFolderCopy = new Folder($TestFolder->Id());

        # verify that TestFolder and TestFolderCopy refer to the same FolderId
        $this->assertSame(
            $TestFolder->Id(),
            $TestFolderCopy->Id()
        );

        # test getting a nonexistent folder
        try {
            $TestFolderCopy = new Folder(Database::INT_MAX_VALUE);
            $this->assertTrue(false);
        } catch (Exception $e) {
            // empty on purpose
        }

        $TestFolder->Delete();
    }

    /**
     * Sort the items of the folder in ascending order.
     * @param $ItemA Id of the first item to compare.
     * @param $ItemB Id of the second item to compare.
     * @return int 0 if the two items are equal, > 0 if ItemA > ItemB, or < 0 if ItemA < ItemB
     */
    public function ascFolderSortingCallback($ItemA, $ItemB)
    {
        return $this->sortFolderCallback($ItemA, $ItemB, true);
    }

    /**
     * Sort the items of the folder in descending order.
     * @param $ItemA Id of the first item to compare.
     * @param $ItemB Id of the second item to compare.
     * @return int 0 if the two items are equal, > 0 if ItemA > ItemB, or < 0 if ItemA < ItemB
     */
    public function descFolderSortingCallback($ItemA, $ItemB)
    {
        return $this->sortFolderCallback($ItemA, $ItemB, false);
    }

    /**
     * Check the contents of a regular folder (not MIXEDCONTENT) to
     * ensure that it has the correct number of items in the correct
     * order.
     * @param Folder $TestFolder Folder to check.
     * @param array $TgtItemIds Items that should appear in the folder.
     */
    private function checkContents($TestFolder, $TgtItemIds)
    {
        $this->assertSame(
            count($TgtItemIds),
            $TestFolder->GetItemCount()
        );
        $this->assertSame(
            $TgtItemIds,
            $TestFolder->GetItemIds()
        );
    }

    /**
     * Check the contents of a MIXEDCONTENT folder to
     * ensure that it has the correct number of items in the correct
     * order.
     * @param Folder $TestFolder Folder to check.
     * @param array $TgtItemIds Items that should appear in the folder.
     */
    private function checkContentsMixed($TestFolder, $TgtItemIds)
    {
        $this->assertSame(
            count($TgtItemIds),
            $TestFolder->GetItemCount()
        );

        $TgtVal = [];
        foreach ($TgtItemIds as $ItemId) {
            $TgtVal[] = ["ID" => $ItemId, "Type" => self::TYPE_NAME];
        }

        $this->assertSame(
            $TgtVal,
            $TestFolder->GetItemIds()
        );
    }

    /**
     * Sort the items of the folder.
     * @param $ItemA Id of the first item to compare.
     * @param $ItemB Id of the second item to compare.
     * @param $AscSortingOrder True to sort the items of the folder in
     *     ascending order. Otherwise, sort in descending order.
     * @return int 0 if the two items are equal, > 0 if ItemA > ItemB, or < 0 if ItemA < ItemB
     */
    private function sortFolderCallback($ItemA, $ItemB, $AscSortingOrder)
    {
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_RESOURCES);
        $SortFieldId = $Schema->getFieldIdByName("Title");
        $ValueCache = [];
        $SortField = new MetadataField($SortFieldId);

        # load values of items not present in cache to cache
        # (value will be used later for sorting)
        foreach ([$ItemA, $ItemB] as $ItemId) {
            # first lookup this item in cache,
            # if it's already present, skip it
            if (isset($ValueCache[$ItemId])) {
                continue;
            }

            $Resource = new Record($ItemId);
            $ResourceSchema = $Resource->getSchema();

            # put this item last if its schema doesn't own the sort_field
            if ($ResourceSchema->Id() != $SortField->SchemaId()) {
                $ValueCache[$ItemId] = null;
                continue;
            }

            # for array value, use the smallest element in the array
            $Value = $Resource->Get($SortField->Id());
            if (is_array($Value)) {
                if (count($Value)) {
                    sort($Value);
                    $Value = current($Value);
                } else {
                    $Value = null;
                }
            }

            # empty string is considered the same as NULL
            if (is_string($Value) && !strlen($Value)) {
                $Value = null;
            }

            # special processing based on sorting field's type
            if (!is_null($Value)) {
                # convert Timestamp type value from string to number (Unix timestamp)
                if ($SortField->Type() == MetadataSchema::MDFTYPE_TIMESTAMP) {
                    $Value = strtotime($Value);
                }

                # convert Date type value from string to number (Unix timestamp)
                if ($SortField->Type() == MetadataSchema::MDFTYPE_DATE) {
                    $Date = new Date($Value);
                    $Value = strtotime($Date->BeginDate());
                }
            }

            $ValueCache[$ItemId] = $Value;
        }

        # get values of ItemA and ItemB
        $ValA = $ValueCache[$ItemA];
        $ValB = $ValueCache[$ItemB];

        # resources with NULL as field value is always put last
        if (is_null($ValA) && !is_null($ValB)) {
            return 1;
        }
        if (is_null($ValB) && !is_null($ValA)) {
            return -1;
        }

        # modify the sort comparison result with respect to the required sorting order
        # in case of descending sorting order, we will reverse the sorting order.
        # the sorting should be case-insensitive.
        return ($AscSortingOrder ? 1 : -1) * StdLib::SortCompare(
            strtolower($ValA),
            strtolower($ValB)
        );
    }
}
