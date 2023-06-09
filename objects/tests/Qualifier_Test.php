<?PHP
#
#   FILE:  Qualifier_Test.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Metavus;

class Qualifier_Test extends \PHPUnit\Framework\TestCase
{

    public function testQualifier()
    {
        $MyQual = Qualifier::Create("Test");

        $this->assertEquals($MyQual->Name(), "Test");

        $ToTest = ["Name", "NSpace", "Url"];
        foreach ($ToTest as $Thing) {
            $TestVal = "Test ".$Thing;
            $this->assertEquals($MyQual->$Thing($TestVal), $TestVal);
            $this->assertEquals($MyQual->$Thing(), $TestVal);
        }

        $MyQual->Destroy();
    }
}
