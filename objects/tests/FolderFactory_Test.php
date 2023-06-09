<?PHP
#
#   FILE:  FolderFactory_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

use ScoutLib\Database;

class FolderFactory_Test extends \PHPUnit\Framework\TestCase
{
    const TYPE_NAME = "XX-Test-XX";
    const OWNER_ID = -10;

    public function testFactory()
    {
        # create a factory tied to a specified owner
        $Factory = new FolderFactory(self::OWNER_ID);

        # verify that they currently have no folders
        $this->assertSame(0, $Factory->GetFolderCount());

        # create a folder for them and verify that the count incrases
        $Factory->CreateFolder(self::TYPE_NAME, "Test Folder 1", self::OWNER_ID);
        $this->assertSame(1, $Factory->GetFolderCount());

        # create a second folder, verify that goes up as well
        $Factory->CreateFolder(self::TYPE_NAME, "Test Folder 2", self::OWNER_ID);
        $this->assertSame(2, $Factory->GetFolderCount());

        # get our folder by name
        $Folder = $Factory->GetFolderByNormalizedName("testfolder1");
        $this->assertTrue($Folder instanceof Folder);

        # get our folder using GetFolders with a name argument
        $MatchingFolders = $Factory->GetFolders(self::TYPE_NAME, self::OWNER_ID, "Test Folder 1");
        $this->assertSame(1, count($MatchingFolders));
        $this->assertSame(current($MatchingFolders)->Id(), $Folder->Id());

        # get our folder using GetFolders with a count argument
        $MatchingFolders = $Factory->GetFolders(self::TYPE_NAME, self::OWNER_ID, null, 0, 1);
        $this->assertSame(1, count($MatchingFolders));
        $this->assertSame(current($MatchingFolders)->Id(), $Folder->Id());

        # delete this folder
        $Folder->Delete();

        # verify that the folder is now gone
        $Folder = $Factory->GetFolderByNormalizedName("testfolder1");
        $this->assertNull($Folder);

        # get our remaining test folder, give it an item
        $Folder = $Factory->GetFolderByNormalizedName("testfolder2");
        $Folder->AppendItem(1);

        # search for that folder using GetFoldersContainingItem
        $MatchingFolders = $Factory->GetFoldersContainingItem(1, self::TYPE_NAME);
        $this->assertSame(1, count($MatchingFolders));
        $this->assertSame(current($MatchingFolders)->Id(), $Folder->Id());

        # clean up our remaining test folder
        $Folder->Delete();
        $this->assertSame(0, $Factory->GetFolderCount());
    }

    /**
    * Destroy tables created for testing.
    */
    public static function setUpBeforeClass() : void
    {
        $DB = new Database();
        $DB->Query("DELETE FROM Folders WHERE OwnerId = ".self::OWNER_ID);
        $DB->Query("DELETE FROM FolderContentTypes WHERE "
                   ."TypeName='".self::TYPE_NAME."' ");
    }

    /**
    * Destroy tables created for testing.
    */
    public static function tearDownAfterClass() : void
    {
        $DB = new Database();
        $DB->Query("DELETE FROM Folders WHERE OwnerId = ".self::OWNER_ID);
        $DB->Query("DELETE FROM FolderContentTypes WHERE "
                   ."TypeName='".self::TYPE_NAME."' ");
    }
}
