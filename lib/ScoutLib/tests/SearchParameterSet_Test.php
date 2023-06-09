<?PHP

use ScoutLib\Database;
use ScoutLib\SearchParameterSet;
use ScoutLib\SearchEngine;

class SearchParameterSet_Test extends PHPUnit\Framework\TestCase
{
    /**
     * Save user-provided callbacks that will be changed during the test
     * so that we can restore them later after the test.
     *
     * We cannot use PHPUnit's "backupStaticAttributes" annotation because
     * it uses serialize() and unserialize() to implement this feature.
     * If the current callbacks use closure, this will cause serialize()
     * to throw error. Therefore, we must do the backup manually.
     */
    public static function setUpBeforeClass() : void
    {
        self::$SavedCFieldFn = SearchParameterSet::canonicalFieldFunction();
        self::$SavedPFieldFn = SearchParameterSet::printableFieldFunction();
        self::$SavedPValueFn = SearchParameterSet::printableValueFunction();
    }

    /**
     * Restore the original callbacks we saved at the beginning of the test.
     */
    public static function tearDownAfterClass() : void
    {
        SearchParameterSet::canonicalFieldFunction(self::$SavedCFieldFn);
        SearchParameterSet::printableFieldFunction(self::$SavedPFieldFn);
        SearchParameterSet::printableValueFunction(self::$SavedPValueFn);
    }

    /**
     * Set up environment.
     */
    public function setUp() : void
    {
        SearchParameterSet::canonicalFieldFunction(function ($Field) {
            return $Field;
        });
    }

    /**
     * Test addParameter and removeParameter.
     */
    public function testSearchParameter()
    {
        $TestParam = "test parameter";
        $TestParams = [
            "test parameter 1",
            "test parameter 2",
            "test parameter 3"
        ];
        $GenericGetSearchStrForFieldMsg = "getSearchStringsForField(field_id) should"
                ." return all parameters matching the input field.";
        $GenericGetFieldsMsg = "getFields() should return fields of all"
                ." existing parameters.";

        # ---- NO SPECIFIED FIELD -------------------------------------------------

        # Test initially no parameter
        $MsgHeader = "Test initially no parameter: ";
        $SearchParam = new SearchParameterSet();
        $this->verifySearchStrings($SearchParam, $MsgHeader, 0, [], []);
        $this->assertEquals(
            [],
            $SearchParam->getFields(),
            $MsgHeader."getFields() initially should return an empty array."
        );

        # Test adding a single parameter without specifying field
        $MsgHeader = "Test adding a single parameter"
                ." without specifying field: ";
        $SearchParam->addParameter($TestParam);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 1, [], [$TestParam]);
        $this->assertEquals(
            [],
            $SearchParam->getFields(),
            $MsgHeader."getFields() should return an empty array."
        );

