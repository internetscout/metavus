<?PHP
#
#   FILE:  RecordEditingUI_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

use ScoutLib\Date;

class RecordEditingUI_Test extends \PHPUnit\Framework\TestCase
{
    /**
    * Creates all of the test sample fields and adds
    * them to class variables $SampleTestFile and $SampleTestImage
    * so any function may use them.
    */
    public static function setUpBeforeClass(): void
    {
        # a temporary sample file is created at the beginning of each test
        file_put_contents("tmp/SampleTextFile.txt", "Sample date");

        self::$SampleTestFile = File::create("tmp/SampleTextFile.txt");
        self::$SampleTestImage =
        Image::create("interface/default/objects/tests/files/SampleImage.svg");
        self::$SampleTestUser = User::create("RecordEditingUI_Test_User");

        # to avoid the assumption that these fields already exist and were not modified
        # we are going to define and create our own test fields to be sure that they
        # actually exist in the format we are testing

        # construct the schema object
        self::$Schema = new MetadataSchema();

        self::$TestFieldIds = [];

        # outline fields to be created
        self::$TestFields = [
            "RecordEditingUI_Test Text Field" => MetadataSchema::MDFTYPE_TEXT,
            "RecordEditingUI_Test Paragraph Field" => MetadataSchema::MDFTYPE_PARAGRAPH,
            "RecordEditingUI_Test Number Field" => MetadataSchema::MDFTYPE_NUMBER,
            "RecordEditingUI_Test Date Field" => MetadataSchema::MDFTYPE_DATE,
            "RecordEditingUI_Test Timestamp Field" => MetadataSchema::MDFTYPE_TIMESTAMP,
            "RecordEditingUI_Test Flag Field" => MetadataSchema::MDFTYPE_FLAG,
            "RecordEditingUI_Test Tree Field" => MetadataSchema::MDFTYPE_TREE,
            "RecordEditingUI_Test CName Field" => MetadataSchema::MDFTYPE_CONTROLLEDNAME,
            "RecordEditingUI_Test Option Field" => MetadataSchema::MDFTYPE_OPTION,
            "RecordEditingUI_Test User Field" => MetadataSchema::MDFTYPE_USER,
            "RecordEditingUI_Test Image Field" => MetadataSchema::MDFTYPE_IMAGE,
            "RecordEditingUI_Test File Field" => MetadataSchema::MDFTYPE_FILE,
            "RecordEditingUI_Test Url Field" => MetadataSchema::MDFTYPE_URL,
            "RecordEditingUI_Test Search Param Set Field" =>
            MetadataSchema::MDFTYPE_SEARCHPARAMETERSET
        ];

        # create the fields
        foreach (self::$TestFields as $FieldName => $FieldType) {
            # retrieve the field with the given FieldName from the database
            # TmpField will be used to store a metadata field object
            $TmpField = self::$Schema->getItemByName($FieldName);
            # we will add the field only if does not already exist
            # this is simply here in case a previous test was run and the
            # test fields were not destoryed
            if ($TmpField === null) {
                $TmpField = self::$Schema->addField($FieldName, $FieldType);
                $TmpField->isTempItem(false);
            }
            # add the created/existing test metadata field id to the array
            self::$TestFieldIds[$FieldName] = $TmpField->id();
        }

        # create the test classifications
        $ClassificationFactory =
        new ClassificationFactory(self::$TestFieldIds["RecordEditingUI_Test Tree Field"]);
        self::$TestClassifications = [
            "RecordEditingUI_Test Classification1",
            "RecordEditingUI_Test Classification2",
            "RecordEditingUI_Test Classification3"
        ];

        foreach (self::$TestClassifications as $ClassificationId => $Classification) {
            # retrieve the classification with the given classification name from the database
            # TmpClassification will be used to store a classification object
            $TmpClassification = $ClassificationFactory->getItemByName($Classification);
            # we will add the classification only if does not already exist
            # this is simply here in case a previous test was run and the
            # test classifications were not destoryed
            if ($TmpClassification === null) {
                $TmpClassification = Classification::create(
                    $Classification,
                    self::$TestFieldIds["RecordEditingUI_Test Tree Field"],
                    Classification::NOPARENT
                );
            }
            # overwrite the Classification element with its equivalent Classification object
            self::$TestClassifications[$ClassificationId] = $TmpClassification;
        }

        # create the test controlled names
        $CNameFactory = new ControlledNameFactory(
            self::$TestFieldIds["RecordEditingUI_Test CName Field"]
        );
        self::$TestCNames = [
            "RecordEditingUI_Test CName1",
            "RecordEditingUI_Test CName2",
            "RecordEditingUI_Test CName3"
        ];

        foreach (self::$TestCNames as $CNameId => $CName) {
            # retrieve the controlled name with the given ControlledName from the database
            # TmpCName will be used to store a controlled name object
            $TmpCName = $CNameFactory->getItemByName($CName);
            # we will add the controlled name only if does not already exist
            # this is simply here in case a previous test was run and the
            # test controlled names were not destoryed
            if ($TmpCName === null) {
                $TmpCName = ControlledName::create(
                    $CName,
                    self::$TestFieldIds["RecordEditingUI_Test CName Field"]
                );
            }
            # overwrite the CName element with its equivalent CName object
            self::$TestCNames[$CNameId] = $TmpCName;
        }
    }

