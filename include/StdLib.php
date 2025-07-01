<?PHP
#
#   FILE:  StdLib.php (deprecated standard library functions)
#
#   Part of the Metavus digital collections platform
#   Copyright 2006-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# PLEASE NOTE:  For the most part, the functions in this file are DEPRECATED,
#   and should not be used in any new code.

use Metavus\InterfaceConfiguration;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;

/**
 * Get the path to the interface directory that contains the fast user rating
 * images/icons, if any.
 * @return string the path to the interface containing the rating stars
 */
function GetFastRatingInterfaceDirectory()
{
    $AF = ApplicationFramework::getInstance();
    if (preg_match(
        '/(.*)\/images\/StarRating--1_0\.[.A-Z0-9]*gif$/',
        $AF->gUIFile("StarRating--1_0.gif"),
        $Matches
    )) {
        return $AF->activeUserInterface();
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
