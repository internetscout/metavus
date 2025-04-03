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
                    ["1999-9-19", "00-00"],
                    ["9-19-1999", "00-00"],
                    ["19-9-1999", "00-00"],
                    ["Sep 19 1999", "00-00"],
                    ["Sep 19, 1999", "00-00"],
                    ["Sep 19th, 1999", "00-00"],
                    ["19990919", "00-00"],
                    ["19-Sep-1999", "00-00"],
                    ["19 Sep 1999", "00-00"],
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
                    ["2010-9-19", "00-00"],
                    ["9-19-2010", "00-00"],
                    ["19-9-2010", "00-00"],
                    ["Sep 19 2010", "00-00"],
                    ["Sep 19, 2010", "00-00"],
                    ["Sep 19th, 2010", "00-00"],
                    ["20100919", "00-00"],
                    ["19-Sep-2010", "00-00"],
                    ["19 Sep 2010", "00-00"],
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
            # includes copyright
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
            # is inferred
            [
                "Inputs" => [
                    "[1999]",
                ],
                "Precision" => Date::PRE_BEGINYEAR
                        | Date::PRE_INFERRED,
                "Formatted" => "[1999]",
                "BeginDate" => "1999-01-01",
                "EndDate" => null,
            ],
            # is continuous
            [
                "Inputs" => [
                    "1999-",
                ],
                "Precision" => Date::PRE_BEGINYEAR
                        | Date::PRE_CONTINUOUS,
                "Formatted" => "1999-",
                "BeginDate" => "1999-01-01",
                "EndDate" => null,
            ],
            # is out of bounds
            [
                "Inputs" => [
                    "1999-09-32",
                    "1999-13-19",
                    ["1999-09-19", "1999-09-32"],
                    ["1999-09-19", "1999-13-31"],
                    # day is out of bounds because of month or year
                    "1999-02-29",
                    "2000-02-30",
                    ["1999-09-19", "1999-02-29"],
                    ["1999-09-19", "2000-02-30"],
                ],
                "Exception" => InvalidArgumentException::class
            ],
            # has no day, month, and year
            [
                "Inputs" => [
                    "19"
                ],
                "Exception" => InvalidArgumentException::class
            ],
            # end year is before begin year
            [
                "Inputs" => [
                    ["2000-09-19", "1999-09-19"],
                ],
                "Precision" => Date::PRE_BEGINYEAR
                        | Date::PRE_BEGINMONTH
                        | Date::PRE_BEGINDAY
                        | Date::PRE_ENDYEAR
                        | Date::PRE_ENDMONTH
                        | Date::PRE_ENDDAY,

                "Formatted" => "1999-09-19 - 2000-09-19",
                "BeginDate" => "1999-09-19",
                "EndDate" => "2000-09-19",
            ],
            # end month is before begin month
            [
                "Inputs" => [
                    ["1999-10-19", "1999-09-19"],
                ],
                "Precision" => Date::PRE_BEGINYEAR
                        | Date::PRE_BEGINMONTH
                        | Date::PRE_BEGINDAY
                        | Date::PRE_ENDYEAR
                        | Date::PRE_ENDMONTH
                        | Date::PRE_ENDDAY,

                "Formatted" => "1999-09-19 - 1999-10-19",
                "BeginDate" => "1999-09-19",
                "EndDate" => "1999-10-19",
            ],
            # end day is before begin day
            [
                "Inputs" => [
                    ["1999-09-20", "1999-09-19"],
                ],
                "Precision" => Date::PRE_BEGINYEAR
                        | Date::PRE_BEGINMONTH
                        | Date::PRE_BEGINDAY
                        | Date::PRE_ENDYEAR
                        | Date::PRE_ENDMONTH
                        | Date::PRE_ENDDAY,

                "Formatted" => "1999-09-19 - 1999-09-20",
                "BeginDate" => "1999-09-19",
                "EndDate" => "1999-09-20",
            ],
            # year not included
            [
                "Inputs" => [
                    "19 Sep",
                    "Sep 19",
                    "September 19",
                    "Sep 19th",
                    "09/19",
                ],
                "Precision" => Date::PRE_BEGINYEAR
                        | Date::PRE_BEGINMONTH
                        | Date::PRE_BEGINDAY,

                "Formatted" => date("Y")."-09-19",
                "BeginDate" => date("Y")."-09-19",
                "EndDate" => null,
            ],
            # year not included in end date and has to be inferred
            [
                "Inputs" => [
                    ["Sep 19, 1999", "Oct 20"],
                ],
                "Precision" => Date::PRE_BEGINYEAR
                        | Date::PRE_BEGINMONTH
                        | Date::PRE_BEGINDAY
                        | Date::PRE_ENDYEAR
                        | Date::PRE_ENDMONTH
                        | Date::PRE_ENDDAY,

                "Formatted" => "1999-09-19 - 2025-10-20",
                "BeginDate" => "1999-09-19",
                "EndDate" => "2025-10-20",
            ],
            # year is 0000
            [
                "Inputs" => [
                    "0000-09-19"
                ],
                "Precision" => Date::PRE_BEGINYEAR
                        | Date::PRE_BEGINMONTH
                        | Date::PRE_BEGINDAY,

                "Formatted" => "0000-09-19",
                "BeginDate" => "0000-09-19",
                "EndDate" => null,
            ],
        ];

        foreach ($FormatsToTest as $Format) {
            foreach ($Format["Inputs"] as $InputArgs) {
                if (!is_array($InputArgs)) {
                    $InputArgs = [ $InputArgs ];
                }
                $Input = implode(", ", $InputArgs);

                if (isset($Format["Exception"])) {
                    try {
                        $TestDate = (new ReflectionClass("ScoutLib\Date"))
                                ->newInstanceArgs($InputArgs);
                        $this->fail(
                            "Testing __construct() throws exception with input \"".$Input."\""
                        );
                    } catch (InvalidArgumentException $e) {
                        continue;
                    }
                }

                $TestDate = (new ReflectionClass("ScoutLib\Date"))->newInstanceArgs($InputArgs);

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
