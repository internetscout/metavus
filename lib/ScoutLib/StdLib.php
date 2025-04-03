<?PHP
#
#   FILE:  StdLib.php
#
#   Part of the ScoutLib application support library
#   Copyright 2016-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#

namespace ScoutLib;
use Closure;
use Exception;
use DOMDocument;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Standard utility library.
 * \nosubgrouping
 */
class StdLib
{
    # cached data timeout for DataCache->set(), in seconds
    const CACHED_DATA_TTL = 60 * 60 * 24;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Get info about call to current function.
     * @param int $LevelsToGoUp Number of levels to go up from calling
     *      context.  (OPTIONAL, defaults to 1)
     * @return array Array with the element names "FileName" (with no leading
     *      path), "FullFileName" (with absolute path), "RelativeFileName"
     *      (with relative path), "LineNumber", "Class", and "Function".
     */
    public static function getCallerInfo(int $LevelsToGoUp = 1): array
    {
        $Trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        if (isset($Trace[$LevelsToGoUp]["file"])) {
            $FullFileName = $Trace[$LevelsToGoUp]["file"];
            $FileName = basename($FullFileName);
            $RelativeFileName = str_replace(getcwd() . "/", "", $FullFileName);
        } else {
            $FullFileName = "UnknownFile";
            $FileName = "UnknownFile";
            $RelativeFileName = "UnknownFile";
        }
        $Info = [
            "FileName" => $FileName,
            "RelativeFileName" => $RelativeFileName,
            "FullFileName" => $FullFileName,
            "LineNumber" => $Trace[$LevelsToGoUp]["line"] ?? "XX",
            "Class" => ($Trace[$LevelsToGoUp + 1]["class"] ?? ""),
            "Function" => "",
        ];
        if (isset($Trace[$LevelsToGoUp + 1]["function"])) {
            $Info["Function"] = ($Trace[$LevelsToGoUp + 1]["class"] ?? "")
                    .($Trace[$LevelsToGoUp + 1]["type"] ?? "")
                    .$Trace[$LevelsToGoUp + 1]["function"];
        }
        return $Info;
    }

    /**
     * Get string with file and line number for call to current function.
     * @return string String with caller info in the form "FILE:LINE".
     */
    public static function getMyCaller(): string
    {
        $Trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $FileName =  isset($Trace[1]["file"])
                ? basename($Trace[1]["file"]) : "UnknownFile";
        return $FileName.":".($Trace[1]["line"] ?? "UnknownLine");
    }

    /**
     * Check the caller of the current function.  In the desired caller
     * parameter, if a file name is specified it should include the ".php"
     * extension but should not have a leading path.  In the exception
     * message parameter, the following strings can be used and the
     * appropriate values will be substituted in:  %FILE% (no leading path),
     * %LINE%, %FULLFILE% (includes leading path), %CLASS%, %FUNCTION%,
     * and %METHOD% (equivalent to "%CLASS%::%FUNCTION%").
     * @param string $DesiredCaller String describing desired caller, in
     *       the form "Class", "Class::Method", "Function", "File", or
     *       "File:Line".
     * @param string $ExceptionMsg If specified and the caller was not the
     *       desired caller, an exception will be thrown with this message.
     *       (OPTIONAL)
     * @return bool true if caller matched desired caller, otherwise false.
     */
    public static function checkMyCaller(
        string $DesiredCaller,
        ?string $ExceptionMsg = null
    ): bool {
        # retrieve caller info
        $Trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $FullFile = $Trace[1]["file"] ?? "(unknown)";
        $File = basename($FullFile);
        $Line = $Trace[1]["line"] ?? "(unknown)";
        $Class = isset($Trace[2]["class"]) ? $Trace[2]["class"] : "";
        $Function = isset($Trace[2]["function"]) ? $Trace[2]["function"] : "";

        # if caller does not match desired caller
        if (($DesiredCaller != $Class)
            && ($DesiredCaller != $Class . "::" . $Function)
            && ($DesiredCaller != $Class . $Function)
            && ($DesiredCaller != $File)
            && ($DesiredCaller != $File . ":" . $Line)) {
            # if exception message supplied
            if ($ExceptionMsg !== null) {
                # make any needed substitutions in exception message
                $Msg = str_replace(
                    array(
                        "%FILE%",
                        "%LINE%",
                        "%FULLFILE%",
                        "%CLASS%",
                        "%FUNCTION%",
                        "%METHOD%"
                    ),
                    array(
                        $File,
                        $Line,
                        $FullFile,
                        $Class,
                        $Function,
                        $Class . "::" . $Function
                    ),
                    $ExceptionMsg
                );

                # throw exception
                throw new Exception($Msg);
            } else {
                # report to our caller that their caller was not the desired one
                return false;
            }
        }

        # report to our caller that their caller was not the desired one
        return true;
    }

    /**
     * Get proper reflection instance for the supplied callback.
     * @param callable $Callback Callback.
     * @return ReflectionFunctionAbstract Reflection instance.
     */
    public static function getReflectionForCallback(
        callable $Callback
    ): ReflectionFunctionAbstract {
        if ($Callback instanceof Closure) {
            return new ReflectionFunction($Callback);
        }

        if (is_string($Callback)) {
            $Pieces = explode('::', $Callback);
            return (count($Pieces) > 1)
                    ? new ReflectionMethod($Pieces[0], $Pieces[1])
                    : new ReflectionFunction($Callback);
        }

        if (!is_array($Callback)) {
            $Callback = [$Callback, "__invoke"];
        }
        return new ReflectionMethod($Callback[0], $Callback[1]);
    }

    /**
     * Get type (if available) for specified parameter for specified callback.
     * @param callable $Callback Function or method.
     * @param int $ArgIndex Index into parameters.  (First parameter is index 0.)
     * @return string|false Parameter type or FALSE if parameter was not found
     *      or parameter type is not available.
     */
    public static function getArgumentType(callable $Callback, int $ArgIndex)
    {
        $Params = (StdLib::getReflectionForCallback($Callback))->getParameters();
        if (!isset($Params[$ArgIndex])) {
            return false;
        }
        $Type = $Params[$ArgIndex]->getType();
        if ($Type === null) {
            return false;
        }

        # trim off optional argument indicator if present
        if ($Type instanceof \ReflectionNamedType) {
            $Type = $Type->getName();
        } else {
            $Type = @(string)$Type;
        }
        if ($Type[0] == "?") {
            $Type = substr($Type, 1);
        }

        return $Type;
    }

    /**
     * Get backtrace as a string.
     * @param bool $IncludeArgs If true, arguments will be included in function
     *       call information.  (OPTIONAL, defaults to true)
     * @return string Backtrace info string.
     */
    public static function getBacktraceAsString(bool $IncludeArgs = true): string
    {
        # get backtrace text
        ob_start();
        $TraceOpts = $IncludeArgs ? 0 : DEBUG_BACKTRACE_IGNORE_ARGS;
        debug_print_backtrace($TraceOpts);  // phpcs:ignore
        $TraceString = (string)ob_get_contents();
        ob_end_clean();

        # remove this function from backtrace entries
        $TraceString = preg_replace(
            "/^#0\s+" . __METHOD__ . "[^\n]*\n/",
            "",
            $TraceString,
            1
        );

        # renumber backtrace entries
        $TraceString = preg_replace_callback(
            "/^#(\d+)/m",
            function ($Matches) {
                return "#".((int)$Matches[1] - 1);
            },
            $TraceString
        );

        # strip leading path off files names
        $HomeDir = dirname($_SERVER["SCRIPT_FILENAME"]);
        $TraceString = preg_replace(
            "%" . preg_quote($HomeDir, "%") . "/%",
            "",
            $TraceString
        );

        # return backtrace string to caller
        return $TraceString;
    }

    /**
     * Get the value from an array with the given index or a default value if it
     * does not exist.
     * @param array $Array Array to search
     * @param mixed $Key Index of the value to retrieve
     * @param mixed $Default Value to return if the key does not exist
     * @return mixed The value at the given index or the default value if no
     *      value exists at the specified index.
     */
    public static function getArrayValue(array $Array, $Key, $Default = null)
    {
        return array_key_exists($Key, $Array) ? $Array[$Key] : $Default;
    }

    /**
     * Retreive PHP configuration settings via a call to phpinfo().
     * @return array An array of PHP configuration settings.
     * @see phpinfo()
     */
    public static function getPhpInfo(): array
    {
        # grab PHP info page
        ob_start();
        phpinfo();
        $InfoPage = (string)ob_get_contents();
        ob_end_clean();

        # start by assuming that no info is available
        $Info = array();

        # for each section on page
        $PageChunks = explode("<h2", $InfoPage);
        foreach ($PageChunks as $PageChunk) {
            # look for module/section name
            preg_match("/<a name=\"module_([^<>]*)\">/", $PageChunk, $Piece);

            # if we found module/section
            if (count($Piece) > 1) {
                # save module/section name
                $ModuleName = trim($Piece[1]);
            } else {
                # assume no module/section name
                $ModuleName = "";
            }

            # pull out info values from HTML tables
            preg_match_all(
                "/<tr[^>]*><td[^>]*>(.*)<\/td><td[^>]*>(.*)<\/td>/Ux",
                $PageChunk,
                $LocalValue
            );
            preg_match_all(
                "/<tr[^>]*><td[^>]*>(.*)<\/td><td[^>]*>(.*)<\/td><td[^>]*>(.*)<\/td>/Ux",
                $PageChunk,
                $MasterValue
            );

            # store "local" info values
            foreach ($LocalValue[0] as $MatchString => $Dummy) {
                $MatchString = trim((string)$MatchString);
                $Info[$ModuleName][trim(strip_tags($LocalValue[1][$MatchString]))] =
                    array(trim(strip_tags($LocalValue[2][$MatchString])));
            }

            # store "master" info values
            foreach ($MasterValue[0] as $MatchString => $Dummy) {
                $MatchString = trim((string)$MatchString);
                $Info[$ModuleName][trim(strip_tags($MasterValue[1][$MatchString]))] =
                    array(
                        trim(strip_tags($MasterValue[2][$MatchString])),
                        trim(strip_tags($MasterValue[3][$MatchString]))
                    );
            }
        }

        # return info to caller
        return $Info;
    }

