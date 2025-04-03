<?PHP
#
#   FILE:  PrivilegeFactory_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023-2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;
use ScoutLib\Database;

class PrivilegeFactory_Test extends \PHPUnit\Framework\TestCase
{
    /**
     * Prior to running any of the tests, this function is
     * run. It instantiates a privilege factory
     * and adds it to class variable $PrivilegeFactory
     * so each function may use it.
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$PrivilegeFactory = new PrivilegeFactory();
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
     * Verify that getPrivileges() works correctly for
     * getting either or both custom and predefined privileges
     * as strings or privilege objects.
     * covers getPrivileges()
     * @return void
     */
    public function testGetPrivileges(): void
    {
        # Create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);

        # Test Case 1: Get list of all privileges (i.e., predefined and custom) as list of objects
        $Privileges = self::$PrivilegeFactory->getPrivileges(true, true);
        $ExpectedIds = self::PREDEFINED_PRIVILEGE_IDS;
        $ExpectedIds = array_merge($ExpectedIds, $this->getCustomPrivIds());

        # check that we have the correct privileges
        $this->assertEqualsCanonicalizing(
            $ExpectedIds,
            array_keys($Privileges),
            "The ids of the predefined and custom privileges returned by"
                ." getPrivileges() are not as expected."
        );
        # check that we did get a list of privilege objects returned
        $this->assertValuesArePrivilegeInstances($Privileges);

        # Test Case 2: Get list of all privileges (i.e., predefined and custom) as list of ids
        $Privileges = self::$PrivilegeFactory->getPrivileges(true, false);

        # check that we have the correct privileges
        $this->assertEqualsCanonicalizing(
            $ExpectedIds,
            array_keys($Privileges),
            "The ids of the predefined and custom privileges returned by"
                ." getPrivileges() are not as expected."
        );
        # check that we did get a list of privilege names (i.e., strings) returned
        $this->assertValuesAreStrings($Privileges);

        # Test Case 3: Get list of custom privileges as list of objects
        $Privileges = self::$PrivilegeFactory->getPrivileges(false, true);
        $ExpectedIds = $this->getCustomPrivIds();

        # check that we have the correct privileges
        $this->assertEqualsCanonicalizing(
            $ExpectedIds,
            array_keys($Privileges),
            "The ids of the custom privileges returned by getPrivileges() are not as expected."
        );
        # check that we did get a list of privilege objects returned
        $this->assertValuesArePrivilegeInstances($Privileges);

        # Test Case 4: Get list of custom privileges as list of ids
        $Privileges = self::$PrivilegeFactory->getPrivileges(false, false);

        # check that we have the correct privileges
        $this->assertEqualsCanonicalizing(
            $ExpectedIds,
            array_keys($Privileges),
            "The ids of the custom privileges returned by getPrivileges() are not as expected."
        );
        # check that we did get a list of privilege names (i.e., strings) returned
        $this->assertValuesAreStrings($Privileges);

        # Delete the created custom privilege
        $CustomPriv->delete();
    }

    /**
     * Verify that getPrivilegeWithName() works correctly for
     * getting a privilege object using the privilege's name.
     * covers getPrivilegeWithName()
     * @return void
     */
    public function testGetPrivilegeWithName(): void
    {
        # Test Case 1: Get a predefined privilege using its name
        $PredefinedPrivId = 1;
        $ExpectedPriv = new Privilege($PredefinedPrivId);
        $ActualPriv = self::$PrivilegeFactory->getPrivilegeWithName(
            self::ACTIVE_PREDEFINED_PRIVILEGES[$PredefinedPrivId]
        );
        $this->assertValuesArePrivilegeInstances([$ActualPriv]);
        $this->assertSame(
            $ExpectedPriv->name(),
            $ActualPriv->name(),
            "GetPrivilegeWithName() returned a predefined privilege with the wrong name."
        );

        # Test Case 2: Get a custom privilege using its name
        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);

        $ActualPriv = self::$PrivilegeFactory->getPrivilegeWithName(self::TEST_PRIVILEGE_NAME);
        $this->assertValuesArePrivilegeInstances([$ActualPriv]);
        $this->assertSame(
            $CustomPriv->name(),
            $ActualPriv->name(),
            "GetPrivilegeWithName() returned a custom privilege with the wrong name."
        );

        # delete the custom privilege
        $CustomPriv->delete();

