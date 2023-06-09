<?PHP
#
#   FILE:  StdLib.php (deprecated standard library functions)
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# PLEASE NOTE:  For the most part, the functions in this file are DEPRECATED,
#   and should not be used in any new code.

use Metavus\User;
use Metavus\PrivilegeSet;
use Metavus\InterfaceConfiguration;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

/**
 * Check whether the user is authorized to view the current page. If the current
 * user does not have at least one of the specified privileges, then a hook is
 * set to cause the "Unauthorized Access" HTML file to display instead of the
 * normal HTML file.
 * @param mixed $AuthFlag Privilege required (or array of possible privileges).
 * @return bool TRUE if user has one of the specified privileges, otherwise FALSE.
 * @see CheckAuthorization_SignalHook()
*/
function CheckAuthorization($AuthFlag = null)
{
    $User = User::getCurrentUser();

    if ($AuthFlag instanceof PrivilegeSet) {
        if ($AuthFlag->MeetsRequirements($User)) {
            return true;
        }
    } else {
        $Privileges = is_array($AuthFlag) ? $AuthFlag : func_get_args();

        # if the user is logged in and no privileges were given or the user has at
        # least one of the specified privileges
        if ($User->IsLoggedIn()
            && ((is_null($AuthFlag)) || $User->HasPriv($Privileges))) {
            return true;
        }
    }

    # the user is not logged in or doesn't have at least one of the specified
    # privileges
    DisplayUnauthorizedAccessPage();
    return false;
}

/**
* Display "Unauthorized Access" HTML file.  This method is intended to be called
* from within a PHP "page" file when a page has been reached which the current
* user does not have the required privileges to access.
*/
function DisplayUnauthorizedAccessPage(): void
{
    $GLOBALS["AF"]->HookEvent(
        "EVENT_HTML_FILE_LOAD",
        "DisplayUnauthorizedAccessPage_SignalHook"
    );
}

/**
 * Hook used to display the "Unauthorized Access" page in conjunction with the
 * DisplayUnauthorizedAccessPage() function.
 * @param string $PageName Page name
 * @return array modified hook parameters
 * @see DisplayUnauthorizedAccessPage()
 */
function DisplayUnauthorizedAccessPage_SignalHook($PageName)
{
    return ["PageName" => "UnauthorizedAccess"];
}

/**
 * Get or set the title of page as displayed in the title bar of a user's
 * web browser.
 * @param string|null $NewTitle The new page title or NULL to leave it as-is
 * @param bool $IncludePortalName TRUE to automatically prepend the portal name
 * @return string the new page title, including the portal name if applicable
 */
function PageTitle($NewTitle = null, $IncludePortalName = true)
{
    static $Title;

    # save a new page title if given one
    if (!is_null($NewTitle)) {
        $Title = $NewTitle;
    }

    # the portal name should be prepended before returning the title...
    $PortalName = InterfaceConfiguration::getInstance()->getString("PortalName");
    if ($IncludePortalName && strlen($PortalName)) {
        return $PortalName . " - " . $Title;
    }

    # ... otherwise just return the page title
    return $Title;
}

/**
 * Get the path to the interface directory that contains the fast user rating
 * images/icons, if any.
 * @return string the path to the interface containing the rating stars
 */
function GetFastRatingInterfaceDirectory()
{
    if (preg_match(
        '/(.*)\/images\/StarRating--1_0\.[.A-Z0-9]*gif$/',
        $GLOBALS["AF"]->GUIFile("StarRating--1_0.gif"),
        $Matches
    )) {
        return $GLOBALS['AF']->ActiveUserInterface();
    } else {
        return "default";
    }
}

/**
 * Debugging output utility function. This should not be used in production
 * code.
 * @param string $VarName The variable name
 * @param mixed $VarValue The value of the variable
 * @see var_dump()
 */