    /**
     * Retrieve a form value from $_POST if available, otherwise retrieve a value
     * from $_GET if available, otherwise returns specified default value.
     * @param mixed $Key Key into $_POST or $_GET to check for value
     * @param mixed $Default Value to return if nothing is found in $_POST or $_GET
     *       (OPTIONAL, defaults to NULL)
     * @return mixed The form value or default if nothing is found.
     */
    public static function getFormValue($Key, $Default = null)
    {
        return array_key_exists($Key, $_POST) ? $_POST[$Key]
            : (array_key_exists($Key, $_GET) ? $_GET[$Key] : $Default);
    }

    /**
     * Determine if a string contains serialized php data.
     * @param string $String String to test.
     * @return bool TRUE when the string represents data.
     */
    public static function isSerializedData($String): bool
    {
        # only strings are serialized data
        if (!is_string($String)) {
            return false;
        }

        # if the string encodes 'FALSE', then it's serialized
        if ($String == serialize(false)) {
            return true;
        }

        # otherwise attempt to unserialize, will get FALSE on error
        $Data = @unserialize($String);
        return ($Data !== false);
    }

    /**
     * Check an object created by unserialize() for saved private variables
     * that were saved when a differently-namespaced version of the object was
     * serialized(), moving these values into the appropriate private
     * variables if found. Meant to be called from a __wakeup() method.
     * @param object $Object Object to check and potentially modify
     * @param string $OldNamespace Previous namespace (OPTIONAL)
     */
    public static function loadLegacyPrivateVariables(
        &$Object,
        string $OldNamespace = ""
    ): void {
        # get prefix for index of non-namespaced saved variables
        # (class prefix is surrounded by null bytes)
        $Reflection = new ReflectionClass($Object);
        $VarNamePrefix = "\0".(strlen($OldNamespace) ? $OldNamespace."\\" : "")
            .$Reflection->getShortName()."\0";

        # convert object to array to be able to access private variables
        $SavedVars = (array)$Object;

        # get the default values for private vars
        $PrivateVarDefaults = $Reflection->getDefaultProperties();

        # get list of all private variables in class
        $PrivateProperties = $Reflection->getProperties(ReflectionProperty::IS_PRIVATE);

        # copy non-namespace value for any of our variables that are not already set
        foreach ($PrivateProperties as $Property) {
            $PVarName = $Property->name;
            $SavedVarName = $VarNamePrefix.$PVarName;

            # if we don't have a legacy saved value for this variable move on
            # to the next one
            if (!isset($SavedVars[$SavedVarName])) {
                continue;
            }

            # get the current value for this member from $Object
            $ReflectionProp = $Reflection->getProperty($PVarName);
            $ReflectionProp->setAccessible(true);

            # get the current and default values
            $Value = $ReflectionProp->getValue($Object);
            $Default = $PrivateVarDefaults[$PVarName];

            # if the current value is empty or the current value is the default
            if (empty($Value) || (!is_null($Default) && $Value === $Default)) {
                # set the current value to the saved legacy value
                $ReflectionProp->setValue($Object, $SavedVars[$SavedVarName]);
            }

            $ReflectionProp->setAccessible(false);
        }
    }

    /**
     * Converts a date into a user-friendly printable format.
     * @param mixed $Date Date value to print, in any format parseable by strtotime().
     * @param bool $Verbose Whether to be verbose about date.  (OPTIONAL, defaults to FALSE)
     * @param string $BadDateString String to display if date appears invalid.  (OPTIONAL,
     *       defaults to "-")
     * @return string Returns a string containing a nicely-formatted date value.
     */
    public static function getPrettyDate(
        $Date,
        bool $Verbose = false,
        string $BadDateString = "-"
    ): string {
        # convert date to seconds
        $TStamp = !is_null($Date) ? strtotime($Date) : false;

        # if time was invalid
        if (($TStamp === false) || ($TStamp < 0)) {
            return $BadDateString;
        }

        # if timestamp was today use "Today"
        if (date("z Y", $TStamp) == date("z Y")) {
            return "Today";
        }

        # if timestamp was yesterday use "Yesterday"
        if (date("n/j/Y", ($TStamp - (24 * 60 * 60))) == date("n/j/Y")) {
            return "Yesterday";
        }

        # if timestamp was this week use format "Monday" or "Mon"
        # (adjust timestamp by a day because "W" begins the week on monday)
        if (date("W/o/Y", ($TStamp + (24 * 60 * 60))) == date("W/o/Y")) {
            $Format = $Verbose ? "l" : "D";
            return date($Format, $TStamp);
        }

        # if timestamp was this year use format "January 31st" or "1/31"
        if (date("Y", $TStamp) == date("Y")) {
            $Format = $Verbose ? "F jS" : "n/j";
            return date($Format, $TStamp);
        }

        # just use format "January 31st, 1999" or "1/31/99"
        $Format = $Verbose ? "F jS, Y" : "n/j/y";
        return date($Format, $TStamp);
    }

    /**
     * Converts a timestamp into a user-friendly printable format.
     * @param mixed $Timestamp Date/time value to print, as a timestamp or in
     *      any format parseable by strtotime().
     * @param bool $Verbose Whether to be verbose about date.  (OPTIONAL, defaults
     *       to FALSE)
     * @param string $BadTimeString String to display if date/time appears invalid.
     *       (OPTIONAL, defaults to "-")
     * @param bool $IncludeOldTimes Whether to include time when date is more than
     *       a week in the past.  (OPTIONAL, defaults to TRUE)
     * @return string A string containing a nicely-formatted timestamp value.
     */
    public static function getPrettyTimestamp(
        $Timestamp,
        $Verbose = false,
        $BadTimeString = "-",
        $IncludeOldTimes = true
    ): string {
        # convert timestamp to seconds if necessary
        if (is_null($Timestamp)) {
            $TStamp = false;
        } else {
            $TStamp = preg_match("/^[0-9]+$/", $Timestamp) ? $Timestamp
                : strtotime($Timestamp);
        }

        # if time was invalid
        if ($TStamp === false) {
            $Pretty = $BadTimeString;
        } elseif (date("z Y", $TStamp) == date("z Y")) {
            # else if timestamp is today use format "1:23pm"
            $Pretty = date("g:ia", $TStamp);
        } elseif (date("n/j/Y", ($TStamp + (24 * 60 * 60))) == date("n/j/Y")) {
            # else if timestamp is yesterday use format "Yesterday 1:23pm"
            $Pretty = "Yesterday "
                .($Verbose ? "at " : "")
                .date("g:ia", $TStamp);
        } elseif (date("n/j/Y", ($TStamp - (24 * 60 * 60))) == date("n/j/Y")) {
            # else if timestamp is tomorrow use format "Tomorrow 1:23pm"
            $Pretty = "Tomorrow "
                .($Verbose ? "at " : "")
                .date("g:ia", $TStamp);
        } elseif (date("W/o/Y", ($TStamp + (24 * 60 * 60))) == date("W/o/Y")) {
            # else if timestamp is this week use format "Monday 1:23pm"
            # (adjust timestamp by a day because "W" begins the week on monday)
            $Pretty = $Verbose ? date('l \a\t g:ia', $TStamp)
                : date("D g:ia", $TStamp);
        } elseif (date("Y", $TStamp) == date("Y")) {
            # else if timestamp is this year use format "1/31 1:23pm"
            $Pretty = date(($Verbose
                ? ($IncludeOldTimes ? 'F jS \a\t g:ia' : 'F jS')
                : ($IncludeOldTimes ? "n/j g:ia" : "n/j")
            ), $TStamp);
        } else {
            # else use format "1/31/99 1:23pm"
            $Pretty = date(($Verbose
                ? ($IncludeOldTimes ? 'F jS, Y \a\t g:ia' : 'F jS, Y')
                : ($IncludeOldTimes ? "n/j/y g:ia" : "n/j/y")
            ), $TStamp);
        }

        # return nicely-formatted timestamp to caller
        return $Pretty;
    }

    /**
     * Pluralize an English word.
     * @param string $Word Word to make plural.
     * @param int $Count Do not pluralize if this value is 1 or -1.  (OPTIONAL)
     * @return string Word in plural form.
     */
    public static function pluralize(string $Word, ?int $Count = null): string
    {
        # return word unchanged if singular count is supplied
        if (($Count == 1) || ($Count == - 1)) {
            return $Word;
        }

        # return word unchanged if singular and plural are the same
        if (in_array(strtolower($Word), self::$UncountableWords)) {
            return $Word;
        }

        # check for irregular singular forms
        foreach (self::$IrregularWords as $Pattern => $Result) {
            $Pattern = '/' . $Pattern . '$/i';
            if (preg_match($Pattern, $Word)) {
                return preg_replace($Pattern, $Result, $Word);
            }
        }

        # check for matches using regular expressions
        foreach (self::$PluralizePatterns as $Pattern => $Result) {
            if (preg_match($Pattern, $Word)) {
                return preg_replace($Pattern, $Result, $Word);
            }
        }

        # return word unchanged if we could not process it
        return $Word;
    }

