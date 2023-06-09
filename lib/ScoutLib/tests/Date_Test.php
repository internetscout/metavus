<?PHP

use ScoutLib\Date;

class Date_Test extends PHPUnit\Framework\TestCase
{
    /**
    * Test date input formats we are supposed to handle, to make sure they
    * result in the right precision and date output values.
    */
    public function testInputFormats()
    {
        $FormatsToTest = [
                [
                    "Inputs" => [
                        "1999-9-19",
                        "9-19-1999",
                        "19-9-1999",
                        "Sep 19 1999",
                        "Sep 19, 1999",
                        "Sep 19th, 1999",
                        "19990919",
                        "19-Sep-1999",
                        "19 Sep 1999",
                        ],
                    "Precision" => Date::PRE_BEGINYEAR
                            | Date::PRE_BEGINMONTH
                            | Date::PRE_BEGINDAY,
                    "Formatted" => "1999-09-19",
                    "BeginDate" => "1999-09-19",
                    "EndDate" => null,
                ],
                [
                    "Inputs" => [
                        ["1999-9-19", "0000-00-00"],
                        ["9-19-1999", "0000-00-00"],
                        ["19-9-1999", "0000-00-00"],
                        ["Sep 19 1999", "0000-00-00"],
                        ["Sep 19, 1999", "0000-00-00"],
                        ["Sep 19th, 1999", "0000-00-00"],
                        ["19990919", "0000-00-00"],
                        ["19-Sep-1999", "0000-00-00"],
                        ["19 Sep 1999", "0000-00-00"],
                        ],
                    "Precision" => Date::PRE_BEGINYEAR
                            | Date::PRE_BEGINMONTH
                            | Date::PRE_BEGINDAY,
                    "Formatted" => "1999-09-19",
                    "BeginDate" => "1999-09-19",
                    "EndDate" => null,
                ],
                [
                    "Inputs" => [
                        ["2010-9-19", "0000-00-00"],
                        ["9-19-2010", "0000-00-00"],
                        ["19-9-2010", "0000-00-00"],
                        ["Sep 19 2010", "0000-00-00"],
                        ["Sep 19, 2010", "0000-00-00"],
                        ["Sep 19th, 2010", "0000-00-00"],
                        ["20100919", "0000-00-00"],
                        ["19-Sep-2010", "0000-00-00"],
                        ["19 Sep 2010", "0000-00-00"],
                        ],
                    "Precision" => Date::PRE_BEGINYEAR
                            | Date::PRE_BEGINMONTH
                            | Date::PRE_BEGINDAY,
                    "Formatted" => "2010-09-19",
                    "BeginDate" => "2010-09-19",
                    "EndDate" => null,
                ],
                [
                    "Inputs" => [
                        "9/19/01",
                        "9-19-01",
                        ],
                    "Precision" => Date::PRE_BEGINYEAR
                            | Date::PRE_BEGINMONTH
                            | Date::PRE_BEGINDAY,
                    "Formatted" => "2001-09-19",
                    "BeginDate" => "2001-09-19",
                    "EndDate" => null,
                ],
                [
                    "Inputs" => [
                        "1999-9",
                        "Sep-1999",
                        "Sep 1999",
                        "199909",
                        ],
                    "Precision" => Date::PRE_BEGINYEAR
                            | Date::PRE_BEGINMONTH,
                    "Formatted" => "1999-09",
                    "BeginDate" => "1999-09-01",
                    "EndDate" => null,
                ],
                /**
                (Date should support this input format, but currently (2018-01-29)
                        does not)
                [
                    "Inputs" => [
                        "1996-1999",
                        ],
                    "Precision" => Date::PRE_BEGINYEAR
                            | Date::PRE_ENDYEAR,
                    "Formatted" => "1996-1999",
                    "BeginDate" => "1996-1-01",
                    "EndDate" => "1999-01-01",
                ],
                */
                [
                    "Inputs" => [
                        "c1999",
                        ],
                    "Precision" => Date::PRE_BEGINYEAR
                            | Date::PRE_COPYRIGHT,
                    "Formatted" => "c1999",
                    "BeginDate" => "1999-01-01",
                    "EndDate" => null,
                ],
                ];

        foreach ($FormatsToTest as $Format) {
            foreach ($Format["Inputs"] as $InputArgs) {
                if (!is_array($InputArgs)) {
                    $InputArgs = [ $InputArgs ];
                }
                $TestDate = (new ReflectionClass("ScoutLib\Date"))->newInstanceArgs($InputArgs);
                $Input = implode(", ", $InputArgs);

                $this->assertInstanceOf(
                    Date::class,
                    $TestDate,
                    "Input: ".$Input
                );
                $this->assertEquals(
                    $Format["BeginDate"],
                    $TestDate->BeginDate(),
                    "Testing BeginDate() with input \"".$Input."\""
                );
                $this->assertEquals(
                    $Format["EndDate"],
                    $TestDate->EndDate(),
                    "Testing EndDate() with input \"".$Input."\""
                );
                $this->assertEquals(
                    $Format["Precision"],
                    $TestDate->Precision(),
                    "Testing Precision() with input \"".$Input."\""
                );
                $this->assertEquals(
                    $Format["Formatted"],
                    $TestDate->Formatted(),
                    "Testing Formatted() with input \"".$Input."\""
                );
            }
        }
    }
}