    /**
    * After to running the tests, this function is
    * run. It deletes all of the test sample fields.
    */
    public static function tearDownAfterClass(): void
    {
        # destroy all the test classifications
        foreach (self::$TestClassifications as $Classification) {
            $Classification->destroy();
        }

        # destroy all the test controlled names
        foreach (self::$TestCNames as $CName) {
            $CName->destroy();
        }

        # drop all of the test fields
        foreach (self::$TestFieldIds as $FieldId) {
            self::$Schema->dropField($FieldId);
        }

        self::$SampleTestFile->destroy();
        self::$SampleTestImage->destroy();
        self::$SampleTestUser->delete();
    }

    /**
     * This function tests that the method convertFormValueToMFieldValue()
     * in the RecordEditingUI class handles all the available field types
     * correctly and as expected.
     */
    public function testConvertFormValueToMFieldValue()
    {
        # test for text format (e.g. a title field)
        $MetadataField = new MetadataField(self::$TestFieldIds["RecordEditingUI_Test Text Field"]);
        $this->assertEquals(
            "Check that this title was trimmed.",
            RecordEditingUI::convertFormValueToMFieldValue(
                $MetadataField,
                " Check that this title was trimmed. "
            )
        );

        # tests for paragraph format (e.g. a description field)
        $MetadataField =
        new MetadataField(self::$TestFieldIds["RecordEditingUI_Test Paragraph Field"]);

        # case: rich text editor not used
        $this->assertEquals(
            "Check that this description was trimmed.",
            RecordEditingUI::convertFormValueToMFieldValue(
                $MetadataField,
                " Check that this description was trimmed. "
            )
        );

        # case: rich text editor used
        $MetadataField->allowHTML(true);
        $MetadataField->useWysiwygEditor(true);
        $this->assertEquals(
            "<p>There is no trailing space.</p>",
            RecordEditingUI::convertFormValueToMFieldValue(
                $MetadataField,
                "<p>There is no trailing space.</p><p>&nbsp;</p>"
            )
        );

        # test for number format (e.g. cumulative rating field)
        $MetadataField =
        new MetadataField(self::$TestFieldIds["RecordEditingUI_Test Number Field"]);
        $this->assertEquals(
            4.5,
            RecordEditingUI::convertFormValueToMFieldValue($MetadataField, 4.5)
        );

        #tests for date format (e.g. date issued field)
        $MetadataField = new MetadataField(self::$TestFieldIds["RecordEditingUI_Test Date Field"]);

        # case: date is entered
        $this->assertEquals(
            new Date("2022-1-10"),
            RecordEditingUI::convertFormValueToMFieldValue($MetadataField, "2022-1-10")
        );

        # case: date is left empty
        $this->assertFalse(RecordEditingUI::convertFormValueToMFieldValue($MetadataField, ""));

        # test for timestamp format (e.g. date of record creation field)
        $MetadataField =
        new MetadataField(self::$TestFieldIds["RecordEditingUI_Test Timestamp Field"]);
        $this->assertEquals(
            "2022-01-10 09:00:00",
            RecordEditingUI::convertFormValueToMFieldValue($MetadataField, "2022-01-10 09:00:00")
        );

        # test for flag format (e.g. has no password field)
        $MetadataField = new MetadataField(self::$TestFieldIds["RecordEditingUI_Test Flag Field"]);
        $this->assertTrue(RecordEditingUI::convertFormValueToMFieldValue($MetadataField, "1"));
        $this->assertFalse(RecordEditingUI::convertFormValueToMFieldValue($MetadataField, "0"));

        # similar tests for different formats
        $FormatsToTest = [
            # tests for tree format (e.g. classification field)
            [
                "MetadataField" => new MetadataField(
                    self::$TestFieldIds["RecordEditingUI_Test Tree Field"]
                ),
                "Cases" => [
                    # case: non-numeric strings are given as values
                    [
                        "Output" => [
                            self::$TestClassifications[0]->id() => self::$TestClassifications[0],
                            self::$TestClassifications[1]->id() => self::$TestClassifications[1]
                        ],
                        "Input" => [
                            self::$TestClassifications[0]->id() => self::$TestClassifications[0],
                            self::$TestClassifications[1]->id() => self::$TestClassifications[1]
                        ]
                    ],
                    # case: numeric strings are given as values
                    [
                        "Output" => [
                            self::$TestClassifications[0]->id() => self::$TestClassifications[0],
                            self::$TestClassifications[1]->id() => self::$TestClassifications[1]
                        ],
                        "Input" => [
                            0 => self::$TestClassifications[0]->id(),
                            1 => self::$TestClassifications[1]->id()
                        ]
                    ],
                    # case: removing values
                    # (in this scenario an empty value "" represents a removed item)
                    [
                        "Output" => [
                            self::$TestClassifications[0]->id() => self::$TestClassifications[0],
                            self::$TestClassifications[1]->id() => self::$TestClassifications[1],
                            self::$TestClassifications[2]->id() => self::$TestClassifications[2]
                        ],
                        "Input" => [
                            0 => self::$TestClassifications[0]->id(),
                            1 => "",
                            2 => self::$TestClassifications[1]->id(),
                            3 => "",
                            4 => self::$TestClassifications[2]->id()
                        ]
                    ]
                ]
            ],
            # tests for controlled name format (e.g. publisher field)
            [
                "MetadataField" => new MetadataField(
                    self::$TestFieldIds["RecordEditingUI_Test CName Field"]
                ),
                "Cases" => [
                    # case: non-numeric strings are given as values
                    [
                        "Output" => [
                            self::$TestCNames[0]->id() => self::$TestCNames[0],
                            self::$TestCNames[1]->id() => self::$TestCNames[1]
                        ],
                        "Input" => [
                            self::$TestCNames[0]->id() => self::$TestCNames[0],
                            self::$TestCNames[1]->id() => self::$TestCNames[1]
                        ]
                    ],
                    # case: numeric strings are given as values
                    [
                        "Output" => [
                            self::$TestCNames[0]->id() => self::$TestCNames[0],
                            self::$TestCNames[1]->id() => self::$TestCNames[1]
                        ],
                        "Input" => [
                            0 => self::$TestCNames[0]->id(),
                            1 => self::$TestCNames[1]->id()
                        ]
                    ],
                    # case: removing values
                    # (in this scenario an empty value "" represents a removed item)
                    [
                        "Output" => [
                            self::$TestCNames[0]->id() => self::$TestCNames[0],
                            self::$TestCNames[1]->id() => self::$TestCNames[1],
                            self::$TestCNames[2]->id() => self::$TestCNames[2]
                        ],
                        "Input" => [
                            0 => "",
                            1 => self::$TestCNames[0]->id(),
                            2 => self::$TestCNames[1]->id(),
                            3 => "",
                            4 => "",
                            5 => self::$TestCNames[2]->id(),
                            6 => ""
                        ]
                    ]
                ]
            ],
            # tests for option format (e.g. resource type)
            [
                "MetadataField" => new MetadataField(
                    self::$TestFieldIds["RecordEditingUI_Test Option Field"]
                ),
                "Cases" => [
                    # case: non-numeric strings are given as values
                    [
                        "Output" => [
                            self::$TestCNames[0]->id() => self::$TestCNames[0],
                            self::$TestCNames[1]->id() => self::$TestCNames[1]
                        ],
                        "Input" => [
                            self::$TestCNames[0]->id() => self::$TestCNames[0],
                            self::$TestCNames[1]->id() => self::$TestCNames[1]
                        ]
                    ],
                    # case: numeric strings are given as values
                    [
                        "Output" => [
                            self::$TestCNames[0]->id() => self::$TestCNames[0],
                            self::$TestCNames[1]->id() => self::$TestCNames[1]
                        ],
                        "Input" => [
                            0 => self::$TestCNames[0]->id(),
                            1 => self::$TestCNames[1]->id()
                        ]
                    ],
                    # case: removing values
                    # (in this scenario an empty value "" represents a removed item)
                    [
                        "Output" => [
                            self::$TestCNames[0]->id() => self::$TestCNames[0],
                            self::$TestCNames[1]->id() => self::$TestCNames[1]
                        ],
                        "Input" => [
                            0 => "",
                            1 => self::$TestCNames[0]->id(),
                            2 => "",
                            3 => self::$TestCNames[1]->id()
                        ]
                    ],
                ]
            ],
            # tests for user format (e.g. last modified by id)
            [
                "MetadataField" => new MetadataField(
                    self::$TestFieldIds["RecordEditingUI_Test User Field"]
                ),
                "Cases" => [
                    # case: non-numeric string is given as value
                    [
                        "Output" => [self::$SampleTestUser->id() => self::$SampleTestUser],
                        "Input" => [self::$SampleTestUser->id() => self::$SampleTestUser]
                    ],
                    # case: numeric strings are given as values
                    [
                        "Output" => [self::$SampleTestUser->id() => self::$SampleTestUser],
                        "Input" => [0 => self::$SampleTestUser->id()]
                    ]
                ]
            ]
        ];

        # loop over the similar tests to run each test with it's set of cases and inputs
        foreach ($FormatsToTest as $Format) {
            $MetadataField = $Format["MetadataField"];
            foreach ($Format["Cases"] as $Case) {
                $Expected = $this->getArrayObjectsId($Case["Output"]);
                $Actual = $this->getArrayObjectsId(
                    RecordEditingUI::convertFormValueToMFieldValue($MetadataField, $Case["Input"])
                );
                $this->assertEquals($Expected, $Actual);
            }
        }

        # tests for image format (e.g. screenshot)
        $MetadataField = new MetadataField(self::$TestFieldIds["RecordEditingUI_Test Image Field"]);

        # case: image is provided
        # the image object created after the user upload an image
        $Expected = $this->getArrayObjectsId([
            self::$SampleTestImage->id() => self::$SampleTestImage
        ]);
        $Actual = $this->getArrayObjectsId(RecordEditingUI::convertFormValueToMFieldValue(
            $MetadataField,
            [self::$SampleTestImage->id() => self::$SampleTestImage]
        ));
        $this->assertEquals($Expected, $Actual);

        # case: no image is provided
        $Expected = [0 => ""];
        $Actual = [0 => ""];
        $this->assertEquals($Expected, $Actual);

        # test for file format (e.g. files)
        $MetadataField = new MetadataField(self::$TestFieldIds["RecordEditingUI_Test File Field"]);

        # case: file is provided
        $Expected = $this->getArrayObjectsId([
            self::$SampleTestFile->id() => self::$SampleTestFile
        ]);
        $Actual = $this->getArrayObjectsId(RecordEditingUI::convertFormValueToMFieldValue(
            $MetadataField,
            [self::$SampleTestFile->id() => self::$SampleTestFile]
        ));
        $this->assertEquals($Expected, $Actual);

        # case: file is not provided
        $this->assertEmpty(RecordEditingUI::convertFormValueToMFieldValue($MetadataField, ""));

        # tests for url format (e.g. url field)
        $MetadataField = new MetadataField(self::$TestFieldIds["RecordEditingUI_Test Url Field"]);

        # case: has provided url
        $Expected = "https://scout.wisc.edu/";
        $Actual = RecordEditingUI::convertFormValueToMFieldValue(
            $MetadataField,
            "https://scout.wisc.edu/"
        );
        $this->assertEquals($Expected, $Actual);

        # tests for search parameter set format (e.g. selection criteria)
        $MetadataField = new MetadataField(self::$TestFieldIds[
            "RecordEditingUI_Test Search Param Set Field"
        ]);

        # case: search parameter provided
        $SampleSearchParamSet = new SearchParameterSet();
        $SampleSearchParamSet->addParameter("Arts");
        $Actual = RecordEditingUI::convertFormValueToMFieldValue(
            $MetadataField,
            $SampleSearchParamSet
        )->data();
        $this->assertEquals($SampleSearchParamSet->data(), $Actual);
    }


    /**
     * Get the Ids of the objects in the passed array.
     *
     * @param array $Arr Array of objects to parse
     * @return array Array of objects' IDs
     */
    private function getArrayObjectsId(array $Arr)
    {
        return array_map(function ($Element) {
            return $Element->id();
        }, $Arr);
    }

    private static $Schema;
    private static $TestFields;
    private static $TestFieldIds;
    private static $TestClassifications;
    private static $TestCNames;
    private static $SampleTestFile;
    private static $SampleTestImage;
    private static $SampleTestUser;
}