    /**
     * Singularize an English word.
     * @param string $Word Word to make singular.
     * @return string Word in singular form.
     */
    public static function singularize(string $Word): string
    {
        # return word unchanged if singular and plural are the same
        if (in_array(strtolower($Word), self::$UncountableWords)) {
            return $Word;
        }

        # check for irregular plural forms
        foreach (self::$IrregularWords as $Result => $Pattern) {
            $Pattern = '/' . $Pattern . '$/i';
            if (preg_match($Pattern, $Word)) {
                return preg_replace($Pattern, $Result, $Word);
            }
        }

        # check for matches using regular expressions
        foreach (self::$SingularizePatterns as $Pattern => $Result) {
            if (preg_match($Pattern, $Word)) {
                return preg_replace($Pattern, $Result, $Word);
            }
        }

        # return word unchanged if we could not process it
        return $Word;
    }

    /**
     * Normalize string to proper title case (assuming English), preserving
     * any HTML tags within the string.  This is based on a port by Kroc Camen
     * of a port by David Gouch of a function originally written by John Gruber.
     * @param string $Title String to normalize.
     * @return string Normalized string.
     * @see http://camendesign.com/code/title-case
     */
    public static function titleCase(string $Title): string
    {
        # remove HTML, storing it for later
        # (HTML elements to ignore | tags | entities)
        $RegEx = '/<(code|var)[^>]*>.*?<\/\1>|<[^>]+>|&\S+;/';
        preg_match_all($RegEx, $Title, $HtmlTags, PREG_OFFSET_CAPTURE);
        $Title = preg_replace($RegEx, '', $Title);

        # find each word (including punctuation attached)
        $SmartQLeft = "\u{2018}";
        $SmartQRight = "\u{2019}";
        preg_match_all(
            '/[\w\p{L}&`\''.$SmartQLeft.$SmartQRight.'"“\.@:\/\{\(\[<>_]+-? */u',
            $Title,
            $Matches,
            PREG_OFFSET_CAPTURE
        );
        foreach ($Matches[0] as &$MatchInfo) {
            list($MatchString, $MatchOffset) = $MatchInfo;

            # correct offsets for multi-byte characters
            # (`PREG_OFFSET_CAPTURE` returns *byte*-offset)
            # (fix by recounting the text before offset using multi-byte aware `strlen`)
            $MatchOffset = mb_strlen(substr($Title, 0, $MatchOffset), 'UTF-8');

            # find words that should always be lowercase…
            # (never on the first word, and never if preceded by a colon)
            $MatchString = $MatchOffset > 0
                && (mb_substr($Title, max(0, $MatchOffset - 2), 1, 'UTF-8') !== ':')
                && !preg_match(
                    '/[\x{2014}\x{2013}] ?/u',
                    mb_substr($Title, max(0, $MatchOffset - 2), 2, 'UTF-8')
                )
                && preg_match(
                    '/^(a(nd?|s|t)?|b(ut|y)|en|for|i[fn]|o[fnr]|t(he|o)|vs?\.?|via)[ \-]/i',
                    $MatchString
                )

                # ...and convert them to lowercase
                ? mb_strtolower($MatchString, 'UTF-8')

                # else: brackets and other wrappers
                : (preg_match('/[\'"_{(\['.$SmartQLeft.'“]/u', mb_substr(
                    $Title,
                    max(0, $MatchOffset - 1),
                    3,
                    'UTF-8'
                ))
                    # convert first letter within wrapper to uppercase
                    ? mb_substr($MatchString, 0, 1, 'UTF-8')
                        .mb_strtoupper(mb_substr($MatchString, 1, 1, 'UTF-8'), 'UTF-8')
                        .mb_substr($MatchString, 2, mb_strlen($MatchString, 'UTF-8') - 2, 'UTF-8')

                    # else: do not uppercase these cases
                    : (preg_match('/[\])}]/', mb_substr(
                        $Title,
                        max(0, $MatchOffset - 1),
                        3,
                        'UTF-8'
                    ))
                            || preg_match(
                                '/[A-Z]+|&|\w+[._]\w+/u',
                                mb_substr(
                                    $MatchString,
                                    1,
                                    mb_strlen($MatchString, 'UTF-8') - 1,
                                    'UTF-8'
                                )
                            )

                        ? $MatchString

                        # if all else fails, then no more fringe-cases; uppercase the word
                        : mb_strtoupper(mb_substr($MatchString, 0, 1, 'UTF-8'), 'UTF-8')
                           .mb_substr($MatchString, 1, mb_strlen($MatchString, 'UTF-8'), 'UTF-8')
                    ));

            # resplice the title with the change (`substr_replace` is not multi-byte aware)
            $Title = mb_substr($Title, 0, $MatchOffset, 'UTF-8')
                    .$MatchString
                    .mb_substr(
                        $Title,
                        $MatchOffset + mb_strlen($MatchString, 'UTF-8'),
                        mb_strlen($Title, 'UTF-8'),
                        'UTF-8'
                    );
        }

        # restore the HTML
        foreach ($HtmlTags[0] as &$Tag) {
            $Title = substr_replace($Title, $Tag[0], $Tag[1], 0);
        }

        return $Title;
    }

    /**
     * Attempt to truncate a string as neatly as possible with respect to word
     * breaks, punctuation, and HTML tags.
     * @param string $String String to truncate
     * @param int $MaxLength The maximum length of the truncated string
     * @param bool $BreakAnywhere true to break exactly at the maximum length
     * @return string The (possibly) truncated string
     */
    public static function neatlyTruncateString(
        string $String,
        int $MaxLength,
        bool $BreakAnywhere = false
    ): string {
        $TagStrippedString = strip_tags(html_entity_decode($String));

        # if the string contained no HTML tags, we can just treat it as text
        if ($String == $TagStrippedString) {
            $Length = self::strlen($String);

            # if string was short enough, we need not do anything
            if ($Length <= $MaxLength) {
                return $String;
            }

            # if BreakAnywhere is set, just chop at the max length
            if ($BreakAnywhere) {
                $BreakPos = $MaxLength;
            } else {
                # otherwise look for an acceptable breakpoint
                $BreakPos = self::strrpos($String, " ", (0 - ($Length - $MaxLength)));

                # if we couldn't find a breakpoint, just chop at max length
                if ($BreakPos === false) {
                    $BreakPos = $MaxLength;
                }
            }

            $Result = self::substr($String, 0, $BreakPos);

            # tack on the ellipsis
            $Result .= "...";
        } else {
            # otherwise, we're in an HTML string and we have to account for
            # how many characters are actually displayed when the string will be
            # rendered

            # if there aren't enough non-whitespace displayed characters to
            # exceed the max length, bail because we don't need to do
            # anything
            if (self::strlen(trim($TagStrippedString)) <= $MaxLength) {
                return $String;
            }

            # okay, the hard way -- we have to do some limited parsing
            # of html and attempt to count the number of printing characters
            # as we're doing that.  to accomplish this, we'll build a
            # little state machine and iterate over the characters one at a
            # time

            # split the string into characters (annoyingly, split()/mb_split()
            # cannot do this, so we have to use preg_split() in unicode mode)
            $Tokens = preg_split('%%u', $String, - 1, PREG_SPLIT_NO_EMPTY);
            if ($Tokens === false) {
                throw new Exception("Unable to split string.");
            }

            # define our states
            $S_Text = 0;
            $S_MaybeTag = 1;
            $S_MaybeEntity = 2;
            $S_Tag = 3;
            $S_Quote = 4;

            # max length of an HTML Entity
            $MaxEntityLength = 8;

            # track how much we have displayed
            $DisplayedLength = 0;

            $Buffer = "";   # for characters we're not sure about
            $BufLen = 0;    # count of buffered characters
            $Result = "";   # for characters we've included
            $QuoteChar = ""; # quote character in use

            # start in the 'text state'
            $State = $S_Text;

            # iterate over all our tokens
            foreach ($Tokens as $Token) {
                switch ($State) {
                    # text state handles words that will be displayed
                    case $S_Text:
                        switch ($Token) {
                            # look for characters that can end a word
                            case "<":
                            case "&":
                            case " ":
                                # if we've buffered up a word
                                if ($BufLen > 0) {
                                    # and if displaying that word exceeds
                                    # our length, then we're done
                                    if ($DisplayedLength + $BufLen > $MaxLength) {
                                        break 3;
                                    }

                                    # otherwise, add the buffered word to our display
                                    $Result .= $Buffer;
                                    $DisplayedLength += $BufLen;
                                }

                                # buffer this character
                                $Buffer = $Token;
                                $BufLen = 1;

                                # if it could have been the start of a tag or an entity,
                                # change state appropriately
                                if ($Token != " ") {
                                    $State = ($Token == "<") ? $S_MaybeTag :
                                        $S_MaybeEntity;
                                }
                                break;

                            # for characters that can't break a word, just buffer them
                            default:
                                $Buffer .= $Token;
                                $BufLen++;
                                break;
                        }
                        break;

                    # MaybeTag state checks if a < began a tag or not
                    case $S_MaybeTag:
                        # tags start with alpha characters (like <b>)
                        # or a slash (like </b>)
                        if (ctype_alpha($Token) || $Token == "/") {
                            # if this was a tag, output it, output it,
                            # clear our buffer, and move to the Tag state
                            $Result .= $Buffer . $Token;
                            $Buffer = "";
                            $BufLen = 0;
                            $State = $S_Tag;
                        } else {
                            # otherwise, check if displaying this character would
                            # exceed our length.  bail if so
                            if ($DisplayedLength + 1 > $MaxLength) {
                                break 2;
                            }
                            # if not, output the characters, clear our buffer,
                            # move to the Text state
                            $Result .= $Buffer . $Token;
                            $DisplayedLength++;
                            $Buffer = "";
                            $BufLen = 0;
                            $State = $S_Text;
                        }
                        break;

                    # Tag state processes the contents of a tag
                    case $S_Tag:
                        # always output tag contents
                        $Result .= $Token;

                        # check if this is the beginning of a quoted string,
                        # changing state appropriately if so
                        if ($Token == "\"" || $Token == "'") {
                            $QuoteChar = $Token;
                            $State = $S_Quote;
                        } elseif ($Token == ">") {
                            # if this is the end of the tag, go back to Text state
                            $State = $S_Text;
                        }
                        break;

                    # Quote state processes quoted attributes
                    case $S_Quote:
                        # always output quote contents
                        $Result .= $Token;

                        # if we've found the matching quote character,
                        # return to the Tag state
                        if ($Token == $QuoteChar) {
                            $State = $S_Tag;
                        }
                        break;

                    # MaybeEntity decides if we're enjoying an HTML entity
                    # or just an ampersand
                    case $S_MaybeEntity:
                        # buffer this token
                        $Buffer .= $Token;
                        $BufLen++;

                        # if it was a space, then we're not in an entity
                        # as they cannot contain spaces
                        if ($Token == " ") {
                            # check if we should be fone
                            if ($DisplayedLength + $BufLen > $MaxLength) {
                                break 2;
                            }
                            # if not, output the buffer, clear it, and return to Text
                            $Result .= $Buffer;
                            $DisplayedLength += $BufLen;
                            $Buffer = "";
                            $BufLen = 0;
                            $State = $S_Text;
                        } elseif ($Token == ";") {
                            # if we have &xxxx; then count that as a single character entity,
                            # output it, clear the buffer, and return to Text
                            $Result .= $Buffer;
                            $DisplayedLength++;
                            $Buffer = "";
                            $BufLen = 0;
                            $State = $S_Text;
                        } elseif ($BufLen > 8) {
                            # if this has been too many characters without a ;
                            # for it to be an entity, return to text
                            $State = $S_Text;
                        }

                        break;
                }
            }

            # tack on the ellipsis
            $Result .= "...";

            $Result = self::closeOpenTags($Result);
        }

        return $Result;
    }