        # Test simultaneously adding multiple parameters without specifying field
        $MsgHeader = "Test simultaneously adding multiple"
                ." parameters without specifying field: ";
        $SearchParam = new SearchParameterSet();
        $SearchParam->addParameter($TestParams);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 3, [], $TestParams);
        $this->assertEquals(
            [],
            $SearchParam->getFields(),
            $MsgHeader."getFields() should return an empty array."
        );

        # Test deleting a single parameter without specifying field
        $MsgHeader = "Test deleting a single parameter"
                ." without specifying field: ";
        $SearchParam->removeParameter($TestParams[0]);
        $RemainingStrs = array_slice($TestParams, 1);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 2, [], $RemainingStrs);

        # Test simultaneously deleting multiple parameters without specifying field
        $MsgHeader = "Test simultaneously deleting multiple"
                ." parameters without specifying field: ";
        $SearchParam->removeParameter($RemainingStrs);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 0, [], []);

        # Test deleting all parameters without specifying field
        $MsgHeader = "Test deleting all parameters without specifying field: ";
        $SearchParam->addParameter($TestParams);
        $SearchParam->removeParameter(null);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 0, [], []);

        # ---- HAS SPECIFIED FIELD ------------------------------------------------

        $MockFieldId = Database::INT_MAX_VALUE - 1000;

        # Test adding a single parameter with a field
        $MsgHeader = "Test adding a single parameter with a field: ";
        $ExpectedValue = [$MockFieldId => [$TestParam]];
        $SearchParam = new SearchParameterSet();
        $SearchParam->addParameter($TestParam, $MockFieldId);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 1, $ExpectedValue, []);
        $this->assertEquals(
            [$TestParam],
            $SearchParam->getSearchStringsForField($MockFieldId),
            $MsgHeader."getSearchStringsForField(field_id) should return an array"
            ." with only that one parameter."
        );
        $this->assertEquals(
            [$MockFieldId],
            $SearchParam->getFields(),
            $MsgHeader."getFields() should return an array with only the field of"
            ." that parameter."
        );

        # Test simultaneously adding multiple parameters with a single field
        $MsgHeader = "Test simultaneously adding multiple"
                ." parameters with a single field: ";
        $ExpectedValue = [$MockFieldId => $TestParams];
        $SearchParam = new SearchParameterSet();
        $SearchParam->addParameter($TestParams, $MockFieldId);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 3, $ExpectedValue, []);
        $this->assertEquals(
            $TestParams,
            $SearchParam->getSearchStringsForField($MockFieldId),
            $MsgHeader."getSearchStringsForField(field_id) should return an array"
            ." with all parameters just added."
        );
        $this->assertEquals(
            [$MockFieldId],
            $SearchParam->getFields(),
            $MsgHeader."getFields() should return an array with only that one field"
            ." of those parameters."
        );

        # Test not deleting a parameter when no field matches the specified field
        $MsgHeader = "Test not deleting a parameter when"
                ." no field matches the specified field: ";
        $SearchParam->removeParameter($TestParams[0], 666);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 3, $ExpectedValue, []);
        $this->assertEquals(
            $TestParams,
            $SearchParam->getSearchStringsForField($MockFieldId),
            $MsgHeader."getSearchStringsForField(field_id) should still return"
            ." the same array of parameters added earlier."
        );
        $this->assertEquals(
            [$MockFieldId],
            $SearchParam->getFields(),
            $MsgHeader."getFields() should return fields of the same array"
            ." of parameters added earlier."
        );

        # Test deleting multiple parameters when there is a field matching the specified field
        $MsgHeader = "Test deleting multiple parameters"
                ." when there is a field matching the specified field: ";
        $SearchParam = new SearchParameterSet();
        $TestStr1 = ["t1", "t2", "t3"];
        $TestStr2 = ["m1", "m2", "m3"];
        $ExpectedValue = [
            100 => ["t1"],
            200 => ["m1", "m2", "m3"]
        ];

        $SearchParam->addParameter($TestStr1, 100);
        $SearchParam->addParameter($TestStr2, 200);
        $SearchParam->removeParameter(["t2", "t3"], 100);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 4, $ExpectedValue, []);
        $this->assertEquals(
            ["t1"],
            $SearchParam->getSearchStringsForField(100),
            $MsgHeader.$GenericGetSearchStrForFieldMsg
        );
        $this->assertEquals(
            ["m1", "m2", "m3"],
            $SearchParam->getSearchStringsForField(200),
            $MsgHeader.$GenericGetSearchStrForFieldMsg
        );
        $this->assertEquals(
            [100, 200],
            $SearchParam->getFields(),
            $MsgHeader.$GenericGetFieldsMsg
        );

        # Test "delete all" not deleting parameters with fields different from the one specified
        $MsgHeader = "Test 'delete all' not deleting"
                . " parameters with fields different from the one specified: ";
        $ExpectedValue = [100 => ["t1"]];
        $SearchParam->removeParameter(null, 200);
        $this->verifySearchStrings($SearchParam, $MsgHeader, 1, $ExpectedValue, []);
        $this->assertEquals(
            ["t1"],
            $SearchParam->getSearchStringsForField(100),
            $MsgHeader.$GenericGetSearchStrForFieldMsg
        );
        $this->assertEquals(
            [],
            $SearchParam->getSearchStringsForField(200),
            $MsgHeader.$GenericGetSearchStrForFieldMsg
        );
        $this->assertEquals(
            [100],
            $SearchParam->getFields(),
            $MsgHeader.$GenericGetFieldsMsg
        );
    }

    /**
     * Test user provided CanonicalFieldFunction is correctly used to parse
     * field object to an integer ID.
     */
    public function testCanonicalFieldFunction()
    {
        $MsgHeader = "Test user provided CanonicalFieldFunction"
                ." is correctly used to parse field object to an integer ID: ";
        $FieldToId = [
            "apple" => Database::INT_MAX_VALUE - 1000,
            "banana" => Database::INT_MAX_VALUE - 100,
            "potato" => Database::INT_MAX_VALUE - 10
        ];

        // the custom callback should accept a mixed type object
        SearchParameterSet::canonicalFieldFunction(function ($Field) use ($FieldToId) {
            return $FieldToId[$Field];
        });
        $MockParameterStr = ["t1", "t2"];
        $SearchParam = new SearchParameterSet();
        $SearchParam->addParameter($MockParameterStr, "apple");
        $ExpectedId = $FieldToId["apple"];
        $this->verifySearchStrings($SearchParam, $MsgHeader, 2, [$ExpectedId => $MockParameterStr], []);
        $this->assertEquals(
            $MockParameterStr,
            $SearchParam->getSearchStringsForField("apple"),
            $MsgHeader."getSearchStringsForField(pre-normalized-id)"
            ." should return an array with all parameters."
        );
    }

    /**
     * Test adding subgroup (sub-SearchParameterSet) to a SearchParameterSet.
     * @depends testSearchParameter
     */
    public function testSubgroup()
    {
        # Test there is initially no subgroup
        $SearchParam = new SearchParameterSet();
        $this->assertEmpty(
            $SearchParam->getSubgroups(),
            "Test getSubgroups() initially returns an empty array."
        );

        # Test adding an empty subgroup (contains no parameter)
        $MockSubgroup = new SearchParameterSet();
        $SearchParam->addSet($MockSubgroup);
        $this->assertEquals(
            [$MockSubgroup],
            $SearchParam->getSubgroups(),
            "Test getSubgroups() returns an array containing the subgroup"
            ." just added, which has no parameter in it."
        );

        # Test adding a non-empty subgroup (contains parameter)
        $SearchParam = new SearchParameterSet();
        $MockSubgroup = new SearchParameterSet();
        $MockSubgroup->addParameter("test param");
        $SearchParam->addSet($MockSubgroup);
        $this->assertEquals(
            [$MockSubgroup],
            $SearchParam->getSubgroups(),
            "Test getSubgroups() returns an array containing the subgroup"
            ." just added, which has one parameter in it."
        );

        # Test subgroup's contained search parameters are accessible by the parent
        $MsgHeader = "Test subgroup's contained search"
                ." parameters are accessible by the parent: ";
        $SearchParam = new SearchParameterSet();
        $MockSubgroup = new SearchParameterSet();
        $MockSearchStrs = ["t1", "t2", "t3"];
        $MockFieldId = Database::INT_MAX_VALUE - 1000;
        $ExpectedSearchStrs = [$MockFieldId => $MockSearchStrs];

        $MockSubgroup->addParameter($MockSearchStrs, $MockFieldId);
        $SearchParam->addSet($MockSubgroup);
        $this->assertSame(
            3,
            $SearchParam->parameterCount(),
            $MsgHeader."parameterCount() should return a correct count that"
            ." takes into account parameters in the subgroup."
        );
        $this->assertEquals(
            [],
            $SearchParam->getSearchStrings(false),
            $MsgHeader."getSearchStrings(include_subgroup == false) should"
            ." return correct parameters only in the parent."
        );
        $this->assertEquals(
            $ExpectedSearchStrs,
            $SearchParam->getSearchStrings(true),
            $MsgHeader."getSearchStrings(include_subgroup == true) should"
            ." also return parameters stored in the subgroups."
        );
        $this->assertEquals(
            [],
            $SearchParam->getSearchStringsForField($MockFieldId, false),
            $MsgHeader."getSearchStringsForField(include_subgroup == false) should return"
            ." correct parameters for the specified field only in the parent."
        );
        $this->assertEquals(
            $MockSearchStrs,
            $SearchParam->getSearchStringsForField($MockFieldId, true),
            $MsgHeader."getSearchStringsForField(include_subgroup == true) should also"
            ." return correct parameters for the specified field in the subgroups."
        );
        $this->assertEquals(
            [$MockFieldId],
            $SearchParam->getFields(),
            $MsgHeader."getFields() should also return fields in the subgroups."
        );

        # Test subgroup's contained keyword search parameters are not accessible by the parent
        $MsgHeader = "Test subgroup's contained keyword"
                ." search parameters are not accessible by the parent: ";
        $SearchParam = new SearchParameterSet();
        $MockSubgroup->addParameter("this is a keyword search str");
        $SearchParam->addSet($MockSubgroup);
        $this->assertSame(
            4,
            $SearchParam->parameterCount(),
            $MsgHeader."parameterCount() should return a correct count that takes"
            ." into account parameters in the subgroup."
        );
        $this->assertEquals(
            [],
            $SearchParam->getKeywordSearchStrings(),
            $MsgHeader."getKeywordSearchStrings() should return only the"
            ." parent's keyword search strings."
        );
        $this->assertEquals(
            [$MockFieldId],
            $SearchParam->getFields(),
            $MsgHeader."getFields() should also return fields in the subgroups."
        );
    }

    /**
     * Test sortBy() and sortDescending().
     */
    public function testSorting()
    {
        # Test the default is sort by relevance score in descending order
        $MsgHeader = "Test the default sorting setting"
                ." is sort by relevance score in descending order: ";
        $SearchParam = new SearchParameterSet();
        $this->assertSame(
            false,
            $SearchParam->sortBy(),
            $MsgHeader."The default value of 'sortBy' should be false (relevance_score)."
        );
        $this->assertSame(
            true,
            $SearchParam->sortDescending(),
            $MsgHeader."The default value of 'sortDescending' should be true."
        );

        # Test setting a single value
        $MsgHeader = "Test setting a single value on 'sortBy' and 'sortDescending': ";
        $SearchParam->sortBy("test field name");
        $SearchParam->sortDescending(false);
        $this->assertSame(
            "test field name",
            $SearchParam->sortBy(),
            $MsgHeader."sortBy() should be able to set a single value on 'sortBy'."
        );
        $this->assertSame(
            false,
            $SearchParam->sortDescending(),
            $MsgHeader."sortDescending() should be able to set a single"
            ." value on 'sortDescending'."
        );

        # Test setting an array of values
        $MsgHeader = "Test setting an array of values on 'sortBy' and 'sortDescending': ";
        $MockSortBy = [6666 => "test field name"];
        $MockSortDescending = [6666 => true];
        $SearchParam->sortBy($MockSortBy);
        $SearchParam->sortDescending($MockSortDescending);
        $this->assertSame(
            $MockSortBy,
            $SearchParam->sortBy(),
            $MsgHeader."sortBy() should be able to set an array of values on 'sortBy'."
        );
        $this->assertSame(
            $MockSortDescending,
            $SearchParam->sortDescending(),
            $MsgHeader."sortDescending() should be able to set an array of values"
            ." on 'sortDescending'."
        );
    }

    /**
     * Test itemTypes().
     */
    public function testItemTypes()
    {
        # Test the default is no restriction
        $SearchParam = new SearchParameterSet();
        $this->assertSame(
            false,
            $SearchParam->itemTypes(),
            "Test initially there is no item type restriction (itemTypes == false)."
        );

        # Test setting an array of item types
        $MockItemTypeArray = [66666];
        $SearchParam->itemTypes($MockItemTypeArray);
        $this->assertEquals(
            $MockItemTypeArray,
            $SearchParam->itemTypes(),
            "Test setting an array of item types."
        );

        # Test setting a single item type restriction (should still return array)
        $SearchParam->itemTypes(88888);
        $this->assertEquals(
            [ 88888 ],
            $SearchParam->itemTypes(),
            "Test setting a single item type restriction."
        );

        # Test setting no restriction
        $SearchParam->itemTypes(false);
        $this->assertSame(
            false,
            $SearchParam->itemTypes(),
            "Test setting no restriction (set to false)."
        );
    }

    /**
     * Test logic().
     */
    public function testLogic()
    {
        # Test setting using string
        $SearchParam = new SearchParameterSet();
        $SearchParam->logic("OR");
        $this->assertSame("OR", $SearchParam->logic(), "Test setting logic to 'OR'.");
        $SearchParam->logic("AND");
        $this->assertSame("AND", $SearchParam->logic(), "Test setting logic to 'AND'.");

        # Test setting using SearchEngine logic constant
        $SearchParam = new SearchParameterSet();
        $SearchParam->logic(SearchEngine::LOGIC_OR);
        $this->assertSame(
            "OR",
            $SearchParam->logic(),
            "Test setting logic to SearchEngine::LOGIC_OR."
        );
        $SearchParam->logic(SearchEngine::LOGIC_AND);
        $this->assertSame(
            "AND",
            $SearchParam->logic(),
            "Test setting logic to SearchEngine::LOGIC_AND."
        );

        # Test getting InvalidArgumentException on invalid argument
        $this->expectException(InvalidArgumentException::class);
        $SearchParam->logic("Bad Argument");
    }

    /**
     * Test data() and __construct().
     * @depends testSubgroup
     * @depends testLogic
     */
    public function testSerialize()
    {
        $DataMsg = "data() should be able to restore a SearchParameterSet"
                ." serialized via data().";
        $ConstructorMsg = "Constructor should be able to restore a SearchParameterSet"
                ." serialized via data().";

        # Test serializing and unserializing an empty SearchParameterSet
        $MsgHeader = "Test serializing and unserializing an empty SearchParameterSet: ";
        $SearchParam = new SearchParameterSet();
        $NewSearchParam = new SearchParameterSet();
        $NewSearchParam->data($SearchParam->data());
        $this->assertEquals($SearchParam, $NewSearchParam, $MsgHeader.$DataMsg);

        $NewSearchParam = new SearchParameterSet($SearchParam->data());
        $this->assertEquals($SearchParam, $NewSearchParam, $MsgHeader.$ConstructorMsg);

        # Test serializing and unserializing a SearchParameterSet with non-default setting
        $MsgHeader = "Test serializing and unserializing"
                ." a SearchParameterSet with non-default setting: ";
        $SearchParam = new SearchParameterSet();
        $SearchParam->addParameter(["k1", "k2", "k3"]);
        $SearchParam->addParameter(["s1", "s2", "s3"], 6666);
        $SearchParam->logic("OR");
        $SearchParam->itemTypes([1,2]);

        $Subgroup = new SearchParameterSet();
        $Subgroup->addParameter(["sub-k1", "sub-k2"]);
        $Subgroup->addParameter(["sub-s1", "sub-s2"], 7777);
        $Subgroup->logic("OR");
        $SearchParam->addSet($Subgroup);

        $NewSearchParam = new SearchParameterSet();
        $NewSearchParam->data($SearchParam->data());
        $this->assertEquals($SearchParam, $NewSearchParam, $MsgHeader.$DataMsg);

        $NewSearchParam = new SearchParameterSet($SearchParam->data());
        $this->assertEquals($SearchParam, $NewSearchParam, $MsgHeader.$ConstructorMsg);

        # Test unserialzing an invalid data string
        $InvalidData = serialize("hhakjsfasdf");
        $this->expectException(InvalidArgumentException::class);
        $SearchParam = new SearchParameterSet();
        $SearchParam->data($InvalidData);

        $this->expectException(InvalidArgumentException::class);
        new SearchParameterSet($InvalidData);
    }

    /**
     * Test urlParameters() and urlParameterString().
     * @depends testSubgroup
     * @depends testLogic
     */
    public function testUrlParameter()
    {
        $SearchParam = new SearchParameterSet();
        $SearchParam->addParameter(["k1", "k2", "k3"]);
        $SearchParam->addParameter(["s1", "s2", "s3"], 6666);
        $SearchParam->logic("OR");
        $SearchParam->itemTypes([3,6,9]);

        $Subgroup = new SearchParameterSet();
        $Subgroup->addParameter(["sub-k1", "sub-k2"]);
        $Subgroup->addParameter(["sub-s1", "sub-s2"], 7777);
        $Subgroup->logic("OR");

        $SearchParam->addSet($Subgroup);
        $UrlParamArray = $SearchParam->urlParameters();
        $UrlParamStr = $SearchParam->urlParameterString();

        # Test urlParameters() returns an array that can properly restore
        #       the original SearchParameterSet
        $NewSearchParam = new SearchParameterSet();
        $NewSearchParam->urlParameters($UrlParamArray);
        $this->assertEquals(
            $SearchParam,
            $NewSearchParam,
            "Test urlParameters() returns an array that can be used to restore"
            ." the original SearchParameterSet by urlParameters()."
        );

        $NewSearchParam = new SearchParameterSet();
        $NewSearchParam->urlParameterString($UrlParamArray);
        $this->assertEquals(
            $SearchParam,
            $NewSearchParam,
            "Test urlParameters() returns an array that can be used to restore"
            ." the original SearchParameterSet by urlParameterString()."
        );

        # Test urlParameterString() returns a string that can properly
        #       restore the original SearchParameterSet
        $NewSearchParam = new SearchParameterSet();
        $NewSearchParam->urlParameters($UrlParamStr);
        $this->assertEquals(
            $SearchParam,
            $NewSearchParam,
            "Test urlParameterString() returns a string that can be used to restore"
            ." the original SearchParameterSet by urlParameters()."
        );

        $NewSearchParam = new SearchParameterSet();
        $NewSearchParam->urlParameterString($UrlParamStr);
        $this->assertEquals(
            $SearchParam,
            $NewSearchParam,
            "Test urlParameterString() returns a string that can be used to restore"
            ." the original SearchParameterSet by urlParameterString()."
        );
    }

    /**
     * Checks whether the target SearchParameterSet contains the correct SearchStrings
     * and KeywordSearchStrings.
     * @param SearchParameterSet $SearchParam Target SearchParameterSet to check against.
     * @param string $MsgHeader Error message header.
     * @param int $ParamCount Search string count to check agaisnt parameterCount().
     * @param array $SearchStrs Search string array to check against getSearchStrings().
     * @param array $KeywordSearchStrs Array of keyword search strings to check
     *      against getKeywordSearchStrings().
     */
    private function verifySearchStrings(
        SearchParameterSet $SearchParam,
        string $MsgHeader,
        int $ParamCount,
        array $SearchStrs,
        array $KeywordSearchStrs
    ) {
        $this->assertSame(
            $ParamCount,
            $SearchParam->parameterCount(),
            $MsgHeader."parameterCount() should return correct number of search"
            ." parameters (including subgroups)."
        );
        $this->assertEquals(
            $SearchStrs,
            $SearchParam->getSearchStrings(),
            $MsgHeader."getSearchStrings(IncludeSubgroups == default_value)"
            ." should return an array with correct search strings."
        );
        $this->assertEquals(
            $KeywordSearchStrs,
            $SearchParam->getKeywordSearchStrings(),
            $MsgHeader."getKeywordSearchStrings() should return an array with"
            ." correct keyword search strings."
        );
    }

    /**
     * Save the original user provided callbacks before the tests are run.
     */
    private static $SavedCFieldFn;
    private static $SavedPFieldFn;
    private static $SavedPValueFn;
}