function PrintForDebug($VarName, $VarValue): void
{
    # print the variable name
    if (PHP_SAPI !== 'cli') {
        print "<pre>";
    }
    print $VarName . ": ";

    # use PHP's built-in variable dumping function if available
    if (function_exists("var_dump")) {
        ini_set('xdebug.var_display_max_depth', "5");
        ini_set('xdebug.var_display_max_children', "256");
        ini_set('xdebug.var_display_max_data', "1024");
        ob_start();
        var_dump($VarValue);
        $DumpLine = __LINE__ - 1;
        $DumpContent = ob_get_contents();
        ob_end_clean();
        # strip out file/line inserted by xdebug
        $DumpContent = str_replace(__FILE__.":"
                .$DumpLine.":", "", $DumpContent);
        print $DumpContent;
    # otherwise use the next best thing
    } else {
        print_r($VarValue);
    }

    # print the closing tag
    if (PHP_SAPI !== 'cli') {
        print "</pre>";
    }
    print "\n";
}

/**
 * Determine whether the given URL is safe to redirect to, i.e., if a protocol
 * and host are specified, make sure the host is the same as the server's host.
 * This is meant to protect from malicious redirects
 * @param string $Url URL to check
 * @return bool TRUE if the URL is safe and FALSE otherwise
 */
function IsSafeRedirectUrl($Url)
{
    $ParsedUrl = parse_url((string)$Url);
    $Protocol = StdLib::getArrayValue($ParsedUrl, "scheme");
    $Host = StdLib::getArrayValue($ParsedUrl, "host");

    # if a protocol and host are specified, make sure the host is equal to the
    # server's host to protect from malicious redirects
    return !$Protocol || $Host == $_SERVER["SERVER_NAME"];
}

/**
 * Strips a string of any tags and attributes that are not provided as
 * exceptions. Stripping of tags or attributes can be disabled by options.
 * Uses the \f (form feed) character as a token, so this will fail in the
 * unlikely event that someone manages to get a \f character into the input
 * string to this function.
 *
 * Options are:
 *     "StripTags" => set to FALSE to disable tag stripping
 *     "StripAttributes" => set to FALSE to disable attribute stripping
 *     "Tags" => string of allowed tags, whitespace delimited (e.g., "a b i")
 *     "Attributes" => string of allowed attributes, whitespace
 *                     delimited (e.g., "href target")
 *
 * @param string $String String to parse
 * @param array $Options Options (see above)
 * @return string the parsed string
 * @see StripXSSThreats()
 * @see StripUnsafeProtocols()
 */
function StripTagsAttributes($String, $Options = [])
{
    # make sure have values for the predicate options
    if (!is_array($Options)) {
        $Options = [];
    }

    $Options["StripTags"] = StdLib::getArrayValue($Options, "StripTags", true);
    $Options["StripAttributes"] = StdLib::getArrayValue($Options, "StripAttributes", true);

    # phase 1: strip invalid tags if necessary
    if ($Options["StripTags"]) {
        $Tags = trim(StdLib::getArrayValue($Options, "Tags"));

        # escape allowed tags if any were given
        if (strlen($Tags)) {
            # strip invalid characters and ready the names for the subpattern
            $Tags = preg_replace('/[^a-z0-9 ]/i', '', $Options["Tags"]);
            $Tags = preg_replace('/\s+/', "|", $Tags);

            # and finally escape the tags
            $String = preg_replace(
                '/<('.$Tags.')(\s[^>]*)?(\/?)>|<(\/)('.$Tags.')(\s[^>]*)?>/i',
                sprintf('%c$1$2$3$4$5$6%c', 12, 12),
                (string)$String
            );
        }

        # remove all other tags and then unescape allowed tags
        $String = preg_replace('/<[^>]*>/', '', $String);
        $String = preg_replace(
            sprintf('/%c([^%c]*)%c/', 12, 12, 12),
            '<$1>',
            $String
        );
    }

    # phase 2: strip attributes if necessary
    if ($Options["StripAttributes"]) {
        $Value = StdLib::getArrayValue($Options, "Attributes");
        $Attributes = !is_null($Value) ? trim($Value) : "";

        # move all of the attributes into separate contexts for validation
        $String = preg_replace(
            '/<([a-z0-9]+)\s+([^>]*[^>\/])(\/)?>/i',
            sprintf('<$1$3>%c$2%c', 12, 12),
            $String
        );

        if (strlen($Attributes)) {
            # remove bad chars and split by whitespace
            $Attributes = preg_replace('/[^a-zA-z0-9 ]/i', '', $Options["Attributes"]);
            $Attributes = preg_split('/\s+/', $Attributes);

            $AttributesCount = count($Attributes);

            # extract each allowed attribute from its context
            for ($i = 0; $i < $AttributesCount; $i++) {
                if ($i < strlen($Attributes[$i])) {
                      $String = preg_replace(
                          sprintf('/<([A-Za-z0-9]+)(\s[^>]*[^>\/])?(\/)?>'.
                            '%c([^%c]*\s*)'.$Attributes[$i].'=("[^"]*"|\'[^\']*\')'.
                            '([^%c]*\s*)%c/i', 12, 12, 12, 12),
                          sprintf("<$1$2 ".$Attributes[$i]."=$5$3>%c$4$6%c", 12, 12),
                          $String
                      );
                } else {
                    return "";
                }
            }
        }

        # destroy all the contexts created, deleting any attributes that aren't
        # allowed, and make well-formed singleton tags
        $String = preg_replace(
            [sprintf('/%c[^%c]*%c/', 12, 12, 12), '/<([^>]+)\/>/'],
            ['', '<$1 />'],
            $String
        );
    }

    # return the string
    return $String;
}