    /**
     * Close any open HTML tags.
     * @param string $String Input string.
     * @return string Output with all tags closed.
     */
    public static function closeOpenTags($String): string
    {
        # list of self closing tags to exclude
        # from: https://www.w3.org/TR/html/syntax.html#void-elements
        $SelfClosingTags = [
            "area",
            "base",
            "br",
            "col",
            "embed",
            "hr",
            "img",
            "input",
            "link",
            "meta",
            "param",
            "source",
            "track",
            "wbr"
        ];
        # if our string contained HTML tags that we may need to close
        if (preg_match_all("%<(/?[a-z0-9]+)[^>]*>%i", $String, $Matches)) {
            # pull out matches for the names of tags
            $Matches = $Matches[1];

            # build up an array of open tags
            $Tags = array();
            while (($Tag = array_shift($Matches)) !== null) {
                # if tag is not self closing
                if (!in_array(strtolower($Tag), $SelfClosingTags)) {
                    # if this was not a close tag, prepend it to our array
                    if (self::substr($Tag, 0, 1) != "/") {
                        array_unshift($Tags, $Tag);
                    } else {
                        # if this tag is not self-closing, append it to our list of open tags

                        # if this was a close tag, look to see if this tag was open
                        $Tgt = array_search(self::substr($Tag, 1), $Tags);
                        if ($Tgt !== false) {
                            # if so, remove this tag from our list
                            unset($Tags[$Tgt]);
                        }
                    }
                }
            }

            # iterate over open tags, closing them as we go
            while (($Tag = array_shift($Tags)) !== null) {
                $String .= "</" . $Tag . ">";
            }
        }

        return $String;
    }

    /**
     * Multibyte-aware (if supported in PHP) version of substr().
     * (Consult PHP documentation for arguments and return value.)
     */
    public static function substr(): string
    {
        return self::callMbStringFuncIfAvailable(__FUNCTION__, func_get_args(), 3);
    }

    /**
     * Multibyte-aware (if supported in PHP) version of strpos().
     * (Consult PHP documentation for arguments and return value.)
     * @return int|false
     */
    public static function strpos()
    {
        return self::callMbStringFuncIfAvailable(__FUNCTION__, func_get_args(), 3);
    }

    /**
     * Multibyte-aware (if supported in PHP) version of strrpos().
     * (Consult PHP documentation for arguments and return value.)
     * @return int|false
     */
    public static function strrpos()
    {
        return self::callMbStringFuncIfAvailable(__FUNCTION__, func_get_args(), 3);
    }

    /**
     * Multibyte-aware (if supported in PHP) version of strlen().
     * (Consult PHP documentation for arguments and return value.)
     */
    public static function strlen(): int
    {
        return self::callMbStringFuncIfAvailable(__FUNCTION__, func_get_args(), 1);
    }

    /**
     * Encode string to be written out in XML as CDATA.  Starting and ending
     * CDATA character sequences are added, and any escaping needed is done
     * by breaking up any CDATA terminator sequences by inserting characters
     * to stop and start the current CDATA section.
     * @param string $String String to be encoded.
     * @return string Encoded string.
     */
    public static function encodeStringForCdata(string $String): string
    {
        return "<![CDATA[" . str_replace("]]>", "]]]]><![CDATA[>", $String) . "]]>";
    }

    /**
     * Perform compare and return value appropriate for sort function callbacks.
     * @param mixed $A First value to compare.
     * @param mixed $B Second value to compare.
     * @return int 0 if values are equal, -1 if A is less than B, or 1 if B is
     *       greater than A.
     */
    public static function sortCompare($A, $B): int
    {
        return $A <=> $B;
    }

    /**
     * Determine if a date string suitable for use with strtotime() is
     * relative to now in some way or reflects an absolute date-time.
     * @param string $TimeString String to test.
     * @return bool TRUE when $TimeString is relative, FALSE otherwise.
     */
    public static function isRelativeDateString(string $TimeString) : bool
    {
        return strtotime($TimeString, 0) != strtotime($TimeString, 2);
    }

    /**
     * Look up the GPS coordinates for a US ZIP code.  Database of GPS
     * coordinates used was drawn from Census 2010. See the "Zip Code
     * Tabulation Areas" section on
     * https://www.census.gov/geo/maps-data/data/gazetteer2010.html for
     * the original source file.  The version used here has been cut
     * down to columns 1, 8, and 9 from that source.
     * @param int $Zip Zip code to look up.
     * @return array|false Having members "Lat" and "Lng" on successful
     * lookup, false otherwise
     * @throws Exception When coordinates file cannot be opened.
     */
    public static function getLatLngForZipCode(int $Zip)
    {
        static $ZipCache = array();

        # if we don't have a cached value for this zip, look one up
        if (!isset($ZipCache[$Zip])) {
            # try to open our zip code database
            $FHandle = fopen(dirname(__FILE__) . "/StdLib--ZipCodeCoords.txt", "r");

            # if we couldn't open the file, we can't look up a value
            if ($FHandle === false) {
                throw new Exception("Unable to open zip code coordinates file");
            }

            # iterate over our database until we find the desired zip
            # or run out of database
            while (($Line = fgetcsv($FHandle, 0, "\t", "\"", "\\")) !== false) {
                if ($Line[0] == $Zip) {
                    $ZipCache[$Zip] = array(
                        "Lat" => $Line[1], "Lng" => $Line[2]
                    );
                    break;
                }
            }

            # if we've scanned the entire file and have no coords for
            # this zip, cache a failure
            if (!isset($ZipCache[$Zip])) {
                $ZipCache[$Zip] = false;
            }
        }

        # hand back cached value
        return $ZipCache[$Zip];
    }

    /**
     * Compute the distance between two US ZIP codes.
     * @param int $ZipA First zip code.
     * @param int $ZipB Second zip code.
     * @return float|false Distance in Km between the two zip codes or false
     *     if either zip could not be found
     */
    public static function zipCodeDistance(int $ZipA, int $ZipB)
    {

        $FirstPoint = self::getLatLngForZipCode($ZipA);
        $SecondPoint = self::getLatLngForZipCode($ZipB);

        # if we scanned the whole file and lack data for either of our
        # points, return null
        if ($FirstPoint === false || $SecondPoint === false) {
            return false;
        }

        return self::computeGreatCircleDistance(
            $FirstPoint["Lat"],
            $FirstPoint["Lng"],
            $SecondPoint["Lat"],
            $SecondPoint["Lng"]
        );
    }

