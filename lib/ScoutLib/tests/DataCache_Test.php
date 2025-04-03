<?PHP
#
#   FILE:  DataCache_Test.php
#
#   Part of the ScoutLib application support library
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace ScoutLib;

class DataCache_Test extends \PHPUnit\Framework\TestCase
{
    # ---- SETUP -------------------------------------------------------------

    # ---- TESTS -------------------------------------------------------------

    /**
     * Test key prefix functionality.
     */
    public function testKeyPrefix()
    {
        $CacheNP = new DataCache();
        $CacheOne = new DataCache("One_");
        $CacheTwo = new DataCache("Two_");

        $CacheNP->set("NoPrefixKey", "NPValue");
        $CacheOne->set("OneKey", "Value1");
        $CacheTwo->set("TwoKey", "Value2");

        $this->assertSame("NPValue", $CacheNP->get("NoPrefixKey"));
        $this->assertSame("Value1", $CacheOne->get("OneKey"));
        $this->assertSame("Value2", $CacheTwo->get("TwoKey"));

        $this->assertSame("DefNP", $CacheNP->get("OneKey", "DefNP"));
        $this->assertSame("Def1", $CacheOne->get("TwoKey", "Def1"));
        $this->assertSame("Def2", $CacheTwo->get("NoPrefixKey", "Def2"));
    }

    /**
     * Test set() and get() methods.
     */
    public function testSetAndGet()
    {
        # test plain set and get
        $TestNumber = 1;
        $Cache = new DataCache(__FUNCTION__.$TestNumber++);
        foreach ($this->TestData as $Key => $Value) {
            $Cache->set($Key, $Value);
        }
        foreach ($this->TestData as $Key => $Value) {
            $RetrievedValue = $Cache->get($Key);
            $this->assertSame($Value, $RetrievedValue);
        }

        # test default value parameter of get()
        $Cache = new DataCache(__FUNCTION__.$TestNumber++);
        foreach ($this->TestData as $Key => $Value) {
            $RetrievedValue = $Cache->get("X2".$Key, $Value);
            $this->assertSame($Value, $RetrievedValue);
        }
    }

    /**
     * Test setMultiple() and getMultiple() methods.
     */
    public function testSetMultipleAndGetMultiple()
    {
        # test multiple both ways
        $TestNumber = 1;
        $Cache = new DataCache(__FUNCTION__.$TestNumber++);
        $Cache->setMultiple($this->TestData);
        $RetrievedData = $Cache->getMultiple(array_keys($this->TestData));
        foreach ($this->TestData as $Key => $Value) {
            $this->assertSame($Value, $RetrievedData[$Key]);
        }

        # test multiple set with singular get
        $Cache = new DataCache(__FUNCTION__.$TestNumber++);
        $Cache->setMultiple($this->TestData);
        foreach ($this->TestData as $Key => $Value) {
            $RetrievedValue = $Cache->get($Key);
            $this->assertSame($Value, $RetrievedValue);
        }

        # test singular set with multiple get
        $Cache = new DataCache(__FUNCTION__.$TestNumber++);
        foreach ($this->TestData as $Key => $Value) {
            $Cache->set($Key, $Value);
        }
        $RetrievedData = $Cache->getMultiple(array_keys($this->TestData));
        foreach ($this->TestData as $Key => $Value) {
            $this->assertSame($Value, $RetrievedData[$Key]);
        }
    }

    /**
     * Test delete() method.
     */
    public function testDelete()
    {
        $DefaultValue = "Default value for delete() test.";
        $TestNumber = 1;
        $Cache = new DataCache(__FUNCTION__.$TestNumber++);
        $Cache->setMultiple($this->TestData);
        foreach ($this->TestData as $Key => $Value) {
            # double check that value was set correctly
            $RetrievedValue = $Cache->get($Key, $DefaultValue);
            $this->assertSame($Value, $RetrievedValue);

            # check that delete is reported as succeeding
            $Status = $Cache->delete($Key);
            $this->assertTrue($Status);

            # check that delete succeeded
            $RetrievedValue = $Cache->get($Key, $DefaultValue);
            $this->assertSame($DefaultValue, $RetrievedValue);
        }
    }


    # ---- PRIVATE -----------------------------------------------------------

    protected $TestData = [
        "TestString" => "This is a test string.",
        "TestInt" => 123456,
        "TestFloat" => 654.321,
        "TestBool" => true,
        "TestArray" => [
            "ElementBool" => true,
            "ElementFloat" => 987.654,
            "ElementInt" => 345,
        ],
    ];
}