        # Test Case 3: Get a privilege using a name that doesn't exist
        # we have already deleted that custom privilege, so its name should no longer exist
        $ActualPriv = self::$PrivilegeFactory->getPrivilegeWithName(self::TEST_PRIVILEGE_NAME);
        $this->assertNull(
            $ActualPriv,
            "GetPrivilegeWithName() returned a privilege with a name that does not exist"
                ." when it should have returned null."
        );
    }

    /**
     * Verify that getPrivilegeWitValue() works correctly for
     * getting a privilege object using the privilege's value.
     * covers getPrivilegeWitValue()
     * @return void
     */
    public function testGetPrivilegeWithValue(): void
    {
        # Test Case 1: Get a predefined privilege using its value
        $PredefinedPrivId = 1;
        $ExpectedPriv = new Privilege($PredefinedPrivId);
        $ActualPriv = self::$PrivilegeFactory->getPrivilegeWithValue($PredefinedPrivId);
        $this->assertValuesArePrivilegeInstances([$ActualPriv]);
        $this->assertSame(
            $ExpectedPriv->id(),
            $ActualPriv->id(),
            "GetPrivilegeWithValue() returned a predefined privilege with the wrong value."
        );

        # Test Case 2: Get a custom privilege using its value
        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);

        $ActualPriv = self::$PrivilegeFactory->getPrivilegeWithValue($CustomPriv->id());
        $this->assertValuesArePrivilegeInstances([$ActualPriv]);
        $this->assertSame(
            $CustomPriv->id(),
            $ActualPriv->id(),
            "GetPrivilegeWithValue() returned a custom privilege with the wrong value."
        );

        # delete the custom privilege
        $CustomPriv->delete();

        # Test Case 3: Get a privilege using a value that doesn't exist
        # we know that a privilege with value -1 doesn't exist because of the way we set the
        # id of new custom privileges. (starts from 100 and keeps incrementing)
        $ActualPriv = self::$PrivilegeFactory->getPrivilegeWithValue(-1);
        $this->assertNull(
            $ActualPriv,
            "GetPrivilegeWithValue() returned a privilege with a value that does not exist"
                ." when it should have returned null."
        );
    }

    /**
     * Verify that getPredefinedPrivilegeConstants() works correctly for
     * getting a list of all predefined privilege constants and their values.
     * covers getPredefinedPrivilegeConstants()
     * @return void
     */
    public function testGetPredefinedPrivilegeConstants(): void
    {
        $this->assertEqualsCanonicalizing(
            self::ACTIVE_PREDEFINED_PRIVILEGES,
            self::$PrivilegeFactory->getPredefinedPrivilegeConstants(),
            "The list of predefined privileges returned by"
                ." getPredefinedPrivilegeConstants() are not as expected."
        );
    }

    /**
     * Verify that getItemNames() works correctly for getting a list
     * of human-readable custom and predefined privilege names.
     * covers getItemNames()
     * @return void
     */
    public function testGetItemNames(): void
    {
        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);

        $this->assertEqualsCanonicalizing(
            self::$PrivilegeFactory->getPrivileges(true, false),
            self::$PrivilegeFactory->getItemNames(),
            "The list of privileges returned by getItemNames() are not as expected."
        );

        # delete the custom privilege
        $CustomPriv->delete();
    }

    /**
     * Verify that privilegeNameExists() works correctly for checking whether
     * a custom or predefined privilege with the given name exists.
     * covers privilegeNameExists()
     * @return void
     */
    public function testPrivilegeNameExists(): void
    {
        # Test Case #1: Check if a predefined privilege with the given name exists
        $this->assertTrue(
            self::$PrivilegeFactory->privilegeNameExists(self::ACTIVE_PREDEFINED_PRIVILEGES[1]),
            "PrivilegeNameExists() returned false for a predefined privilege name "
                ."when it should have returned true."
        );

        # Test Case #2: Check if a predefined privilege with the given description exists
        $PredefinedPrivDesc = self::PREDEFINED_PRIVILEGE_DESCRIPTIONS[1];
        $this->assertTrue(
            self::$PrivilegeFactory->privilegeNameExists($PredefinedPrivDesc),
            "PrivilegeNameExists() returned false for a predefined privilege description "
                ."when it should have returned true."
        );

        # Test Case #3: Check if a custom privilege with the given name exists
        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);
        $this->assertTrue(
            self::$PrivilegeFactory->privilegeNameExists(self::TEST_PRIVILEGE_NAME),
            "PrivilegeNameExists() returned false for an existing custom privilege name "
                ."when it should have returned true."
        );
        # delete the custom privilege
        $CustomPriv->delete();

        # Test Case #4: Check if a privilege with the given name doesn't exist
        # we have already deleted the custom privilege with the
        # given name (i.e., that name should no longer exist)
        $this->assertFalse(
            self::$PrivilegeFactory->privilegeNameExists(self::TEST_PRIVILEGE_NAME),
            "PrivilegeNameExists() returned true for a deleted custom privilege name "
                ."when it should have returned false."
        );
    }

    /**
     * Verify that privilegeValueExists() works correctly for checking whether
     * a custom or predefined privilege with the given value exists.
     * covers privilegeValueExists()
     * @return void
     */
    public function testPrivilegeValueExists(): void
    {
        # Test Case #1: Check if a predefined privilege with the given value exists
        $this->assertTrue(
            self::$PrivilegeFactory->privilegeValueExists(1),
            "PrivilegeValueExists() returned false for a predefined privilege value "
                ."when it should have returned true."
        );

        # Test Case #2: Check if a custom privilege with the given value exists
        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);
        $this->assertTrue(
            self::$PrivilegeFactory->privilegeValueExists($CustomPriv->id()),
            "PrivilegeValueExists() returned false for an existing custom privilege value "
                ."when it should have returned true."
        );
        # delete the custom privilege
        $CustomPriv->delete();

        # Test Case #3: Check if a privilege with the given value doesn't exist
        # we know that a privilege with value -1 doesn't exist because of the way we set the
        # id of new custom privileges. (starts from 100 and keeps incrementing)
        $this->assertFalse(
            self::$PrivilegeFactory->privilegeValueExists(-1),
            "PrivilegeValueExists() returned true for a privilege value that does not exist "
                ."when it should have returned false."
        );
    }

    /**
     * Verify that normalizePrivileges() works correctly for getting
     * the ids of custom or predefined privileges with the given names or ids.
     * covers normalizePrivileges()
     * @return void
     */
    public function testNormalizePrivileges(): void
    {
        # Test Case #1: Get the ids of the predefined privileges with the given full names
        $this->assertEqualsCanonicalizing(
            array_keys(self::ACTIVE_PREDEFINED_PRIVILEGES),
            PrivilegeFactory::normalizePrivileges(array_values(self::ACTIVE_PREDEFINED_PRIVILEGES)),
            "The ids of the predefined privileges with the given full names returned by"
                ." normalizePrivileges() are not as expected."
        );

        # Test Case #2: Get the ids of the predefined privileges with the given names
        # (without the "PRIV_" prefix)
        $PrivNames = [
            1 => "SYSADMIN",
            2 => "NEWSADMIN",
            7 => "RELEASEADMIN",
            8 => "USERADMIN",
            75 => "ISLOGGEDIN"
        ];
        $this->assertEqualsCanonicalizing(
            array_keys($PrivNames),
            PrivilegeFactory::normalizePrivileges(array_values($PrivNames)),
            "The ids of the predefined privileges with the given names (without the 'PRIV_' prefix)"
                ." returned by normalizePrivileges() are not as expected."
        );

        # Test Case #3: Get the ids of the predefined privileges with the given names
        # (without the "PRIV_" prefix and the "ADMIN" suffix)
        $PrivNames = [
            1 => "SYS",
            2 => "NEWS",
            7 => "RELEASE",
            8 => "USER",
            75 => "ISLOGGEDIN"
        ];
        $this->assertEqualsCanonicalizing(
            array_keys($PrivNames),
            PrivilegeFactory::normalizePrivileges(array_values($PrivNames)),
            "The ids of the predefined privileges with the given names (without the 'PRIV_' prefix "
                ."and the 'ADMIN' suffix) returned by normalizePrivileges() are not as expected."
        );

        # Test Case #4: Get the passed in list of ids if they are within the
        # standard privilege ids range (P.S., those ids don't need to be predefined)
        $PrivIds = [
            1,
            2,
            10,
            54,
            74
        ];
        $this->assertEqualsCanonicalizing(
            $PrivIds,
            PrivilegeFactory::normalizePrivileges($PrivIds),
            "NormalizePrivileges() was given a list of valid standard privilege ids "
                ."and did not return that list as is."
        );

        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);

        # Test Case #5: Get the ids of the privileges with the given names
        # (custom privilege names)
        $this->assertFalse(
            PrivilegeFactory::normalizePrivileges([$CustomPriv->name()]),
            "NormalizePrivileges() should have returned false when given a"
                ." custom privilege name, but it did not."
        );

        # Test Case #6: Get the ids of the privileges with the given ids
        # (custom privilege ids)
        $this->assertEqualsCanonicalizing(
            [$CustomPriv->id()],
            PrivilegeFactory::normalizePrivileges([$CustomPriv->id()]),
            "NormalizePrivileges() was given a list of existing custom privilege ids "
                ."and did not return that list as is."
        );

        # delete the custom privilege
        $CustomPriv->delete();
    }

    /**
     * Verify that getPrivilegeOptions() works correctly for getting
     * a list of privileges, excluding pseudo-privileges.
     * covers getPrivilegeOptions()
     * @return void
     */
    public function testGetPrivilegeOptions(): void
    {
        $ActualPrivIds = self::$PrivilegeFactory->getPrivilegeOptions();
        $ExpectedPrivIds = [
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
            9,
            10,
            12,
            13,
        ];
        $this->assertEqualsCanonicalizing(
            $ExpectedPrivIds,
            array_keys($ActualPrivIds),
            "The list of privilege ids returned by getPrivilegeOptions() are not as expected."
        );
    }

    /**
     * Verify that getPrivilegeConstantName() works correctly for getting
     * a privilege constant name for specified value.
     * covers getPrivilegeConstantName()
     * @return void
     */
    public function testGetPrivilegeConstantName(): void
    {
        # Test Case #1: Get the name of a predefined privilege using its id
        $this->assertSame(
            self::ACTIVE_PREDEFINED_PRIVILEGES[1],
            self::$PrivilegeFactory->getPrivilegeConstantName(1),
            "GetPrivilegeConstantName() returned the wrong name for a predefined"
                ." privilege with the given id."
        );

        # Test Case #2: Get the name of a custom privilege using its id
        # create a custom privilege
        $CustomPriv = new Privilege(null, self::TEST_PRIVILEGE_NAME);
        $this->assertFalse(
            self::$PrivilegeFactory->getPrivilegeConstantName($CustomPriv->id()),
            "GetPrivilegeConstantName() should have returned false when given"
                ." a custom privilege id, but it did not."
        );
        # delete the custom privilege
        $CustomPriv->delete();
    }

    /**
     * Assert that the values in an array are instances of the Privilege class.
     * @param array $Array The array to check.
     * @return void
     */
    private function assertValuesArePrivilegeInstances(array $Array): void
    {
        foreach ($Array as $Value) {
            $this->assertInstanceOf(
                Privilege::class,
                $Value,
                "The given value was not a Privilege object when it should have been."
            );
        }
    }

    /**
     * Assert that the values in an array are of type string.
     * @param array $Array The array to check.
     * @return void
     */
    private function assertValuesAreStrings(array $Array): void
    {
        foreach ($Array as $Value) {
            $this->assertIsString(
                $Value,
                "The given value was not of type string when it should have been."
            );
        }
    }

    /**
     * Get the ids of custom privileges directly from the database.
     * @return array The ids of the custom privileges.
     */
    private function getCustomPrivIds(): array
    {
        $DB = new Database();
        $DB->query(
            "SELECT Id FROM CustomPrivileges"
        );
        return $DB->fetchColumn("Id");
    }

    /**
     * A list of all the predefined privileges. (i.e., Active and Deprecated)
     */
    const PREDEFINED_PRIVILEGE_IDS = [
        1,
        2,
        3,
        # 4: deprecated privilege maintained (for now) for backward compatibility
        4,
        5,
        6,
        7,
        8,
        # 9: deprecated privilege maintained (for now) for backward compatibility
        9,
        10,
        11,
        # 12: deprecated privilege maintained (for now) for backward compatibility
        12,
        13,
        75
    ];

    /**
     * A list of all the  active predefined privileges. (i.e., Ignore the deprecated privileges)
     */
    const ACTIVE_PREDEFINED_PRIVILEGES = [
        1 => "PRIV_SYSADMIN",
        2 => "PRIV_NEWSADMIN",
        3 => "PRIV_RESOURCEADMIN",
        5 => "PRIV_CLASSADMIN",
        6 => "PRIV_NAMEADMIN",
        7 => "PRIV_RELEASEADMIN",
        8 => "PRIV_USERADMIN",
        10 => "PRIV_POSTCOMMENTS",
        11 => "PRIV_USERDISABLED",
        13 => "PRIV_COLLECTIONADMIN",
        75 => "PRIV_ISLOGGEDIN"
    ];

    const PREDEFINED_PRIVILEGE_DESCRIPTIONS = [
        1  => "System Administrator",
        2  => "News Administrator",
        3  => "Master Resource Administrator",
        5  => "Classification Administrator",
        6  => "Controlled Name Administrator",
        7  => "Release Flag Administrator",
        8  => "User Account Administrator",
        13 => "Collection Administrator",
        # following are user permissions, not admin privileges
        10 => "Can Post Resource Comments",
        11 => "User Account Disabled",
        # following are pseudo-privileges, not admin privileges
        75 => "Is Logged In",
        # deprecated privileges maintained (for now) for backward compatibility
        4  => "Forum Administrator (Deprecated)",
        9  => "Can Post To Forums (Deprecated)",
        12 => "Personal Resource Administrator (Deprecated)",
    ];
    const TEST_PRIVILEGE_NAME = "XX-TEST-PRIVILEGE";
    private static $PrivilegeFactory;
}