    /**
     * Computes the distance in kilometers between two points, assuming a
     * spherical earth.
     * @param float $LatSrc Latitude of the source coordinate.
     * @param float $LonSrc Longitude of the source coordinate.
     * @param float $LatDst Latitude of the destination coordinate.
     * @param float $LonDst Longitude of the destination coordinate.
     * @return float Distance in miles between the two points.
     */
    public static function computeGreatCircleDistance(
        float $LatSrc,
        float $LonSrc,
        float $LatDst,
        float $LonDst
    ): float {
        # See http://en.wikipedia.org/wiki/Great-circle_distance

        # Convert it all to Radians
        $Ps = deg2rad($LatSrc);
        $Ls = deg2rad($LonSrc);
        $Pf = deg2rad($LatDst);
        $Lf = deg2rad($LonDst);

        # Compute the central angle
        return 3958.756 * atan2(
            sqrt(pow(cos($Pf) * sin($Lf - $Ls), 2) +
                    pow(cos($Ps) * sin($Pf) - sin($Ps) * cos($Pf) * cos($Lf - $Ls), 2)),
            sin($Ps) * sin($Pf) + cos($Ps) * cos($Pf) * cos($Lf - $Ls)
        );
    }

    /**
     * Computes the initial angle on a course connecting two points, assuming a
     * spherical earth.
     * @param float $LatSrc Latitude of the source coordinate.
     * @param float $LonSrc Longitude of the source coordinate.
     * @param float $LatDst Latitude of the destination coordinate.
     * @param float $LonDst Longitude of the destination coordinate.
     * @return float Initial angle on a course connecting two points.
     */
    public static function computeBearing(
        float $LatSrc,
        float $LonSrc,
        float $LatDst,
        float $LonDst
    ): float {
        # See http://mathforum.org/library/drmath/view/55417.html

        # Convert angles to radians
        $Ps = deg2rad($LatSrc);
        $Ls = deg2rad($LonSrc);
        $Pf = deg2rad($LatDst);
        $Lf = deg2rad($LonDst);

        return rad2deg(atan2(
            sin($Lf - $Ls) * cos($Pf),
            cos($Ps) * sin($Pf) - sin($Ps) * cos($Pf) * cos($Lf - $Ls)
        ));
    }

    /**
     * Return all possible permutations of a given array.
     * @param array $Items Array to permutate.
     * @param array $Perms Current set of permutations, used internally for
     *       recursive calls.  (DO NOT USE)
     * @return array Array of arrays of permutations.
     */
    public static function arrayPermutations($Items, $Perms = array()): array
    {
        if (empty($Items)) {
            $Result = array($Perms);
        } else {
            $Result = array();
            for ($Index = count($Items) - 1; $Index >= 0; --$Index) {
                $NewItems = $Items;
                $NewPerms = $Perms;
                list($Segment) = array_splice($NewItems, $Index, 1);
                array_unshift($NewPerms, $Segment);
                $Result = array_merge(
                    $Result,
                    self::arrayPermutations($NewItems, $NewPerms)
                );
            }
        }
        return $Result;
    }

    /**
     * Randomize order of array elements using supplied seed value.
     * The randomized array is returned with new numerical keys.
     * @param array $Values Array to be randomized
     * @param int $Seed Seed value to use in randomization.
     * @return array Randomized array.
     */
    public static function randomizeArray(array $Values, int $Seed): array
    {
        # seed random number generator
        mt_srand($Seed);

        # generate random order with no duplicate values
        do {
            $RandomOrder = array_map(
                function ($Value) {
                    return mt_rand();
                },
                range(1, count($Values))
            );
            $RandomOrder = array_unique($RandomOrder);
        } while (count($RandomOrder) < count($Values));

        # sort record IDs based on generated random order
        $SortArray = array_combine($RandomOrder, $Values);
        ksort($SortArray);
        $Values = array_values($SortArray);

        return $Values;
    }

    /**
     * Get an array of US state names with their two-letter abbreviations as the
     * index.
     * @return array Returns an array of US state names with their two-letter
     *      abbreviations as the index.
     */
    public static function getUsStatesList(): array
    {
        return array(
            "AL" => "Alabama",
            "AK" => "Alaska",
            "AZ" => "Arizona",
            "AR" => "Arkansas",
            "CA" => "California",
            "CO" => "Colorado",
            "CT" => "Connecticut",
            "DE" => "Delaware",
            "DC" => "District of Columbia",
            "FL" => "Florida",
            "GA" => "Georgia",
            "HI" => "Hawaii",
            "ID" => "Idaho",
            "IL" => "Illinois",
            "IN" => "Indiana",
            "IA" => "Iowa",
            "KS" => "Kansas",
            "KY" => "Kentucky",
            "LA" => "Louisiana",
            "ME" => "Maine",
            "MD" => "Maryland",
            "MA" => "Massachusetts",
            "MI" => "Michigan",
            "MN" => "Minnesota",
            "MS" => "Mississippi",
            "MO" => "Missouri",
            "MT" => "Montana",
            "NE" => "Nebraska",
            "NV" => "Nevada",
            "NH" => "New Hampshire",
            "NJ" => "New Jersey",
            "NM" => "New Mexico",
            "NY" => "New York",
            "NC" => "North Carolina",
            "ND" => "North Dakota",
            "OH" => "Ohio",
            "OK" => "Oklahoma",
            "OR" => "Oregon",
            "PA" => "Pennsylvania",
            "RI" => "Rhode Island",
            "SC" => "South Carolina",
            "SD" => "South Dakota",
            "TN" => "Tennessee",
            "TX" => "Texas",
            "UT" => "Utah",
            "VT" => "Vermont",
            "VA" => "Virginia",
            "WA" => "Washington",
            "WV" => "West Virginia",
            "WI" => "Wisconsin",
            "WY" => "Wyoming",
        );
    }

    /**
     * Adjust hexadecimal RGB color by specified amount.  Pass in negative
     * values to reduce luminance or saturation.
     * @param string $Color Hex RGB color string (may have leading "#").
     * @param int $LAdjust Percentage amount to adjust luminance.
     * @param int $SAdjust Percentage amount to adjust saturation.  (OPTIONAL)
     * @return string Adjusted hex RGB color string (with leading "#").
     */
    public static function adjustHexColor(string $Color, int $LAdjust, int $SAdjust = 0): string
    {
        # split into RGB components
        list($R, $G, $B) = self::hexColorToRgb($Color);

        # convert RGB to HSL
        list($H, $S, $L) = self::rgbToHsl($R, $G, $B);

        # adjust luminance and saturation
        $L = $L + ($L * ($LAdjust / 100));
        $S = $S + ($S * ($SAdjust / 100));

        # convert HSL to RGB
        list($R, $G, $B) = self::hslToRgb(
            (int)round($H),
            (int)round($S),
            (int)round($L)
        );

        # assemble RGB components back into hex color string
        $NewColor = self::rgbToHexColor(
            (int)round($R),
            (int)round($G),
            (int)round($B)
        );

        # return new color to caller
        return $NewColor;
    }

    /**
     * Generate RGB hex color string from HSL (hue/saturation/luminance) values.
     * (Hue is the tone of the color, saturation is the amount of color, and
     * luminance is how light or dark the color is.  Adjustments to hue will
     * not be visible with a saturation value of 0.  White, black, and grey
     * (which are all saturation=0) can have any hue.)
     * @param int $H Hue value in the range 0-359 (degrees).
     * @param int $S Saturation value in the range 0-100 (percentage).
     * @param int $L Luminance value in the range 0-100 (percentage).
     * @return string Hex RGB color string (with leading "#").
     */
    public static function hslToHexColor(int $H, int $S, int $L): string
    {
        # convert HSL to RGB
        list($R, $G, $B) = self::hslToRgb($H, $S, $L);

        # assemble RGB components into hex color string
        $HexColor = self::rgbToHexColor($R, $G, $B);

        # return new color to caller
        return $HexColor;
    }

    /**
     * Convert hex color string to RGB component parts.
     * @param string $Color Hex RGB color string (may have leading "#").
     * @return array RGB values in the range 0-255, in the order: R, G, B.
     */
    public static function hexColorToRgb(string $Color): array
    {
        $Color = str_replace("#", "", $Color);
        $Pieces = str_split($Color, 2);
        $R = (int)hexdec($Pieces[0]);
        $G = (int)hexdec($Pieces[1]);
        $B = (int)hexdec($Pieces[2]);
        return [$R, $G, $B];
    }

    /**
     * Assemble RGB component parts into hex color string.
     * @param int $R Red value in the range 0-255.
     * @param int $G Green value in the range 0-255.
     * @param int $B Blue value in the range 0-255.
     * @return string Hex RGB color string (with leading "#").
     */
    public static function rgbToHexColor(int $R, int $G, int $B): string
    {
        $FormFunc = function ($Comp) {
            return str_pad(strtoupper(dechex($Comp)), 2, "0", STR_PAD_LEFT);
        };
        $HexColor = "#".$FormFunc($R).$FormFunc($G).$FormFunc($B);
        return $HexColor;
    }

