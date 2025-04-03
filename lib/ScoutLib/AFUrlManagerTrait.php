<?PHP
#
#   FILE:  AFUrlManagerTrait.php
#
#   Part of the ScoutLib application support library
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

/**
 * URL management components of top-level framework for web applications.
 */
trait AFUrlManagerTrait
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Add clean URL mapping.  This method allows a "clean URL" (usually a
     * purely structural URL that does not contain a query string and is
     * more human-friendly) to be specified and mapped to a particular page,
     * with segments of the clean URL being extracted and put into $_GET
     * variables, as if they had been in a query string.  IMPORTANT: If the
     * $Template parameter is used to automagically swap in clean URLs in page
     * output, the number of variables specified by $GetVars should be limited,
     * as X variables causes X! regular expression replacements to be performed
     * on the output.
     * @param string $Pattern Regular expression to match against clean URL,
     *      with starting and ending delimiters.
     * @param string $Page Page (P= value) to load if regular expression matches.
     * @param array $GetVars Array of $_GET variables to set using matches from
     *      regular expression, with variable names for the array indexes and
     *      variable value templates (with $N as appropriate, for captured
     *      subpatterns from matching) for the array values.  (OPTIONAL)
     * @param string $Template Template to use to insert clean URLs in
     *      HTML output.  $_GET variables value locations should be specified
     *      in the template via the variable name preceded by a "$".  (OPTIONAL)
     * @param string $Domain Domain to which to limit the clean URL.  (OPTIONAL)
     * @param int $Order Preference for when the regular expression should
     *      be applied to match against clean URLs.  Clean URLs with very
     *      broad patterns should usually specify ORDER_LAST, to avoid
     *      trumping other very-specific clean URL patterns.  (OPTIONAL,
     *       defaults to ORDER_MIDDLE)
     */
    public function addCleanUrl(
        string $Pattern,
        string $Page,
        ?array $GetVars = null,
        ?string $Template = null,
        ?string $Domain = null,
        int $Order = self::ORDER_MIDDLE
    ): void {
        # flag that output modifications need to be regenerated from clean URL info
        $this->OutputModificationsAreCurrent = false;

        # save clean URL mapping parameters
        $this->CleanUrlMappings[] = array(
            "Pattern" => $Pattern,
            "Page" => $Page,
            "GetVars" => $GetVars,
            "AddedBy" => StdLib::getCallerInfo(),
            "Template" => $Template,
            "Domain" => $Domain,
            "Order" => $Order,
        );
    }

    /**
     * Add clean URL mapping.  This method allows a "clean URL" (usually a
     * purely structural URL that does not contain a query string and is
     * more human-friendly) to be specified and mapped to a particular page,
     * with segments of the clean URL being extracted and put into $_GET
     * variables, as if they had been in a query string.
     * @param string $Pattern Regular expression to match against clean URL,
     *      with starting and ending delimiters.
     * @param string $Page Page (P= value) to load if regular expression matches.
     * @param array $GetVars Array of $_GET variables to set using matches from
     *      regular expression, with variable names for the array indexes and
     *      variable value templates (with $N as appropriate, for captured
     *      subpatterns from matching) for the array values.  (OPTIONAL)
     * @param callable $Callback Callback that takes matches as its first
     *      parameter (similar to those passed by preg_replace_callback())
     *      the $Pattern value as its second parameter, the $Page value as the third
     *      parameter, and the full pattern (with "href=" etc) being matched for
     *      the fourth parameter.  The callback should return a string to replace
     *      the matched (conventional/unclean) URL.
     * @param string $Domain Domain to which to limit the clean URL.  (OPTIONAL)
     * @param int $Order Preference for when the regular expression should
     *      be applied to match against clean URLs.  Clean URLs with very
     *      broad patterns should usually specify ORDER_LAST, to avoid
     *      trumping other very-specific clean URL patterns.  (OPTIONAL,
     *       defaults to ORDER_MIDDLE)
     * @see addCleanUrl()
     */
    public function addCleanUrlWithCallback(
        string $Pattern,
        string $Page,
        ?array $GetVars = null,
        ?callable $Callback = null,
        ?string $Domain = null,
        int $Order = self::ORDER_MIDDLE
    ): void {
        # flag that output modifications need to be regenerated from clean URL info
        $this->OutputModificationsAreCurrent = false;

        # save clean URL mapping parameters
        $this->CleanUrlMappings[] = array(
            "Pattern" => $Pattern,
            "Page" => $Page,
            "GetVars" => $GetVars,
            "AddedBy" => StdLib::getCallerInfo(),
            "Template" => $Callback,
            "Domain" => $Domain,
            "Order" => $Order,
        );
    }

    /**
     * Add clean URL with destination page and (optionally) any $_GET parameters
     * to pass to the page.
     * @param string $CleanUrl Clean URL suffix (i.e. the portion after the
     *      domain and any site base path).
     * @param string $Page Name of page that clean URL should load.
     * @param array $PageParameters Any $_GET parameters to add to the page,
     *      with parameter names for the index and parameter values for the
     *      values.  (OPTIONAL)
     * @param string $Domain Domain to which to limit the clean URL.  (OPTIONAL)
     * @param int $Order Preference for when the regular expression should
     *      be applied to match against clean URLs.  Clean URLs with very
     *      broad patterns should usually specify ORDER_LAST, to avoid
     *      trumping other very-specific clean URL patterns.  (OPTIONAL,
     *       defaults to ORDER_MIDDLE)
     */
    public function addSimpleCleanUrl(
        string $CleanUrl,
        string $Page,
        array $PageParameters = [],
        ?string $Domain = null,
        int $Order = self::ORDER_MIDDLE
    ): void {
        $this->addCleanUrl(
            "%^".preg_quote($CleanUrl, "%")."/?$%i",
            $Page,
            $PageParameters,
            $CleanUrl,
            $Domain,
            $Order
        );
    }

    /**
     * If called, only clean URL mappings that have a domain specified that
     * matches the current domain will be handled.
     */
    public function mapOnlyDomainSpecificCleanUrls(): void
    {
        $this->MapOnlyDomainSpecificCleanUrls = true;
    }

    /**
     * Report whether clean URL has already been mapped.
     * @param string $Path Relative URL path to test against.
     * @return bool TRUE if pattern is already mapped, otherwise FALSE.
     */
    public function cleanUrlIsMapped(string $Path): bool
    {
        foreach ($this->CleanUrlMappings as $Info) {
            if (preg_match($Info["Pattern"], $Path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the clean URL mapped for a path.  This only works for clean URLs
     * where a replacement template for insertion into output (the $Template
     * parameter to addCleanUrl()) was specified.
     * @param string $Path Unclean path, e.g., index.php?P=FullRecord&ID=123.
     * @return string Returns the clean URL for the path if one exists. Otherwise it
     *     returns the path unchanged.
     * @see ApplicationFramework::addCleanUrl()
     */
    public function getCleanRelativeUrlForPath(string $Path): string
    {
        # make sure update modification patterns and callbacks are current
        $this->convertCleanUrlRequestsToOutputModifications();

        # the search patterns and callbacks require a specific format
        $Format = "href=\"" . str_replace("&", "&amp;", $Path) . "\"";
        $Search = $Format;

        # perform any regular expression replacements on the search string
        $Search = preg_replace(
            $this->OutputModificationPatterns,
            $this->OutputModificationReplacements,
            $Search
        );

        # only run the callbacks if a replacement hasn't already been performed
        if ($Search == $Format) {
            # perform any callback replacements on the search string
            foreach ($this->OutputModificationCallbacks as $Info) {
                # make the information available to the callback
                $this->OutputModificationCallbackInfo = $Info;

                # execute the callback
                $Search = preg_replace_callback(
                    $Info["SearchPattern"],
                    array($this, "outputModificationCallbackShell"),
                    $Search
                );
            }
        }

        # return the path untouched if no replacements were performed
        if ($Search == $Format) {
            return $Path;
        }

        # remove the bits added to the search string to get it recognized by
        # the replacement expressions and callbacks
        $Result = substr($Search, 6, -1);

        return $Result;
    }

    /**
     * Get the unclean URL for mapped for a path.
     * @param string $Path Clean path, e.g., r123/resource-title
     * @return string Returns the unclean URL for the path if one exists.
     *       Otherwise it returns the path unchanged.
     */
    public function getUncleanRelativeUrlWithParamsForPath(string $Path): string
    {
        # for each clean URL mapping
        foreach ($this->CleanUrlMappings as $Info) {
            # if current path matches the clean URL pattern
            if (preg_match($Info["Pattern"], $Path, $Matches)) {
                # the GET parameters for the URL, starting with the page name
                $GetVars = array("P" => $Info["Page"]);

                # if additional $_GET variables specified for clean URL
                if ($Info["GetVars"] !== null) {
                    # for each $_GET variable specified for clean URL
                    foreach ($Info["GetVars"] as $VarName => $VarTemplate) {
                        # start with template for variable value
                        $Value = $VarTemplate;

                        # for each subpattern matched in current URL
                        foreach ($Matches as $Index => $Match) {
                            # if not first (whole) match
                            if ($Index > 0) {
                                # make any substitutions in template
                                $Value = str_replace("$" . $Index, $Match, $Value);
                            }
                        }

                        # add the GET variable
                        $GetVars[$VarName] = $Value;
                    }
                }

                # return the unclean URL
                return "index.php?" . http_build_query($GetVars);
            }
        }

        # return the path unchanged
        return $Path;
    }

    /**
     * Get the clean URL for the current page if one is available. Otherwise,
     * the unclean URL will be returned.
     * @return string Returns the clean URL for the current page if possible.
     */
    public function getCleanRelativeUrl(): string
    {
        return $this->getCleanRelativeUrlForPath($this->getUncleanRelativeUrlWithParams());
    }

    /**
     * Get the unclean URL for the current page.
     * @return string Returns the unclean URL for the current page.
     */
    public function getUncleanRelativeUrlWithParams(): string
    {
        $GetVars = array("P" => $this->getPageName()) + $_GET;
        return "index.php?" . http_build_query($GetVars);
    }

    /**
     * Get list of all clean URLs currently added.
     * @return array Array of arrays of clean URL info, with the indexes "Pattern",
     *       "Page", "GetVars", and "AddedBy".  The values for the first three are
     *       in the same format is was passed in to addCleanUrl(), while the value
     *       for "AddedBy" is in the format returned by StdLib::getCallerInfo().
     */
    public function getCleanUrlList()
    {
        return $this->CleanUrlMappings;
    }

    /**
     * Add possible alternate domain/hostname for the site.
     * @param string $Domain Domain/hostname to add.
     */
    public function addAlternateDomain(string $Domain): void
    {
        $this->AlternateDomains[] = $Domain;
    }

    /**
     * Get the list of configured alternate domains.
     * @return array of alternate domains (e.g., example.com).
     */
    public function getAlternateDomains()
    {
        $this->AlternateDomains = array_unique($this->AlternateDomains);
        sort($this->AlternateDomains);
        return $this->AlternateDomains;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $AlternateDomains = [];
    private $CleanUrlRewritePerformed = false;
    private $CleanUrlMappings = [];
    private $MapOnlyDomainSpecificCleanUrls = false;
    private $OutputModificationsAreCurrent = false;

    /**
     * Get the current URL path (i.e. the portion without the protocol, domain,
     * or arguments.  This is retrieved from $_SERVER, looking in "SCRIPT_URL",
     * "REQUEST_URI", and "REDIRECT_URL", in that order.
     * @return string|null Trimmed URL value or NULL if unable to determine value.
     */
    private static function getUrlPath()
    {
        if (array_key_exists("SCRIPT_URL", $_SERVER)) {
            return $_SERVER["SCRIPT_URL"];
        } elseif (array_key_exists("REQUEST_URI", $_SERVER)) {
            $Piece = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
            return ($Piece === false) ? null : $Piece;
        } elseif (array_key_exists("REDIRECT_URL", $_SERVER)) {
            return $_SERVER["REDIRECT_URL"];
        } else {
            return null;
        }
    }

    /**
     * If the invoking URL matches one of our clean URL mappings, return
     * the name of the page to load and set any $_GET values parsed out of
     * the clean URL.
     * @return string Page name for matching clean URL, or empty string if
     *      invoking URL did not match a clean URL mapping.
     */
    private function getPageAndSetParamsForCleanUrl(): string
    {
        # if URL rewriting is supported by the server
        if ($this->cleanUrlSupportAvailable()) {
            # retrieve current URL without base path
            $Url = $this->getRelativeUrl();

            # sort clean URL mappings in order
            $CleanUrlMappings = $this->CleanUrlMappings;
            $SortFunc = function ($A, $B) {
                return $A["Order"] <=> $B["Order"];
            };
            usort($CleanUrlMappings, $SortFunc);

            # for each clean URL mapping
            foreach ($CleanUrlMappings as $Info) {
                # if current URL matches clean URL pattern
                if (preg_match($Info["Pattern"], $Url, $Matches)) {
                    # set new page
                    $PageName = $Info["Page"];

                    # if $_GET variables specified for clean URL
                    if ($Info["GetVars"] !== null) {
                        # for each $_GET variable specified for clean URL
                        foreach ($Info["GetVars"] as $VarName => $VarTemplate) {
                            # start with template for variable value
                            $Value = $VarTemplate;

                            # for each subpattern matched in current URL
                            foreach ($Matches as $Index => $Match) {
                                # if not first (whole) match
                                if ($Index > 0) {
                                    # make any substitutions in template
                                    $Value = str_replace("$" . $Index, $Match, $Value);
                                }
                            }

                            # set $_GET variable
                            $_GET[$VarName] = $Value;
                        }
                    }

                    # set flag indicating clean URL mapped
                    $this->CleanUrlRewritePerformed = true;

                    # stop looking for a mapping
                    break;
                }
            }
        }

        # return (possibly) updated page name to caller
        return $PageName ?? "";
    }

    /**
     * Convert all previously-saved clean URL requests to output modification
     * patterns or callbacks.
     */
    private function convertCleanUrlRequestsToOutputModifications(): void
    {
        # if no new CleanURLs added since the last time we updated,
        # then nothing to do
        if ($this->OutputModificationsAreCurrent) {
            return;
        }

        # clear any existing output modifications
        $this->clearAllOutputModifications();

        # for each requested clean URL
        $CurrentDomain = self::getCurrentDomain();
        foreach ($this->CleanUrlMappings as $CleanUrlParams) {
            # skip if non-domain-specific and only domain-specific requested
            $Domain = $CleanUrlParams["Domain"];
            if ($Domain === null) {
                if ($this->MapOnlyDomainSpecificCleanUrls) {
                    continue;
                }
            # skip if domain-specific and domain does not match
            } else {
                if ($Domain != $CurrentDomain) {
                    continue;
                }
            }

            # pull all parameters into local variables
            $Pattern = $CleanUrlParams["Pattern"];
            $Page = $CleanUrlParams["Page"];
            $GetVars = $CleanUrlParams["GetVars"];
            $Template = $CleanUrlParams["Template"];

            # skip if no replacement template specified
            if ($Template === null) {
                continue;
            }

            # if GET parameters specified
            if (($GetVars !== null) && count($GetVars)) {
                # retrieve all possible permutations of GET parameters
                $GetPerms = StdLib::arrayPermutations(array_keys($GetVars));

                # for each permutation of GET parameters
                foreach ($GetPerms as $VarPermutation) {
                    # construct search pattern for permutation
                    $SearchPattern = "/href=([\"'])index\\.php\\?P=" . $Page;
                    $GetVarSegment = "";
                    foreach ($VarPermutation as $GetVar) {
                        if (preg_match("%\\\$[0-9]+%", $GetVars[$GetVar])) {
                            $GetVarSegment .= "&amp;" . $GetVar . "=((?:(?!\\1)[^&])+)";
                        } else {
                            $GetVarSegment .= "&amp;" . $GetVar . "=" . $GetVars[$GetVar];
                        }
                    }
                    $SearchPattern .= $GetVarSegment . "\\1/i";

                    # construct replacement string for permutation (if needed)
                    if (is_callable($Template)) {
                        $Replacement = "";
                    } else {
                        $Replacement = $Template;
                        $Index = 2;
                        foreach ($VarPermutation as $GetVar) {
                            $Replacement = str_replace(
                                "\$" . $GetVar,
                                "\$" . $Index,
                                $Replacement
                            );
                            $Index++;
                        }
                        $Replacement = "href=\"" . $Replacement . "\"";
                    }

                    $this->addOutputModification(
                        $Pattern,
                        $Page,
                        $SearchPattern,
                        $Replacement,
                        $Template
                    );
                }
            } else {
                $SearchPattern = "/href=\"index\\.php\\?P=" . $Page . "\"/i";
                $Replacement = is_callable($Template) ? "" : "href=\"".$Template."\"";
                $this->addOutputModification(
                    $Pattern,
                    $Page,
                    $SearchPattern,
                    $Replacement,
                    $Template
                );
            }
        }

        $this->OutputModificationsAreCurrent = true;
    }
}