/**
 * Strip potentially unsafe tags, attributes, and protocols from the given
 * string to remove any XSS threats.
 * @param string $String The string to strip
 * @return string the string stripped of potentially unsafe text
 * @see StripTagsAttributes()
 * @see StripUnsafeProtocols()
 */
function StripXSSThreats($String)
{
    $Options["Tags"] = "
        a abbr b blockquote br caption cite code dd dl dt del div em h1 h2 h3 h4
        h5 h6 hr i ins kbd ol ul li mark p pre q s samp small span strike strong
        sub sup table tbody td tfoot th thead time tr u var";
    $Options["Attributes"] = "href id name summary";
    return StripUnsafeProtocols(StripTagsAttributes($String, $Options));
}

/**
 * Strip any attributes with potentially unsafe protocols and data in the given
 * HTML. This does not strip out unsafe data in attributes where potentially
 * unsafe data is expected, e.g., the onclick and onhover attributes. Those
 * attributes should be removed entirely if their data cannot be trusted.
 * @param string $Html HTML to strip
 * @return string stripped HTML
 * @see StripTagsAttributes()
 */
function StripUnsafeProtocols($Html)
{
    # the attributes that potentially allow unsafe protocols, depending on the
    # user agent
    $CheckedAttributes = [
        "action",
        "cite",
        "data",
        "for",
        "formaction",
        "formtarget",
        "href",
        "poster",
        "src",
        "srcdoc",
        "target"
    ];

    # the protocols that are considered unsafe
    $UnsafeProtocols = join("|", ["data", "javascript", "vbscript"]);

    # remove unsafe protocols from each checked attribute
    foreach ($CheckedAttributes as $CheckedAttribute) {
        $Html = preg_replace(
            '/<([a-z0-9]+)\s+([^>]*)'.$CheckedAttribute
                .'\s*=\s*(["\'])\s*('.$UnsafeProtocols
                .')\s*:.*?[^\\\]\3\s?(.*?)>/i',
            '<\1 \2\5>',
            $Html
        );
    }

    # return the stripped HTML
    return $Html;
}

/**
 * Uses the default character set from the system configuration along with the
 * version-agnostic HTML special characters translation function to escape HTML
 * special characters in the given string in the same manner as htmlentities().
 * @param string $String String to translate
 * @return string the translated string
 * @see htmlentities()
 * @see htmlspecialchars()
 * @see defaulthtmlspecialchars()
 */
function defaulthtmlentities($String)
{
    $CharacterSet = InterfaceConfiguration::getInstance()->getString("DefaultCharacterSet");
    if ($CharacterSet == "") {
        $CharacterSet = "UTF-8";
    }
    return htmlentities($String ?? "", ENT_QUOTES, $CharacterSet, false);
}

/**
 * Uses the default character set from the system configuration along with the
 * version-agnostic HTML special characters translation function to escape HTML
 * special characters in the given string in the same manner as
 * htmlspecialchars().
 * @param string $String String to translate
 * @return string the translated string
 * @see htmlspecialchars()
 * @see htmlentities()
 * @see defaulthtmlentities()
 */