    /**
     * Get name (string) for constant.  If there are multiple constants
     * defined with the same value, the first constant found with a name that
     * matches the prefix (if supplied) is returned.
     * @param mixed $ClassName Class name or object.
     * @param mixed $Value Constant value.
     * @param string $Prefix Prefix to look for at beginning of name.  Needed
     *       when there may be multiple constants with the same value.  (OPTIONAL)
     * @return string|null Constant name or null if no matching value found.
     */
    public static function getConstantName($ClassName, $Value, ?string $Prefix = null): ?string
    {
        static $Constants;

        # retrieve all constants for class
        if (is_object($ClassName)) {
            $ClassName = get_class($ClassName);
        }
        if (!isset($Constants[$ClassName])) {
            $Reflect = new ReflectionClass($ClassName);
            $Constants[$ClassName] = $Reflect->getConstants();
        }

        # for each constant
        foreach ($Constants[$ClassName] as $CName => $CValue) {
            # if value matches and prefix (if supplied) matches
            if (($CValue == $Value) &&
                (($Prefix === null) || (strpos($CName, $Prefix) === 0))) {
                # return name to caller
                return $CName;
            }
        }

        # report to caller that no matching constant was found
        return null;
    }

    /**
     * Validate the given string is a valid XHTML according to the official
     * XSD from the W3C.
     * Note the provided XHTML will be wrapped within <body> tags and other
     * surrounding necessary tags (<xml>, <html>, etc) before being validated.
     * You can specify an array of regex to match error meesages you want to
     * ignore. For example, "/The attribute '\w+' is not allowed/" will match
     * and ignore all error messages about invalid element attribute.
     * @param string $Xhtml XHTML to validate.
     * @param array $ErrorsToIgnore An array of error message regex to filter out
     *      (OPTIONAL).
     * @return array Any errors found (as LibXMLError objects), or empty if no errors.
     */
    public static function validateXhtml(string $Xhtml, array $ErrorsToIgnore = []): array
    {
        $UseInternalErrors = libxml_use_internal_errors(true);

        # wrap and validate the XHTML
        $Xhtml = '<?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE html
                PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
                "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
            <head><title>Title</title></head><body>'.$Xhtml.'</body></html>';

        $XsdPath = dirname(__FILE__) . "/xsd/xhtml1-strict.xsd";
        $DOM = new DOMDocument();
        $DOM->loadXML($Xhtml);
        $DOM->schemaValidate($XsdPath);

        # filter out errors as required
        $Errors = libxml_get_errors();
        $ErrorFilter = function ($Error) use ($ErrorsToIgnore) {
            foreach ($ErrorsToIgnore as $MsgPattern) {
                if (preg_match($MsgPattern, $Error->message)) {
                    return false;
                }
            }
            return true;
        };
        $Errors = array_filter($Errors, $ErrorFilter);

        libxml_clear_errors();
        libxml_use_internal_errors($UseInternalErrors);
        return $Errors;
    }

    /**
     * Form supplied XML document with appropriate indentation.
     * @param string $XmlToFormat XML string.
     * @return string|false Formatted XML or FALSE if an error occurred.
     */
    public static function formatXmlDocumentNicely(string $XmlToFormat)
    {
        $DomDoc = new \DOMDocument('1.0');
        $DomDoc->preserveWhiteSpace = false;
        $DomDoc->formatOutput = true;
        if (@$DomDoc->loadXML($XmlToFormat) === false) {
            return false;
        }
        return $DomDoc->saveXML();
    }

    /**
     * Convert a hex color string (e.g., #FF00FF") to a css 'rgba(' format color.
     * @param string $Hex Color to convert.
     * @param float $Opacity Opacity (OPTIONAL, default 1).
     * @return string Color in rgba() format.
     * @throws Exception If incoming hex color is not either three or six chars long.
     */
    public static function hexToRgba(string $Hex, float $Opacity = 1): string
    {
        $Hex = preg_replace('/[^a-fA-F0-9]/', "", $Hex);

        if (strlen($Hex) == 6) {
            $Color["R"] = hexdec($Hex[0] . $Hex[1]);
            $Color["G"] = hexdec($Hex[2] . $Hex[3]);
            $Color["B"] = hexdec($Hex[4] . $Hex[5]);
        } elseif (strlen($Hex) == 3) {
            $Color["R"] = hexdec($Hex[0]);
            $Color["G"] = hexdec($Hex[1]);
            $Color["B"] = hexdec($Hex[2]);
        } else {
            throw new Exception("Unexpected hex color length.");
        }

        return "rgba("
            . $Color["R"] . ","
            . $Color["G"] . ","
            . $Color["B"] . ","
            . $Opacity . ")";
    }

