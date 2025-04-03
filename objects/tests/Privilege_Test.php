<?PHP
#
#   FILE:  Privilege_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;
use ScoutLib\Database;

class Privilege_Test extends \PHPUnit\Framework\TestCase
{
    /**
     * Prior to running any of the tests, this function is
     * run. It instantiates a privilege factory
     * and gets a predefined privilege.
     * It adds them to class variables $PrivilegeFactory
     * and $PredefinedPrivilege respectively
     * so each function may use them.
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$PrivilegeFactory = new PrivilegeFactory();
        self::$PredefinedPrivilege = new Privilege(PRIV_USERDISABLED);
    }

    /**
     * After to running the tests, this function is run.
     * It cleans up the custom privileges database to
     * ensure any created test privileges gets deleted.
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        $DB = new Database();
        $Query = "DELETE FROM CustomPrivileges "
            ."WHERE Name LIKE '".addslashes(self::TEST_PRIVILEGE_NAME)."%'";
        $DB->query($Query);
    }

    /**
     * Verify that custom privileges can be created.
     * @return void
     */
    public function testAddCustomPriv(): void
    {
        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);
        $this->assertInstanceOf(
            Privilege::class,
            $CustomPriv,
            "Failed to create a custom privilege."
        );
        $this->assertSame(
            self::TEST_PRIVILEGE_NAME,
            $CustomPriv->name(),
            "The newly created custom privilege does not have the correct name."
        );
        # delete the created custom privilege
        $CustomPriv->delete();
    }

    /**
     * Verify that delete() works correctly for both
     * custom and predefined privileges.
     * covers delete()
     * @return void
     */
    public function testDelete(): void
    {
        # Test Case 1: Delete a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);
        $CustomPrivId = $CustomPriv->id();
        $CustomPriv->delete();
        # should successfully delete the custom privilege
        $this->assertNull(
            self::$PrivilegeFactory->getPrivilegeWithValue($CustomPrivId),
            "Failed to delete a custom privilege."
        );

        # Test Case 2: Delete a predefined privilege
        self::$PredefinedPrivilege->delete();
        # should not delete the predefined privilege
        $this->assertNotNull(
            self::$PrivilegeFactory->getPrivilegeWithValue(self::$PredefinedPrivilege->id()),
            "Managed to delete a predefined privilege when we should not be able to."
        );
    }

    /**
     * Verify that name() works correctly for both
     * custom and predefined privileges.
     * covers name()
     * @return void
     */
    public function testName(): void
    {
        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);

        # Test Case 1: Get the name of a custom privilege
        $this->assertSame(
            self::TEST_PRIVILEGE_NAME,
            $CustomPriv->name(),
            "Name() returned the wrong custom privilege name."
        );

        # Test Case 2: Get the name of a predefined privilege
        $this->assertSame(
            self::PREDEFINED_PRIVILEGE_NAME,
            self::$PredefinedPrivilege->name(),
            "Name() returned the wrong predefined privilege name."
        );

        # Test Case 3: Set the name of a custom privilege
        $CustomPriv->name(self::TEST_PRIVILEGE_NAME_2);
        # should successfully change the custom privilege name
        $this->assertSame(
            self::TEST_PRIVILEGE_NAME_2,
            $CustomPriv->name(),
            "Name() failed to update the name of a custom privilege."
        );

        # Test Case 4: Set the name of a predefined privilege
        self::$PredefinedPrivilege->name(self::TEST_PRIVILEGE_NAME);
        # should not change the name of the predefined privilege
        $this->assertSame(
            self::PREDEFINED_PRIVILEGE_NAME,
            self::$PredefinedPrivilege->name(),
            "Name() managed to update the name of a predefined "
                ."privilege when it should not be able to."
        );

        # delete the custom privilege
        $CustomPriv->delete();
    }

    /**
     * Verify that id() and isPredefined() works correctly for both
     * custom and predefined privileges.
     * covers id() and isPredefined()
     * @return void
     */
    public function testMiscRemaining(): void
    {
        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);

        # Test Case 1: Get the id of a custom privilege
        $this->assertSame(
            $this->getPrivId($CustomPriv->name()),
            $CustomPriv->id(),
            "Id() returned the wrong custom privilege id."
        );

        # Test Case 2: Get the id of a predefined privilege
        $this->assertSame(
            self::PREDEFINED_PRIVILEGE_ID,
            self::$PredefinedPrivilege->id(),
            "Id() returned the wrong predefined privilege id."
        );

        # Test Case 3: Check if isPredefined will correctly
        # return that the custom privilege is not predefined.
        $this->assertFalse(
            $CustomPriv->isPredefined(),
            "IsPredefined() mistakenly identified a custom privilege as predefined."
        );

        # Test Case 4: Check if isPredefined will correctly
        # return that predefined privilege is indeed predefined.
        $this->assertTrue(
            self::$PredefinedPrivilege->isPredefined(),
            "IsPredefined() failed to identify a predefined privilege."
        );

        # delete the custom privilege
        $CustomPriv->delete();
    }

    /**
     * Get the id of a custom privilege directly from the database using its name.
     * @return int The id of the custom privilege if found. Otherwise, returns 0.
     */
    private function getPrivId($Name): int
    {
        $DB = new Database();
        return (int)$DB->queryValue(
            "SELECT Id FROM CustomPrivileges WHERE Name='".addslashes($Name)."'",
            "Id"
        );
    }

    const PREDEFINED_PRIVILEGE_NAME = "User Account Disabled";
    const PREDEFINED_PRIVILEGE_ID = 11;
    const TEST_PRIVILEGE_NAME = "XX-TEST-PRIVILEGE";
    const TEST_PRIVILEGE_NAME_2 = "XX-TEST-PRIVILEGE-2";
    private static $PrivilegeFactory;
    private static $PredefinedPrivilege;
}