function defaulthtmlspecialchars($String)
{
    $CharacterSet = InterfaceConfiguration::getInstance()->getString("DefaultCharacterSet");
    if ($CharacterSet == "") {
        $CharacterSet = "UTF-8";
    }
    return htmlspecialchars($String ?? "", ENT_QUOTES, $CharacterSet, false);
}



/**
 * Converts a simpleXML element into an array. Preserves attributes and
 * everything. You can choose to get your elements either flattened, or stored
 * in a custom index that you define.
 * For example, for a given element
 * <field name="someName" type="someType"/>
 * if you choose to flatten attributes, you would get:
 * $array['field']['name'] = 'someName';
 * $array['field']['type'] = 'someType';
 * If you choose not to flatten, you get:
 * $array['field']['@attributes']['name'] = 'someName';
 * _____________________________________
 * Repeating fields are stored in indexed arrays. so for a markup such as:
 * <parent>
 * <child>a</child>
 * <child>b</child>
 * <child>c</child>
 * </parent>
 * you array would be:
 * $array['parent']['child'][0] = 'a';
 * $array['parent']['child'][1] = 'b';
 * ...And so on.
 * _____________________________________
 * @param SimpleXMLElement $Xml XML to convert
 * @param boolean $FlattenValues Whether to flatten values
 *       or to set them under a particular index.  Defaults to TRUE;
 * @param boolean $FlattenAttributes Whether to flatten attributes
 *       or to set them under a particular index. Defaults to TRUE;
 * @param boolean $FlattenChildren Whether to flatten children
 *       or to set them under a particular index. Defaults to TRUE;
 * @param string $ValueKey Index for values, in case $FlattenValues was
 *       set to FALSE. Defaults to "@value"
 * @param string $AttributesKey Index for attributes, in case
 *       $FlattenAttributes was set to FALSE. Defaults to "@attributes"
 * @param string $ChildrenKey Index for children, in case $FlattenChildren
 *       was set to FALSE. Defaults to "@children"
 * @return array The resulting array.
 */
function SimpleXMLToArray(
    $Xml,
    $FlattenValues = true,
    $FlattenAttributes = true,
    $FlattenChildren = true,
    $ValueKey = "@values",
    $AttributesKey = "@attributes",
    $ChildrenKey = "@children"
) {
    $Array = [];
    $XMLClassName = "SimpleXMLElement";
    if (!($Xml instanceof $XMLClassName)) {
        return $Array;
    }

    $Name = $Xml->getName();
    $Value = trim((string)$Xml);
    if (!strlen($Value)) {
        $Value = null;
    }

    if ($Value !== null) {
        if ($FlattenValues) {
            $Array = $Value;
        } else {
            $Array[$ValueKey] = $Value;
        }
    }

    $Children = [];
    foreach ($Xml->children() as $ElementName => $Child) {
        $Value = SimpleXMLToArray(
            $Child,
            $FlattenValues,
            $FlattenAttributes,
            $FlattenChildren,
            $ValueKey,
            $AttributesKey,
            $ChildrenKey
        );

        if (isset($Children[$ElementName])) {
            if (!isset($MultipleMembers[$ElementName])) {
                $Temp = $Children[$ElementName];
                unset($Children[$ElementName]);
                $Children[$ElementName][] = $Temp;
                $MultipleMembers[$ElementName] = true;
            }
            $Children[$ElementName][] = $Value;
        } else {
            $Children[$ElementName] = $Value;
        }
    }
    if (count($Children)) {
        if ($FlattenChildren) {
            $Array = array_merge($Array, $Children);
        } else {
            $Array[$ChildrenKey] = $Children;
        }
    }

    $Attribs = [];
    foreach ($Xml->attributes() as $Name => $Value) {
        $Attribs[$Name] = trim($Value);
    }
    if (count($Attribs)) {
        if (!$FlattenAttributes) {
            $Array[$AttributesKey] = $Attribs;
        } else {
            $Array = array_merge($Array, $Attribs);
        }
    }

    return $Array;
}

/**
 * Remove the file or directory with the given path. Recursively removes
 * directories.
 * @param string $Path Path of the file or directory to remove
 * @return bool TRUE if successful or FALSE otherwise
 */
