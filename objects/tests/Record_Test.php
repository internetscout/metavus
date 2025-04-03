<?PHP
#
#   FILE:  Record_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022-2023 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Date;
use ScoutLib\StdLib;

class Record_Test extends \PHPUnit\Framework\TestCase
{
    /**
    * Prior to running any of the tests, this function is
    * run.It creates all of the test Metadata fields and adds
    * them to class variables $TestFieldIds and $TestFields
    * so each function may use them.
    */
    public static function setUpBeforeClass(): void
    {
        # construct the schema object
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);

        self::$TestFieldIds = [];

        # outline fields to be created
        self::$TestFields = [
            "Test Text Field" => MetadataSchema::MDFTYPE_TEXT,
            "Test Timestamp Field" => MetadataSchema::MDFTYPE_TIMESTAMP,
            "Test Paragraph Field" => MetadataSchema::MDFTYPE_PARAGRAPH,
            "Test Url Field" => MetadataSchema::MDFTYPE_URL,
            "Test Reference Field" => MetadataSchema::MDFTYPE_REFERENCE,
            "Test User Field" => MetadataSchema::MDFTYPE_USER,
            "Test Option Field" => MetadataSchema::MDFTYPE_OPTION,
            "Test CName Field" => MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "Test Tree Field" => MetadataSchema::MDFTYPE_TREE,
            "Test Date Field" => MetadataSchema::MDFTYPE_DATE,
            "Test Flag Field" => MetadataSchema::MDFTYPE_FLAG,
            "Test Number Field" => MetadataSchema::MDFTYPE_NUMBER,
            "Test Point Field" => MetadataSchema::MDFTYPE_POINT,
        ];

        # create the fields
        foreach (self::$TestFields as $FieldName => $FieldType) {
            $TmpField = $Schema->GetItemByName($FieldName);
            if ($TmpField === null) {
                $TmpField = $Schema->AddField($FieldName, $FieldType);
            }
            $TmpField->IsTempItem(false);
            self::$TestFieldIds[$FieldName] = $TmpField->Id();
        }

        # Resource::Create() expects a user to be logged in,
        # so log in an admin user
        $UFactory = new UserFactory();
        $Users = $UFactory->GetUsersWithPrivileges(
            PRIV_RESOURCEADMIN,
            PRIV_COLLECTIONADMIN
        );
        $UserIds = array_keys($Users);
        $AdminUserId = array_pop($UserIds);
        self::$AdminUser = new User($AdminUserId);
        User::getCurrentUser()->Login(self::$AdminUser->Name(), "", true);

