<?PHP
#
#   FILE:  JsonHelper.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2002-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

use Metavus\InterfaceConfiguration;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

/**
 * Convenience class for standardizing JSON responses, making it easier to export
 * primitive data types to JSON format, and printing JSON responses.
 */
class JsonHelper
{

    /**
     * Object constructor.
     */
    public function __construct()
    {
        ApplicationFramework::getInstance()->logMessage(
            ApplicationFramework::LOGLVL_WARNING,
            "Deprecated class JsonHelper constructed at "
            .StdLib::getMyCaller().", json_encode instead."
        );
        $this->Data = [];
        $this->Warnings = [];
    }

    /**
     * Add a datum identified by a key to export in the JSON response.
     * @param string $Key Key used to identify the datum.
     * @param mixed $Value Datum to export.
     * @return void
     */
    public function addDatum($Key, $Value): void
    {
        $this->Data[$Key] = $Value;
    }

    /**
     * Add a warning message to export in the JSON response. Warnings are for
     * issues that might be problematic but won't interrupt execution.
     * @param string $Message Warning message to export.
     * @return void
     * @see Error()
     * @see Success()
     */
    public function addWarning($Message): void
    {
        $this->Warnings[] = strval($Message);
    }

    /**
     * Add an error message to export in the JSON response and then send the
     * response. A possible use of this method is to output and export a message
     * about a missing parameter to a callback.
     * @param string $Message Error message to send.
     * @return void
     * @see Success()
     */
    public function error($Message): void
    {
        $this->sendResult($this->generateResult("ERROR", $Message));
    }

    /**
     * Signal that the callback was successful and optionally set a message.
     * @param string $Message Message to export. This parameter is optional.
     * @return void
     * @see Error()
     */
    public function success($Message = ""): void
    {
        $this->sendResult($this->generateResult("OK", $Message));
    }

    private $Data;
    private $Warnings;

    /**
     * Export the data and messages. This sets the Content-Type header and prints
     * the JSON response.
     * @param array $Result Data to export. The data will be converted to JSON.
     * @returnvoid
     */
    private function sendResult(array $Result): void
    {
        $CharSet = InterfaceConfiguration::getInstance()->getString(
            "DefaultCharacterSet"
        );
        header("Content-Type: application/json; charset=".$CharSet, true);
        $this->printArrayToJson($Result);
    }

    /**
     * Generate standard result data based on a final state and message.
     * @param string $State State that the result data is in, e.g., "ERROR" or
     *      "OK"
     * @param string $Message Message to include in the standard result data.
     *     This parameter is optional.
     * @return array Returns an array of standard results data.
     */
    private function generateResult($State, $Message): array
    {
        return [
            "data" => $this->Data,
            "status" => [
                "state" => strval($State),
                "message" => strval($Message),
                "numWarnings" => count($this->Warnings),
                "warnings" => $this->Warnings
            ]
        ];
    }

    /**
     * Print an array of data in JSON format.
     * @param array $Array Array to print in JSON format.
     * @returnvoid
     */
    private function printArrayToJson(array $Array): void
    {
        # variables needed for printing commas if necessary
        $Offset = 0;
        $Count = count($Array);

        # determine whether or not we have a true array or a hash map
        $TrueArray = true;
        $ArrayCount = count($Array);
        for ($i = 0, reset($Array); $i < $ArrayCount; $i++, next($Array)) {
            if (key($Array) !== $i) {
                $TrueArray = false;
                break;
            }
        }

        # opening bracket
        print ($TrueArray) ? "[" : "{";

        # print each member
        foreach ($Array as $key => $value) {
            # replacements so we can escape strings and replace smart quotes
            static $Replace = [
                ["\\", "/", "\n", "\t", "\r", "\b", "\f", '"', "", "", "", "", ""],
                ['\\\\', '\\/', '\\n', '\\t', '\\r', '\\b', '\\f', '\"', "'", "'", '\"', '\"', '-']
            ];

            # print key if a hash map
            if (!$TrueArray) {
                # escape, remove smart quotes, and print the key
                print '"'.str_replace($Replace[0], $Replace[1], $key).'":';
            }

            # scalar values (int, float, string, or boolean)
            if (is_scalar($value)) {
                # numeric (i.e., float, int, or float/int string)
                if (is_numeric($value)) {
                    print $value;
                # string
                } elseif (is_string($value)) {
                    # escape, remove smart quotes, and print the value
                    print '"'.str_replace($Replace[0], $Replace[1], $value).'"';
                # boolean true
                } elseif ($value === true) {
                    print "true";
                # boolean false
                } elseif ($value === false) {
                    print "false";
                }
            # recur if the value is an array
            } elseif (is_array($value)) {
                $this->printArrayToJson($value);
            # null
            } elseif (is_null($value)) {
                print "null";
            # object, just print the name and don't possibly expose secret details
            } elseif (is_object($value)) {
                print '"object('.get_class($value).')"';
            # resource, just print the name and don't possibly expose secret details
            } else {
                print '"resource('.get_resource_type($value).')"';
            }

            # print comma if necessary
            if (++$Offset < $Count) {
                print ",";
            }
        }

        # closing bracket
        print ($TrueArray) ? "]" : "}";
    }
}