function RemoveFromFilesystem($Path)
{
    # the path is a directory
    if (is_dir($Path)) {
        # recursively delete directories
        foreach (glob($Path."/*") as $Item) {
            if (!RemoveFromFilesystem($Item)) {
                return false;
            }
        }

        # and then remove this directory
        return @rmdir($Path);
    # the path is a file
    } else {
        return @unlink($Path);
    }
}

/**
* Convert a date range into a user-friendly printable format.
* @param string $StartDate Starting date, in any format parseable by strtotime().
* @param string $EndDate Ending date, in any format parseable by strtotime().
* @param bool $Verbose Whether to be verbose about dates.  (OPTIONAL, defaults to
*       TRUE)
* @param string $BadDateString String to display if start date appears invalid.
*       (OPTIONAL, defaults to "-")
* @return string Returns a string containing a nicely-formatted date value.
*/
function GetPrettyDateRange($StartDate, $EndDate, $Verbose = true, $BadDateString = "-")
{
    # convert dates to seconds
    $Start = strtotime($StartDate);
    $End = strtotime($EndDate);

    # return bad date string if start date was invalid
    if ($Start === false) {
        return $BadDateString;
    }

    # return pretty printed date if end date was invalid or same as start date
    if (($End === false) || ($End == $Start)) {
        return StdLib::getPrettyDate($EndDate, $Verbose, $BadDateString);
    }

    # use short or long month names based on verbosity setting
    $MChar = $Verbose ? "F" : "M";


    $AddYear = true;

    # set the date range format
    if (date("dmY", $Start) == date("dmY", $End)) {
        # if the start and end have the same day & month, use "January 1"
        $Range = date($MChar." j", $Start);
    } elseif (date("mY", $Start) == date("mY", $End)) {
        # if start and end month are the same use "January 1-10"
        $Range = date($MChar." j-", $Start).date("j", $End);
    } elseif (date("Y", $Start) == date("Y", $End)) {
        # else if start and end year are the same use "January 21 - February 3"
        $Range = date($MChar." j - ", $Start).date($MChar." j", $End);
    } else {
        # else use "December 21, 2013 - January 3, 2014"
        $Range = date($MChar." j, Y - ", $Start).date($MChar." j, Y", $End);
        $AddYear = false;
    }

    # if end year is not current year and we haven't already added it
    if ((date("Y", $End) != date("Y")) && $AddYear) {
        # add end year to date
        $Range .= date(", Y", $End);
    }

    # return pretty date range to caller
    return $Range;
}

/**
* Convert a date range into a user-friendly printable format broken into pieces,
* useful when adding a date to a page with semantic markup.
* @param string $StartDate Starting date, in any format parseable by strtotime().
* @param string $EndDate Ending date, in any format parseable by strtotime().
* @param bool $Verbose Whether to be verbose about dates.  (OPTIONAL, defaults to
*       TRUE)
* @return array|null Returns an associative array with "Start" and "End" elements,
*       each portions of a string containing a nicely-formatted date value, or
*       NULL if the date range was invalid.
*/
function GetPrettyDateRangeInParts($StartDate, $EndDate, $Verbose = true)
{
    # generate pretty date range string
    $RangeString = GetPrettyDateRange($StartDate, $EndDate, $Verbose, null);

    # return NULL if date was not valid
    if ($RangeString === null) {
        return null;
    }

    # break range string into pieces
    $Pieces = explode("-", $RangeString, 2);
    $RangeParts["Start"] = trim($Pieces[0]);
    $RangeParts["End"] = (count($Pieces) > 1) ? trim($Pieces[1]) : "";

    # return pieces to caller
    return $RangeParts;
}

/**
* Get a specified number of random alphanumeric characters.
* @param int $NumChars Number of characters to get.
* @param string $ExcludePattern PCRE pattern to exclude undesired characters
*    (OPTIONAL, default [^A-Za-z0-9]).
* @return string Random characters.
*/
function GetRandomCharacters($NumChars, $ExcludePattern = "/[^A-Za-z0-9]/")
{
    $rc = '';

    while (strlen($rc) < $NumChars) {
        # append random alphanumerics
        $rc .= preg_replace(
            $ExcludePattern,
            "",
            base64_encode(openssl_random_pseudo_bytes(3 * $NumChars))
        );
    }

    return substr($rc, 0, $NumChars);
}