    /**
     * Hide email address by showing only the first and last letter of either side of the @,
     *      replacing all other characters with asterisks
     * @param string $EmailAddress email address to obfuscate
     * @return string obfuscated email address
     */
    public static function obfuscateEmailAddress(string $EmailAddress): string
    {
        if (!filter_var($EmailAddress, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address passed to StdLib::obfuscateEmailAddress()");
        }

        $EmailSplit = explode("@", $EmailAddress);
        $Local = $EmailSplit[0];
        $LocalLen = strlen($Local);
        $Domain = $EmailSplit[1];
        $DomainLen = strlen($Domain);
        $LocalConstruct = ($LocalLen == 1) ? "*" :
            $Local[0].str_repeat("*", $LocalLen - 2).$Local[$LocalLen - 1];
        $DomainConstruct = $Domain[0].str_repeat("*", $DomainLen - 2).$Domain[$DomainLen - 1];
        return $LocalConstruct."@".$DomainConstruct;
    }

    /**
     * Generate a list of alternative URLs that are probably synonyms for a provided URL.
     * @param string $Url Source URL.
     * @return array List of probable synonyms including the original Url provided.
     */
    public static function getPossibleSynonymsForUrl(string $Url): array
    {
        # parse the url into components
        $UrlParts = parse_url($Url);
        if (($UrlParts === false)
                || !isset($UrlParts["scheme"])
                || !isset($UrlParts["host"])) {
            return [ $Url ];
        }

        # try both http and https, preferring the one in the source URL
        $Schemes = [
            $UrlParts["scheme"],
            ($UrlParts["scheme"] == "http") ? "https" : "http",
        ];

        # try the host both with and without a leading www, preferring the one
        # given in the source URL
        $Hosts = [
            $UrlParts["host"],
        ];
        if (strpos($UrlParts["host"], "www.") === 0) {
            $Hosts[] = preg_replace(
                "%^www\.%",
                "",
                $UrlParts["host"]
            );
        }

        # also construct upper and lower case versions of the hostnames
        $HostsNew = [];
        foreach ($Hosts as $Host) {
            $HostsNew[] = $Host;
            $HostsNew[] = strtolower($Host);
            $HostsNew[] = strtoupper($Host);
        }
        $Hosts = $HostsNew;

        if (!isset($UrlParts["path"])) {
            $UrlParts["path"] = "/";
        }

        # construct a number of alternate paths
        $Paths = [
            $UrlParts["path"],
            # strip trailing slash
            preg_replace(
                "%/$%",
                "",
                $UrlParts["path"]
            ),
            # stripping trailing index.EXT
            preg_replace(
                "%index\.[a-z]+$%i",
                "",
                $UrlParts["path"]
            ),
            # strip trailing /index.EXT
            preg_replace(
                "%/index\.[a-z]+$%i",
                "",
                $UrlParts["path"]
            ),
        ];

        # if path ends with a slash, append several flavors of index.EXT
        if (substr($UrlParts["path"], -1) == "/") {
            foreach (["html", "htm", "php", "cfm", "shtml", "asp", "jsp", "aspx"] as $Ext) {
                $Paths[] = "/index.".$Ext;
                $Paths[] = "/index.".strtoupper($Ext);
                $Paths[] = "/INDEX.".$Ext;
                $Paths[] = "/INDEX.".strtoupper($Ext);
            }
        }

        # start with the exact URL we were provided
        $UrlList = [
            $Url,
        ];

        # generate and add alternates
        foreach ($Schemes as $Scheme) {
            foreach ($Hosts as $Host) {
                foreach ($Paths as $Path) {
                    $AltUrl = $Scheme."://".$Host.$Path;
                    if (!in_array($AltUrl, $UrlList)) {
                        $UrlList[] = $AltUrl;
                    }
                }
            }
        }

        return $UrlList;
    }

    /**
     * Get PHP memory limit in bytes.  This is necessary because the PHP
     * configuration setting can be in "shorthand" (e.g. "16M").
     * @return int PHP memory limit in bytes.
     */
    public static function getPhpMemoryLimit(): int
    {
        $Setting = ini_get("memory_limit");
        if ($Setting == false) {
            throw new Exception("Unable to retrieve memory_limit PHP setting.");
        }
        return self::convertPhpIniSizeToBytes($Setting);
    }

    /**
     * Get the maximum size for a file upload in bytes.  This is
     * necessary because the PHP configuration setting can be in
     * "shorthand" (e.g. "16M").
     * @return int Max upload size in bytes.
     */
    public static function getPhpMaxUploadSize(): int
    {
        return min(
            self::convertPhpIniSizeToBytes(
                (string)ini_get("post_max_size")
            ),
            self::convertPhpIniSizeToBytes(
                (string)ini_get("upload_max_filesize")
            )
        );
    }

    /**
     * Convert an abbreviated size from php.ini (e.g., 2g) to a number
     * of bytes.
     * @param string $Size Size from php.ini.
     * @return int Number of bytes.
     */
    public static function convertPhpIniSizeToBytes(string $Size): int
    {
        $Str = strtoupper($Size);

        # trim off 'B' suffix for KB/MB/GB
        if (substr($Str, -1) == "B") {
            $Str = substr($Str, 0, strlen($Str) - 1);
        }

        # pull out the numeric part of our setting
        $Val = intval($Str);

        # adjust it based on the units
        switch (substr($Str, -1)) {
            case "G":
                $Val *= 1073741824;
                break;

            case "M":
                $Val *= 1048576;
                break;

            case "K":
                $Val *= 1024;
                break;

            default:
                break;
        }

        return $Val;
    }

    /**
     * Retrieve the host name for the given IP address. Returns the IP address
     * if no host name can be found.  Host names are cached for CACHED_DATA_TTL
     * time.
     * @param string|null $IpAddress IP address.  (OPTIONAL, if not supplied
     *      then $_SERVER["REMOTE_ADDR"] is used)
     * @return string Host name, or IP address if no host name available for IP.
     */
    public static function getHostName(?string $IpAddress = null): string
    {
        if ($IpAddress === null) {
            $IpAddress = $_SERVER["REMOTE_ADDR"];
        }

        $Cache = self::getCache();
        $CacheKey = __FUNCTION__."-"
                .str_replace(DataCache::CHARS_NOT_ALLOWED_IN_KEYS, "_", $IpAddress);
        $HostName = $Cache->get($CacheKey);

        if ($HostName === null) {
            if ($IpAddress == "::1") {
                $HostName = "localhost";
            } else {
                $IpAddressAppearsValid = preg_match(
                    "/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/",
                    $IpAddress
                );
                if ($IpAddressAppearsValid) {
                    $HostName = gethostbyaddr($IpAddress);
                } else {
                    $HostName = $IpAddress;
                }
            }
            $Cache->set($CacheKey, $HostName, self::CACHED_DATA_TTL);
        }
        return $HostName;
    }

    /**
     * Get number of CPU cores for current server, if available.
     * @return int|null Number of cores or NULL if number could not be determined.
     */
    public static function getNumberOfCpuCores(): ?int
    {
        $OSName = strtoupper(substr(PHP_OS, 0, 3));
        switch ($OSName) {
            case "LIN":     # Linux
                # try using "nproc"
                $CmdOutput = shell_exec("nproc 2> /dev/null");
                if (is_string($CmdOutput)) {
                    $CoreCount = trim($CmdOutput);
                    if (ctype_digit($CoreCount) && ((int)$CoreCount > 0)) {
                        return (int)$CoreCount;
                    }
                }

                # try parsing count from /proc/cpuinfo
                if (is_readable("/proc/cpuinfo")) {
                    $CpuInfo = file_get_contents("/proc/cpuinfo");
                    if ($CpuInfo !== false) {
                        preg_match_all('/^processor\s+:\s+\d+/m', $CpuInfo, $Matches);
                        return count($Matches[0]);
                    }
                }

                # report that we could not determine count
                return null;

            case "DAR":     # MacOS (Darwin)
                # try using "sysctl"
                $CmdOutput = shell_exec("sysctl -n hw.ncpu 2> /dev/null");
                if (is_string($CmdOutput)) {
                    $CoreCount = trim($CmdOutput);
                    if (ctype_digit($CoreCount) && ((int)$CoreCount > 0)) {
                        return (int)$CoreCount;
                    }
                }

                # report that we could not determine count
                return null;

            case "WIN":     # Windows
                # try using environment variable
                $CoreCount = getenv("NUMBER_OF_PROCESSORS");
                if (ctype_digit($CoreCount) && ((int)$CoreCount > 0)) {
                    return (int)$CoreCount;
                }

                # try using "wmic"
                $CpuInfo = shell_exec("wmic cpu get NumberOfCores 2>NUL");
                if ($CpuInfo) {
                    # first line is a header and rest should contain core counts
                    $Lines = array_filter(array_map("trim", explode("\n", $CpuInfo)));

                    # sum core counts
                    $LinesWithCounts = array_values(array_filter(
                        $Lines,
                        function ($Line) {
                            return ctype_digit($Line);
                        }
                    ));
                    if (count($LinesWithCounts)) {
                        $CoreCount = 0;
                        foreach ($LinesWithCounts as $Line) {
                            $CoreCount += (int)$Line;
                        }
                    }
                    return ($CoreCount > 0) ? (int)$CoreCount : null;
                }

                # report that we could not determine count
                return null;

            default:
                return null;
        }
    }

    /**
     * Get (numerical) file mode.
     * @param string $FileName Name of file for which to retrieve mode.
     * @return int File mode.
     * @throws InvalidArgumentException If status could not be check on specified file.
     */
    public static function getFileMode(string $FileName): int
    {
        $Stats = @stat($FileName);
        if ($Stats === false) {
            throw new InvalidArgumentException("Could not stat file \"".$FileName."\".");
        }
        return $Stats["mode"] & 0777;
    }

    /**
     * Recursively delete directory tree or file.
     * @param string $DirName Directory or file name.
     * @return bool TRUE if successful or FALSE if unsuccessful.
     */
    public static function deleteDirectoryTree(string $DirName): bool
    {
        if (is_dir($DirName)) {
            $DirEntries = @glob($DirName."/*");
            if ($DirEntries === false) {
                return false;
            }
            foreach ($DirEntries as $DirEntry) {
                if (self::deleteDirectoryTree($DirEntry) == false) {
                    return false;
                }
            }
            return @rmdir($DirName);
        } else {
            return @unlink($DirName);
        }
    }


    /**
     * Prune a cache directory to a specified maximum number of files
     * and maximum size, also removing files older than a specified
     * maximum age.
     * @param string $Path Path to cache directory.
     * @param int $MaxAge Maximum file age in seconds or zero for no maximum age.
     * @param int $MaxEntries Maximum number of files to retain in the cache.
     * @param int $MaxSize Maximum size of the cache in MB.
     * @return int Number of files deleted.
     * @throws InvalidArgumentException if $Path is not a directory or cannot
     *     be written.
     * @throws InvalidArgumentException if $MaxAage is negative.
     * @throws InvalidArgumentException if $MaxEntries is negative.
     * @throws InvalidArgumentException if $MaxSize is negative.
     * @throws Exception when files in the cache cannot be removed.
     */
    public static function pruneFileCache(
        string $Path,
        int $MaxAge,
        int $MaxEntries,
        int $MaxSize
    ) : int {

        if (!is_dir($Path)) {
            throw new InvalidArgumentException(
                $Path." is not a directory."
            );
        }

        if (!is_writable($Path)) {
            throw new InvalidArgumentException(
                $Path." is not writeable."
            );
        }

        if ($MaxAge < 0) {
            throw new InvalidArgumentException(
                "Maximum age cannot be negative."
            );
        }

        if ($MaxEntries < 0) {
            throw new InvalidArgumentException(
                "Maximum number of entries cannot be negative."
            );
        }

        if ($MaxSize < 0) {
            throw new InvalidArgumentException(
                "Maximum size cannot be negative."
            );
        }


        $NumFilesPruned = 0;

        $DirEntries = scandir($Path, SCANDIR_SORT_NONE);
        if ($DirEntries === false) {
            throw new Exception("scandir() call failed.");
        }

        $Now = time();
        $TotalSize = 0;
        $FileSizes = [];
        $FileAges = [];

        # iterate over directory entries
        foreach ($DirEntries as $Entry) {
            # skip dot files
            if ($Entry[0] == ".") {
                continue;
            }

            # skip non-regular files
            $FullPath = $Path."/".$Entry;
            if (!is_file($FullPath)) {
                continue;
            }

            $FileAge = $Now - filemtime($FullPath);
            # if this file is too old, nuke it
            if ($MaxAge > 0 && $FileAge > $MaxAge) {
                $Result = unlink($FullPath);
                if ($Result === false) {
                    throw new Exception(
                        "Failed to delete file ".$FullPath
                    );
                }
                $NumFilesPruned++;
                continue;
            }

            # otherwise, note its size and age
            $FileSize = filesize($FullPath);
            $TotalSize += $FileSize;
            $FileAges[$FullPath] = $FileAge;
            $FileSizes[$FullPath] = $FileSize;
        }

        # sort files from oldest to newest
        arsort($FileAges, SORT_NUMERIC);

        # if we have too many items in cache, delete some of the oldest ones
        if (count($FileAges) > $MaxEntries) {
            $NumToDelete = count($FileAges) - $MaxEntries;
            $FilesToDelete = array_slice(array_keys($FileAges), 0, $NumToDelete);
            foreach ($FilesToDelete as $File) {
                $TotalSize -= $FileSizes[$File];
                unlink($File);
                $NumFilesPruned++;
            }

            $FileAges = array_slice($FileAges, $NumToDelete, null, true);
        }

        # check if the cache is too large

        if ($TotalSize < $MaxSize) {
            # if not, nothing to do
            return $NumFilesPruned;
        }

        # if so, prune the oldest items
        foreach (array_keys($FileAges) as $File) {
            unlink($File);
            $NumFilesPruned++;
            $TotalSize -= $FileSizes[$File];
            if ($TotalSize < $MaxSize) {
                break;
            }
        }

        return $NumFilesPruned;
    }

    /**
     * Get current amount of free memory.  The value returned is a "best
     * guess" based on reported memory usage.
     * @return int Number of bytes.
     */
    public static function getFreeMemory(): int
    {
        return self::getPhpMemoryLimit() - memory_get_usage();
    }

    /**
     * Get current percentage of free memory.  The value returned is based
     * on a "best guess" from reported memory usage.
     * @return float Estimated percentage free.
     */
    public static function getPercentFreeMemory(): float
    {
        return (self::getFreeMemory() / self::getPhpMemoryLimit()) * 100;
    }

    /** Format to feed to date() to get SQL-compatible date/time string. */
    const SQL_DATE_FORMAT = "Y-m-d H:i:s";

    /** Regex to match SQL-compatible date/time strings. */
    const SQL_DATE_REGEX = "%^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$%";


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private static $PluralizePatterns = array(
        '/(quiz)$/i' => "$1zes",
        '/^(ox)$/i' => "$1en",
        '/([m|l])ouse$/i' => "$1ice",
        '/(matr|vert|ind)ix|ex$/i' => "$1ices",
        '/(x|ch|ss|sh)$/i' => "$1es",
        '/([^aeiouy]|qu)y$/i' => "$1ies",
        '/(hive)$/i' => "$1s",
        '/(?:([^f])fe|([lr])f)$/i' => "$1$2ves",
        '/(shea|lea|loa|thie)f$/i' => "$1ves",
        '/sis$/i' => "ses",
        '/([ti])um$/i' => "$1a",
        '/(tomat|potat|ech|her|vet)o$/i' => "$1oes",
        '/(bu)s$/i' => "$1ses",
        '/(alias)$/i' => "$1es",
        '/(octop)us$/i' => "$1i",
        '/(ax|test)is$/i' => "$1es",
        '/(us)$/i' => "$1es",
        '/s$/i' => "s",
        '/$/' => "s"
    );
    private static $SingularizePatterns = array(
        '/(quiz)zes$/i' => "$1",
        '/(matr)ices$/i' => "$1ix",
        '/(vert|ind)ices$/i' => "$1ex",
        '/^(ox)en$/i' => "$1",
        '/(alias)es$/i' => "$1",
        '/(octop|vir)i$/i' => "$1us",
        '/(cris|ax|test)es$/i' => "$1is",
        '/(shoe)s$/i' => "$1",
        '/(o)es$/i' => "$1",
        '/(bus)es$/i' => "$1",
        '/([m|l])ice$/i' => "$1ouse",
        '/(x|ch|ss|sh)es$/i' => "$1",
        '/(m)ovies$/i' => "$1ovie",
        '/(s)eries$/i' => "$1eries",
        '/([^aeiouy]|qu)ies$/i' => "$1y",
        '/([lr])ves$/i' => "$1f",
        '/(tive)s$/i' => "$1",
        '/(hive)s$/i' => "$1",
        '/(li|wi|kni)ves$/i' => "$1fe",
        '/(shea|loa|lea|thie)ves$/i' => "$1f",
        '/(^analy)ses$/i' => "$1sis",
        '/((a)naly|(b)a|(d)iagno|(p)arenthe|(p)rogno|(s)ynop|(t)he)ses$/i' => "$1$2sis",
        '/([ti])a$/i' => "$1um",
        '/(n)ews$/i' => "$1ews",
        '/(h|bl)ouses$/i' => "$1ouse",
        '/(corpse)s$/i' => "$1",
        '/(us)es$/i' => "$1",
        '/s$/i' => ""
    );
    private static $IrregularWords = array(
        'move' => 'moves',
        'foot' => 'feet',
        'goose' => 'geese',
        'sex' => 'sexes',
        'child' => 'children',
        'man' => 'men',
        'tooth' => 'teeth',
        'person' => 'people'
    );
    private static $UncountableWords = array(
        'sheep',
        'fish',
        'deer',
        'series',
        'species',
        'money',
        'rice',
        'information',
        'equipment'
    );

    /**
     * Get a DataCache for StdLib. It is not user-specific.
     * @return DataCache Cache
     */
    private static function getCache(): DataCache
    {
        static $Cache = null;
        if ($Cache === null) {
            $Cache = new DataCache("ScoutLib-StdLib-");
        }
        return $Cache;
    }

    /**
     * Call PHP multibyte string function if available, otherwise call plain
     * version of function.
     * @param string $Func Name of plain function.
     * @param array $Args Argument list to pass to function.
     * @param int $NumPlainArgs Number of arguments to plain version of function.
     * @return mixed
     */
    private static function callMbStringFuncIfAvailable(
        string $Func,
        array $Args,
        int $NumPlainArgs
    ) {
        if (is_callable("mb_".$Func)) {
            $FuncToCall = "mb_".$Func;
        } else {
            if (count($Args) > $NumPlainArgs) {
                throw new Exception(
                    "Function mb_".$Func."() required but not available."
                );
            }
            $FuncToCall = $Func;
        }
        if (!is_callable($FuncToCall)) {
            throw new InvalidArgumentException("Uncallable function \""
                    .$Func."\" specified.");
        }
        return call_user_func_array($FuncToCall, array_values($Args));
    }

    /**
     * Convert RGB (red/green/blue) values to HSL (hue/saturation/luminance).
     * Formulas adapted from:
     *   http://www.niwa.nu/2013/05/math-behind-colorspace-conversions-rgb-hsl/
     * @param int $R Red value in the range 0-255.
     * @param int $G Green value in the range 0-255.
     * @param int $B Blue value in the range 0-255.
     * @return array HSL values in the order H, S, and L.  Hue is in degrees
     *      (0-359), and saturation and luminance are percentages (0-100).
     * @throws Exception If max RGB value does not match expected value.
     */
    private static function rgbToHsl(int $R, int $G, int $B): array
    {
        # ensure incoming values are within range
        $R = max(min($R, 255), 0);
        $G = max(min($G, 255), 0);
        $B = max(min($B, 255), 0);

        # scale RGB values to range of 0-1
        $R /= 255;
        $G /= 255;
        $B /= 255;

        # determine RGB range
        $MinVal = min($R, $G, $B);
        $MaxVal = max($R, $G, $B);
        $MaxDif = $MaxVal - $MinVal;

        # calculate luminance
        $L = ($MinVal + $MaxVal) / 2.0;
        $L *= 100;

        # if RGB are all equal
        if ($MinVal == $MaxVal) {
            # no hue or saturation
            $S = 0;
            $H = 0;
        } else {
            # calculate saturation
            if ($L < 50) {
                $S = $MaxDif / ($MinVal + $MaxVal);
            } else {
                $S = $MaxDif / ((2.0 - $MaxVal) - $MinVal);
            }
            $S *= 100;

            # calculate hue
            switch ($MaxVal) {
                case $R:
                    $H = ($G - $B) / $MaxDif;
                    break;

                case $G:
                    $H = ($B - $R) / $MaxDif;
                    $H += 2.0;
                    break;

                case $B:
                    $H = ($R - $G) / $MaxDif;
                    $H += 4.0;
                    break;

                default:
                    throw new Exception("Unexpected max RGB value.");
            }
            $H *= 60;
            if ($H < 0) {
                $H += 360;
            }
        }

        # return calculated values to caller
        return [$H, $S, $L];
    }

    /**
     * Convert HSL (hue/saturation/luminance) to RGB (red/green/blue) values.
     * Formula adapted from:
     *   http://www.niwa.nu/2013/05/math-behind-colorspace-conversions-rgb-hsl/
     * @param int $H Hue value in the range 0-359 (degrees).
     * @param int $S Saturation value in the range 0-100 (percentage).
     * @param int $L Luminance value in the range 0-100 (percentage).
     * @return array Integer RGB values in the range 0-255, in the order: R, G, B.
     */
    private static function hslToRgb(int $H, int $S, int $L): array
    {
        # ensure incoming values are within range
        $H = max(min($H, 360), 0);
        $S = max(min($S, 100), 0);
        $L = max(min($L, 100), 0);

        # scale HSL to range of 0-1
        $H /= 360;
        $S /= 100;
        $L /= 100;

        # if no saturation
        if ($S == 0) {
            # result is greyscale, with equal RGB values based on luminance
            $R = $L * 255;
            $G = $R;
            $B = $R;
        } else {
            # calculate RGB
            $R = self::hslToRgbComponent(($H + (1 / 3)), $S, $L) * 255;
            $G = self::hslToRgbComponent($H, $S, $L) * 255;
            $B = self::hslToRgbComponent(($H - (1 / 3)), $S, $L) * 255;
        }

        # return calculated values to caller
        return [(int)$R, (int)$G, (int)$B];
    }

    /**
     * Convert HSL to RGB component (red, green, or blue).  For red, 1/3
     * should be added to the hue passed in, and for blue, 1/3 should be
     * subtracted.  Formulas adapted from:
     *   http://www.easyrgb.com/en/math.php
     * @param float $H Hue value in the range 0-1.
     * @param float $S Saturation value in the range 0-1.
     * @param float $L Luminance value in the range 0-1.
     * @return float RGB component value in the range 0-1;
     */
    private static function hslToRgbComponent(float $H, float $S, float $L): float
    {
        # ensure hue is in the range 0-1
        if ($H < 0) {
            $H += 1;
        } elseif ($H > 1) {
            $H -= 1;
        }

        # calculate saturation/luminance adjustments
        if ($L < 0.5) {
            $Adj1 = (1 + $S) * $L;
        } else {
            $Adj1 = ($S + $L) - ($S * $L);
        }
        $Adj2 = ($L * 2) - $Adj1;

        # calculate RGB component and return it to caller
        if (($H * 6) < 1) {
            return (($Adj1 - $Adj2) * $H * 6) + $Adj2;
        } elseif (($H * 2) < 1) {
            return $Adj1;
        } elseif (($H * 3) < 2) {
            return (($Adj1 - $Adj2) * ((2 / 3) - $H) * 6) + $Adj2;
        } else {
            return $Adj2;
        }
    }
}
