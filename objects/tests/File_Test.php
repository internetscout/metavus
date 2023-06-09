<?PHP

namespace Metavus;

use ScoutLib\Database;

class File_Test extends \PHPUnit\Framework\TestCase
{
    public function testFile()
    {
        $File = File::Create("invalid/path", "testFile");
        $this->assertSame(File::FILESTAT_DOESNOTEXIST, $File);

        $File = File::Create("objects/tests/files/ZeroLengthFile.txt");
        $this->assertSame(File::FILESTAT_ZEROLENGTH, $File);

        $TmpDir = sys_get_temp_dir();
        $TmpDir = $TmpDir.((substr($TmpDir, -1) != "/") ? "/" : "");
        copy("objects/tests/files/ValidFile.txt", $TmpDir."ValidFile.txt");
        $File = File::Create($TmpDir."ValidFile.txt");
        $this->assertInstanceOf('Metavus\\File', $File);

        $this->assertSame("local/data/files", $File->GetStorageDirectory());

        $StoredFilePath = $File->GetNameOfStoredFile();
        $this->assertSame(
            file_get_contents("objects/tests/files/ValidFile.txt"),
            file_get_contents($StoredFilePath)
        );

        $this->assertSame(21, $File->GetLength());
        $this->assertSame('text/plain', $File->GetType());
        $this->assertSame("text/plain", $File->GetMimeType());

        $File->Comment("abc123");
        $this->assertSame("abc123", $File->Comment());

        $Copy = $File->duplicate();
        $this->assertSame($Copy->Name(), $File->Name());
        $this->assertSame(
            file_get_contents($Copy->GetNameOfStoredFile()),
            file_get_contents("objects/tests/files/ValidFile.txt")
        );

        $this->assertSame(Record::NO_ITEM, $File->FieldId());
        $File->FieldId(Database::INT_MAX_VALUE);
        $this->assertSame(Database::INT_MAX_VALUE, $File->FieldId());


        $this->assertSame(Record::NO_ITEM, $File->ResourceId());
        $File->ResourceId(Database::INT_MAX_VALUE);
        $this->assertSame(Database::INT_MAX_VALUE, $File->ResourceId());


        $GLOBALS["G_PluginManager"]->PluginEnabled("CleanURLs", false);
        $this->assertSame("index.php?P=DownloadFile&Id=".$File->Id(), $File->GetLink());

        $GLOBALS["G_PluginManager"]->PluginEnabled("CleanURLs", true);
        $this->assertSame("downloads/".$File->Id()."/ValidFile.txt", $File->GetLink());


        $Copy->Destroy();
        $File->Destroy();

        $this->assertFileDoesNotExist($StoredFilePath);
    }
}