        # Create Classification, ControlledName, and Option values
        self::$TestClassification = Classification::Create(
            "Test Classification",
            self::$TestFieldIds['Test Tree Field']
        );
        self::$TestControlledName = ControlledName::Create(
            "Test Controlled Name",
            self::$TestFieldIds['Test CName Field']
        );
        self::$TestOptionCName = ControlledName::Create(
            "Test Option Name",
            self::$TestFieldIds['Test Option Field']
        );
    }

    /**
    * After to running the tests, this function is
    * run.It deletes all of the test Metadata fields.
    */
    public static function tearDownAfterClass(): void
    {
        # construct the schema object
        $Schema = new MetadataSchema();
        $Database = new Database();

        # drop all of the test fields
        foreach (self::$TestFieldIds as $FieldName => $FieldId) {
            $Schema->DropField($FieldId);
        }
    }

    /**
    * This function exercises the Resource get and set methods for
    * each Metadata types using the fields created in setUpBeforeClass().
    */
    public function testResource()
    {
        # create test-specific objects
        $TestResource = Record::Create(MetadataSchema::SCHEMAID_DEFAULT);
        $this->assertTrue(
            $TestResource->isTempRecord(),
            "Check that newly created resources are temporary."
        );

        $this->assertFalse(
            $TestResource->isTempRecord(false),
            "Check resources can be set permanent."
        );

        # test Comments features
        $this->CheckComments($TestResource);

        # test Ratings features
        $this->CheckRatings($TestResource);

        # test permissions-related functions
        $this->CheckPermissions($TestResource);

        # test get, set, and clear
        $TestReferenceResource = Record::Create(MetadataSchema::SCHEMAID_DEFAULT);
        $TestReferenceResource->isTempRecord(false);
        $this->CheckGetSetClear($TestResource, $TestReferenceResource);

        # check that resource schemas can be retrieved
        $this->CheckGetSchemaForRecord(
            $TestResource,
            $TestReferenceResource
        );

        # check that GetAsArray works
        $this->CheckGetAsArray(
            $TestResource,
            $TestReferenceResource
        );

        # check that perm resource can be made temporary and don't
        # lose any values in the process
        $this->CheckTempToggle($TestResource);

        $this->runTasks();

        # clean up function-specific objects
        $TestResource->destroy();
        $TestReferenceResource->destroy();
    }

    /**
    * Check that get, set, and clear all function for all tested field types.
    * @param Record $Resource Resource to test.
    * @param Record $RefResource Resource to use as a reference field value.
    */
    private function checkGetSetClear($Resource, $RefResource)
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # test get, set, and clear for each test field
        foreach (self::$TestFieldIds as $FieldName => $FieldId) {
            $Field = MetadataField::getField($FieldId);

            # whether, before testing equivalence, we need to pop the
            # returned value out of an array
            $BeforeTestArrayShift = false;

            # if we're testing the object return, this is the object we'll compare it to.
            unset($TestObject);

            switch ($Field->Type()) {
                case MetadataSchema::MDFTYPE_TEXT:
                    $TgtVal = "A test title";
                    break;

                case MetadataSchema::MDFTYPE_URL:
                    $TgtVal = "http://testtesttest.com";
                    break;

                case MetadataSchema::MDFTYPE_PARAGRAPH:
                    $TgtVal = "I am a test paragraph.";
                    break;

                case MetadataSchema::MDFTYPE_NUMBER:
                    $TgtVal = "0";
                    break;

                case MetadataSchema::MDFTYPE_FLAG:
                    $TgtVal = "1";
                    break;

                case MetadataSchema::MDFTYPE_DATE:
                    $TgtVal = date("Y-m-d");
                    $TestObject = new Date(strval($TgtVal));
                    $TestObjectType = 'ScoutLib\\Date';
                    $TestFunctionName = 'BeginDate';
                    $TestFunctionArguments = null;
                    break;

                case MetadataSchema::MDFTYPE_TIMESTAMP:
                    $TgtVal = date("Y-m-d H:i:s");
                    break;

                case MetadataSchema::MDFTYPE_TREE:
                    $TgtVal = [];
                    $TgtVal[self::$TestClassification->Id()] = "Test Classification";
                    $TestObject = self::$TestClassification;
                    $TestObjectType = 'Metavus\\Classification';
                    $TestFunctionName = 'FullName';
                    $TestFunctionArguments = null;
                    $BeforeTestArrayShift = true;
                    break;

                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                    $TgtVal = [];
                    $TgtVal[self::$TestControlledName->Id()] = "Test Controlled Name";
                    $TestObject = self::$TestControlledName;
                    $TestObjectType = 'Metavus\\ControlledName';
                    $TestFunctionName = 'Name';
                    $TestFunctionArguments = null;
                    $BeforeTestArrayShift = true;
                    break;

                case MetadataSchema::MDFTYPE_OPTION:
                    $TgtVal = [];
                    $TgtVal[self::$TestOptionCName->Id()] = "Test Option Name";
                    $TestObject = self::$TestOptionCName;
                    $TestObjectType = 'Metavus\\ControlledName';
                    $TestFunctionName = 'Name';
                    $TestFunctionArguments = null;
                    $BeforeTestArrayShift = true;
                    break;

                case MetadataSchema::MDFTYPE_USER:
                    $UserId = $User->Id();
                    $TestObject = new User($UserId);
                    $TgtVal = [$UserId => $TestObject->Name()];
                    $TestObjectType = 'Metavus\\User';
                    $TestFunctionName = 'Id';
                    $TestFunctionArguments = null;
                    $BeforeTestArrayShift = true;
                    break;

                case MetadataSchema::MDFTYPE_POINT:
                    $TgtVal = [];
                    $TgtVal['X'] = 5;
                    $TgtVal['Y'] = 7;
                    break;

                case MetadataSchema::MDFTYPE_REFERENCE:
                    $TestObject = $RefResource;
                    $TgtVal = [];
                    $TgtVal[$RefResource->Id()] = $RefResource->Id();
                    $TestFunctionName = 'Id';
                    $TestObjectType = 'Metavus\\Record';
                    $TestFunctionArguments = null;
                    $BeforeTestArrayShift = true;
                    break;

                default:
                    throw new Exception("Data type not handled.");
                    break;
            }

            # set the value on the test resource
            $Resource->Set($Field, $TgtVal);

            # assert the default get returns the expected value
            $FieldTypeName = StdLib::GetConstantName(
                "Metavus\\MetadataSchema",
                $Field->Type(),
                "MDFTYPE_"
            );
            $this->assertEquals(
                $TgtVal,
                $Resource->get($Field),
                "Check that value returned by get() matches for field type "
                .$FieldTypeName
            );

            $this->assertTrue(
                $Resource->fieldIsSet($Field),
                "Check that fieldIsSet() returns TRUE after setting value"
                ." for field type ".$FieldTypeName
            );

            $RCopy = new Record($Resource->Id());
            $this->assertEquals(
                $TgtVal,
                $RCopy->Get($Field),
                "Check that value returned by Get() matches"
                ." for field type w/ new resource ".$FieldTypeName
            );

            if (isset($TestObject)) {
                $ReturnedObject = $Resource->Get($Field, true);

                if ($BeforeTestArrayShift) {
                    $ReturnedObject = array_shift($ReturnedObject);
                }

                $array_for_test_object = [
                    $TestObject,
                    $TestFunctionName
                ];
                $array_for_returned_object = [
                    $ReturnedObject,
                    $TestFunctionName
                ];

                if ($TestFunctionArguments !== null) {
                    $this->assertEquals(
                        call_user_func(
                            $array_for_returned_object,
                            $TestFunctionArguments
                        ),
                        call_user_func(
                            $array_for_test_object,
                            $TestFunctionArguments
                        )
                    );
                } else {
                    $this->assertEquals(
                        call_user_func($array_for_returned_object),
                        call_user_func($array_for_test_object)
                    );
                }

                $this->assertInstanceOf($TestObjectType, $ReturnedObject);
            }

            # clear the value from the field
            $Resource->clear($Field);

            switch ($Field->Type()) {
                case MetadataSchema::MDFTYPE_TEXT:
                case MetadataSchema::MDFTYPE_URL:
                case MetadataSchema::MDFTYPE_PARAGRAPH:
                case MetadataSchema::MDFTYPE_DATE:
                case MetadataSchema::MDFTYPE_TIMESTAMP:
                case MetadataSchema::MDFTYPE_NUMBER:
                case MetadataSchema::MDFTYPE_FLAG:
                    $TgtVal = null;
                    break;

                case MetadataSchema::MDFTYPE_TREE:
                case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
                case MetadataSchema::MDFTYPE_OPTION:
                case MetadataSchema::MDFTYPE_USER:
                case MetadataSchema::MDFTYPE_REFERENCE:
                    $TgtVal = [];
                    break;

                case MetadataSchema::MDFTYPE_POINT:
                    $TgtVal = [
                        "X" => null,
                        "Y" => null
                    ];
                    break;

                default:
                    throw new Exception("Data type not handled.");
                    break;
            }

            $this->assertEquals(
                $TgtVal,
                $Resource->get($Field),
                "Check that ".$FieldTypeName." can be cleared"
            );

            $this->assertFalse(
                $Resource->fieldIsSet($Field),
                "Check that fieldIsSet() returns FALSE after clearing value"
                ." for field type ".$FieldTypeName
            );

            $RCopy = new Record($Resource->Id());
            $this->assertEquals(
                $TgtVal,
                $RCopy->Get($Field),
                "Check that value returned by Get() matches"
                ." for field type w/ new resource ".$FieldTypeName
            );
        }
    }

    /**
    * Check that newly created resources have no comments, that a
    * comment can be added, and that this comment can be removed.
    * @param Record $Resource Newly-created Resource to test.
    */
    private function checkComments($Resource)
    {
        $this->assertEquals(
            0,
            $Resource->numberOfComments(),
            "Check that newly created resources have no comments."
        );
        $this->assertEquals(
            $Resource->comments(),
            [],
            "Check that newly created resources have empty comment list."
        );

        $TestComment = Message::Create();
        $TestComment->ParentType(Message::PARENTTYPE_RESOURCE);
        $TestComment->ParentId($Resource->Id());

        # reload resource to nuke internal caches
        $Resource = new Record($Resource->Id());

        $this->assertEquals(
            1,
            $Resource->NumberOfComments(),
            "Check that NumberOfComments() is one after adding a single comment."
        );

        $RComments = $Resource->Comments();

        $this->assertTrue(
            is_array($RComments),
            "Check that Comments() returns an array."
        );

        $this->assertEquals(
            1,
            count($RComments),
            "Check that Comments() returns an array of length 1"
        );

        $RComment = array_Shift($RComments);

        $this->assertTrue(
            $RComment instanceof Message,
            "Check that the comment is a Message."
        );

        $this->assertEquals(
            $TestComment->Id(),
            $RComment->Id(),
            "Check that the CommentId of the single Message in the array returned "
             ."by Comments() matches the Id of the test comment "
            ."that we just associated with the resource."
        );

        $TestComment->Destroy();

        # reload resource to nuke internal caches
        $Resource = new Record($Resource->Id());

        $this->assertEquals(
            0,
            $Resource->numberOfComments(),
            "Check that resource has no comments after deleting comment."
        );
        $this->assertEquals(
            $Resource->comments(),
            [],
            "Check that resource has empty comment list after deleting comment."
        );
    }

    /**
    * Check that newly created resources have no initial ratings, but
    * that they can be rated, and that this rating can be changed.
    * @param Record $Resource Newly-created resource to test.
    */
    private function checkRatings($Resource)
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        $this->assertEquals(
            0,
            $Resource->NumberOfRatings(),
            "Check that newly created resources have no ratings."
        );
        $this->assertEquals(
            0,
            $Resource->CumulativeRating(),
            "Check that newly created resources have no cumulative rating."
        );
        $this->assertEquals(
            0,
            $Resource->ScaledCumulativeRating(),
            "Check that newly created resources have no scaled cumulative rating."
        );

        # ratings checks
        $this->assertNull(
            $Resource->Rating(),
            "Check that admin user hasn't rated this resource."
        );
        $this->assertEquals(
            25,
            $Resource->Rating(25),
            "Check that admin user can rate this resource."
        );
        $this->assertEquals(
            25,
            $Resource->Rating(),
            "Check that admin's rating was saved"
        );
        $this->assertEquals(
            1,
            $Resource->NumberOfRatings(),
            "Check that number of ratings is correct."
        );
        $this->assertEquals(
            25,
            $Resource->CumulativeRating(),
            "Check that cumulative rating is correct."
        );
        $this->assertEquals(
            3,
            $Resource->ScaledCumulativeRating(),
            "Check that scaled cumulative rating is correct."
        );
        $this->assertEquals(
            50,
            $Resource->Rating(50),
            "Check that admin can change rating."
        );
        $this->assertEquals(
            1,
            $Resource->NumberOfRatings(),
            "Check that number of ratings is correct."
        );
        $this->assertEquals(
            50,
            $Resource->CumulativeRating(),
            "Check that cumulative rating is correct."
        );
        $this->assertEquals(
            5,
            $Resource->ScaledCumulativeRating(),
            "Check that scaled cumulative rating is correct."
        );

        $User->Logout();
        $this->assertNull(
            $Resource->Rating(),
            "Check that anon user hasn't rated this resource."
        );
        $User->Login(self::$AdminUser->Name(), "", true);
    }

    /**
    * Check permissions functions --
    * UserCan(View|Edit|Author|Modify)(Field)?.Assumes a schema
    * where newly created resources cannot be accessed at all by anon
    * users, but the administrative user in self::$AdminUser (having
    * PRIV_RESOURCEADMIN and PRIV_COLLECTIONADMIN) has full access --
    * this is the case for the Resource Schema in a default CWIS
    * install and on all Scout sites.
    * @param Record $Resource Resource to test.
    */
    private function checkPermissions($Resource)
    {
        $TitleField = $Resource->getSchema()->GetFieldByMappedName("Title");
        foreach (["View", "Edit", "Author", "Modify"] as $Action) {
            $CheckFn = "UserCan".$Action;
            $FieldCheckFn = "UserCan".$Action."Field";

            $this->assertFalse(
                $Resource->$CheckFn(User::GetAnonymousUser()),
                "Check that Anon users cannot ".strtolower($Action)
                ." a new Resource."
            );
            $this->assertFalse(
                $Resource->$FieldCheckFn(User::GetAnonymousUser(), $TitleField),
                "Check that Anon users cannot ".strtolower($Action)
                ." the Title field on a new Resource."
            );

            $this->assertTrue(
                $Resource->UserCanView(self::$AdminUser),
                "Check that admin users can ".strtolower($Action)
                ." a new Resource."
            );
            $this->assertTrue(
                $Resource->$FieldCheckFn(self::$AdminUser, $TitleField),
                "Check that admin users can ".strtolower($Action)
                ." the Title field on a new Resource."
            );
        }

        $this->assertFalse(
            $Resource->UserCanViewMappedField(User::GetAnonymousUser(), "Title"),
            "Check that Anon users cannot view mapped Title on a new Resource."
        );

        $Field = $Resource->getSchema()->GetField("Test Text Field");
        $Field->Enabled(false);


        # do disabled field check on a copy of the resource so that
        # the PermissionCache doesn't cause it to succeed erroneously
        $RCopy = new Record($Resource->Id());
        $this->assertFalse(
            $RCopy->UserCanViewField(self::$AdminUser, $Field),
            "Check that users cannot view disabled fields."
        );
        $Field->Enabled(true);
    }

    /**
    * Check that GetSchemaForRecord() returns correct values.
    * @param Record $Resource Resource to test.
    * @param Record $RefResource Second resource to test.
    */
    private function checkGetSchemaForRecord($Resource, $RefResource)
    {
        $this->assertEquals(
            MetadataSchema::SCHEMAID_DEFAULT,
            Record::getSchemaForRecord($Resource->Id()),
            "Check that getSchemaForRecord() returns correct result."
        );

        try {
            Record::getSchemaForRecord(Database::INT_MAX_VALUE);
            $this->assertFalse(
                true,
                "getSchemaForRecord() did not throw exception on invalid ID."
            );
        } catch (Exception $e) {
            $this->assertTrue(
                $e instanceof InvalidArgumentException,
                "getSchemaForRecord() threw wrong exception type (".get_class($e).")."
                        ."  Location: ".$e->getFile().":".$e->getLine()
                        ."  Msg: ".$e->getMessage()
            );
        }

        $Ids = [$Resource->Id(), $RefResource->Id()];
        $this->assertEquals(
            array_fill_keys($Ids, MetadataSchema::SCHEMAID_DEFAULT),
            Record::getSchemasForRecords($Ids),
            "Check that getSchemasForRecords() provides correct results."
        );

        $Ids = [$Resource->Id(), $RefResource->Id()."!$$%TEST"];
        try {
            Record::getSchemasForRecords($Ids);
            $this->assertFalse(
                true,
                "getSchemasForRecords() did not throw exception on illegal ID."
            );
        } catch (Exception $e) {
            $this->assertTrue(
                $e instanceof InvalidArgumentException,
                "getSchemasForRecords() threw wrong exception type (".get_class($e).")."
                        ."  Location: ".$e->getFile().":".$e->getLine()
                        ."  Msg: ".$e->getMessage()
            );
        }

        $Ids = [$Resource->Id(), $RefResource->Id(), Database::INT_MAX_VALUE];
        try {
            Record::getSchemasForRecords($Ids);
            $this->assertFalse(
                true,
                "getSchemasForRecords() did not throw exception on invalid ID."
            );
        } catch (Exception $e) {
            $this->assertTrue(
                $e instanceof InvalidArgumentException,
                "getSchemasForRecords() threw wrong exception type (".get_class($e).")."
                        ."  Location: ".$e->getFile().":".$e->getLine()
                        ."  Msg: ".$e->getMessage()
            );
        }
    }

    /**
    * Check that GetAsArray() returns correct values.
    * @param Record $Resource Resource to test.
    * @param Record $RefResource Resource to use as a reference field value.
    */
    private function checkGetAsArray($Resource, $RefResource)
    {
        # retrieve user currently logged in
        $User = User::getCurrentUser();

        $Values = [
            "Test Text Field" => "TestValue",
            "Test Url Field" => "http://example.com",
            "Test Reference Field" =>
                [$RefResource->Id() => $RefResource->Id()],
            "Test User Field" =>
                [$User->Id() => $User->Get("UserName")],
            "Test Option Field" =>
                [self::$TestOptionCName->Id() => self::$TestOptionCName->Name()],
            "Test CName Field" =>
                [self::$TestControlledName->Id() => self::$TestControlledName->Name()],
            "Test Tree Field" =>
                [self::$TestClassification->Id()
                        => self::$TestClassification->FullName()
                ],
        ];

        foreach ($Values as $FieldName => $Value) {
            $Resource->Set($FieldName, $Value);
        }

        $Result = $Resource->GetAsArray(false, false);

        # subset to just the fields that we've set
        $Result = array_intersect_key($Result, $Values);

        $this->assertEquals(
            $Values,
            $Result,
            "Checking GetAsArray()"
        );
    }

    /**
    * Check that permanent resources cannot be made temporary.
    * @param Record $Resource Permanent resource for testing.
    */
    private function checkTempToggle($Resource)
    {
        $this->assertFalse(
            $Resource->isTempRecord(),
            "Check that provided resource is permanent."
        );

        try {
            $Resource->isTempRecord(true);
            $this->fail("Should not be able to make perm resources temp.");
        } catch (Exception $ex) {
            ;
        }
    }

    /**
     * Run queued tasks.
     */
    private function runTasks()
    {
        $AF = ApplicationFramework::getInstance();
        do {
            $TaskList = $AF->getQueuedTaskList();
            foreach ($TaskList as $Task) {
                try {
                    if ($Task["Parameters"]) {
                        call_user_func_array($Task["Callback"], $Task["Parameters"]);
                    } else {
                        call_user_func($Task["Callback"]);
                    }
                } catch (Exception $ex) {
                    // do nothing
                }
                $AF->deleteTask($Task["TaskId"]);
            }
        } while (count($TaskList) > 0);
    }

    protected static $TestFieldIds;
    protected static $TestFields;
    protected static $AdminUser;
    protected static $TestClassification;
    protected static $TestControlledName;
    protected static $TestOptionCName;
}
