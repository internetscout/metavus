<?PHP
#
#   FILE:  ApplicationFramework.php
#
#   Part of the ScoutLib application support library
#   Copyright 2009-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use DirectoryIterator;
use Exception;
use InvalidArgumentException;
use JavaScriptPacker;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use Throwable;

/**
 * Top-level framework for web applications.
 * \nosubgrouping
 */
class ApplicationFramework
{
    use AFTaskManagerTrait;
    use AFUrlManagerTrait;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** TO DO:  Move these constants to AFTaskManagerTrait when minimum */
    /**     required PHP version can support trait constants.  (PHP 8.2) */
    const PRIORITY_HIGH = 1;        /**  Highest priority. */
    const PRIORITY_MEDIUM = 2;      /**  Medium (default) priority. */
    const PRIORITY_LOW = 3;         /**  Lower priority. */
    const PRIORITY_BACKGROUND = 4;  /**  Lowest priority. */


    /** @name Application Framework */ /*@(*/

    /**
     * Get universal instance of class.
     * @return self Class instance.
     */
    public static function getInstance()
    {
        if (!isset(self::$Instance)) {
            self::$Instance = new static();
        }
        return self::$Instance;
    }

    /** @cond */
    /**
     * Default top-level exception handler.
     * @param Throwable $Exception Exception to be handled.
     **/
    public function globalExceptionHandler(Throwable $Exception): void
    {
        # display exception info
        $Message = $Exception->getMessage();
        $Location = str_replace(
            getcwd() . "/",
            "",
            $Exception->getFile() . "[" . $Exception->getLine() . "]"
        );
        $Trace = preg_replace(
            ":(#[0-9]+) " . getcwd() . "/" . ":",
            "$1 ",
            $Exception->getTraceAsString()
        );
        if ($this->isRunningFromCommandLine()) {
            print "Uncaught Exception\n"
                . "Message: " . $Message . "\n"
                . "Location: " . $Location . "\n"
                . "Trace: \n"
                . $Trace . "\n";
        } else {
            ?>
            <table width="100%" cellpadding="5"
                   style="border: 2px solid #666666;  background: #CCCCCC;
                    font-family: Courier New, Courier, monospace;
                    margin-top: 10px;">
            <tr>
                <td>
                    <div style="color: #666666;">
                <span style="font-size: 150%;">
                        <b>Uncaught Exception</b></span><br/>
                        <b>Message:</b> <i><?= $Message ?></i><br/>
                        <b>Location:</b> <i><?= $Location ?></i><br/>
                        <b>Trace:</b>
                        <blockquote>
                            <pre><?= $Trace ?></pre>
                        </blockquote>
                    </div>
                </td>
            </tr></table><?PHP
        }

        # log exception if not running from command line
        if (!$this->isRunningFromCommandLine()) {
            $TraceString = $Exception->getTraceAsString();
            $TraceString = str_replace("\n", ", ", $TraceString);
            $TraceString = preg_replace(
                ":(#[0-9]+) " . getcwd() . "/" . ":",
                "$1 ",
                $TraceString
            );
            $LogMsg = "Uncaught exception (".$Exception->getMessage().")"
                    ." at ".$Location."."
                    ."  TRACE: " . $TraceString;
            if (!$this->RunningInBackground) {
                $LogMsg .= "  IP: " . $_SERVER["REMOTE_ADDR"]
                        ."  URL: ".$this->fullUrl();
            }
            $this->logError(self::LOGLVL_ERROR, $LogMsg);
        }
    }
    /** @endcond */

    /**
     * Get/set default namespace prefix.  The default prefix is stripped off
     * of class namespaces when attempting to find class files.
     * @param string $NewValue New prefix.  (OPTIONAL)
     * @return string Current prefix.
     */
    public static function defaultNamespacePrefix(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            if (substr($NewValue, -1) != "\\") {
                $NewValue = $NewValue."\\";
            }
            self::$DefaultNamespacePrefix = $NewValue;
        }
        return self::$DefaultNamespacePrefix;
    }

    /**
     * Get/set local namespace prefix.  The local prefix is stripped off of
     * class namespaces when attempting to find class files in the "local"
     * subdirectory tree.
     * @param string $NewValue New prefix.  (OPTIONAL)
     * @return string Current prefix.
     */
    public static function localNamespacePrefix(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            if (substr($NewValue, -1) != "\\") {
                $NewValue = $NewValue."\\";
            }
            self::$LocalNamespacePrefix = $NewValue;
        }
        return self::$LocalNamespacePrefix;
    }

    /**
     * Add directory to be searched for object files when autoloading.
     * Directories are searched in the order they are added.  The token
     * "%ACTIVEUI%" may be included in the directory names, and will be
     * replaced with the canonical name of the currently active UI when
     * searching for files.
     * @param string $Dir Directory to be searched.
     * @param string|array $NamespacePrefixes Namespace prefix or array of namespace
     *      prefixes, for which directory will be the base directory, as
     *      per PSR-4 (http://www.php-fig.org/psr/psr-4/).  if a default
     *      namespace prefix is set, it will be stripped off before these
     *      prefixes are used.  (OPTIONAL)
     * @param callable $Callback Callback function to apply to class
     *      name before using it to build the object file name to be
     *      search for.  Function should expect a class name (string),
     *      and return a modified class name (string), and will be called
     *      before any namespace prefix is stripped off.  (OPTIONAL)
     * @throws InvalidArgumentException If the namespace or callback
     *      parameters overtly appear invalid.
     */
    public static function addObjectDirectory(
        string $Dir,
        $NamespacePrefixes = [],
        $Callback = null
    ): void {
        # check to make sure any supplied callback looks valid
        if ($Callback !== null) {
            if (!is_callable($Callback)) {
                throw new InvalidArgumentException("Supplied callback (\""
                    . $Callback . "\") is invalid.");
            }
        }

        # make sure directory has trailing slash
        $Dir = $Dir.((substr($Dir, -1) != "/") ? "/" : "");

        # make sure namespace prefixes are an array
        if (!is_array($NamespacePrefixes)) {
            $NamespacePrefixes = [$NamespacePrefixes];
        }

        # make sure namespace prefixes are in decreasing order of length
        usort($NamespacePrefixes, function ($A, $B) {
            return strlen($B) - strlen($A);
        });

        # add directory to directory list
        self::$ObjectDirectories[$Dir] = [
            "NamespacePrefixes" => $NamespacePrefixes,
            "Callback" => $Callback,
        ];
    }

    /**
     * Add additional directory(s) to be searched for image files.
     * Specified directory(s) will be searched in order.  If a directory is
     * already present in the list, it will be moved to end to be searched
     * last.  If SearchFirst is TRUE, all search order aspects are reversed,
     * with directories (new or already present) added to the front of the list
     * (to be searced first), and new directories searched in the reverse of
     * the order in which they are supplied.
     * The token "%ACTIVEUI%" may be included in the directory names, and will
     * be replaced with the canonical name of the currently active UI when
     * searching for files.
     * @param array $NewDirs New directories to be searched.
     * @param bool $SearchFirst If TRUE, the directory(s) are searched after the entries
     *       current in the list, instead of before.  (OPTIONAL, defaults to FALSE)
     * @see ApplicationFramework::gUIFile()
     * @see ApplicationFramework::pUIFile()
     */
    public function addImageDirectories(
        array $NewDirs,
        bool $SearchFirst = false
    ): void {
        # add directories to existing image directory list
        $this->ImageDirList = $this->addToDirList(
            $this->ImageDirList,
            $NewDirs,
            $SearchFirst
        );
    }

    /**
     * Add additional directory(s) to be searched for user interface include
     * (CSS, JavaScript, common PHP, common HTML, etc) files.
     * Specified directory(s) will be searched in order.  If a directory is
     * already present in the list, it will be moved to end to be searched
     * last.  If SearchFirst is TRUE, all search order aspects are reversed,
     * with directories (new or already present) added to the front of the list
     * (to be searced first), and new directories searched in the reverse of
     * the order in which they are supplied.
     * The token "%ACTIVEUI%" may be included in the directory names, and will
     * be replaced with the canonical name of the currently active UI when
     * searching for files.
     * @param array $NewDirs New directories to be searched.
     * @param bool $SearchFirst If TRUE, the directory(s) are searched after the entries
     *       current in the list, instead of before.  (OPTIONAL, defaults to FALSE)
     * @see ApplicationFramework::gUIFile()
     * @see ApplicationFramework::pUIFile()
     */
    public function addIncludeDirectories(
        array $NewDirs,
        bool $SearchFirst = false
    ): void {
        # add directories to existing image directory list
        $this->IncludeDirList = $this->addToDirList(
            $this->IncludeDirList,
            $NewDirs,
            $SearchFirst
        );

        # cleared caches for user interface settings
        $this->InterfaceSettingsCache = null;
        $this->InterfaceSettingsByDirCache = null;
    }

    /**
     * Add additional directorys to be searched for user interface (HTML) files.
     * Specified directories will be searched in the order they are added.  If a
     * directory is already present in the list, it will be moved to end to be
     * searched last.  If SearchFirst is TRUE, all search order aspects are reversed,
     * with directories (new or already present) added to the front of the list
     * (to be searched first), and new directories searched in the reverse of
     * the order in which they are supplied.
     * The token "%ACTIVEUI%" may be included in the directory names, and will
     * be replaced with the canonical name of the currently active UI when
     * searching for files.
     * @param array $NewDirs New directories to be searched.
     * @param bool $SearchFirst If TRUE, the directory(s) are searched after the entries
     *       current in the list, instead of before.  (OPTIONAL, defaults to FALSE)
     * @see ApplicationFramework::gUIFile()
     * @see ApplicationFramework::pUIFile()
     */
    public function addInterfaceDirectories(
        array $NewDirs,
        bool $SearchFirst = false
    ): void {
        # add directories to existing image directory list
        $this->InterfaceDirList = $this->addToDirList(
            $this->InterfaceDirList,
            $NewDirs,
            $SearchFirst
        );

        # cleared cached lists for user interfaces
        $this->InterfaceSettingsCache = null;
        $this->InterfaceSettingsByDirCache = null;
        self::$UserInterfaceListCache = [];
        self::$UserInterfacePathsCache = [];
    }

    /**
     * Add callback to map page names when looking in specified interface
     * or page file directories.  This mechanism can also be used to skip
     * interface or page file directories by returning an empty string
     * rather than a name.
     * @param array $Dirs Directories for which to apply mapping function.
     * @param callable $Func Mapping function, that takes a directory as
     *      its first argument and a page name as its second argument, and
     *      returns a potentially-modified page name to look for in that
     *      directory, or an empty string to skip that directory.
     */
    public function addPageNameMappingFunction(
        array $Dirs,
        callable $Func
    ): void {
        foreach ($Dirs as $Dir) {
            $this->PageNameMapFuncs[$Dir] = $Func;
        }
    }

    /**
     * Add additional directorys to be searched for page (PHP) files.
     * Specified directories will be searched in the order they are added.  If a
     * directory is already present in the list, it will be moved to end to be
     * searched last.  If SearchFirst is TRUE, all search order aspects are reversed,
     * with directories (new or already present) added to the front of the list
     * (to be searched first), and new directories searched in the reverse of
     * the order in which they are supplied.
     * @param array $NewDirs New directories to be searched.
     * @param bool $SearchFirst If TRUE, the directory(s) are searched after the entries
     *       current in the list, instead of before.  (OPTIONAL, defaults to FALSE)
     */
    public function addPageFileDirectories(
        array $NewDirs,
        bool $SearchFirst = false
    ): void {
        # add directories to existing image directory list
        $this->PageFileDirList = $this->addToDirList(
            $this->PageFileDirList,
            $NewDirs,
            $SearchFirst
        );
    }

    /**
     * Add additional directory(s) to be searched for function ("F-") files.
     * Specified directory(s) will be searched in order.  If a directory is
     * already present in the list, it will be moved to end to be searched
     * last.  If SearchFirst is TRUE, all search order aspects are reversed,
     * with directories (new or already present) added to the front of the list
     * (to be searced first), and new directories searched in the reverse of
     * the order in which they are supplied.
     * The token "%ACTIVEUI%" may be included in the directory names, and will
     * be replaced with the canonical name of the currently active UI when
     * searching for files.
     * @param array $NewDirs New directories to be searched.
     * @param bool $SearchFirst If TRUE, the directory(s) are searched after the entries
     *       current in the list, instead of before.  (OPTIONAL, defaults to FALSE)
     * @see ApplicationFramework::gUIFile()
     * @see ApplicationFramework::pUIFile()
     */
    public function addFunctionDirectories(
        array $NewDirs,
        bool $SearchFirst = false
    ): void {
        # add directories to existing image directory list
        $this->FunctionDirList = $this->addToDirList(
            $this->FunctionDirList,
            $NewDirs,
            $SearchFirst
        );
    }

    /**
     * Specify function to use to detect the web browser type.  Function should
     * return an array of browser names.
     * @param callable $DetectionFunc Browser detection function callback.
     */
    public function setBrowserDetectionFunc(callable $DetectionFunc): void
    {
        $this->BrowserDetectFunc = $DetectionFunc;
    }

    /**
     * Add a callback that will be executed after buffered content has
     * been output and that won't have its output buffered.
     * @param callable $Callback Callback to add.
     * @param array $Parameters Callback parameters in an array.  (OPTIONAL)
     */
    public function addUnbufferedCallback(
        callable $Callback,
        array $Parameters = []
    ): void {
        if (is_callable($Callback)) {
            $this->UnbufferedCallbacks[] = [ $Callback, $Parameters ];
        }
    }

    /**
     * Get/set UI template location cache expiration period in minutes.  An
     * expiration period of 0 disables caching.
     * @param int $NewValue New expiration period in minutes.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current expiration period in minutes.
     */
    public function templateLocationCacheExpirationInterval(
        ?int $NewValue = null,
        bool $Persistent = false
    ): int {
        if ($NewValue !== null) {
            $this->TemplateLocationCacheInterval = $NewValue;
        }
        return $this->updateIntSetting(
            "TemplateLocationCacheInterval",
            $NewValue,
            $Persistent
        );
    }

    /**
     * Clear template location cache.
     */
    public function clearTemplateLocationCache(): void
    {
        $this->TemplateLocationCache = [];
        $this->SaveTemplateLocationCache = true;
    }

    /**
     * Get/set object file location cache expiration period in minutes.  An
     * expiration period of 0 disables caching.
     * @param int $NewValue New expiration period in minutes.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current expiration period in minutes.
     */
    public function objectLocationCacheExpirationInterval(
        ?int $NewValue = null,
        bool $Persistent = false
    ): int {
        if ($NewValue !== null) {
            self::$ObjectLocationCacheInterval = $NewValue;
        }
        return $this->updateIntSetting(
            "ObjectLocationCacheInterval",
            $NewValue,
            $Persistent
        );
    }

    /**
     * Clear object (class) file location cache.
     */
    public function clearObjectLocationCache(): void
    {
        self::$ObjectLocationCache = [];
        self::$SaveObjectLocationCache = true;
    }

    /**
     * Get/set whether URL fingerprinting is enabled.  (Initially defaults to
     * enabled on installation.)
     * @param bool $NewValue TRUE to enable, or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if enabled, otherwise FALSE.
     */
    public function urlFingerprintingEnabled(
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get/set whether SCSS compilation support is enabled.  (Initially defaults
     * to enabled on installation.)
     * @param bool $NewValue TRUE to enable, or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if enabled, otherwise FALSE.
     * @see ApplicationFramework::generateCompactCss()
     */
    public function scssSupportEnabled(
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get/set whether generating compact CSS (when compiling SCSS) is enabled.
     * (Initially defaults to enabled on installation.)  If SCSS compilation is
     * not enabled, this setting has no effect.
     * @param bool $NewValue TRUE to enable, or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if enabled, otherwise FALSE.
     * @see ApplicationFramework::scssSupportEnabled()
     */
    public function generateCompactCss(
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get/set whether minimized JavaScript will be searched for and used if
     * found.  (Initially defaults to enabled on installation.)  Minimized
     * files end with ".min.js".
     * @param bool $NewValue TRUE to enable, or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if enabled, otherwise FALSE.
     * @see ApplicationFramework::javascriptMinimizationEnabled()
     */
    public function useMinimizedJavascript(
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get/set whether the application framework will attempt to generate
     * minimized JavaScript.  (Initially defaults to enabled on installation.)
     * This setting has no effect if useMinimizedJavascript() is set to FALSE.
     * @param bool $NewValue TRUE to enable, or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if enabled, otherwise FALSE.
     * @see ApplicationFramework::useMinimizedJavascript()
     */
    public function javascriptMinimizationEnabled(
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Record the current execution context in case of crash.  The current
     * context (backtrace) will be saved with the crash info in case a task
     * crashes.  This is primarily intended as a debugging tool, to help
     * determine the circumstances under which a background task is crashing.
     * The $BacktraceLimit parameter is only supported in PHP 5.4 and later.
     * (You can still supply it for earlier PHP versions, but it will be ignored.)
     * @param int $BacktraceOptions Option flags to pass to debug_backtrace()
     *       when retrieving context.  (OPTIONAL, defaults to 0, which records
     *       function/method arguments but not objects)
     * @param int $BacktraceLimit Maximum number of stack frames to record.
     *       (OPTIONAL, defaults to recording all stack frames)
     */
    public function recordContextInCaseOfCrash(
        int $BacktraceOptions = 0,
        int $BacktraceLimit = 0
    ): void {
        if (!$this->RunningInBackground) {
            return;
        }

        if (version_compare(PHP_VERSION, "5.4.0", ">=")) {
            $this->SavedContext = debug_backtrace(
                $BacktraceOptions,
                $BacktraceLimit
            );
        } else {
            // phpcs:disable
            // (to silence warning from PHPCompatibility about
            // BacktraceOptions potentially being changed)
            $this->SavedContext = debug_backtrace($BacktraceOptions);
            // phpcs:enable
        }
        array_shift($this->SavedContext);
    }

    /**
     * Load page PHP and HTML/TPL files.
     * @param string $PageName Name of page to be loaded (e.g. "BrowseResources").
     */
    public function loadPage(string $PageName): void
    {
        # check whether we were invoked by a mapped clean URL, and switch to
        #   appropriate page and set appropriate $_GET parameters if so
        $CleanUrlPageName = $this->getPageAndSetParamsForCleanUrl();
        if (strlen($CleanUrlPageName)) {
            $PageName = $CleanUrlPageName;
        }

        # sanitize incoming page name and save local copy
        $PageName = preg_replace("/[^a-zA-Z0-9_.-]/", "", $PageName);
        $this->PageName = $PageName;

        # if cached page is available
        if ($CachedPage = $this->getCachedPage($this->PageName)) {
            # call any registered callback for this page cache hit
            $this->runCallbackForPageCacheHit($PageName);

            # display cached page and exit
            print $CachedPage;
            return;
        }

        # signal page load
        $SignalResult = $this->signalEvent(
            "EVENT_PAGE_LOAD",
            ["PageName" => $this->PageName]
        );
        if (($SignalResult["PageName"] != $this->PageName)
            && strlen($SignalResult["PageName"])) {
            $this->PageName = $SignalResult["PageName"];
        }

        # load PHP file
        $PageFileOutput = $this->loadPhpFileForPage($this->PageName);

        # signal PHP file load is complete
        ob_start();
        $Context["Variables"] = $this->CurrentLoadingContext;
        $this->signalEvent(
            "EVENT_PHP_FILE_LOAD_COMPLETE",
            [ "PageName" => $PageName, "Context" => $Context ]
        );
        $PageCompleteOutput = (string)ob_get_contents();
        ob_end_clean();

        # set up for possible TSR (Terminate and Stay Resident :))
        $ShouldTSR = $this->prepForTSR();

        # if PHP file indicated we should immediately autorefresh to elsewhere
        if (($this->JumpToPage) && ($this->JumpToPageDelay == 0)) {
            # if no PHP page file output
            if (!strlen(trim($PageFileOutput))) {
                # do not log slow page load if jumping to page outside our site
                if (self::urlIsExternal($this->JumpToPage)) {
                    $this->DoNotLogSlowPageLoad = true;
                }

                # autorefresh to new page
                self::redirectToPage($this->JumpToPage);
            }
            # else if HTML loading is not suppressed
        } elseif (!$this->SuppressHTML) {
            # set content-type to avoid diacritic errors
            header("Content-Type: text/html; charset=".$this->HtmlCharset, true);

            # load UI functions
            $this->loadUIFunctions();

            # load HTML file
            $PageContentOutput = $this->loadHtmlFileForPage($PageName);

            # load standard page start/end if not suppressed
            if (!$this->SuppressStdPageStartAndEnd) {
                $PageStartOutput = $this->loadStandardPageStart();
                $PageEndOutput = $this->loadStandardPageEnd();
            } else {
                $PageStartOutput = "";
                $PageEndOutput = "";
            }

            # clear include file context because it may be large and is no longer needed
            unset($this->CurrentLoadingContext);

            # if page auto-refresh requested
            if ($this->JumpToPage) {
                # add auto-refresh tag to page
                $this->addMetaTag([
                    "http-equiv" => "refresh",
                    "content" => $this->JumpToPageDelay
                        . ";url=" . $this->JumpToPage,
                ]);
            }

            # assemble full page
            $FullPageOutput = $PageStartOutput . $PageContentOutput . $PageEndOutput;

            $FullPageOutput = $this->postProcessPageOutput($FullPageOutput);

            # update page cache for this page
            $this->updatePageCache($PageName, $FullPageOutput);

            # write out full page
            print $FullPageOutput;
        }

        # run any post-processing routines
        foreach ($this->PostProcessingFuncs as $Func) {
            call_user_func_array($Func["Function"], $Func["Arguments"]);
        }

        # write out any output buffered from page code execution
        $this->displayPageFileOutput($PageFileOutput);

        # write out any output buffered from the page code execution complete signal
        if (!$this->JumpToPage && !$this->SuppressHTML && strlen($PageCompleteOutput)) {
            print $PageCompleteOutput;
        }

        # log slow page loads
        $this->checkForAndLogSlowPageLoads();

        # execute callbacks that should not have their output buffered
        foreach ($this->UnbufferedCallbacks as $Callback) {
            call_user_func_array($Callback[0], $Callback[1]);
        }

        # log high memory usage
        $this->checkForAndLogHighMemoryUsage();

        $this->updateLastUsedTimeForActiveSessions();

        # terminate and stay resident (TSR!) if indicated and HTML has been output
        # (only TSR if HTML has been output because otherwise browsers will misbehave)
        if ($ShouldTSR) {
            $this->launchTSR();
        }
    }

    /**
     * Determine whether currently running inside a background task.
     * @return bool TRUE if running in background, otherwise FALSE.
     */
    public function isRunningInBackground(): bool
    {
        return $this->RunningInBackground;
    }

    /**
     * Determine whether currently running from the command line.
     * @return bool TRUE if running from command line, otherwise FALSE.
     */
    public function isRunningFromCommandLine(): bool
    {
        return (PHP_SAPI == "cli");
    }

    /**
     * Get name of page being loaded.  The page name will not include an extension.
     * This call is only meaningful once loadPage() has been called.
     * @return string Page name.
     */
    public function getPageName(): string
    {
        return $this->PageName;
    }

    /**
     * Determine whether AF believes that the specified page exists (i.e. has
     * an associated HTML and/or PHP file).
     * @param string $PageName Name of page to check.
     * @return bool TRUE if page exists, otherwise FALSE.
     */
    public function isExistingPage(string $PageName): bool
    {
        # look for PHP file for supplied page name
        $PageFile = $this->findFile(
            $this->PageFileDirList,
            $PageName,
            ["php"]
        );

        # report to caller that page exists if PHP file was found
        if ($PageFile !== null) {
            return true;
        }

        # look for HTML file for supplied page name
        $PageFile = $this->findFile(
            $this->InterfaceDirList,
            $PageName,
            [ "tpl", "html" ]
        );

        # report to caller that page exists if HTML file was found
        if ($PageFile !== null) {
            return true;
        }

        # report to caller that page does not exist
        return false;
    }

    /**
     * Get the URL path to the page without the base path, if present.  Case
     * is ignored when looking for a base path to strip off.
     * @return string|null URL path without the base path, or NULL if unable
     *      to determine path.
     */
    public function getRelativeUrl()
    {
        # retrieve current URL
        $Url = self::getUrlPath();

        # remove the base path if present
        $BasePath = $this->Settings["BasePath"];
        if (stripos($Url, $BasePath) === 0) {
            $Url = substr($Url, strlen($BasePath));
        }

        return $Url;
    }

    /**
     * Get the full URL to the page.
     * @return string The full URL to the page.
     */
    public function getAbsoluteUrl(): string
    {
        return self::baseUrl() . $this->getRelativeUrl();
    }

    /**
     * Set URL of page to autoload after PHP page file is executed.  The HTML/TPL
     * file will never be loaded if this is set.  Pass in NULL to clear any autoloading.
     * @param string|null $Page URL of page to jump to (autoload).  If the URL
     *       does not appear to point to a PHP or HTML file then "index.php?P="
     *       will be prepended to it.
     * @param int $Delay If non-zero, the page HTML will be generated and displayed,
     *       and the jump (refresh) will not occur until the specified delay in
     *       seconds has elapsed.  (OPTIONAL, defaults to 0)
     * @param bool $IsLiteral If TRUE, do not attempt to prepend "index.php?P=" to page.
     *       (OPTIONAL, defaults to FALSE)
     */
    public function setJumpToPage($Page, int $Delay = 0, bool $IsLiteral = false): void
    {
        if (!is_null($Page)
            && (!$IsLiteral)
            && (strpos($Page, "?") === false)
            && ((strpos($Page, "=") !== false)
                || ((stripos($Page, ".php") === false)
                    && (stripos($Page, ".htm") === false)
                    && (strpos($Page, "/") === false)))
            && (stripos($Page, "http://") !== 0)
            && (stripos($Page, "https://") !== 0)) {
            $this->JumpToPage = self::baseUrl() . "index.php?P=" . $Page;
        } else {
            $this->JumpToPage = $Page;
        }
        $this->JumpToPageDelay = $Delay;
    }

    /**
     * Set URL to jump to after PHP page file is executed.
     * @param string $Url Url to jump to.
     * @throws InvalidArgumentException If an invalid URL is provided.
     */
    public function setJumpToUrl(string $Url): void
    {
        # if a relative URL was given, convert to absolute w/in our site
        if (parse_url($Url, PHP_URL_SCHEME) === null) {
            $TargetUrl = $this->baseUrl().$Url;
            if (filter_var($TargetUrl, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException(
                    "Relative URL (".$Url.") provided for setJumpToUrl()"
                    ." not valid after converting to absolute URL (".$TargetUrl.")."
                );
            }
        } else {
            if (filter_var($Url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException(
                    "Invalid URL (".$Url.") provided for setJumpToUrl()."
                );
            }
            $TargetUrl = $Url;
        }

        # set up page jump
        $this->JumpToPage = $TargetUrl;
        $this->JumpToPageDelay = 0;
    }

    /**
     * Report whether a page to autoload has been set.
     * @return bool TRUE if page is set to autoload, otherwise FALSE.
     */
    public function jumpToPageIsSet(): bool
    {
        return ($this->JumpToPage === null) ? false : true;
    }

    /**
     * Get/set HTTP character encoding value.  This is set for the HTTP header and
     * may be queried and set in the HTML header by the active user interface.
     * The default charset is UTF-8.
     * A list of valid character set values can be found at
     * http://www.iana.org/assignments/character-sets
     * @param string $NewSetting New character encoding value string (e.g. "ISO-8859-1").
     * @return string Current character encoding value.
     */
    public function htmlCharset(?string $NewSetting = null): string
    {
        if ($NewSetting !== null) {
            $this->HtmlCharset = $NewSetting;
        }
        return $this->HtmlCharset;
    }

    /**
     * Specify file(s) to not attempt to minimize.  File names can include
     * paths, in which case only files that exactly match that path will be
     * excluded, or can be just the base file name, in which case any file
     * with that name will be excluded.  This does not prevent minimized
     * versions of files from being used if found in the interface directories,
     * just local (cached) minimized versions being generated and/or used.
     * @param string|array $File File name or array of file names.
     */
    public function doNotMinimizeFile($File): void
    {
        if (!is_array($File)) {
            $File = [ $File ];
        }
        $this->DoNotMinimizeList = array_merge($this->DoNotMinimizeList, $File);
    }

    /**
     * Get/set whether or not to use the "base" tag to ensure relative URL
     * paths are correct.  (Without the "base" tag, an attempt will be made
     * to dynamically rewrite relative URLs where needed.)  Using the "base"
     * tag may be problematic because it also affects relative anchor
     * references and empty target references, which some third-party
     * JavaScript libraries may rely upon.
     * @param bool $NewValue TRUE to enable use of tag, or FALSE to disable.  (OPTIONAL)
     * @return bool TRUE if tag is currently used, otherwise FALSE..
     */
    public function useBaseTag(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            $this->UseBaseTag = $NewValue ? true : false;
        }
        return $this->UseBaseTag;
    }

    /**
     * Suppress loading of HTML files.  This is useful when the only output from a
     * page is intended to come from the PHP page file.  NOTE: This also prevents any
     * background tasks from executing on this page load.
     * @param bool $NewSetting TRUE to suppress HTML output, FALSE to not suppress HTML
     *       output.  (OPTIONAL, defaults to TRUE)
     */
    public function suppressHtmlOutput(bool $NewSetting = true): void
    {
        $this->SuppressHTML = $NewSetting;
    }

    /**
     * Suppress loading of standard page start and end files.  This is useful
     * when the only output from a page is intended to come from the main HTML
     * file, often in response to an AJAX request.
     * @param bool $NewSetting TRUE to suppress standard page start/end, FALSE
     *       to not suppress standard start/end.  (OPTIONAL, defaults to TRUE)
     */
    public function suppressStandardPageStartAndEnd(bool $NewSetting = true): void
    {
        $this->SuppressStdPageStartAndEnd = $NewSetting;
    }

    /**
     * Get/set whether session initialization is intentionally suppressed.
     * @param bool $NewValue TRUE to suppress, or FALSE to allow.
     * @return bool TRUE if suppressed, otherwise FALSE.
     */
    public static function suppressSessionInitialization(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            self::$SuppressSessionInitialization = $NewValue;
        }
        return self::$SuppressSessionInitialization;
    }

    /**
     * Get/set name of current default user interface.
     * @param string $UIName Name of new default user interface.  (OPTIONAL)
     * @return string Name of currently default user interface.
     */
    public static function defaultUserInterface(?string $UIName = null): string
    {
        if ($UIName !== null) {
            self::$DefaultUI = $UIName;
        }
        return self::$DefaultUI;
    }

    /**
     * Get/set name of current active user interface.  Any "SPTUI--" prefix is
     * stripped out for backward compatibility.
     * @param string $UIName Name of new active user interface.  (OPTIONAL)
     * @return string Name of currently active user interface.
     */
    public static function activeUserInterface(?string $UIName = null): string
    {
        if ($UIName !== null) {
            self::$ActiveUI = preg_replace("/^SPTUI--/", "", $UIName);
        }
        return self::$ActiveUI;
    }

    /**
     * Get list of available user interfaces and their labels.  Labels are
     * taken from the file "NAME" in the base directory for the interface,
     * if available.  IF a NAME file isn't available, the canonical name is
     * used for the label.
     * @param string $FilterExp If this regular expression (preg_match() format)
     *       is specified, only interfaces whose directory path matches the
     *       expression will be returned.  (OPTIONAL)
     * @return array List of users interfaces (canonical name => label).
     */
    public function getUserInterfaces(?string $FilterExp = null): array
    {
        if (!isset(self::$UserInterfaceListCache[$FilterExp])) {
            # retrieve paths to user interface directories
            $Paths = $this->getUserInterfacePaths($FilterExp);

            # start out with an empty list
            self::$UserInterfaceListCache[$FilterExp] = [];

            # for each possible UI directory
            foreach ($Paths as $CanonicalName => $Path) {
                # if name file available
                $LabelFile = $Path . "/NAME";
                if (is_readable($LabelFile)) {
                    # read the UI name
                    $Label = file_get_contents($LabelFile);

                    # if the UI name looks reasonable
                    if (($Label !== false) && strlen(trim($Label))) {
                        # use UI name read from NAME file
                        self::$UserInterfaceListCache[$FilterExp][$CanonicalName] =
                                trim($Label);
                    }
                }

                # if we do not have a name yet
                if (!isset(self::$UserInterfaceListCache[$FilterExp][$CanonicalName])) {
                    # use base directory for name
                    self::$UserInterfaceListCache[$FilterExp][$CanonicalName] =
                            basename($Path);
                }
            }
        }

        # return list to caller
        return self::$UserInterfaceListCache[$FilterExp];
    }

    /**
     * Get list of available user interfaces and the relative paths to the
     * base directory for each interface.
     * @param string $FilterExp If this regular expression (preg_match() format)
     *       is specified, only interfaces whose directory path matches the
     *       expression will be returned.  (OPTIONAL)
     * @return array List of users interface paths (canonical name => interface path)
     */
    public function getUserInterfacePaths(?string $FilterExp = null): array
    {
        if (!isset(self::$UserInterfacePathsCache[$FilterExp])) {
            # extract possible UI locations from interface location list
            $InterfaceDirs = [];
            $ExpDirList = $this->expandDirectoryList($this->InterfaceDirList);
            foreach ($ExpDirList as $Dir) {
                $Matches = [];
                if (preg_match(
                    "#([a-zA-Z0-9/]*interface)/[a-zA-Z0-9%/]*#",
                    $Dir,
                    $Matches
                )) {
                    $Dir = $Matches[1];
                    if (($Dir != "interface") && ($Dir != "local/interface")
                            && !in_array($Dir, $InterfaceDirs)) {
                        $InterfaceDirs[] = $Dir;
                    }
                }
            }
            # ("interface" and "local/interface" always go at the end
            #       because they can be sources of new interfaces)
            if (is_dir("local/interface")) {
                $InterfaceDirs[] = "local/interface";
            }
            $InterfaceDirs[] = "interface";

            # reverse order of interface directories so that the directory
            #       returned is the base directory for the interface
            $InterfaceDirs = array_reverse($InterfaceDirs);

            # start out with an empty list
            self::$UserInterfacePathsCache[$FilterExp] = [];
            $InterfacesFound = [];

            # for each possible UI directory
            foreach ($InterfaceDirs as $InterfaceDir) {
                # skip directory if it does not exist
                if (!is_dir($InterfaceDir)) {
                    continue;
                }

                $Dir = dir($InterfaceDir);
                if (!is_object($Dir)) {
                    $this->logError(self::LOGLVL_WARNING, "Unable to read"
                            ." interface directory \"".$InterfaceDir."\".");
                } else {
                    # for each file in directory
                    while (($DirEntry = $Dir->read()) !== false) {
                        $InterfacePath = $InterfaceDir . "/" . $DirEntry;

                        # skip anything we have already found
                        #   or that doesn't have a name in the required format
                        #   or that isn't a directory
                        #   or that doesn't match the filter regex (if supplied)
                        if (in_array($DirEntry, $InterfacesFound)
                                || !preg_match('/^[a-zA-Z0-9]+$/', $DirEntry)
                                || !is_dir($InterfacePath)
                                || (($FilterExp !== null)
                                    && !preg_match($FilterExp, $InterfacePath))) {
                            continue;
                        }

                        # add interface to list
                        self::$UserInterfacePathsCache[$FilterExp][$DirEntry] =
                            $InterfacePath;
                        $InterfacesFound[] = $DirEntry;
                    }
                    $Dir->close();
                }
            }
        }

        # return list to caller
        return self::$UserInterfacePathsCache[$FilterExp];
    }

    /**
     * Retrieve specified setting, for active interface.
     * @param string $SettingName Name of setting to retrieve.
     * @return string|null Setting value or NULL if no setting value
     *      available with that name.
     * @throws Exception If setting value is found but multi-value.
     */
    public function getInterfaceSetting(string $SettingName): ?string
    {
        if (is_null($this->InterfaceSettingsCache)) {
            $this->InterfaceSettingsCache = $this->loadInterfaceSettings();
        }

        if (isset($this->InterfaceSettingsCache[$SettingName])
                && is_array($this->InterfaceSettingsCache[$SettingName])) {
            throw new Exception("Attempt to retrieve multi-value interface"
                    ." setting using single value method.");
        }
        return $this->InterfaceSettingsCache[$SettingName] ?? null;
    }

    /**
     * Retrieve specified multi-value setting, for active interface.
     * @param string $SettingName Name of setting to retrieve.
     * @return array|null Setting values or NULL if no setting values
     *      available with that name.
     * @throws Exception If setting value is found but not multi-value.
     */
    public function getMultiValueInterfaceSetting(string $SettingName): ?array
    {
        if (is_null($this->InterfaceSettingsCache)) {
            $this->InterfaceSettingsCache = $this->loadInterfaceSettings();
        }
        if (isset($this->InterfaceSettingsCache[$SettingName])
                && !is_array($this->InterfaceSettingsCache[$SettingName])) {
            throw new Exception("Attempt to retrieve single value interface"
                    ." setting using multi-value method.");
        }
        return $this->InterfaceSettingsCache[$SettingName] ?? null;
    }

    /**
     * Add function to be called after HTML has been loaded.  The arguments are
     * optional and are saved as references so that any changes to their value
     * that occured while loading the HTML will be recognized.
     * @param callable $Function Function or method to be called.
     * @param mixed $Arg1 First argument to be passed to function.
     *       (OPTIONAL, REFERENCE)
     * @param mixed $Arg2 Second argument to be passed to function.
     *       (OPTIONAL, REFERENCE)
     * @param mixed $Arg3 Third argument to be passed to function.
     *       (OPTIONAL, REFERENCE)
     * @param mixed $Arg4 Fourth argument to be passed to function.
     *       (OPTIONAL, REFERENCE)
     * @param mixed $Arg5 FifthFirst argument to be passed to function.
     *       (OPTIONAL, REFERENCE)
     * @param mixed $Arg6 Sixth argument to be passed to function.
     *       (OPTIONAL, REFERENCE)
     * @param mixed $Arg7 Seventh argument to be passed to function.
     *       (OPTIONAL, REFERENCE)
     * @param mixed $Arg8 Eighth argument to be passed to function.
     *       (OPTIONAL, REFERENCE)
     * @param mixed $Arg9 Ninth argument to be passed to function.
     *       (OPTIONAL, REFERENCE)
     */
    public function addPostProcessingCall(
        callable $Function,
        &$Arg1 = self::NOVALUE,
        &$Arg2 = self::NOVALUE,
        &$Arg3 = self::NOVALUE,
        &$Arg4 = self::NOVALUE,
        &$Arg5 = self::NOVALUE,
        &$Arg6 = self::NOVALUE,
        &$Arg7 = self::NOVALUE,
        &$Arg8 = self::NOVALUE,
        &$Arg9 = self::NOVALUE
    ): void {
        $FuncIndex = count($this->PostProcessingFuncs);
        $this->PostProcessingFuncs[$FuncIndex]["Function"] = $Function;
        $this->PostProcessingFuncs[$FuncIndex]["Arguments"] = [];
        $Index = 1;
        while (isset(${"Arg" . $Index}) && (${"Arg" . $Index} !== self::NOVALUE)) {
            $this->PostProcessingFuncs[$FuncIndex]["Arguments"][$Index]
                =& ${"Arg" . $Index};
            $Index++;
        }
    }

    /**
     * Configure filtering of variables left in the execution environment
     * for the next loaded file after a PHP or HTML file is loaded.
     * The order of file loading is CONTEXT_PAGE, CONTEXT_INTERFACE,
     * CONTEXT_START, and CONTEXT_END.  The default is to allow
     * everything from CONTEXT_START, variables that begin with "H_" from CONTEXT_PAGE,
     * and nothing from all other files.  (NOTE: There is currently no purpose for
     * setting a filter for CONTEXT_END, because it is the last file loaded.)
     * @param int $Context Context to set for (CONTEXT_PAGE,
     *      CONTEXT_INTERFACE, CONTEXT_START, CONTEXT_END).
     * @param bool|array|string $NewSetting TRUE to allow everything, FALSE
     *      to allow nothing, or a prefix or array of prefixes to match the
     *      beginning of variable names.
     * @throws InvalidArgumentException If new setting appears invalid.
     */
    public function setContextFilter(int $Context, $NewSetting): void
    {
        if (($NewSetting === true)
            || ($NewSetting === false)
            || is_array($NewSetting)) {
            $this->ContextFilters[$Context] = $NewSetting;
        } elseif (is_string($NewSetting)) {
            $this->ContextFilters[$Context] = [ $NewSetting ];
        } else {
            throw new InvalidArgumentException(
                "Invalid setting (" . $NewSetting . ")."
            );
        }
    }

    /** File loading context: PHP page file (from "pages"). */
    const CONTEXT_PAGE = 1;
    /** File loading context: HTML interface file. */
    const CONTEXT_INTERFACE = 2;
    /** File loading context: page start file. */
    const CONTEXT_START = 3;
    /** File loading context: page end file. */
    const CONTEXT_END = 4;

    /**
     * Search UI directories for specified image or CSS file and return name
     * of correct file.
     * @param string $FileName Base file name.
     * @return string|null Full relative path name of file or NULL if file not found.
     */
    public function gUIFile(string $FileName): ?string
    {
        # determine which location to search based on file suffix
        $FileType = $this->getFileType($FileName);
        $DirsToSearch = ($FileType == self::FT_IMAGE)
            ? $this->ImageDirList : $this->IncludeDirList;

        # if type is JS and directed to use minimized JavaScript files
        if (($FileType == self::FT_JAVASCRIPT) && $this->useMinimizedJavascript()) {
            # generate file name for possible minimized version of file
            $MinimizedFileName = substr_replace($FileName, ".min", -3, 0);

            # look for minimized version of file
            $FoundFileName = $this->findFile($DirsToSearch, $MinimizedFileName);

            # if minimized file was not found
            if (is_null($FoundFileName)) {
                # look for unminimized file
                $FoundFileName = $this->findFile($DirsToSearch, $FileName);

                # if unminimized file found
                if (!is_null($FoundFileName)) {
                    # if minimization enabled and supported
                    if ($this->javascriptMinimizationEnabled()
                        && self::jsMinRewriteSupport()) {
                        # attempt to create minimized file
                        $MinFileName = $this->minimizeJavascriptFile(
                            $FoundFileName
                        );

                        # if minimization succeeded
                        if ($MinFileName !== null) {
                            # use minimized version
                            $FoundFileName = $MinFileName;

                            # remember to strip off cache location (.htaccess will handle)
                            $CacheLocationToStrip = self::$JSMinCacheDir;

                            # set flag to indicate that we have used compiled JS files
                            $this->MinimizedJsFileUsed = true;
                        }
                    }
                }
            }
            # else if type is CSS and directed to use SCSS files
        } elseif (($FileType == self::FT_CSS) && $this->scssSupportEnabled()) {
            # look for SCSS version of file
            $SourceFileName = preg_replace("/.css$/", ".scss", $FileName);
            $FoundSourceFileName = $this->findFile($DirsToSearch, $SourceFileName);

            # if SCSS file not found
            if ($FoundSourceFileName === null) {
                # look for CSS file
                $FoundFileName = $this->findFile($DirsToSearch, $FileName);
            } else {
                # compile SCSS file (if updated) and use resulting CSS file
                $FoundFileName = $this->compileScssFile($FoundSourceFileName);

                # remember to strip off cache location (.htaccess will handle)
                $CacheLocationToStrip = self::$ScssCacheDir;

                # set flag to indicate that we have used compiled CSS files
                $this->CompiledCssFileUsed = true;
            }
            # otherwise just search for the file
        } else {
            $FoundFileName = $this->findFile($DirsToSearch, $FileName);
        }

        # bail out (no more processing needed) if file not found
        if ($FoundFileName === null) {
            return $FoundFileName;
        }

        # add non-image files to list of found files
        # (used later when checking whether required files have been loaded)
        if ($FileType != self::FT_IMAGE) {
            $FoundUIFileName = basename($FoundFileName);
            if ($FileType == self::FT_JAVASCRIPT) {
                $FoundUIFileName =  str_replace(".min.js", ".js", $FoundUIFileName);
            }

            $this->FoundUIFiles[] = $FoundUIFileName;
        }

        # add fingerprint to file name (if appropriate)
        $FoundFileName = $this->addFingerprintToFileName($FoundFileName);

        # strip cache location off name, if necessary
        if (isset($CacheLocationToStrip)) {
            $FoundFileName = str_replace(
                $CacheLocationToStrip."/",
                "",
                $FoundFileName
            );
        }

        # return file name to caller
        return $FoundFileName;
    }

    /**
     * Get HTML tag for loading specified CSS, JavaScript, or image file,
     * using a relative URL for the file.  If the file is not found or is
     * an unknown or unsupported type of file, an empty string is returned,
     * and a LOGLVL_WARNING message is written to the log.
     * @param string $FileName Base file name.
     * @param ?array $ExtraAttribs Any additional attributes that should
     *      be included in HTML tag, with attribute names for the index.
     *      Attributes with no value should be set to TRUE or FALSE and
     *      will only be included if the value is TRUE.  (OPTIONAL)
     * @return string Tag to load file, or empty string if file was not
     *      found or file type was unknown or unsupported.
     */
    public function gUIFileTag(
        string $FileName,
        ?array $ExtraAttribs = null
    ): string {
        # find specific file to use (with full relative path)
        $FullFileName = $this->gUIFile($FileName);
        if ($FullFileName === null) {
            $this->logError(
                self::LOGLVL_WARNING,
                "Could not find UI file \"".$FileName."\" to generate tag for."
                        ." (Active UI: ".self::$ActiveUI.")"
            );
            return "";
        }

        # generate tag for specified file
        $Tag = $this->getUIFileLoadingTag($FullFileName, $ExtraAttribs);
        if ($Tag === "") {
            $this->logError(
                self::LOGLVL_WARNING,
                "Could not generate tag for UI file \"".$FileName."\"."
            );
            return "";
        }
        return $Tag;
    }

    /**
     * Get HTML tag for loading specified CSS, JavaScript, or image file,
     * using an absolute URL for the file.  If the file is not found or is
     * an unknown or unsupported type of file, an empty string is returned,
     * and a LOGLVL_WARNING message is written to the log.
     * @param string $FileName Base file name.
     * @param ?array $ExtraAttribs Any additional attributes that should
     *      be included in HTML tag, with attribute names for the index.
     *      Attributes with no value should be set to TRUE or FALSE and
     *      will only be included if the value is TRUE.  (OPTIONAL)
     * @return string Tag to load file, or empty string if file was not
     *      found or file type was unknown or unsupported.
     */
    public function gUIFileTagAbs(
        string $FileName,
        ?array $ExtraAttribs = null
    ): string {
        # find specific file to use (with full relative path)
        $FullFileName = $this->gUIFile($FileName);
        if ($FullFileName === null) {
            $this->logError(
                self::LOGLVL_WARNING,
                "Could not find UI file \"".$FileName."\" to generate tag for."
                        ." (Active UI: ".self::$ActiveUI.")"
            );
            return "";
        }
        $FullFileName = $this->baseUrl().$FullFileName;

        # generate tag for specified file
        $Tag = $this->getUIFileLoadingTag($FullFileName, $ExtraAttribs);
        if ($Tag === "") {
            $this->logError(
                self::LOGLVL_WARNING,
                "Could not generate tag for UI file \"".$FileName."\"."
            );
            return "";
        }
        return $Tag;
    }

    /**
     * Search UI directories for specified interface (image, CSS, JavaScript
     * etc) file and print name of correct file with leading path.  If the file
     * is not found, nothing is printed.  This is intended to be called from
     * within interface HTML files to ensure that the correct file is loaded,
     * regardless of which interface the file is in.
     * @param string $FileName Base file name (without leading path).
     * @deprecated
     */
    public function pUIFile(string $FileName): void
    {
        $FullFileName = $this->gUIFile($FileName);
        if ($FullFileName) {
            print $FullFileName;
        } else {
            $this->logError(
                self::LOGLVL_WARNING,
                "Could not find UI file \"" . $FileName . "\" to print."
            );
        }
    }

    /**
     * Search UI directories for specified JavaScript or CSS file and
     * print HTML tag to load file, using name of correct file
     * with leading path.  If the file is not found, nothing is printed.
     * This is intended to be called from within interface HTML files to
     * ensure that the correct file is loaded, regardless of which interface
     * the file is in.  An override version of the file (with "-Override" at
     * the end of the name, before the suffix) is also searched for and will
     * be included if found.
     * @param string|array $FileNames File name or array of file names,
     *       without leading path.
     */
    public function includeUIFile($FileNames): void
    {
        # convert file name to array if necessary
        if (!is_array($FileNames)) {
            $FileNames = [$FileNames];
        }

        # for each file
        foreach ($FileNames as $BaseFileName) {
            # retrieve full file name
            $FileName = $this->gUIFile($BaseFileName);

            # if file was found
            if ($FileName) {
                # print appropriate tag
                print $this->getUIFileLoadingTag($FileName);
            } else {
                $this->logError(
                    self::LOGLVL_WARNING,
                    "Could not find UI file \"" . $BaseFileName
                    . "\" to include.  (Active UI: " . self::$ActiveUI . ")"
                );
            }

            # if we are not already loading an override file
            if (!preg_match("/-Override.(css|scss|js)$/", $BaseFileName)) {
                # attempt to load override file if available
                $OverridePatterns = [
                    self::FT_CSS => "/\.(css|scss)$/",
                    self::FT_JAVASCRIPT => "/\.js$/",
                ];
                $OverrideReplacements = [
                    self::FT_CSS => "-Override.$1",
                    self::FT_JAVASCRIPT => "-Override.js",
                ];
                $FileType = $this->getFileType($BaseFileName);
                if (isset($OverridePatterns[$FileType])) {
                    $BaseOverrideFileName = preg_replace(
                        $OverridePatterns[$FileType],
                        $OverrideReplacements[$FileType],
                        $BaseFileName
                    );
                    $OverrideFileName = $this->gUIFile($BaseOverrideFileName);
                    if ($OverrideFileName) {
                        print $this->getUIFileLoadingTag($OverrideFileName);
                    }
                }
            }
        }
    }

    /**
     * Clear all CSS files compiled from SCSS.  This must be called ONLY
     * before any CSS files have been generated on this page load, via calls
     * to gUIFile(), gUIFileTag(), gUIFileTagAbs(), pUIFile(), includeUIFile()
     * or other code that may call any of those methods.  Calling this method
     * may disrupt other page loads or reloads in progress (because the CSS
     * file they cite may no longer exist), so should not be done lightly.
     * @return bool TRUE if removal succeeded, otherwise FALSE.
     * @throws Exception If called after compiled CSS file has been used.
     */
    public function clearCompiledCssFiles(): bool
    {
        if ($this->CompiledCssFileUsed) {
            throw new Exception("Attempt to clear compiled CSS files after"
                    ." compiled file has already been used.");
        }
        return StdLib::deleteDirectoryTree(self::$ScssCacheDir);
    }

    /**
     * Clear all JavaScript files we have minimized.  This must be called ONLY
     * before any JavaScript files have been minimized on this page load, via
     * calls to gUIFile(), gUIFileTag(), gUIFileTagAbs(), pUIFile(),
     * includeUIFile() or other code that may call any of those methods.
     * Calling this method may disrupt other page loads or reloads in progress
     * (because the minimized JavaScript file they cite may no longer exist),
     * so should not be done lightly.
     * @return bool TRUE if removal succeeded, otherwise FALSE.
     * @throws Exception If called after compiled CSS file has been used.
     */
    public function clearMinimizedJavascriptFiles(): bool
    {
        if ($this->MinimizedJsFileUsed) {
            throw new Exception("Attempt to clear minimized JS files after"
                    ." minimized file has already been used.");
        }
        return StdLib::deleteDirectoryTree(self::$JSMinCacheDir);
    }

    /**
     * Specify file or file name pattern to exclude from URL fingerprinting.
     * The argument is treated as a file name unless the first and last
     * characters are the same.
     * @param string $Pattern File name or file name pattern.
     */
    public function doNotUrlFingerprint(string $Pattern): void
    {
        $this->UrlFingerprintBlacklist[] = $Pattern;
    }

    /**
     * Add file to list of required UI files.  This is used to make sure a
     * particular JavaScript or CSS file is loaded.  Only files loaded with
     * ApplicationFramework::gUIFile() or ApplicationFramework::pUIFile()
     * are considered when deciding if a file has already been loaded.
     * @param string|array $FileNames Base name (without path) or array of base
     *       names of required file(s).
     * @param int $Order Preference for when file(s) should be loaded, with
     *       respect to other required files of the same type.  (OPTIONAL,
     *       defaults to ORDER_MIDDLE)
     */
    public function requireUIFile($FileNames, int $Order = self::ORDER_MIDDLE): void
    {
        # convert file names to array if necessary
        if (!is_array($FileNames)) {
            $FileNames = [$FileNames];
        }

        # add file names to list of required files
        foreach ($FileNames as $FileName) {
            $this->AdditionalRequiredUIFiles[$FileName] = $Order;
        }
    }

    /**
     * Determine type of specified file based on the file name.
     * @param string $FileName Name of file.
     * @return int File type (FT_ enumerated value).
     */
    public static function getFileType(string $FileName): int
    {
        static $FileTypeCache;
        if (isset($FileTypeCache[$FileName])) {
            return $FileTypeCache[$FileName];
        }

        $FileSuffix = strtolower(substr($FileName, -3));
        if ($FileSuffix == "css") {
            $FileTypeCache[$FileName] = self::FT_CSS;
        } elseif ($FileSuffix == ".js") {
            $FileTypeCache[$FileName] = self::FT_JAVASCRIPT;
        } elseif (($FileSuffix == "gif")
            || ($FileSuffix == "jpg")
            || ($FileSuffix == "png")
            || ($FileSuffix == "svg")
            || ($FileSuffix == "ico")) {
            $FileTypeCache[$FileName] = self::FT_IMAGE;
        } else {
            $FileTypeCache[$FileName] = self::FT_OTHER;
        }

        return $FileTypeCache[$FileName];
    }

    /** File type other than CSS, image, or JavaScript. */
    const FT_OTHER = 0;
    /** CSS file type. */
    const FT_CSS = 1;
    /** Image (GIF/JPG/PNG) file type. */
    const FT_IMAGE = 2;
    /** JavaScript file type. */
    const FT_JAVASCRIPT = 3;

    /**
     * Get time elapsed since constructor was called.
     * @return float Elapsed execution time in seconds (as a float).
     */
    public function getElapsedExecutionTime(): float
    {
        return microtime(true) - $this->ExecutionStartTime;
    }

    /**
     * Get remaining available (PHP) execution time.
     * @return float Number of seconds remaining before script times out (as a float).
     */
    public function getSecondsBeforeTimeout(): float
    {
        return $this->maxExecutionTime() - $this->getElapsedExecutionTime();
    }

    /**
     * Add meta tag to page output.
     * @param array $Attribs Tag attributes, with attribute names for the index.
     */
    public function addMetaTag(array $Attribs): void
    {
        # add new meta tag to list
        $this->MetaTags[] = $Attribs;
    }

    /**
     * Add meta tag to page output if not already present.  If $UniqueAttribs
     * is not supplied, the first key/value pair in $Attribs is assumed to be
     * the unique attribute.
     * @param array $Attribs Tag attributes, with attribute names for the index.
     * @param array $UniqueAttribs Tag attribute(s) that must be unique, with
     *       attribute names for the index.  (OPTIONAL)
     */
    public function addMetaTagOnce(array $Attribs, ?array $UniqueAttribs = null): void
    {
        # add new meta tag to list
        $this->UniqueMetaTags[] = [
            "Attribs" => $Attribs,
            "UniqueAttribs" => $UniqueAttribs,
        ];
    }

    /*@)*/ /* Application Framework */


    # ---- Page Building -----------------------------------------------------

    /** @name Page Building */ /*@(*/

    /**
     * Register an HTML insertion keyword and the corresponding callback to
     * provide the replacement text for it.  Arguments are passed to callback
     * functions in the order they are specified at registration.
     * @param string $Keyword Alphanumeric keyword.
     * @param callable $Callback Function or method to call for replacement.
     * @param array $ReqArgs Alphanumeric names of required arguments for
     *      callback.  (OPTIONAL)
     * @param array $OptArgs Alphanumeric names of optional arguments for
     *      callback.  (OPTIONAL)
     * @param array $Pages Pages on which to look for keyword.  (OPTIONAL)
     * @throws InvalidArgumentException When $Keyword is empty or not entirely alphanumeric.
     * @throws InvalidArgumentException When $Callback is not callable.
     */
    public function registerInsertionKeywordCallback(
        string $Keyword,
        callable $Callback,
        array $ReqArgs = [],
        array $OptArgs = [],
        array $Pages = []
    ): void {
        if (!strlen($Keyword) || !ctype_alnum(str_replace("-", "", $Keyword))) {
            throw new InvalidArgumentException("Invalid insertion keyword ('" . $Keyword
                . "') passed from " . StdLib::getMyCaller() . ".");
        }
        if (!is_callable($Callback)) {
            throw new InvalidArgumentException("Invalid callback passed from "
                . StdLib::getMyCaller() . ".");
        }
        $this->InsertionKeywordCallbacks[$Keyword][] = [
            "Callback" => $Callback,
            "ReqArgs" => $ReqArgs,
            "OptArgs" => $OptArgs,
            "Pages" => $Pages,
        ];
    }

    /**
     * Generate a correctly-formatted insertion keyword string, with argument
     * values escaped if necessary.
     * @param string $Keyword Alphanumeric keyword.
     * @param array $Args Callback arguments, with argument names for the index.
     * @return string Formatted insertion keyword string.
     */
    public function formatInsertionKeyword(string $Keyword, ?array $Args = null): string
    {
        $ArgString = "";
        if (($Args !== null) && count($Args)) {
            foreach ($Args as $ArgName => $ArgValue) {
                $ArgValue = str_replace(['|', ':'], ['\\|', '\\:'], $ArgValue);
                $ArgString .= "|" . $ArgName . ":" . $ArgValue;
            }
        }
        return "{{" . $Keyword . $ArgString . "}}";
    }

    /**
     * Prevent any insertion keywords in specified content from being expanded.
     * @param string $Content Content to filter.
     * @param array $AllowedKeywords Keywords that should still be allowed to
     *      be expanded.  (OPTIONAL, defaults to none)
     * @return string Filtered content.
     */
    public function escapeInsertionKeywords(
        string $Content,
        array $AllowedKeywords = []
    ): string {

        # function for keyword escaping
        $Callback = function ($Matches) use ($AllowedKeywords) {
            $Content = $Matches[0];
            $Keyword = $Matches[1];
            if (!in_array($Keyword, $AllowedKeywords)) {
                $this->EscapedInsertionKeywords[] = $Keyword;
                $Content = '\\' . $Content;
            }
            return $Content;
        };

        # look for insertion keywords, with the keyword in $Matches[1]
        $RegEx = '%{{([A-Z0-9-]+)%i';
        return preg_replace_callback($RegEx, $Callback, $Content);
    }

    /**
     * Get any insertion keywords that were found in page output but not
     * matched to registered callbacks.  This list is not available until
     * post-processing of page output has begun.
     * @return array Unmatched insertion keywords.
     * @throws Exception If called before list is available.
     */
    public function getUnmatchedInsertionKeywords(): array
    {
        if (!isset($this->UnmatchedInsertionKeywords)) {
            throw new Exception("Attempt to retrieve unmatched insertion"
                    ." keywords before page output post-processing has begun.");
        }
        return $this->UnmatchedInsertionKeywords;
    }

    /**
     * Get list of variables available to HTML file.
     * @return array Unmatched insertion keywords.
     * @throws Exception If called before list is available.
     */
    public function getHtmlFileContext(): array
    {
        if (!isset($this->HtmlFileContext)) {
            throw new Exception("Attempt to retrieve HTML file context before"
                    ." HTML file load has begun.");
        }
        return $this->HtmlFileContext;
    }

    /*@)*/ /* Page Building */


    # ---- Page Caching ------------------------------------------------------

    /** @name Page Caching */ /*@(*/

    /**
     * Enable/disable page caching.  Page caching is disabled by default.
     * @param bool $NewValue TRUE to enable caching, or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool Current setting.
     * @see ApplicationFramework::doNotCacheCurrentPage()
     */
    public function pageCacheEnabled(
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get page cache expiration period in minutes.
     * @return int Current setting.
     */
    public function getPageCacheExpirationPeriod(): int
    {
        return $this->updateIntSetting(substr(__FUNCTION__, 3));
    }

    /**
     * Set page cache expiration period in minutes.  The default is 24
     * hours (1440 minutes).
     * @param int $NewValue Expiration period in minutes.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     */
    public function setPageCacheExpirationPeriod(
        ?int $NewValue = null,
        bool $Persistent = false
    ): void {
        $this->updateIntSetting(substr(__FUNCTION__, 3), $NewValue, $Persistent);
    }

    /**
     * Get/set page cache expiration period in minutes.  The default is
     * 24 hours (1440 minutes).
     * @param int $NewValue Expiration period in minutes.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current setting.
     * @deprecated
     */
    public function pageCacheExpirationPeriod(
        ?int $NewValue = null,
        bool $Persistent = false
    ): int {
        if ($NewValue !== null) {
            $this->setPageCacheExpirationPeriod($NewValue, $Persistent);
        }
        return $this->getPageCacheExpirationPeriod();
    }

    /**
     * Prevent the current page from being cached.
     * @see ApplicationFramework::pageCachingEnabled()
     */
    public function doNotCacheCurrentPage(): void
    {
        $this->CacheCurrentPage = false;
    }

    /**
     * Add caching tag for current page or specified pages.
     * @param string $Tag Tag string to add.
     * @param array $Pages List of pages.  (OPTIONAL, defaults to current page)
     * @see ApplicationFramework::clearPageCacheForTag()
     */
    public function addPageCacheTag(string $Tag, ?array $Pages = null): void
    {
        # normalize tag
        $Tag = strtolower($Tag);

        # if pages were supplied
        if ($Pages !== null) {
            # add pages to list for this tag
            if (isset($this->PageCacheTags[$Tag])) {
                $this->PageCacheTags[$Tag] = array_merge(
                    $this->PageCacheTags[$Tag],
                    $Pages
                );
            } else {
                $this->PageCacheTags[$Tag] = $Pages;
            }
        } else {
            # add current page to list for this tag
            $this->PageCacheTags[$Tag][] = "CURRENT";
        }
    }

    /**
     * Clear all cached pages associated with specified tag.
     * @param string $Tag Tag to clear pages for.
     * @see ApplicationFramework::addPageCacheTag()
     */
    public function clearPageCacheForTag(string $Tag): void
    {
        # get tag ID
        $TagId = $this->getPageCacheTagId($Tag);

        # delete pages and tag/page connections for specified tag
        $this->DB->query("DELETE CPC"
            ." FROM AF_CachedPageCallbacks CPC, AF_CachedPageTagInts CPTI"
            ." WHERE CPTI.TagId = ".intval($TagId)
            ." AND CPC.CacheId = CPTI.CacheId");
        $this->DB->query("DELETE CP, CPTI"
            ." FROM AF_CachedPages CP, AF_CachedPageTagInts CPTI"
            ." WHERE CPTI.TagId = ".intval($TagId)
            ." AND CP.CacheId = CPTI.CacheId");
    }

    /**
     * Clear all pages from page cache.
     */
    public function clearPageCache(): void
    {
        # clear all page cache tables
        $this->DB->query("TRUNCATE TABLE AF_CachedPages");
        $this->DB->query("TRUNCATE TABLE AF_CachedPageTags");
        $this->DB->query("TRUNCATE TABLE AF_CachedPageTagInts");
        $this->DB->query("TRUNCATE TABLE AF_CachedPageCallbacks");
    }

    /**
     * Get page cache information.  The key difference between this method
     * and getPageCacheExtendedInfo() is that this method omits information
     * that takes significantly more time to retrieve.
     * @return array Associative array of cache info, with these entries:
     *      "NumberOfEntries" - number of entris in page cache
     *      "OldestTimestamp" - date on oldest cache entry (Unix timestamp)
     *      "NewestTimestamp" - date on newest cache entry (Unix timestamp)
     */
    public function getPageCacheInfo(): array
    {
        $Query = "SELECT"
                ." COUNT(*) AS NumberOfEntries, "
                ." UNIX_TIMESTAMP(MIN(CachedAt)) AS OldestTimestamp,"
                ." UNIX_TIMESTAMP(MAX(CachedAt)) AS NewestTimestamp"
                ." FROM AF_CachedPages";
        $this->DB->query($Query);
        $Row = $this->DB->fetchRow();
        assert(is_array($Row));
        return $Row;
    }

    /**
     * Get extended page cache information.  The difference between this
     * method and getPageCacheInfo() is that this method adds information
     * that takes significantly more time to retrieve.
     * @return array In addition to the values returned by getPageCacheInfo(),
     *      this method adds these entries:
     *      "PageInfo" - associative array of associative arrays with page
     *          names for the outer index, and the following for the inner
     *          index and values:
     *              "AverageSize" - average size of cached page (bytes)
     *              "Count" - number of times page appears in cache
     *              "OldestTimestamp" - date on oldest cache entry for
     *                  page (Unix timestamp)
     *              "NewestTimestamp" - date on newest cache entry for
     *                  page (Unix timestamp)
     *              "Page" - page name (same as index)
     */
    public function getPageCacheExtendedInfo(): array
    {
        $Query = "SELECT"
                ." COUNT(*) AS Count,"
                ." UNIX_TIMESTAMP(MIN(CachedAt)) AS OldestTimestamp,"
                ." UNIX_TIMESTAMP(MAX(CachedAt)) AS NewestTimestamp,"
                ." AVG(LENGTH(PageContent)) AS AverageSize,"
                ." REGEXP_REPLACE(Fingerprint,'-[0-9a-f]+$','') AS Page"
                ." FROM AF_CachedPages GROUP BY Page";
        $this->DB->query($Query);
        $ExtendedInfo["PageInfo"] = [];
        while ($Row = $this->DB->fetchRow()) {
            $ExtendedInfo["PageInfo"][$Row["Page"]] = $Row;
            $ExtendedInfo["PageInfo"][$Row["Page"]] = $Row;
            $ExtendedInfo["PageInfo"][$Row["Page"]] = $Row;
        }
        return $this->getPageCacheInfo() + $ExtendedInfo;
    }

    /**
     * Get list of cached pages.
     * @return array Associative array with page names for the index and
     *       count of number of times they appear in cache for the values,
     *       in descending order of appearance frequency.
     */
    public function getPageCachePageList(): array
    {
        $this->DB->query("SELECT SUBSTRING_INDEX(Fingerprint, '-', 1) AS PageName,"
            . " COUNT(*) AS Number FROM AF_CachedPages"
            . " GROUP BY PageName ORDER BY Number DESC");
        return $this->DB->fetchColumn("Number", "PageName");
    }

    /**
     * Get/set expiration date/time for new cached version of current page.
     * @param string $Date New expiration date.  (OPTIONAL)
     * @return string|false Current expiration date, in SQL date format, or FALSE if
     *      no expiration date set.
     */
    public function expirationDateForCurrentPage(?string $Date = null)
    {
        if ($Date !== null) {
            $DateStamp = strtotime($Date);
            if ($DateStamp === false) {
                throw new InvalidArgumentException("Invalid expiration date (\""
                        .$Date."\").");
            }
            $this->CurrentPageExpirationDate = date(
                StdLib::SQL_DATE_FORMAT,
                $DateStamp
            );
        }
        return $this->CurrentPageExpirationDate ?? false;
    }

    /**
     * Register a callback to be called when a cached version of the currrent
     * page is served up (i.e. there is a page cache hit for the current page).
     If there is an existing
     * callback already registered for the current page, it will be replaced by
     * the supplied callback.
     * IMPORTANT: Because they are called on page cache hits, callback functions
     * should be written to run as fast as possible and rely on as little of the
     * normal operating environment as is practical.  For this reason,
     * non-static class methods cannot be used as callbacks, and passing them
     * to this method will cause an exception to be thrown.  When the callback
     * is called, the Database class will definitely be available, and page
     * loading will definitely not have started.  Other portions of the
     * operating environment may or may not be available, depending on how much
     * the boot sequence has been trimmed down for page cache hits.)
     * @param callable $Func Callback function to be called.
     * @param array $Params Parameters to be passed to function.
     */
    public function registerCallbackForPageCacheHit(
        callable $Func,
        array $Params
    ): void {
        if (is_array($Func) && is_object($Func[0])) {
            throw new InvalidArgumentException("Instance of ".get_class($Func[0])
                    ." passed in for callback.  A static method must be used"
                    ." if a class method callback is desired.");
        }
        $this->CallbackForPageCacheHits = [
            "Function" => $Func,
            "Parameters" => $Params
        ];
    }

    /*@)*/ /* Page Caching */


    # ---- Logging -----------------------------------------------------------

    /** @name Logging */ /*@(*/

    /**
     * Get/set whether logging of long page load times is enabled.  When
     * enabled, pages that take more than the time specified via
     * slowPageLoadThreshold() (default 10 seconds) are logged via logMessage()
     * with a level of LOGLVL_INFO.  (This will not, of course, catch pages that
     * take so long to load that the PHP execution timeout is reached.)
     * @param bool $NewValue TRUE to enable logging or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if logging is enabled, otherwise FALSE.
     * @see slowPageLoadThreshold()
     */
    public function logSlowPageLoads(
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get/set how long a page load can take before it should be considered
     * "slow" and may be logged.  (Defaults to 10 seconds.)
     * @param int $NewValue Threshold in seconds.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current threshold in seconds.
     * @see logSlowPageLoads()
     */
    public function slowPageLoadThreshold(
        ?int $NewValue = null,
        bool $Persistent = false
    ): int {
        return $this->updateIntSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get/set whether logging of high memory usage is enabled.  When
     * enabled, pages that use more than the percentage of max memory
     * specified via highMemoryUsageThreshold() (default 90%) are logged
     * via logMessage() with a level of LOGLVL_INFO.  (This will not, of
     * course, catch pages that crash because PHP's memory limit is reached.)
     * @param bool $NewValue TRUE to enable logging or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if logging is enabled, otherwise FALSE.
     * @see highMemoryUsageThreshold()
     */
    public function logHighMemoryUsage(
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get/set whether to log PHP notices with LOGLVL_WARNING logging level.
     * @param bool $NewValue TRUE to log or FALSE to disable logging.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if logging is enabled, otherwise FALSE.
     */
    public function logPhpNotices(?bool $NewValue = null, $Persistent = false): bool
    {
        return !array_key_exists("LogPhpNotices", $this->Settings) ? false
                : $this->updateBoolSetting("LogPhpNotices", $NewValue, $Persistent);
    }

    /**
     * Get/set whether logging of database cache pruning is enabled.  Pruning
     * activity will be logged with LOGLVL_INFO if enabled.
     * @param bool $NewValue TRUE to enable logging or FALSE to disable.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if logging is enabled, otherwise FALSE.
     */
    public function logDBCachePruning(
        ?bool $NewValue = null,
        bool $Persistent = false
    ): bool {
        return $this->updateBoolSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get/set what percentage of max memory (set via the memory_limit PHP
     * configuration directive) a page load can use before it should be
     * considered to be using high memory and may be logged.  (Defaults to 90%.)
     * @param int $NewValue Threshold percentage.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current threshold percentage.
     * @see logHighMemoryUsage()
     */
    public function highMemoryUsageThreshold(
        ?int $NewValue = null,
        bool $Persistent = false
    ): int {
        return $this->updateIntSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Write error message to log.  The difference between this and logMessage
     * is the way that an inability to write to the log is handled.  If this
     * method is unable to log the error and the error level was LOGLVL_ERROR
     * or LOGLVL_FATAL, an exception is thrown.
     * @param int $Level Current message logging must be at or above specified
     *       level for error message to be written.  (See loggingLevel() for
     *       definitions of the error logging levels.)
     * @param string $Msg Error message text.
     * @return bool TRUE if message was logged, otherwise FALSE.
     * @see ApplicationFramework::loggingLevel()
     * @see ApplicationFramework::logMessage()
     */
    public function logError(int $Level, string $Msg): bool
    {
        # if error level is at or below current logging level
        if ($this->Settings["LoggingLevel"] >= $Level) {
            # attempt to log error message
            $Result = $this->logMessage($Level, $Msg);

            # if logging attempt failed and level indicated significant error
            if (($Result === false) && ($Level <= self::LOGLVL_ERROR)) {
                # throw exception about inability to log error
                static $AlreadyThrewException = false;
                if (!$AlreadyThrewException) {
                    $AlreadyThrewException = true;
                    throw new Exception("Unable to log error (" . $Level . ": " . $Msg
                        . ") to " . $this->LogFileName);
                }
            }

            # report to caller whether message was logged
            return $Result;
        } else {
            # report to caller that message was not logged
            return false;
        }
    }

    /**
     * Write status message to log.  The difference between this and logError
     * is the way that an inability to write to the log is handled.
     * @param int $Level Current message logging must be at or above specified
     *       level for message to be written.  (See loggingLevel() for
     *       definitions of the error logging levels.)
     * @param string $Msg Message text.
     * @return bool TRUE if message was logged, otherwise FALSE.
     * @see ApplicationFramework::loggingLevel()
     * @see ApplicationFramework::logError()
     */
    public function logMessage(int $Level, string $Msg): bool
    {
        # if message level is at or below current logging level
        if ($this->Settings["LoggingLevel"] >= $Level) {
            # attempt to open log file
            $FHndl = @fopen($this->LogFileName, "a");

            # if log file could not be open
            if ($FHndl === false) {
                # report to caller that message was not logged
                return false;
            } else {
                # format log entry
                $ErrorAbbrevs = [
                    self::LOGLVL_FATAL => "FTL",
                    self::LOGLVL_ERROR => "ERR",
                    self::LOGLVL_WARNING => "WRN",
                    self::LOGLVL_INFO => "INF",
                    self::LOGLVL_DEBUG => "DBG",
                    self::LOGLVL_TRACE => "TRC",
                ];
                $Msg = str_replace([ "\n", "\t", "\r" ], " ", $Msg);
                $Msg = substr(trim($Msg), 0, self::LOGFILE_MAX_LINE_LENGTH);
                $LogEntry = date(StdLib::SQL_DATE_FORMAT)
                    . " " . ($this->isRunningInBackground() ? "B" : "F")
                    . " " . $ErrorAbbrevs[$Level]
                    . " " . $Msg;

                # write entry to log
                $Success = fwrite($FHndl, $LogEntry . "\n");

                # close log file
                fclose($FHndl);

                # report to caller whether message was logged
                return ($Success === false) ? false : true;
            }
        } else {
            # report to caller that message was not logged
            return false;
        }
    }

    /**
     * Write debug message and (optionally) contents of variable to log.
     * Using the AF class alias, this method can be called like this:
     *      \AF::logDebug("Message or variable name", $OptionalVariable);
     * @param string $Msg Debug message to be written to log.
     * @param mixed $Variable Variable to include content of in logged
     *      debug message.  [OPTIONAL]
     */
    public static function logDebug(string $Msg, $Variable = null): void
    {
        # if a variable was supplied in addition to a message
        if (func_num_args() > 1) {
            # limit output in case Xdebug version of var_dump() is used
            ini_set('xdebug.var_display_max_depth', "5");
            ini_set('xdebug.var_display_max_children', "256");
            ini_set('xdebug.var_display_max_data', "1024");

            # dump variable contents
            ob_start();
            var_dump($Variable);
            $DumpLine = __LINE__ - 1;
            $DumpContent = (string)ob_get_contents();
            ob_end_clean();

            # strip out file/line and HTML tags if inserted by Xdebug
            $DumpContent = str_replace(__FILE__.":"
                    .$DumpLine.":", "", $DumpContent);
            $DumpContent = strip_tags($DumpContent);

            # add variable contents dump to message to be logged
            $Msg .= " ".$DumpContent;
        }

        # write message to log
        self::getInstance()->logError(self::LOGLVL_DEBUG, $Msg);
    }

    /**
     * Get/set logging level.  Status and error messages are only written if
     * their associated level is at or below this value.  The six levels of
     * log messages are, in increasing level of severity:
     *   6: TRACE - Very detailed logging, usually only used when attempting
     *       to diagnose a problem in one specific section of code.
     *   5: DEBUG - Information that is diagnostically helpful when debugging.
     *   4: INFO - Generally-useful information, that may come in handy but
     *       to which little attention is normally paid.  (This should not be
     *       used for events that routinely occur with every page load.)
     *   3: WARNING - An event that may potentially cause problems, but is
     *       automatically recovered from.
     *   2: ERROR - Any error which is fatal to the operation currently being
     *       performed, but does not result in overall application shutdown or
     *       persistent data corruption.
     *   1: FATAL - Any error which results in overall application shutdown or
     *       persistent data corruption.
     * @param int $NewValue New error logging level.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current error logging level.
     * @see ApplicationFramework::logError()
     */
    public function loggingLevel(?int $NewValue = null, bool $Persistent = false): int
    {
        # constrain new level (if supplied) to within legal bounds
        if ($NewValue !== null) {
            $NewValue = max(min($NewValue, 6), 1);
        }

        # set new logging level (if supplied) and return current level to caller
        return $this->updateIntSetting(ucfirst(__FUNCTION__), $NewValue, $Persistent);
    }

    /**
     * Get/set log file name.  The log file location defaults to
     * "local/logs/site.log", but can be changed via this method.
     * @param string $NewValue New log file name.  (OPTIONAL)
     * @return string Current log file name.
     */
    public function logFile(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->LogFileName = $NewValue;
        }
        return $this->LogFileName;
    }

    /**
     * Get log entries, in reverse chronological order.
     * @param int $Limit Maximum number of entries to return.  (OPTIONAL,
     *       defaults to returning all entries)
     * @return array Associative array with entry data, with the indexes
     *       "Time" (Unix timestamp), "Background" (TRUE if error was
     *       logged when running background task), "Level" (logging level
     *       constant), and "Message".
     */
    public function getLogEntries(int $Limit = 0): array
    {
        # return no entries if there isn't a log file
        #       or we can't read it or it's empty
        $LogFile = $this->logFile();
        if (!is_readable($LogFile) || !filesize($LogFile)) {
            return [];
        }

        # if max number of entries specified
        if ($Limit > 0) {
            # load lines from file
            $FHandle = fopen($LogFile, "r");
            if ($FHandle === false) {
                $this->logError(self::LOGLVL_WARNING, "Unable to open log file \""
                        .$LogFile."\".");
                return [];
            }
            $FileSize = filesize($LogFile);
            $SeekPosition = max(
                0,
                ($FileSize - (self::LOGFILE_MAX_LINE_LENGTH
                        * ($Limit + 1)))
            );
            fseek($FHandle, $SeekPosition);
            $Block = fread($FHandle, max(1, $FileSize - $SeekPosition));
            fclose($FHandle);
            if ($Block === false) {
                $this->logError(self::LOGLVL_WARNING, "Unable to read from log file \""
                        .$LogFile."\".");
                return [];
            }
            $Lines = explode(PHP_EOL, $Block);
            array_pop($Lines);

            # prune array back to requested number of entries
            $Lines = array_slice($Lines, (0 - $Limit));
        } else {
            # load all lines from log file
            $Lines = file($LogFile, FILE_IGNORE_NEW_LINES);
            if ($Lines === false) {
                return [];
            }
        }

        # reverse line order
        $Lines = array_reverse($Lines);

        # for each log file line
        $Entries = [];
        foreach ($Lines as $Line) {
            # attempt to parse line into component parts
            $Pieces = explode(" ", $Line, 5);
            $Date = isset($Pieces[0]) ? $Pieces[0] : "";
            $Time = isset($Pieces[1]) ? $Pieces[1] : "";
            $Back = isset($Pieces[2]) ? $Pieces[2] : "";
            $Level = isset($Pieces[3]) ? $Pieces[3] : "";
            $Msg = isset($Pieces[4]) ? $Pieces[4] : "";

            # skip line if it looks invalid
            $ErrorAbbrevs = [
                "FTL" => self::LOGLVL_FATAL,
                "ERR" => self::LOGLVL_ERROR,
                "WRN" => self::LOGLVL_WARNING,
                "INF" => self::LOGLVL_INFO,
                "DBG" => self::LOGLVL_DEBUG,
                "TRC" => self::LOGLVL_TRACE,
            ];
            if ((($Back != "F") && ($Back != "B"))
                || !array_key_exists($Level, $ErrorAbbrevs)
                || !strlen($Msg)) {
                continue;
            }

            # convert parts into appropriate values and add to entries
            $Entries[] = [
                "Time" => strtotime($Date . " " . $Time),
                "Background" => ($Back == "B") ? true : false,
                "Level" => $ErrorAbbrevs[$Level],
                "Message" => $Msg,
            ];
        }

        # return entries to caller
        return $Entries;
    }

    /**
     * TRACE error logging level.  Very detailed logging, usually only used
     * when attempting to diagnose a problem in one specific section of code.
     */
    const LOGLVL_TRACE = 6;
    /**
     * DEBUG error logging level.  Information that is diagnostically helpful
     * when debugging.
     */
    const LOGLVL_DEBUG = 5;
    /**
     * INFO error logging level.  Generally-useful information, that may
     * come in handy but to which little attention is normally paid.  (This
     * should not be used for events that routinely occur with every page load.)
     */
    const LOGLVL_INFO = 4;
    /**
     * WARNING error logging level.  An event that may potentially cause
     * problems, but is automatically recovered from.
     */
    const LOGLVL_WARNING = 3;
    /**
     * ERROR error logging level.  Any error which is fatal to the operation
     * currently being performed, but does not result in overall application
     * shutdown or persistent data corruption.
     */
    const LOGLVL_ERROR = 2;
    /**
     * FATAL error logging level.  Any error which results in overall
     * application shutdown or persistent data corruption.
     */
    const LOGLVL_FATAL = 1;

    /**
     * Maximum length for a line in the log file.
     */
    const LOGFILE_MAX_LINE_LENGTH = 2048;

    /*@)*/ /* Logging */


    # ---- Event Handling ----------------------------------------------------

    /** @name Event Handling */ /*@(*/

    /**
     * Default event type.  Any handler return values are ignored.
     */
    const EVENTTYPE_DEFAULT = 1;
    /**
     * Result chaining event type.  For this type the parameter array to each
     * event handler is the return value from the previous handler, and the
     * final return value is sent back to the event signaller.
     */
    const EVENTTYPE_CHAIN = 2;
    /**
     * First response event type.  For this type event handlers are called
     * until one returns a non-NULL result, at which point no further handlers
     * are called and that last result is passed back to the event signaller.
     */
    const EVENTTYPE_FIRST = 3;
    /**
     * Named result event type.  Return values from each handler are placed into an
     * array with the handler (function or class::method) name as the index, and
     * that array is returned to the event signaller.  The handler name for
     * class methods is the class name plus "::" plus the method name.
     * are called and that last result is passed back to the event signaller.
     */
    const EVENTTYPE_NAMED = 4;

    /** Handle item first (i.e. before ORDER_MIDDLE items). */
    const ORDER_FIRST = 1;
    /** Handle item after ORDER_FIRST and before ORDER_LAST items. */
    const ORDER_MIDDLE = 2;
    /** Handle item last (i.e. after ORDER_MIDDLE items). */
    const ORDER_LAST = 3;

    /**
     * Register one or more events that may be signaled.
     * @param array|string $EventsOrEventName Name of event (string).  To register multiple
     *       events, this may also be an array, with the event names as the index
     *       and the event types as the values.
     * @param int $EventType Type of event (constant).  (OPTIONAL if EventsOrEventName
     *       is an array of events)
     */
    public function registerEvent($EventsOrEventName, ?int $EventType = null): void
    {
        # convert parameters to array if not already in that form
        $Events = is_array($EventsOrEventName) ? $EventsOrEventName
            : [ $EventsOrEventName => $EventType ];

        # for each event
        foreach ($Events as $Name => $Type) {
            # store event information
            $this->RegisteredEvents[$Name]["Type"] = $Type;
            $this->RegisteredEvents[$Name]["Hooks"] = [];
        }
    }

    /**
     * Check if event has been registered (is available to be signaled).
     * @param string $EventName Name of event (string).
     * @return bool TRUE if event is registered, otherwise FALSE.
     * @see isHookedEvent()
     */
    public function isRegisteredEvent(string $EventName): bool
    {
        return array_key_exists($EventName, $this->RegisteredEvents)
            ? true : false;
    }

    /**
     * Check if an event is registered and is hooked to.
     * @param string $EventName Name of event.
     * @return bool Returns TRUE if the event is hooked, otherwise FALSE.
     * @see isRegisteredEvent()
     */
    public function isHookedEvent(string $EventName): bool
    {
        # the event isn't hooked to if it isn't even registered
        if (!$this->isRegisteredEvent($EventName)) {
            return false;
        }

        # return TRUE if there is at least one callback hooked to the event
        return count($this->RegisteredEvents[$EventName]["Hooks"]) > 0;
    }

    /**
     * Hook one or more functions to be called when the specified event is
     * signaled.
     * @param array|string $EventsOrEventName Name of the event to hook.  To hook multiple
     *       events, this may also be an array, with the event names as the index
     *       and the callbacks as the values.
     * @param callable $Callback Function to be called when event is signaled.  (OPTIONAL
     *       if EventsOrEventName is an array of events)
     * @param int $Order Preference for when function should be called, primarily for
     *       CHAIN and FIRST events.  (OPTIONAL, defaults to ORDER_MIDDLE)
     * @return bool TRUE if all callbacks were successfully hooked, otherwise FALSE.
     */
    public function hookEvent(
        $EventsOrEventName,
        ?callable $Callback = null,
        int $Order = self::ORDER_MIDDLE
    ): bool {
        # convert parameters to array if not already in that form
        $Events = is_array($EventsOrEventName) ? $EventsOrEventName
            : [ $EventsOrEventName => $Callback ];

        # for each event
        $Success = true;
        foreach ($Events as $EventName => $EventCallback) {
            # if callback is valid
            if (is_callable($EventCallback)) {
                # if this is a periodic event we process internally
                if (isset($this->PeriodicEvents[$EventName])) {
                    # process event now
                    $this->processPeriodicEvent($EventName, $EventCallback);
                    # else if specified event has been registered
                } elseif (isset($this->RegisteredEvents[$EventName])) {
                    # add callback for event
                    $this->RegisteredEvents[$EventName]["Hooks"][]
                            = [ "Callback" => $EventCallback, "Order" => $Order ];

                    # sort callbacks by order
                    if (count($this->RegisteredEvents[$EventName]["Hooks"]) > 1) {
                        usort(
                            $this->RegisteredEvents[$EventName]["Hooks"],
                            function ($A, $B) {
                                return StdLib::sortCompare(
                                    $A["Order"],
                                    $B["Order"]
                                );
                            }
                        );
                    }
                } else {
                    $Success = false;
                }
            } else {
                $Success = false;
            }
        }

        # report to caller whether all callbacks were hooked
        return $Success;
    }

    /**
     * Unhook one or more functions that were previously hooked to be called
     * when the specified event is signaled.
     * @param array|string $EventsOrEventName Name of the event to unhook.  To
     *       unhook multiple events, this may also be an array, with the event
     *       names as the index and the callbacks as the values.
     * @param callable $Callback Function to be called when event is signaled.
     *       (OPTIONAL if EventsOrEventName is an array of events)
     * @param int $Order Preference for when function should be called, primarily for
     *       CHAIN and FIRST events.  (OPTIONAL, defaults to ORDER_MIDDLE)
     * @return int Number of event/callback pairs unhooked.
     */
    public function unhookEvent(
        $EventsOrEventName,
        ?callable $Callback = null,
        int $Order = self::ORDER_MIDDLE
    ): int {
        # convert parameters to array if not already in that form
        $Events = is_array($EventsOrEventName)
                ? $EventsOrEventName
                : [ $EventsOrEventName => $Callback ];

        # for each event
        $UnhookCount = 0;
        foreach ($Events as $EventName => $EventCallback) {
            # if this event has been registered and hooked
            if (isset($this->RegisteredEvents[$EventName])
                && count($this->RegisteredEvents[$EventName])) {
                # if this callback has been hooked for this event
                $CallbackData = [ "Callback" => $EventCallback, "Order" => $Order ];
                $Hooks = $this->RegisteredEvents[$EventName]["Hooks"];
                if (in_array($CallbackData, $Hooks)) {
                    # unhook callback
                    $HookIndex = array_search($CallbackData, $Hooks);
                    unset($this->RegisteredEvents[$EventName]["Hooks"][$HookIndex]);
                    $UnhookCount++;
                }
            }
        }

        # report number of callbacks unhooked to caller
        return $UnhookCount;
    }

    /**
     * Signal that an event has occured.
     * @param string $EventName Name of event being signaled.
     * @param array $Parameters Associative array of parameters for event,
     *      with CamelCase names as indexes.  The order of the array
     *      MUST correspond to the order of the parameters expected by the
     *      signal handlers.  (OPTIONAL)
     * @return mixed Appropriate return value for event type.  Returns
     *      NULL if no event with specified name was registered and for
     *      EVENTTYPE_DEFAULT events.
     */
    public function signalEvent(string $EventName, array $Parameters = [])
    {
        $ReturnValue = null;

        # if event has been registered
        if (isset($this->RegisteredEvents[$EventName])) {
            # set up default return value (if not NULL)
            switch ($this->RegisteredEvents[$EventName]["Type"]) {
                case self::EVENTTYPE_CHAIN:
                    $ReturnValue = $Parameters;
                    break;

                case self::EVENTTYPE_NAMED:
                    $ReturnValue = [];
                    break;
            }

            # for each callback for this event
            foreach ($this->RegisteredEvents[$EventName]["Hooks"] as $Hook) {
                # invoke callback
                $Callback = $Hook["Callback"];
                $Result = count($Parameters)
                    ? call_user_func_array($Callback, array_values($Parameters))
                    : call_user_func($Callback);

                # process return value based on event type
                switch ($this->RegisteredEvents[$EventName]["Type"]) {
                    case self::EVENTTYPE_CHAIN:
                        if ($Result !== null) {
                            foreach ($Parameters as $Index => $Value) {
                                if (array_key_exists($Index, $Result)) {
                                    $Parameters[$Index] = $Result[$Index];
                                }
                            }
                            $ReturnValue = $Parameters;
                        }
                        break;

                    case self::EVENTTYPE_FIRST:
                        if ($Result !== null) {
                            $ReturnValue = $Result;
                            break 2;
                        }
                        break;

                    case self::EVENTTYPE_NAMED:
                        $CallbackName = is_array($Callback)
                            ? (is_object($Callback[0])
                                ? get_class($Callback[0])
                                : $Callback[0]) . "::" . $Callback[1]
                            : $Callback;
                        $ReturnValue[$CallbackName] = $Result;
                        break;

                    default:
                        break;
                }
            }
        } else {
            $this->logError(
                self::LOGLVL_WARNING,
                "Unregistered event (" . $EventName . ") signaled by "
                . StdLib::getMyCaller() . "."
            );
        }

        # return value if any to caller
        return $ReturnValue;
    }

    /**
     * Report whether specified event only allows static callbacks.
     * @param string $EventName Name of event to check.
     * @return bool TRUE if specified event only allows static callbacks, otherwise FALSE.
     */
    public function isStaticOnlyEvent(string $EventName): bool
    {
        return isset($this->PeriodicEvents[$EventName]) ? true : false;
    }

    /**
     * Get date/time a periodic event will next run.  This is when the event
     * should next go into the event queue, so it is the earliest time the
     * event might run.  Actual execution time will depend on whether there
     * are other events already in the queue.
     * @param string $EventName Periodic event name (e.g. "EVENT_DAILY").
     * @param callable $Callback Event callback.
     * @return int|false Next run time as a timestamp, or FALSE if event was not
     *       a periodic event or was not previously run.
     */
    public function eventWillNextRunAt(string $EventName, callable $Callback)
    {
        # if event is not a periodic event report failure to caller
        if (!array_key_exists($EventName, $this->EventPeriods)) {
            return false;
        }

        # retrieve last execution time for event if available
        $Signature = self::getCallbackSignature($Callback);
        $LastRunTime = $this->DB->queryValue("SELECT LastRunAt FROM PeriodicEvents"
            . " WHERE Signature = '" . addslashes($Signature) . "'", "LastRunAt");

        # if event was not found report failure to caller
        if ($LastRunTime === null) {
            return false;
        }

        # calculate next run time based on event period
        $NextRunTime = strtotime($LastRunTime) + $this->EventPeriods[$EventName];

        # report next run time to caller
        return $NextRunTime;
    }

    /**
     * Get list of known periodic events.  This returns a list with information
     * about periodic events that have been hooked this invocation, and when they
     * are next expected to run.  The array returned has the following values:
     * - Callback - Callback for event.
     * - Period - String containing "EVENT_" followed by period.
     * - LastRun - Timestamp for when the event was last run or FALSE if event
     *       was never run or last run time is not known.  This value is always
     *       FALSE for periodic events.
     * - NextRun - Timestamp for the earliest time when the event will next run
     *       or FALSE if next run time is not known.
     * - Parameters - (present for compatibility but always NULL)
     *
     * @return array List of info about known periodic events.
     */
    public function getKnownPeriodicEvents(): array
    {
        # retrieve last execution times
        $this->DB->query("SELECT * FROM PeriodicEvents");
        $LastRunTimes = $this->DB->fetchColumn("LastRunAt", "Signature");

        # for each known event
        $Events = [];
        foreach ($this->KnownPeriodicEvents as $Signature => $Info) {
            # if last run time for event is available
            if (array_key_exists($Signature, $LastRunTimes)) {
                # calculate next run time for event
                $LastRun = strtotime($LastRunTimes[$Signature]);
                $NextRun = $LastRun + $this->EventPeriods[$Info["Period"]];
                if ($Info["Period"] == "EVENT_PERIODIC") {
                    $LastRun = false;
                }
            } else {
                # set info to indicate run times are not known
                $LastRun = false;
                $NextRun = false;
            }

            # add event info to list
            $Events[$Signature] = $Info;
            $Events[$Signature]["LastRun"] = $LastRun;
            $Events[$Signature]["NextRun"] = $NextRun;
            $Events[$Signature]["Parameters"] = null;
        }

        # return list of known events to caller
        return $Events;
    }

    /**
     * Run periodic event and then save info needed to know when to run it again.
     * @param string $EventName Name of event.
     * @param callable $Callback Event callback.
     * @param array $Parameters Array of parameters to pass to event.
     */
    public static function runPeriodicEvent(
        string $EventName,
        callable $Callback,
        array $Parameters
    ): void {
        static $DB;
        if (!isset($DB)) {
            $DB = new Database();
        }

        # run event
        $CallbackParamCount = StdLib::getReflectionForCallback(
            $Callback
        )->getNumberOfParameters();
        if ($CallbackParamCount == 0) {
            $ReturnVal = $Callback();
        } else {
            $ReturnVal = $Callback(...array_values($Parameters));
        }

        # if event is already in database
        $Signature = self::getCallbackSignature($Callback);
        if ($DB->queryValue("SELECT COUNT(*) AS EventCount FROM PeriodicEvents"
            . " WHERE Signature = '" . addslashes($Signature) . "'", "EventCount")) {
            # update last run time for event
            $DB->query("UPDATE PeriodicEvents SET LastRunAt = "
                . (($EventName == "EVENT_PERIODIC")
                    ? "'" . date(StdLib::SQL_DATE_FORMAT, time() + ($ReturnVal * 60)) . "'"
                    : "NOW()")
                . " WHERE Signature = '" . addslashes($Signature) . "'");
        } else {
            # add last run time for event to database
            $DB->query("INSERT INTO PeriodicEvents (Signature, LastRunAt) VALUES "
                . "('" . addslashes($Signature) . "', "
                . (($EventName == "EVENT_PERIODIC")
                    ? "'" . date(StdLib::SQL_DATE_FORMAT, time() + ($ReturnVal * 60)) . "'"
                    : "NOW()") . ")");
        }
    }

    /*@)*/ /* Event Handling */


    # ---- Server Environment ------------------------------------------------

    /** @name Server Environment */ /*@(*/

    /**
     * Get/set session timeout in seconds.
     * @param int $NewValue New session timeout value.  (OPTIONAL)
     * @return int Current session timeout value in seconds.
     */
    public function sessionLifetime(?int $NewValue = null)
    {
        # if we don't yet have a SessionLifetime column because the update
        # to create it hasn't yet run, use the default value
        if (!isset($this->Settings["SessionLifetime"])) {
            return 1800;
        }

        return $this->updateIntSetting("SessionLifetime", $NewValue);
    }

    /**
     * Check if rewrite support for clean URLs appears to be available.
     * This method depends on the environment variable CLEAN_URL_SUPPORT being
     * set in .htaccess.
     * @return bool TRUE if clean URL support appears to be available,
     *      or FALSE otherwise
     */
    public static function cleanUrlSupportAvailable(): bool
    {
        return isset($_SERVER["CLEAN_URL_SUPPORT"])
            || isset($_SERVER["REDIRECT_CLEAN_URL_SUPPORT"]);
    }

    /**
     * Determine if rewrite support for URL fingerprinting is available.  This
     * method depends on the environment variable URL_FINGERPRINTING_SUPPORT
     * being set in .htaccess.
     * @return bool TRUE if URL fingerprinting support is available or FALSE otherwise
     */
    public static function urlFingerprintingRewriteSupport(): bool
    {
        return isset($_SERVER["URL_FINGERPRINTING_SUPPORT"])
            || isset($_SERVER["REDIRECT_URL_FINGERPRINTING_SUPPORT"]);
    }

    /**
     * Determine if SCSS rewrite support is available.  This method
     * depends on the environment variable SCSS_REWRITE_SUPPORT being
     * set in .htaccess.
     * @return bool TRUE if SCSS rewrite support is available or FALSE otherwise
     */
    public static function scssRewriteSupport(): bool
    {
        return isset($_SERVER["SCSS_REWRITE_SUPPORT"])
            || isset($_SERVER["REDIRECT_SCSS_REWRITE_SUPPORT"]);
    }

    /**
     * Determine if rewrite support for JavaScript minification is available.
     * This method depends on the environment variable JSMIN_REWRITE_SUPPORT
     * being set in .htaccess.
     * @return bool TRUE if URL fingerprinting is available or FALSE otherwise
     */
    public static function jsMinRewriteSupport(): bool
    {
        return isset($_SERVER["JSMIN_REWRITE_SUPPORT"])
            || isset($_SERVER["REDIRECT_JSMIN_REWRITE_SUPPORT"]);
    }

    /**
     * Get portion of current URL through host name, with no trailing
     * slash (e.g. http://foobar.com).
     * @return string URL portion.
     * @see ApplicationFramework::preferHttpHost()
     */
    public static function rootUrl(): string
    {
        # determine scheme name
        $Protocol = (isset($_SERVER["HTTPS"]) ? "https" : "http");

        # if no server info available
        if (!isset($_SERVER["SERVER_NAME"])) {
            # fall back to host name for domain
            $DomainName = gethostname();
        # if HTTP_HOST is preferred or SERVER_NAME points to localhost
        #       and HTTP_HOST is set
        } elseif ((self::$PreferHttpHost || ($_SERVER["SERVER_NAME"] == "127.0.0.1"))
            && isset($_SERVER["HTTP_HOST"])) {
            # use HTTP_HOST for domain name
            $DomainName = $_SERVER["HTTP_HOST"];
        } else {
            # use SERVER_NAME for domain name
            $DomainName = $_SERVER["SERVER_NAME"];
        }

        # build URL root
        $Url = $Protocol . "://" . $DomainName;

        # add port number if non-standard
        $Port = $_SERVER["SERVER_PORT"] ?? (($Protocol == "https") ? "443" : "80");
        if (($Port != "80") && ($Port != "443")) {
            $Url .= ":".$Port;
        }

        return $Url;
    }

    /**
     * Get current base URL (the part before index.php) (e.g. http://foobar.com/path/).
     * The base URL is determined using the ultimate executing URL, after
     * any clean URL remapping has been applied, so any extra "directory"
     * segments that are really just part of a clean URL will not be included.
     * @return string Base URL string with trailing slash.
     * @see ApplicationFramework::preferHttpHost()
     */
    public static function baseUrl(): string
    {
        static $BaseUrl = null;
        if (is_null($BaseUrl)) {
            $BaseUrl = self::rootUrl() . dirname($_SERVER["SCRIPT_NAME"]);
            if (substr($BaseUrl, -1) != "/") {
                $BaseUrl .= "/";
            }
        }
        return $BaseUrl;
    }

    /**
     * Get current full URL, before any clean URL remapping and with any query
     * string (e.g. http://foobar.com/path/index.php?A=123&B=456).
     * @return string Full URL.
     * @see ApplicationFramework::preferHttpHost()
     */
    public static function fullUrl(): string
    {
        return self::rootUrl() . ($_SERVER["REQUEST_URI"] ?? "");
    }

    /**
     * Get/set whether to prefer $_SERVER["HTTP_HOST"] (if available) over
     * $_SERVER["SERVER_NAME"] when determining the current URL.  The default
     * is FALSE.
     * @param bool $NewValue TRUE to prefer HTTP_HOST, or FALSE to prefer SERVER_NAME.
     * @return bool TRUE if HTTP_HOST is currently preferred, otherwise FALSE.
     * @see ApplicationFramework::rootUrl()
     * @see ApplicationFramework::baseUrl()
     * @see ApplicationFramework::fullUrl()
     */
    public static function preferHttpHost(?bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            self::$PreferHttpHost = ($NewValue ? true : false);
        }
        return self::$PreferHttpHost;
    }

    /**
     * Get current base path (usually the part after the host name).
     * @return string Base path string with trailing slash.
     */
    public static function basePath(): string
    {
        $BasePath = dirname($_SERVER["SCRIPT_NAME"]);

        if (substr($BasePath, -1) != "/") {
            $BasePath .= "/";
        }

        return $BasePath;
    }

    /**
     * Report whether a specified URL appears to be external (i.e. for a page
     * outside of the site).
     * @param string $Url URL to check.
     * @return bool TRUE if URL looks like it is for an outside page, otherwise FALSE.
     */
    public static function urlIsExternal(string $Url): bool
    {
        $UrlStart = substr($Url, 0, 7);
        if (($UrlStart != "http://") && ($UrlStart != "https:/")) {
            return false;
        }
        $UrlParts = parse_url($Url);
        if (($UrlParts === false) || !isset($UrlParts["host"])
                || ($UrlParts["host"] != parse_url(self::rootUrl(), PHP_URL_HOST))) {
            return false;
        }
        return true;
    }

    /**
     * Get the domain being used for the current request.
     * @return string Domain being used, or an empty string if unable to
     *      determine the domain.
     */
    public static function getCurrentDomain() : string
    {
        static $Domain = false;
        if ($Domain === false) {
            $Domain = parse_url(
                ApplicationFramework::rootUrl(),
                PHP_URL_HOST
            ) ?? "";
        }
        return $Domain;
    }


    /**
     * Determine if the URL was rewritten, i.e., the script is being accessed
     * through a URL that isn't directly accessing the file the script is in.
     * This is not equivalent to determining whether a clean URL is set up for
     * the URL.
     * @param string $ScriptName The file name of the running script.
     * @return bool Returns TRUE if the URL was rewritten and FALSE if not.
     */
    public static function wasUrlRewritten(string $ScriptName = "index.php"): bool
    {
        # get path portion of URL (does not include query or fragment)
        $Path = parse_url(self::getUrlPath(), PHP_URL_PATH);

        if (is_string($Path)) {
            $BasePath = self::basePath();

            # if path ends in slash, then we're being accessed as a directory index
            if (substr($Path, -1) == "/") {
                $Path .= "index.php";
            }

            # the URL was rewritten if the path isn't the path to this script
            if ($BasePath.$ScriptName != $Path) {
                return true;
            }
        }

        # the URL wasn't rewritten
        return false;
    }

    /**
     * Determine if we were reached via an AJAX-based (or other automated)
     * page load.  This is dependent on either a JavaScript framework (e.g.
     * jQuery) setting the appropriate value before making the request, or
     * on some code explicitly calling this method to set a value for it.
     * @param bool $NewSetting New (forced override) value for this.
     * @return bool TRUE if page was loaded via AJAX (or other automated
     *       method), otherwise FALSE.
     */
    public static function reachedViaAjax(?bool $NewSetting = null): bool
    {
        if ($NewSetting !== null) {
            self::$IsAjaxPageLoad = $NewSetting;
        }

        if (isset(self::$IsAjaxPageLoad)) {
            return self::$IsAjaxPageLoad;
        } elseif (isset($_SERVER["HTTP_X_REQUESTED_WITH"])
            && (strtolower($_SERVER["HTTP_X_REQUESTED_WITH"])
                == "xmlhttprequest")) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Log a message about slow database queries.
     * @param string $Query SQL query
     * @param array $QuerySite Location that issued the query in
     *   the format provided by debug_backtrace()
     * @param float $Duration Time taken by the query in seconds
     */
    public static function logSlowDBQuery(
        string $Query,
        array $QuerySite,
        float $Duration
    ): void {
        $QuerySiteDesc = self::stackFrameSummary($QuerySite);

        # reduce repeated strings of whitespace to single spaces
        $Query = preg_replace('/\h+/', ' ', trim($Query));

        # truncate long queries
        if (strlen($Query) > 300) {
            $Query = trim(substr($Query, 0, 300))."...";
        }

        $AF = self::getInstance();
        $AF->logMessage(
            self::LOGLVL_INFO,
            "Slow database query (".round($Duration, 2)."s)"
                ." at ".$QuerySiteDesc."."
                ."  QUERY: ".$Query
                ."  URL: ".$AF->fullUrl()
                ."  IP: ".($_SERVER["REMOTE_ADDR"] ?? "(none)")
        );
    }

    /**
     * Log a message when the database caches are pruned.
     * @param array $QuerySite Location that issued the query prompting the
     *   cache pruning in the format provided by debug_backtrace()
     * @param array $CachedQueries Queries that were in the cache when it was pruned
     */
    public static function logDBCachePrune(
        array $QuerySite,
        array $CachedQueries
    ): void {
        $AF = self::getInstance();
        if (!$AF->logDBCachePruning()) {
            return;
        }

        $QuerySiteDesc = self::stackFrameSummary($QuerySite);

        $NumCacheEntries = count($CachedQueries);

        # compute size of each result in cache
        $QuerySizes = [];
        foreach ($CachedQueries as $Query => $Results) {
            $Size = 0;
            foreach ($Results as $Index => $Row) {
                if ($Index == "NumRows") {
                    continue;
                }
                foreach ($Row as $Col) {
                    $Size += strlen((string)$Col);
                }
            }

            $QuerySizes[$Query] = $Size;
        }

        # get the largest five
        arsort($QuerySizes, SORT_NUMERIC);
        $QuerySizes = array_slice($QuerySizes, 0, 5, true);

        # generate a summary of those five
        $CacheSummary = "";
        foreach ($QuerySizes as $Query => $Size) {
            if (strlen($Query) > 300) {
                $Query = trim(substr($Query, 0, 300))."...";
            }
            $CacheSummary .= " ".$Query." (Result Size: ".number_format($Size)." bytes);";
        }

        $AF->logMessage(
            self::LOGLVL_INFO,
            "Database caches pruned at ".$QuerySiteDesc."."
                ." Cache contained ".$NumCacheEntries." entries."
                ." Largest five result sets summarized here."
                ."  URL: ".$AF->fullUrl()
                ."  IP: ".($_SERVER["REMOTE_ADDR"] ?? "(none)")
                ."  CACHE:".$CacheSummary
        );
    }

    /**
     * Get/set maximum PHP execution time.  Setting a new value is not possible
     * if PHP is running in safe mode.  Note that this method returns the actual
     * maximum execution time as currently understood by PHP, which could be
     * different from the saved ApplicationFramework setting.  To prevent hazardous
     * behavior due to malfunctioning PHP setting access, if a value of 0 is returned
     * by ini_get() for max_execution_time, this method logs an error with a level
     * of LOGLVL_WARNING and returns 30 instead.
     * @param int $NewValue New setting for max execution time in seconds.  (OPTIONAL,
     *       but minimum value is 5 if specified)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current max execution time in seconds.
     */
    public function maxExecutionTime(?int $NewValue = null, bool $Persistent = false): int
    {
        if ($NewValue !== null) {
            $NewValue = max($NewValue, 5);
            ini_set("max_execution_time", (string)$NewValue);
            set_time_limit($NewValue - (int)$this->getElapsedExecutionTime());
            $this->updateIntSetting("MaxExecTime", $NewValue, $Persistent);
        }
        $CurrentValue = (int)ini_get("max_execution_time");
        if ($CurrentValue == 0) {
            $CurrentValue = 30;
            $this->logError(
                self::LOGLVL_WARNING,
                "PHP max_execution_time value was 0."
            );
        }
        return $CurrentValue;
    }

    /**
     * Get/set threshold for when database queries are considered "slow" when
     * running in the foreground.
     * @param int $NewValue New threshold in seconds.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current threshold in seconds.
     */
    public function databaseSlowQueryThresholdForForeground(
        ?int $NewValue = null,
        bool $Persistent = false
    ): int {
        if (($NewValue !== null) && !$this->isRunningInBackground()) {
            Database::slowQueryThreshold($NewValue);
        }
        return $this->updateIntSetting(
            "DbSlowQueryThresholdForeground",
            $NewValue,
            $Persistent
        );
    }

    /**
     * Get/set threshold for when database queries are considered "slow" when
     * running in the background.
     * @param int $NewValue New threshold in seconds.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to FALSE)
     * @return int Current threshold in seconds.
     */
    public function databaseSlowQueryThresholdForBackground(
        ?int $NewValue = null,
        bool $Persistent = false
    ): int {
        if (($NewValue !== null) && $this->isRunningInBackground()) {
            Database::slowQueryThreshold($NewValue);
        }
        return $this->updateIntSetting(
            "DbSlowQueryThresholdBackground",
            $NewValue,
            $Persistent
        );
    }

    /** minimum threshold for what is considered a slow database query */
    const MIN_DB_SLOW_QUERY_THRESHOLD = 2;

    /*@)*/ /* Server Environment */


    # ---- Utility -----------------------------------------------------------

    /** @name Utility */ /*@(*/

    /**
     * Send specified file for download by user.  This method takes care of
     * setting up the headers and suppressing further output, and is normally
     * called from within the page file.
     * @param string $FilePath Full path to file.
     * @param string $FileName Name of file.  If not supplied, the name will
     *      be taken from the file path.  (OPTIONAL)
     * @param string $MimeType MIME type of file.  If not supplied, an attempt
     *      will be made to determine the MIME type.  (OPTIONAL)
     * @param bool $AlwaysDownload Always include Content-Disposition header
     *      that indicates to download the file, even for types where it would
     *      otherwise be left up to the browser to decide (e.g. images, PDFs).
     *      (OPTIONAL, defaults to FALSE)
     * @return bool TRUE if no issues were detected, otherwise FALSE.
     */
    public function downloadFile(
        string $FilePath,
        ?string $FileName = null,
        ?string $MimeType = null,
        bool $AlwaysDownload = false
    ): bool {
        # check that file is readable
        if (!is_readable($FilePath)) {
            return false;
        }

        # if file name was not supplied
        if ($FileName === null) {
            # extract file name from path
            $FileName = basename($FilePath);
        }

        # if MIME type was not supplied
        if ($MimeType === null) {
            # attempt to determine MIME type
            $FInfoHandle = finfo_open(FILEINFO_MIME);
            if ($FInfoHandle) {
                $FInfoMime = finfo_file($FInfoHandle, $FilePath);
                finfo_close($FInfoHandle);
                if ($FInfoMime) {
                    $MimeType = $FInfoMime;
                }
            }

            # use default if unable to determine MIME type
            if ($MimeType === null) {
                $MimeType = "application/octet-stream";
            }
        }

        header("Content-Type: " . $MimeType);

        # list of mime types where we allow the browser to decide on
        # how to display the item by omitting the Content-Disposition
        # header
        $InlineTypes = [
            "image/gif",
            "image/jpeg",
            "image/png",
            "application/pdf",
        ];

        # set headers to download file
        if ($AlwaysDownload
                || ($this->CleanUrlRewritePerformed
                        && !in_array($MimeType, $InlineTypes))) {
            header('Content-Disposition: attachment; filename="' . $FileName . '"');
        }

        # make sure that apache does not attempt to compress file
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }

        # send file to user, but unbuffered to avoid memory issues
        $this->addUnbufferedCallback(function ($File) {
            $FileSize = (int)filesize($File);
            $BlockSize = 512000;

            $Handle = @fopen($File, "rb");
            if ($Handle === false) {
                return;
            }

            # (close out session, making it read-only, so that session file
            #       lock is released and others are not potentially hanging
            #       waiting for it while the download completes)
            session_write_close();

            if (self::isSupportedRangeRequest()) {
                self::handleRangeRequest($Handle, $FileSize, $BlockSize);
            } else {
                header("Content-Length: " . $FileSize);
                while (!feof($Handle)) {
                    print fread($Handle, $BlockSize);
                    flush();
                }
            }

            fclose($Handle);
        }, [ $FilePath ]);

        # prevent HTML output that might interfere with download
        $this->suppressHtmlOutput();

        # set flag to indicate not to log a slow page load in case client
        #       connection delays PHP execution because of header
        $this->DoNotLogSlowPageLoad = true;

        # report no errors found to caller
        return true;
    }

    /**
     * Get an exclusive ("write") lock on the specified name.  If the
     * maximum PHP execution time is being modified in proximity to
     * obtaining a lock (e.g. because a task will take longer than typical),
     * that should be done before calling getLock().
     * @param string $LockName Name of lock.  (OPTIONAL, defaults to
     *      name of calling method)
     * @param bool $Wait If TRUE, method will not return until a lock has
     *      been obtained.  (OPTIONAL, defaults to TRUE)
     * @return bool TRUE if lock was obtained, otherwise FALSE.
     * @see ApplicationFramework::releaseLock()
     * @see ApplicationFramework::maxExecutionTime()
     */
    public function getLock(?string $LockName = null, bool $Wait = true): bool
    {
        # use name of calling function if lock name if not supplied
        if ($LockName === null) {
            $LockName = StdLib::getCallerInfo()["Function"];
        }

        # assume we will not get a lock
        $GotLock = false;

        # clear out any stale locks
        static $CleanupHasBeenDone = false;
        if (!$CleanupHasBeenDone) {
            # (margin for clearing stale locks is twice the known
            #       maximum PHP execution time, because the max time
            #       techinically does not include external operations
            #       like database queries)
            $ClearLocksObtainedBefore = date(
                StdLib::SQL_DATE_FORMAT,
                (time() - ($this->maxExecutionTime() * 2))
            );
            $this->DB->query("DELETE FROM AF_Locks WHERE"
                . " ObtainedAt < '" . $ClearLocksObtainedBefore . "' AND"
                . " LockName = '" . addslashes($LockName) . "'");
        }

        do {
            # lock database table so nobody else can try to get a lock
            $this->DB->query("LOCK TABLES AF_Locks WRITE");

            # look for lock with specified name
            $FoundCount = $this->DB->queryValue("SELECT COUNT(*) AS FoundCount"
                . " FROM AF_Locks WHERE LockName = '"
                . addslashes($LockName) . "'", "FoundCount");
            $LockFound = ($FoundCount > 0) ? true : false;

            # if lock found
            if ($LockFound) {
                # unlock database tables
                $this->DB->query("UNLOCK TABLES");

                # if blocking was requested
                if ($Wait) {
                    # wait to give someone else a chance to release lock
                    sleep(2);
                }
            }
            // @codingStandardsIgnoreStart
            // (because phpcs does not correctly handle do-while loops)
            # while lock was found and blocking was requested
        } while ($LockFound && $Wait);
        // @codingStandardsIgnoreEnd

        # if lock was not found
        if (!$LockFound) {
            # get our lock
            $this->DB->query("INSERT INTO AF_Locks (LockName) VALUES ('"
                . addslashes($LockName) . "')");
            $GotLock = true;

            # unlock database tables
            $this->DB->query("UNLOCK TABLES");
        }

        # report to caller whether lock was obtained
        return $GotLock;
    }

    /**
     * Release lock with specified name.
     * @param string $LockName Name of lock.  (OPTIONAL, defaults to
     *      name of calling method)
     * @return bool TRUE if an existing lock was released, or FALSE if no lock
     *      with specified name was found.
     * @see ApplicationFramework::getLock()
     */
    public function releaseLock(?string $LockName = null): bool
    {
        # use name of calling function if lock name if not supplied
        if ($LockName === null) {
            $LockName = StdLib::getCallerInfo()["Function"];
        }

        # release any existing locks
        $this->DB->query("DELETE FROM AF_Locks WHERE LockName = '"
            . addslashes($LockName) . "'");

        # report to caller whether existing lock was released
        return $this->DB->numRowsAffected() ? true : false;
    }

    /**
     * Begin an AJAX response, setting the necessary HTTP headers and
     * optionally closing the PHP session. Note that badly-behaved
     * clients may ignore these headers. When PHP starts processing a
     * pageload, it grabs an exclusive lock on the session file. If we
     * don't release this lock by closing the session, it will
     * serialize AJAX requests. This can be less than ideal for
     * requests that are 1) somewhat slow to run to completion (like
     * searching in large vocabularies) and 2) tend to arrive in a
     * flurry (e.g., as a user types a search string). Also sets
     * headers controlling client-side caching, configuring a 30 second
     * cache expiry. This can be extended with a subsequent call to
     * ApplicationFramework::setBrowserCacheExpirationTime().
     * @param string $ResponseType Type of the response as one of
     *     "JSON", "XML", or "HTML". (OPTIONAL, default "JSON").
     * @param bool $CloseSession FALSE not to close the session
     *     (OPTIONAL, default TRUE).
     */
    public function beginAjaxResponse(
        string $ResponseType = "JSON",
        bool $CloseSession = true
    ): void {
        switch ($ResponseType) {
            case "JSON":
                $this->suppressHtmlOutput();
                header("Content-Type: application/json; charset="
                        .$this->HtmlCharset, true);
                break;
            case "XML":
                $this->suppressHtmlOutput();
                header("Content-Type: application/xml; charset="
                        .$this->HtmlCharset, true);
                break;
            case "HTML":
                break;
            default:
                throw new Exception(
                    "Unsupported response type: " . $ResponseType
                );
        }

        $this->setBrowserCacheExpirationTime(
            self::$DefaultBrowserCacheExpiration
        );

        if ($CloseSession) {
            session_write_close();
        }
    }

    /**
     * Set headers to control client-side caching of data served to the
     * browser in this page load (usually JSON, XML, or HTML).
     * @param int $MaxAge Max number of seconds a page should be cached.
     */
    public function setBrowserCacheExpirationTime(int $MaxAge): void
    {
        # set headers to control caching
        header("Expires: " . gmdate("D, d M Y H:i:s \G\M\T", time() + $MaxAge));
        header("Cache-Control: private, max-age=" . $MaxAge);
        header("Pragma:");
    }

    /**
     * Get/Set value of SessionInUse, which indicates if the current
     * session is currently in use.
     * @param bool $InUse TRUE for sessions that are in use (OPTIONAL)
     * @return bool TRUE if session is in use, otherwise FALSE.
     */
    public function sessionInUse(?bool $InUse = null): bool
    {
        if ($InUse !== null) {
            $this->SessionInUse = $InUse;
        }

        return $this->SessionInUse;
    }

    /*@)*/ /* Utility */


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $AdditionalRequiredUIFiles = [];
    private $BrowserDetectFunc;
    private $CacheCurrentPage = true;
    private $CompiledCssFileUsed = false;
    private $CssUrlFingerprintPath;
    private $CurrentPageExpirationDate = null;
    private $DB;
    private $DefaultPage = "Home";
    private $DoNotMinimizeList = [];
    private $DoNotLogSlowPageLoad = false;
    private $ExecutionStartTime;
    private $FoundUIFiles = [];
    private $HtmlCharset = "UTF-8";
    private $InterfaceSettingsCache = null;
    private $InterfaceSettingsByDirCache = null;
    private $JSMinimizerJavaScriptPackerAvailable = false;
    private $JSMinimizerJShrinkAvailable = true;
    private $JumpToPage = null;
    private $JumpToPageDelay = 0;
    private $LogFileName = "local/logs/site.log";
    private $MinimizedJsFileUsed = false;
    private $MetaTags = [];
    private $OutputModificationCallbackInfo;
    private $OutputModificationCallbacks = [];
    private $OutputModificationPatterns = [];
    private $OutputModificationReplacements = [];
    private $PageCacheTags = [];
    private $PageName = "";
    private $PostProcessingFuncs = [];
    private $RunningInBackground = false;
    private $SavedContext;
    private $SaveTemplateLocationCache = false;
    private $SessionStorage;
    private $SessionGcProbability;
    private $Settings;
    private $SuppressHTML = false;
    private $SuppressStdPageStartAndEnd = false;
    private $TemplateLocationCache;
    private $TemplateLocationCacheInterval = 60;        # in minutes
    private $TemplateLocationCacheExpiration;
    private $UnbufferedCallbacks = [];
    private $UniqueMetaTags = [];
    private $UrlFingerprintBlacklist = [];
    private $UseBaseTag = true;
    private $SessionInUse = false;

    # ---- Page Building (Internal Variables) --------------------------------
    private $CallbackForPageCacheHits;
    private $ContextFilters = [
        self::CONTEXT_START => true,
        self::CONTEXT_PAGE => [ "H_" ],
    ];
    private $CurrentLoadingContext = [];
    private $EscapedInsertionKeywords = [];
    private $HtmlFileContext;
    private $InsertionKeywordCallbacks = [];
    private $NoHtmlFileFoundMsg = "<h2>ERROR:  No HTML/TPL template found"
    . " for this page (%PAGENAME%).</h2>";
    private $PageHtmlFile = null;
    private $PagePhpFile = null;
    private $UnmatchedInsertionKeywords;

    private static $ActiveUI = "default";
    private static $AppName = "ScoutAF";
    private static $DefaultBrowserCacheExpiration = 30; # in seconds
    private static $DefaultNamespacePrefix = "";
    private static $DefaultUI = "default";
    private static $Instance;
    private static $IsAjaxPageLoad;
    private static $JSMinCacheDir = "local/data/caches/JSMin";
    private static $LocalNamespacePrefix = "";
    private static $ObjectDirectories = [];
    private static $ObjectLocationCache = [];
    private static $ObjectLocationCacheInterval = 60;
    private static $ObjectLocationCacheExpiration;
    private static $PreferHttpHost = false;
    private static $SaveObjectLocationCache = false;
    private static $ScssCacheDir = "local/data/caches/SCSS";
    private static $SuppressSessionInitialization = false;
    private static $UserInterfaceListCache = [];
    private static $UserInterfacePathsCache = [];

    # offset used to generate page cache tag IDs from numeric tags
    const PAGECACHETAGIDOFFSET = 100000;

    # minimum expired session garbage collection probability
    const MIN_GC_PROBABILITY = 0.01;

    /**
     * Set to TRUE to not close browser connection before running
     *       background tasks (useful when debugging)
     */
    private $NoTSR = false;

    private $RegisteredEvents = [];
    private $KnownPeriodicEvents = [];
    private $PeriodicEvents = [
        "EVENT_HOURLY" => self::EVENTTYPE_DEFAULT,
        "EVENT_DAILY" => self::EVENTTYPE_DEFAULT,
        "EVENT_WEEKLY" => self::EVENTTYPE_DEFAULT,
        "EVENT_MONTHLY" => self::EVENTTYPE_DEFAULT,
        "EVENT_PERIODIC" => self::EVENTTYPE_NAMED,
    ];
    private $EventPeriods = [
        "EVENT_HOURLY" => 3600,
        "EVENT_DAILY" => 86400,
        "EVENT_WEEKLY" => 604800,
        "EVENT_MONTHLY" => 2592000,
        "EVENT_PERIODIC" => 0,
    ];
    private $UIEvents = [
        "EVENT_PAGE_LOAD" => self::EVENTTYPE_CHAIN,
        "EVENT_PHP_FILE_LOAD" => self::EVENTTYPE_CHAIN,
        "EVENT_PHP_FILE_LOAD_COMPLETE" => self::EVENTTYPE_DEFAULT,
        "EVENT_HTML_FILE_LOAD" => self::EVENTTYPE_CHAIN,
        "EVENT_HTML_FILE_LOAD_COMPLETE" => self::EVENTTYPE_DEFAULT,
        "EVENT_PAGE_OUTPUT_FILTER" => self::EVENTTYPE_CHAIN,
    ];

    /**
     * Object constructor.
     **/
    protected function __construct()
    {
        # clear memory usage tracking peak (if we can) to make it more
        #       likely that our memory usage tracking will be accurate
        # (function added in PHP 8.2)
        if (function_exists("memory_reset_peak_usage")) {
            memory_reset_peak_usage();
        }

        # check that classes needed for bootstrapping are available
        if (!class_exists("ScoutLib\\Database")) {
            throw new Exception("Required class \"ScoutLib\\Database\" not available.");
        }
        if (!class_exists("ScoutLib\\StdLib")) {
            throw new Exception("Required class \"ScoutLib\\StdLib\" not available.");
        }

        # set up a class alias for convenience
        class_alias("ScoutLib\\ApplicationFramework", "AF");

        # adjust environment in case we are being run via CGI
        self::adjustEnvironmentForCgi();

        # make sure a default time zone is set
        # (using CST/CDT if nothing set because we have to use something
        #       and Scout is based in Madison, WI, USA which is in CST/CDT)
        /* @phpstan-ignore-next-line */
        if ((ini_get("date.timezone") === false)
            || !strlen(ini_get("date.timezone"))) {
            ini_set("date.timezone", "America/Chicago");
        }

        # save execution start time
        $this->ExecutionStartTime = microtime(true);

        # set up object file autoloader
        spl_autoload_register([$this, "autoloadObjects"]);

        # set up function to output any buffered text in case of crash
        register_shutdown_function([ $this, "onCrash" ]);

        # set up our internal environment
        $this->DB = new Database();
        $this->DB->setValueUpdateParameters("ApplicationFrameworkSettings");

        # load our settings from database
        $this->loadSettings();

        # if we were not invoked via command line interface
        #       and session initialization has not been explicitly suppressed
        if ((!$this->isRunningFromCommandLine()) && (!self::$SuppressSessionInitialization)) {
            # attempt to start PHP session
            $this->startPhpSession();
        }

        # set up our exception handler
        set_exception_handler([$this, "globalExceptionHandler"]);

        # set PHP maximum execution time
        ini_set("max_execution_time", $this->Settings["MaxExecTime"]);
        set_time_limit($this->Settings["MaxExecTime"]);

        # set database slow query threshold for foreground execution
        Database::slowQueryThreshold(max(
            self::MIN_DB_SLOW_QUERY_THRESHOLD,
            self::databaseSlowQueryThresholdForForeground()
        ));

        # register events we handle internally
        $this->registerEvent($this->PeriodicEvents);
        $this->registerEvent($this->UIEvents);

        # attempt to create SCSS cache directory if needed and it does not exist
        if ($this->scssSupportEnabled() && !is_dir(self::$ScssCacheDir)) {
            @mkdir(self::$ScssCacheDir, 0777, true);
        }

        # attempt to create minimized JS cache directory if needed and it does not exist
        if ($this->useMinimizedJavascript()
            && $this->javascriptMinimizationEnabled()
            && !is_dir(self::$JSMinCacheDir)) {
            @mkdir(self::$JSMinCacheDir, 0777, true);
        }

        # set up logging of notices if requested
        if ($this->logPhpNotices()) {
            set_error_handler([$this, "phpNoticeHandler"]);
        }
    }

    /**
     * Handler to log PHP notice messages.
     * @param int $ErrNo Level of error raised.
     * @param string $ErrStr Error message.
     * @param string $ErrFile Name of file that error was raised in.
     * @param int $ErrLine Line number in file where error was raised.
     * @return bool FALSE to run normal PHP error handler, or TRUE to
     *      skip normal PHP error handler.
     */
    public function phpNoticeHandler(
        int $ErrNo,
        string $ErrStr,
        string $ErrFile,
        int $ErrLine
    ) {
        # do not log notice if it was explicitly suppressed with "@"
        if (!(error_reporting() & $ErrNo)) {
            return false;
        }

        # record notice message to log
        $BaseDir = dirname($_SERVER["SCRIPT_FILENAME"])."/";
        $ErrFileRelative = str_replace($BaseDir, "", $ErrFile);
        $Msg = "PHP Notice - ".$ErrFileRelative."[".$ErrLine."]: ".$ErrStr;
        $this->logMessage(self::LOGLVL_WARNING, $Msg);

        # indicate that normal PHP error handler should still run
        return false;
    }

    /** @cond */
    /**
     * Object destructor.
     **/
    public function __destruct()
    {
        # if template location cache is flagged to be saved
        if ($this->SaveTemplateLocationCache) {
            # write template location cache out and update cache expiration
            $CacheString = serialize($this->TemplateLocationCache);
            $CacheDate = date(
                StdLib::SQL_DATE_FORMAT,
                $this->TemplateLocationCacheExpiration
            );
            $this->DB->query("UPDATE ApplicationFrameworkSettings SET"
                . " TemplateLocationCache = '"
                . $this->DB->escapeString($CacheString) . "',"
                . " TemplateLocationCacheExpiration = '" . $CacheDate . "'");
        }

        # if object location cache is flagged to be saved
        if (self::$SaveObjectLocationCache) {
            # write object location cache out and update cache expiration
            $CacheString = serialize(self::$ObjectLocationCache);
            $CacheDate = date(
                StdLib::SQL_DATE_FORMAT,
                self::$ObjectLocationCacheExpiration
            );
            $this->DB->query("UPDATE ApplicationFrameworkSettings"
                . " SET ObjectLocationCache = '"
                . $this->DB->escapeString($CacheString) . "',"
                . " ObjectLocationCacheExpiration = '" . $CacheDate . "'");
        }
    }
    /** @endcond */

    /**
     * Load our settings from database, initializing them if needed.
     * @throws Exception If unable to load settings.
     */
    private function loadSettings(): void
    {
        # read settings in from database
        $this->DB->query("SELECT * FROM ApplicationFrameworkSettings");
        $this->Settings = $this->DB->fetchRow();

        # if settings were not previously initialized
        if ($this->Settings === false) {
            # initialize settings in database
            $this->DB->query("INSERT INTO ApplicationFrameworkSettings"
                . " (LastTaskRunAt) VALUES ('2000-01-02 03:04:05')");

            # read new settings in from database
            $this->DB->query("SELECT * FROM ApplicationFrameworkSettings");
            $this->Settings = $this->DB->fetchRow();

            # bail out if reloading new settings failed
            if ($this->Settings === false) {
                throw new Exception(
                    "Unable to load application framework settings."
                );
            }
        }

        # if base path was not previously set or we appear to have moved
        if (!array_key_exists("BasePath", $this->Settings)
                || (!strlen($this->Settings["BasePath"] ?? ""))
                || (!array_key_exists("BasePathCheck", $this->Settings))
                || (__FILE__ != $this->Settings["BasePathCheck"])) {
            # attempt to extract base path from Apache .htaccess file
            $BasePath = self::getRewritebaseFromHtaccess();

            # if base path was found
            if (strlen($BasePath)) {
                # save base path locally
                $this->Settings["BasePath"] = $BasePath;

                # save base path to database
                $this->DB->query("UPDATE ApplicationFrameworkSettings"
                    . " SET BasePath = '" . addslashes($BasePath) . "'"
                    . ", BasePathCheck = '" . addslashes(__FILE__) . "'");
            }
        }

        # retrieve template location cache
        if (strlen($this->Settings["TemplateLocationCache"] ?? "")) {
            $this->TemplateLocationCache = unserialize(
                $this->Settings["TemplateLocationCache"]
            );
        } else {
            $this->TemplateLocationCache = [];
        }
        $this->TemplateLocationCacheInterval =
            $this->Settings["TemplateLocationCacheInterval"];
        $this->TemplateLocationCacheExpiration =
            strtotime($this->Settings["TemplateLocationCacheExpiration"]);

        # if template location cache looks invalid or has expired
        $CurrentTime = time();
        if (!is_array($this->TemplateLocationCache)
                || !count($this->TemplateLocationCache)
                || ($CurrentTime >= $this->TemplateLocationCacheExpiration)) {
            # clear cache and reset cache expiration
            $this->TemplateLocationCache = [];
            $this->TemplateLocationCacheExpiration =
                $CurrentTime + ($this->TemplateLocationCacheInterval * 60);
            $this->SaveTemplateLocationCache = true;
        }

        # retrieve object location cache
        self::$ObjectLocationCache =
            unserialize($this->Settings["ObjectLocationCache"]);
        self::$ObjectLocationCacheInterval =
            $this->Settings["ObjectLocationCacheInterval"];
        self::$ObjectLocationCacheExpiration =
            strtotime($this->Settings["ObjectLocationCacheExpiration"]);

        # if object location cache looks invalid or has expired
        if (!is_array(self::$ObjectLocationCache)
                || !count(self::$ObjectLocationCache)
                || ($CurrentTime >= self::$ObjectLocationCacheExpiration)) {
            # clear cache and reset cache expiration
            self::$ObjectLocationCache = [];
            self::$ObjectLocationCacheExpiration =
                $CurrentTime + (self::$ObjectLocationCacheInterval * 60);
            self::$SaveObjectLocationCache = true;
        }
    }

    /**
     * Look for template file in supplied list of possible locations,
     * including the currently active UI in the location path where
     * indicated.  Locations are read from a cache, which is discarded
     * when the cache expiration time is reached.  If updated, the cache
     * is saved to the database in __destruct().
     * @param array $DirectoryList Array of directories (or array of arrays
     *       of directories) to search.  Directories must include a
     *       trailing slash.
     * @param string $BaseName File name or file name base.
     * @param array $PossibleSuffixes Array with possible suffixes for file
     *       name, if no suffix evident.  (Suffixes should not include
     *       a leading period.)  (OPTIONAL)
     * @param array $PossiblePrefixes Array with possible prefixes for file to
     *       check.  (OPTIONAL)
     * @return string|null File name with leading relative path or NULL if no
     *       matching file found.
     */
    private function findFile(
        array $DirectoryList,
        string $BaseName,
        ?array $PossibleSuffixes = null,
        ?array $PossiblePrefixes = null
    ) {
        # generate template cache index for this page
        $CacheKey = md5(serialize($DirectoryList))
                .self::$DefaultUI
                .self::$ActiveUI
                .$BaseName;

        # if caching is enabled and we have cached location
        if (($this->TemplateLocationCacheInterval > 0)
                && array_key_exists($CacheKey, $this->TemplateLocationCache)) {
            # use template location from cache
            $FoundFileName = $this->TemplateLocationCache[$CacheKey];
        } else {
            # if suffixes specified and base name does not include suffix
            if ($PossibleSuffixes !== null
                && count($PossibleSuffixes)
                && !preg_match("/\.[a-zA-Z0-9]+$/", $BaseName)) {
                # add versions of file names with suffixes to file name list
                $FileNames = [];
                foreach ($PossibleSuffixes as $Suffix) {
                    $FileNames[] = $BaseName . "." . $Suffix;
                }
            } else {
                # use base name as file name
                $FileNames = [ $BaseName ];
            }

            # if prefixes specified
            if ($PossiblePrefixes !== null && count($PossiblePrefixes)) {
                # add versions of file names with prefixes to file name list
                $NewFileNames = [];
                foreach ($FileNames as $FileName) {
                    foreach ($PossiblePrefixes as $Prefix) {
                        $NewFileNames[] = $Prefix . $FileName;
                    }
                }
                $FileNames = $NewFileNames;
            }

            # expand directory list to include variants
            $OriginList = [];
            $DirectoryList = $this->expandDirectoryList($DirectoryList, $OriginList);

            # for each possible location
            $FoundFileName = null;
            foreach ($OriginList as $Dir => $OrigDir) {
                $MapFunc = $this->PageNameMapFuncs[$OrigDir] ?? null;

                # for each possible file name
                foreach ($FileNames as $File) {
                    # map file name if mapping function available for this directory
                    if ($MapFunc !== null) {
                        $File = ($MapFunc)($OrigDir, $File);
                    }

                    # if template is found at location
                    if (file_exists($Dir.$File)) {
                        # save full template file name and stop looking
                        $FoundFileName = $Dir.$File;
                        break 2;
                    }
                }
            }

            # save location in cache
            $this->TemplateLocationCache[$CacheKey]
                = $FoundFileName;

            # set flag indicating that cache should be saved
            $this->SaveTemplateLocationCache = true;
        }

        # return full template file name to caller
        return $FoundFileName;
    }

    /**
     * Generate version of directory list that includes "local" entries and
     * entries for parent interface directories, and makes any needed keyword
     * substitutions.
     * @param array $DirList List to expand.
     * @param array $OriginList If supplied, this array will have the new
     *      directories list for the keys, and the original directory entries
     *      from which each entry came for the values.  [OPTIONAL]
     * @return array Expanded list.
     */
    private function expandDirectoryList(array $DirList, &$OriginList = null): array
    {
        # generate lookup for supplied list
        $ExpandedListKey = md5(serialize($DirList)
                .self::$DefaultUI.self::$ActiveUI);

        # if we already have expanded version of supplied list
        if (isset($this->ExpandedDirectoryListCache[$ExpandedListKey])) {
            # return expanded version to caller
            if ($OriginList !== null) {
                $OriginList = $this->ExpandedDirectoryListOriginCache[$ExpandedListKey];
            }
            return $this->ExpandedDirectoryListCache[$ExpandedListKey];
        }

        # for each directory in list
        $ExpDirList = [];
        $MyOriginList = [];
        foreach ($DirList as $Dir) {
            # get normalized version of dir plus local version and parent dirs
            $NewDirs = $this->getNormalizedDirPlusParentDirs($Dir);

            # for each new directory
            foreach ($NewDirs as $NewDir) {
                # if directory exists
                if (is_dir($NewDir)) {
                    # add directory to expanded list
                    $ExpDirList[] = $NewDir;

                    # add directory to origin list
                    $MyOriginList[$NewDir] = $Dir;
                }
            }
        }

        # save expanded version and origin list to cache
        $this->ExpandedDirectoryListCache[$ExpandedListKey] = $ExpDirList;
        $this->ExpandedDirectoryListOriginCache[$ExpandedListKey] = $MyOriginList;

        # set origin list for caller if requested
        if ($OriginList !== null) {
            $OriginList = $MyOriginList;
        }

        # return expanded version to caller
        return $ExpDirList;
    }

    /**
     * Based on supplied directory, get list with normalized version of
     * directory, "local" version of normalized directory (if needed), and
     * any parent directories.  In this case, "normalized" means that any
     * active or default UI keywords in the directory name are replaced with
     * the appropriate values.
     * @param string $Dir Starting directory.
     * @return array List of directories.
     */
    private function getNormalizedDirPlusParentDirs(string $Dir): array
    {
        # if supplied directory appears to be literal (i.e. does not contain
        #       a default or active dir keyword) and thus cannot support
        #       substitutions needed for parent interface mechanism
        if ((strpos($Dir, "%DEFAULTUI%") === false)
                && (strpos($Dir, "%ACTIVEUI%") === false)) {
            # if directory is in "local" tree
            if (strpos($Dir, "local/") === 0) {
                # return just supplied directory
                return [ $Dir ];
            } else {
                # return supplied directory plus "local" version
                return [ "local/".$Dir, $Dir ];
            }
        }

        # clear looping check data
        $ParentsFound = [];

        # use default values for initial interface keyword replacement
        $Patterns = [ "%ACTIVEUI%", "%DEFAULTUI%" ];
        $Replacements = [ self::$ActiveUI, self::$DefaultUI ];

        # loop until no parents found
        do {
            $ParentInterface = null;

            # perform interface keyword replacement
            $CurrDir = (string)str_replace($Patterns, $Replacements, $Dir);

            # if directory is not already in "local" tree
            if (strpos($CurrDir, "local/") !== 0) {
                # add "local" version of directory to list
                $LocalDir = "local/".$CurrDir;
                $DirList[] = $LocalDir;

                # look for parent interface in "local" version of directory
                $Settings = $this->getInterfaceSettingsForDir($LocalDir);
                $ParentInterface = $Settings["ParentInterface"] ?? null;
            }

            # add directory to list
            $DirList[] = $CurrDir;

            # if parent not yet found
            if ($ParentInterface === null) {
                # look for parent interface in current directory
                $Settings = $this->getInterfaceSettingsForDir($CurrDir);
                $ParentInterface = $Settings["ParentInterface"] ?? null;
            }

            # if parent was found
            if ($ParentInterface !== null) {
                # if already seen this directory (and are thus caught in a loop)
                if (isset($ParentsFound[$ParentInterface])) {
                    # stop looking (exit parent search loop)
                    break;
                }

                # mark parent as having been seen
                $ParentsFound[$ParentInterface] = true;

                # use parent for next interface keyword replacement
                $Replacements = [$ParentInterface, $ParentInterface];
            }

            # repeat if parent was available
        } while ($ParentInterface !== null);

        # return list of directories to caller
        return $DirList;
    }

    /**
     * Get interface settings for specified directory, with caching.  If no
     * settings is file is found in specified directory, parent directories
     * in the specified path are checked, until a file with settings is found
     * or all directories in the path have been checked.
     * @param string $Dir Directory in which to look for file.
     * @return array Setting values, with setting names for the index.
     */
    private function getInterfaceSettingsForDir(string $Dir): array
    {
        # returned cached settings if available
        if (!is_null($this->InterfaceSettingsByDirCache)
                && isset($this->InterfaceSettingsByDirCache[$Dir])) {
            return $this->InterfaceSettingsByDirCache[$Dir];
        }

        # do while we do not have settings and there is a parent dir to check
        $ParentDir = $Dir;
        $Settings = [];
        do {
            $CurrDir = $ParentDir;

            # if settings file exists in current directory
            $InterfaceFile = $CurrDir."/interface.ini";
            if (file_exists($InterfaceFile)) {
                # check to make sure settings file is readable
                if (!is_readable($InterfaceFile)) {
                    throw new Exception("Unable to read interface "
                        ." file \"".$InterfaceFile."\".");
                }

                # read settings from file
                $Settings = parse_ini_file($InterfaceFile);
                if ($Settings === false) {
                    throw new Exception("Error trying to parse interface settings"
                            ." file \"".$InterfaceFile."\".");
                }
            }

            # move to parent dir for next pass
            $ParentDir = dirname($CurrDir);
        } while (!count($Settings) && ($ParentDir != $CurrDir));

        $this->InterfaceSettingsByDirCache[$Dir] = $Settings;
        return $Settings;
    }

    /**
     * Load interface settings from all interface directories, giving
     * precedence to settings found in directories that appear earlier
     * in the interface directory list.
     * @return array Setting values, with setting names for the index.
     */
    private function loadInterfaceSettings(): array
    {
        # return settings from template location cache if enabled and available
        if (($this->TemplateLocationCacheInterval > 0)
                && isset($this->TemplateLocationCache["InterfaceSettings"])) {
            return $this->TemplateLocationCache["InterfaceSettings"];
        }

        # get list of interface directories
        $Dirs = $this->expandDirectoryList($this->InterfaceDirList);

        # reverse dir list so that values from preferred dirs take precedence
        $Dirs = array_reverse($Dirs);

        # for each directory
        $Settings = [];
        foreach ($Dirs as $Dir) {
            # load interface settings for directory
            $DirSettings = $this->getInterfaceSettingsForDir($Dir);

            # add loaded settings to overall settings
            foreach ($DirSettings as $Key => $Val) {
                if (is_array($Val) && isset($Settings[$Key])) {
                    $Settings[$Key] = array_merge(
                        $Settings[$Key],
                        $DirSettings[$Key]
                    );
                } else {
                    $Settings[$Key] = $DirSettings[$Key];
                }
            }
        }

        # save settings with template locations
        $this->TemplateLocationCache["InterfaceSettings"] = $Settings;
        $this->SaveTemplateLocationCache = true;

        # return loaded settings to caller
        return $Settings;
    }

    /**
     * Begin PHP session.
     * @return bool TRUE if session was started, otherwise FALSE.
     */
    private function startPhpSession()
    {
        # build cookie domain string
        $SessionDomain = isset($_SERVER["SERVER_NAME"]) ? $_SERVER["SERVER_NAME"]
            : (isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"]
                : php_uname("n"));

        # include a leading period so that older browsers implementing
        # rfc2109 do not reject our cookie
        $SessionDomain = "." . $SessionDomain;

        # if it appears our session storage area is writable
        $SessionSavePath = session_save_path();
        if ($SessionSavePath  && is_writable($SessionSavePath)) {
            # store our session files in a subdirectory to avoid
            #   accidentally sharing sessions with other installations
            #   on the same domain
            $SessionStorage = $SessionSavePath
                . "/" . self::$AppName . "_" . md5($SessionDomain . dirname(__FILE__));

            # create session storage subdirectory if not found
            if (!is_dir($SessionStorage)) {
                mkdir($SessionStorage, 0700);
            }

            # if session storage subdirectory is writable
            if (is_writable($SessionStorage)) {
                # save parameters of our session storage as instance variables
                #   for later use
                $this->SessionGcProbability =
                    (int)ini_get("session.gc_probability") / (int)ini_get("session.gc_divisor");
                # require a gc probability of at least MIN_GC_PROBABILITY
                if ($this->SessionGcProbability < self::MIN_GC_PROBABILITY) {
                    $this->SessionGcProbability = self::MIN_GC_PROBABILITY;
                }

                $this->SessionStorage = $SessionStorage;

                # set the new session storage location
                session_save_path($SessionStorage);

                # disable PHP's garbage collection, as it does not handle
                #   subdirectories (instead, we'll do the cleanup as we run
                #   background tasks)
                ini_set("session.gc_probability", (string)0);
            }
        }

        # set garbage collection max period to our session lifetime
        ini_set("session.gc_maxlifetime", (string)$this->sessionLifetime());

        # limit cookie to secure connection if we are running over same
        $SecureCookie = isset($_SERVER["HTTPS"]) ? true : false;

        # Cookies lacking embedded dots are... fun.
        # rfc2109 sec 4.3.2 says to reject them
        # rfc2965 sec 3.3.2 says to reject them
        # rfc6265 sec 4.1.2.3 says only that "public suffixes"
        #   should be rejected.  They reference Mozilla's
        #   publicsuffix.org, which does not contain 'localhost'.
        #   However, empirically in early 2017 Firefox still rejects
        #   'localhost'.
        # Therefore, don't set a cookie domain if we're running on
        #   localhost to avoid this problem.
        if (preg_match('/^\.localhost(:[0-9]+)?$/', $SessionDomain)) {
            $SessionDomain = "";
        }
        session_set_cookie_params(
            $this->sessionLifetime(),
            "/",
            $SessionDomain,
            $SecureCookie,
            true
        );

        # attempt to start session
        $SessionStarted = @session_start();

        # if session start failed
        if (!$SessionStarted) {
            # regenerate session ID and attempt to start session again
            @session_regenerate_id(true);
            $SessionStarted = @session_start();
        }

        # if we have a session bump up our cookie expiry time, so that it
        # will die $SessionLifetime from now, rather than $SessionLifetime
        # from whenever we created it
        if ($SessionStarted) {
            # (can only bump expiry time if session info is available)
            $SessionName = session_name();
            $SessionId = session_id();
            if ($SessionName && $SessionId) {
                setcookie(
                    $SessionName,
                    $SessionId,
                    time() + $this->sessionLifetime(),
                    "/",
                    $SessionDomain,
                    $SecureCookie,
                    true
                );
            }
        }

        # report to caller whether we were able to start session
        return $SessionStarted;
    }

    /**
     * Compile SCSS file (if updated) to cache directory and return path to
     * resulting CSS file to caller.
     * @param string $SrcFile SCSS file name with leading path.
     * @return string|null Compiled CSS file with leading path or NULL if compile
     *       failed or compiled CSS file could not be written.
     * @see ApplicationFramework::cssUrlFingerprintInsertion()
     */
    private function compileScssFile(string $SrcFile)
    {
        # build path to CSS file
        $DstFile = self::$ScssCacheDir . "/" . dirname($SrcFile)
            . "/" . basename($SrcFile);
        $DstFile = substr_replace($DstFile, "css", -4);

        # if SCSS file is newer than CSS file
        if (!file_exists($DstFile)
            || (filemtime($SrcFile) > filemtime($DstFile))) {
            # attempt to create CSS cache subdirectory if not present
            if (!is_dir(dirname($DstFile))) {
                @mkdir(dirname($DstFile), 0777, true);
            }

            # if CSS cache directory and CSS file path appear writable
            static $CacheDirIsWritable;
            if (!isset($CacheDirIsWritable)) {
                $CacheDirIsWritable = is_writable(self::$ScssCacheDir);
            }
            if (is_writable($DstFile)
                || (!file_exists($DstFile) && $CacheDirIsWritable)) {
                $ScssCode = file_get_contents($SrcFile);
                if ($ScssCode !== false) {
                    try {
                        $CssCode = $this->compileScssCode($ScssCode, $SrcFile);
                        # write out CSS file
                        file_put_contents($DstFile, $CssCode);
                    } catch (Exception $Ex) {
                        $this->logError(
                            self::LOGLVL_ERROR,
                            "Error compiling SCSS file ".$SrcFile.": ".$Ex->getMessage()
                        );
                        $DstFile = null;
                    }
                } else {
                    # log error and clear CSS file path to indicate failure
                    $this->logError(
                        self::LOGLVL_ERROR,
                        "Unable to load SCSS code from file ".$SrcFile."."
                    );
                    $DstFile = null;
                }
            } else {
                # log error and clear CSS file path to indicate failure
                $this->logError(
                    self::LOGLVL_ERROR,
                    "Unable to write out CSS file (compiled from SCSS) to ".$DstFile
                );
                $DstFile = null;
            }
        }

        # return CSS file path to caller
        return $DstFile;
    }

    /**
     * Compile supplied SCSS code to CSS.
     * @param string $ScssCode SCSS code to compile.
     * @param string $SrcFile Name of source file from which SCSS was loaded.
     * @return string Generated CSS code.
     * @throws Exception If compilation fails.
     */
    private function compileScssCode(string $ScssCode, string $SrcFile): string
    {
        $ScssCompiler = new \ScssPhp\ScssPhp\Compiler();
        $ScssCompiler->setFormatter($this->generateCompactCss()
                ? "ScssPhp\\ScssPhp\\Formatter\\Compressed"
                : "ScssPhp\\ScssPhp\\Formatter\\Expanded");
        $CssCode = $ScssCompiler->compile($ScssCode);

        # add fingerprinting for URLs in CSS
        $this->CssUrlFingerprintPath = dirname($SrcFile);
        $CssCode = preg_replace_callback(
            "/url\((['\"]?)([^)]+)\.([a-z]+)(['\"]?)\)/",
            [ $this, "cssUrlFingerprintInsertion" ],
            $CssCode
        );
        if ($CssCode === null) {
            throw new Exception(
                "Failure inserting URL fingerprints. "
                . "PCRE error code: " . preg_last_error()
            );
        }

        # strip out comments from CSS (if requested)
        if ($this->generateCompactCss()) {
            $CssCode = preg_replace(
                '!/\*[^*]*\*+([^/][^*]*\*+)*/!',
                '',
                $CssCode
            );

            if ($CssCode === null) {
                throw new Exception(
                    "Failure compacting CSS. "
                    . "PCRE error code: " . preg_last_error()
                );
            }
        }

        return $CssCode;
    }

    /**
     * Minimize JavaScript file (if updated) to cache directory and return
     * path to resulting minimized file to caller.
     * @param string $SrcFile JavaScript file name with leading path.
     * @return string|null Minimized JavaScript file with leading path or NULL
     *       if minimization failed or minimized file could not be written.
     */
    private function minimizeJavascriptFile(string $SrcFile)
    {
        # bail out if file is on exclusion list
        foreach ($this->DoNotMinimizeList as $DNMFile) {
            if (($SrcFile == $DNMFile) || (basename($SrcFile) == $DNMFile)) {
                return null;
            }
        }

        # build path to minimized file
        $DstFile = self::$JSMinCacheDir . "/" . dirname($SrcFile)
            . "/" . basename($SrcFile);
        $DstFile = substr_replace($DstFile, ".min", -3, 0);

        # if original file is newer than minimized file
        if (!file_exists($DstFile)
            || (filemtime($SrcFile) > filemtime($DstFile))) {
            # attempt to create cache subdirectory if not present
            if (!is_dir(dirname($DstFile))) {
                @mkdir(dirname($DstFile), 0777, true);
            }

            # if cache directory and minimized file path appear writable
            static $CacheDirIsWritable;
            if (!isset($CacheDirIsWritable)) {
                $CacheDirIsWritable = is_writable(self::$JSMinCacheDir);
            }
            if (is_writable($DstFile)
                || (!file_exists($DstFile) && $CacheDirIsWritable)) {
                # load JavaScript code
                $Code = file_get_contents($SrcFile);

                # minimize code if available
                if ($Code !== false) {
                    try {
                        $MinimizedCode = $this->minimizeJavascriptCode($Code);
                    } catch (Exception $Exception) {
                        $MinimizeError = $Exception->getMessage();
                    }
                }

                # if minimization succeeded
                if (isset($MinimizedCode) && ($MinimizedCode !== null)) {
                    # write out minimized file
                    file_put_contents($DstFile, $MinimizedCode);
                } else {
                    # log error
                    $ErrMsg = "Unable to minimize JavaScript file " . $SrcFile;
                    if (isset($MinimizeError)) {
                        $ErrMsg .= " (" . $MinimizeError . ")";
                    }
                    $this->logError(self::LOGLVL_ERROR, $ErrMsg);

                    # clear destination file path to indicate failure
                    $DstFile = null;
                }
            } else {
                # log error
                $this->logError(
                    self::LOGLVL_ERROR,
                    "Unable to write out minimized JavaScript to file " . $DstFile
                );

                # clear destination file path to indicate failure
                $DstFile = null;
            }
        }

        # return CSS file path to caller
        return $DstFile;
    }

    /**
     * Minimize supplied JavaScript code with appropriate minimizer.
     * @param string $Code Code to minimize.
     * @return string|null Minimized code or NULL if minimization failed.
     * @throws Exception If minimize fails.
     */
    private function minimizeJavascriptCode(string $Code)
    {
        $MinimizedCode = null;

        # decide which minimizer to use
        if ($this->JSMinimizerJavaScriptPackerAvailable
            && $this->JSMinimizerJShrinkAvailable) {
            $Minimizer = (strlen($Code) < 5000)
                ? "JShrink" : "JavaScriptPacker";
        } elseif ($this->JSMinimizerJShrinkAvailable) {
            $Minimizer = "JShrink";
        } else {
            $Minimizer = "NONE";
        }

        # minimize code
        switch ($Minimizer) {
            case "JavaScriptMinimizer":
                $Packer = new JavaScriptPacker($Code, "Normal");
                $MinimizedCode = $Packer->pack();
                break;

            case "JShrink":
                $MinimizedCode = \JShrink\Minifier::minify($Code);
                break;
        }

        return $MinimizedCode;
    }

    /**
     * Insert fingerprint string in file name in URL within CSS.  This is
     * intended to be called via preg_replace_callback().
     * @param array $Matches Array of strings matching patterns.
     * @return string URL string with fingerprint inserted.
     * @see ApplicationFramework::compileScssFile()
     */
    private function cssUrlFingerprintInsertion(array $Matches): string
    {
        # generate fingerprint string from CSS file modification time
        $FileName = $this->CssUrlFingerprintPath."/".$Matches[2].".".$Matches[3];
        $RealFileName = realpath($FileName);
        if ($RealFileName === false) {
            throw new Exception("Unable to determine real path for file \""
                    .$FileName."\".");
        }
        $MTime = filemtime($RealFileName);
        $Fingerprint = sprintf("%06X", ($MTime % 0xFFFFFF));

        # build URL string with fingerprint and return it to caller
        return "url(" . $Matches[1] . $Matches[2] . "." . $Fingerprint
            . "." . $Matches[3] . $Matches[4] . ")";
    }

    /**
     * Figure out which required UI files have not yet been loaded for specified
     * page content file.
     * @param string $PageContentFile Page content file.  (OPTIONAL)
     * @return array Array with names of required files (without paths) for the
     *       index, and loading order hints (ORDER_*) for the values..
     */
    private function getRequiredFilesNotYetLoaded(?string $PageContentFile = null)
    {
        # start out assuming no files required
        $RequiredFiles = [];

        # if page content file supplied
        if ($PageContentFile) {
            # if file containing list of required files is available
            $Path = dirname($PageContentFile);
            $RequireListFile = $Path . "/REQUIRES";
            if (file_exists($RequireListFile)) {
                # read in list of required files
                $RequestedFiles = file($RequireListFile);
                if ($RequestedFiles === false) {
                    throw new Exception("Unable to read required files list from \""
                            .$RequireListFile."\".");
                }

                # for each line in required file list
                foreach ($RequestedFiles as $Line) {
                    # if line is not a comment
                    $Line = trim($Line);
                    if (!preg_match("/^#/", $Line)) {
                        # if file has not already been loaded
                        if (!in_array($Line, $this->FoundUIFiles)) {
                            # add to list of required files
                            $RequiredFiles[$Line] = self::ORDER_MIDDLE;
                        }
                    }
                }
            }
        }

        # add in additional required files if any
        if (count($this->AdditionalRequiredUIFiles)) {
            # remove files we've already included
            $AdditionalRequiredUIFiles = array_diff_key(
                $this->AdditionalRequiredUIFiles,
                array_fill_keys($this->FoundUIFiles, true)
            );

            $RequiredFiles = array_merge(
                $RequiredFiles,
                $AdditionalRequiredUIFiles
            );
        }

        # return list of required files to caller
        return $RequiredFiles;
    }

    /**
     * Substitute browser name (if known) into file names where keywords
     * appear in the names.  If browser name is unknown, remove any file
     * names that contain the browser keyword.
     * @param array $FileNames Array with file names for index.
     * @return array Updated array with file names for index.  (Incoming
     *       values for array will be preserved.)
     */
    private function subBrowserIntoFileNames(array $FileNames)
    {
        # if a browser detection function has been made available
        $UpdatedFileNames = [];
        if (is_callable($this->BrowserDetectFunc)) {
            # call function to get browser list
            $Browsers = call_user_func($this->BrowserDetectFunc);

            # for each required file
            foreach ($FileNames as $FileName => $Value) {
                # if file name includes browser keyword
                if (preg_match("/%BROWSER%/", $FileName)) {
                    # for each browser
                    foreach ($Browsers as $Browser) {
                        # substitute in browser name and add to new file list
                        $NewFileName = preg_replace(
                            "/%BROWSER%/",
                            $Browser,
                            $FileName
                        );
                        $UpdatedFileNames[$NewFileName] = $Value;
                    }
                } else {
                    # add to new file list
                    $UpdatedFileNames[$FileName] = $Value;
                }
            }
        } else {
            # filter out any files with browser keyword in their name
            foreach ($FileNames as $FileName => $Value) {
                if (!preg_match("/%BROWSER%/", $FileName)) {
                    $UpdatedFileNames[$FileName] = $Value;
                }
            }
        }

        return $UpdatedFileNames;
    }

    /**
     * Add any requested meta tags to page output.
     * @param string $PageOutput Full page output.
     * @return string Full page output, potentially modified.
     */
    private function addMetaTagsToPageOutput(string $PageOutput): string
    {
        # start with unconditional (non-unique) tags
        $TagsToAdd = $this->MetaTags;

        # for each unique tag
        foreach ($this->UniqueMetaTags as $UniqueMetaTag) {
            $Attribs = $UniqueMetaTag["Attribs"];
            $UniqueAttribs = $UniqueMetaTag["UniqueAttribs"];

            # if no unique attributes specified
            if ($UniqueAttribs === null) {
                # use first attribute as unique attribute
                $UniqueAttribs = array_slice($Attribs, 0, 1);
            }

            # for each already-queued tag
            # (look for meta tags that match all attributes in
            #       the current unique tag)
            foreach ($TagsToAdd as $TagAttribs) {
                # for each attribute in current unique tag
                # (look for attributes in the current unique tag that do
                #       not match attributes in the this queued tag)
                foreach ($UniqueAttribs as $UniqueName => $UniqueValue) {
                    # if unique attribute is not found in queued tag
                    #       or queued tag attribute has a different value
                    if (!isset($TagAttribs[$UniqueName])
                        || ($TagAttribs[$UniqueName] != $UniqueValue)) {
                        # skip to next queued tag
                        # (some attribute in the current unique tag
                        #       was not found in the queued tag)
                        continue 2;
                    }
                }

                # skip to next unique tag
                # (all attributes in the current unique tag were found
                #       in the queued tag, so do not queue this unique tag)
                continue 2;
            }

            # generate potential combinations of unique attributes
            $UniqueAttribNameCombos = StdLib::arrayPermutations(
                array_keys($UniqueAttribs)
            );

            # for each combination of unique attributes
            foreach ($UniqueAttribNameCombos as $UniqueNameCombo) {
                # for each attribute in combination
                $AttribStrings = [];
                foreach ($UniqueNameCombo as $UniqueName) {
                    # add attrib/value string to list
                    $AttribStrings[] = $UniqueName . "=\""
                        . htmlspecialchars($UniqueAttribs[$UniqueName]) . "\"";
                }

                # build search string from list of attribute pairs
                $SearchString = "<meta " . implode(" ", $AttribStrings);

                # if search string appears in page output
                if (strpos($PageOutput, $SearchString) !== false) {
                    # skip to next unique tag
                    continue 2;
                }

                # repeat search with single quotes instead of double quotes
                $SearchString = strtr($SearchString, '"', "'");
                if (strpos($PageOutput, $SearchString) !== false) {
                    # skip to next unique tag
                    continue 2;
                }
            }

            # unique tag was not found in page output, so add it to inserted tags
            $TagsToAdd[] = $Attribs;
        }

        # if there are meta tags to be added
        if (count($TagsToAdd)) {
            # start with an empty segment
            $Section = "";

            # for each meta tag
            foreach ($TagsToAdd as $Attribs) {
                # assemble tag and add it to the segment
                $Section .= "<meta";
                foreach ($Attribs as $AttribName => $AttribValue) {
                    $Section .= " " . $AttribName . "=\""
                        . htmlspecialchars(trim($AttribValue)) . "\"";
                }
                $Section .= " />\n";
            }

            # if standard page start and end have been disabled
            # and page output contains no <head> element
            if ($this->SuppressStdPageStartAndEnd &&
                strpos($PageOutput, "<head>") === false) {
                # add segment to beginning of page output
                $PageOutput = $Section . $PageOutput;
            } else {
                # insert segment at beginning of HTML head section in page output
                $PageOutput = preg_replace(
                    "#<head>#i",
                    "<head>\n" . $Section,
                    $PageOutput,
                    1
                );
            }
        }

        # return (potentially modified) page output to caller
        return $PageOutput;
    }

    /**
     * Add any requested file loading tags to page output.
     * @param string $PageOutput Full page output.
     * @param array $Files Array with names of required for the index
     *       and loading order preferences (ORDER_*) for the values.
     * @return string Full page output, potentially modified.
     */
    private function addFileTagsToPageOutput(string $PageOutput, array $Files): string
    {
        # substitute browser name into names of required files as appropriate
        $Files = $this->subBrowserIntoFileNames($Files);

        # initialize content sections
        $HeadContent = [
            self::ORDER_FIRST => "",
            self::ORDER_MIDDLE => "",
            self::ORDER_LAST => "",
        ];
        $BodyContent = [
            self::ORDER_FIRST => "",
            self::ORDER_MIDDLE => "",
            self::ORDER_LAST => "",
        ];

        # for each required file
        foreach ($Files as $File => $Order) {
            # locate specific file to use
            $FilePath = $this->gUIFile($File);

            # if file was found
            if ($FilePath) {
                # generate tag for file
                $Tag = $this->getUIFileLoadingTag($FilePath);

                # add file to HTML output based on file type
                $FileType = $this->getFileType($FilePath);
                switch ($FileType) {
                    case self::FT_CSS:
                        $HeadContent[$Order] .= $Tag . "\n";
                        break;

                    case self::FT_JAVASCRIPT:
                        $BodyContent[$Order] .= $Tag . "\n";
                        break;
                }
            } else {
                $this->logError(
                    self::LOGLVL_WARNING,
                    "Could not find required UI file \"" . $File . "\"."
                );
            }
        }

        # add content to head
        $Replacement = $HeadContent[self::ORDER_MIDDLE]
            . $HeadContent[self::ORDER_LAST];
        $UpdatedPageOutput = str_ireplace(
            "</head>",
            $Replacement . "</head>",
            $PageOutput,
            $ReplacementCount
        );
        # (if no </head> tag was found, just prepend tags to page content)
        if ($ReplacementCount == 0) {
            $PageOutput = $Replacement . $PageOutput;
            # (else if multiple </head> tags found, only prepend tags to the first)
        } elseif ($ReplacementCount > 1) {
            $PageOutput = preg_replace(
                "#</head>#i",
                $Replacement . "</head>",
                $PageOutput,
                1
            );
        } else {
            $PageOutput = $UpdatedPageOutput;
        }
        $Replacement = $HeadContent[self::ORDER_FIRST];
        $UpdatedPageOutput = str_ireplace(
            "<head>",
            "<head>\n" . $Replacement,
            $PageOutput,
            $ReplacementCount
        );
        # (if no <head> tag was found, just prepend tags to page content)
        if ($ReplacementCount == 0) {
            $PageOutput = $Replacement . $PageOutput;
            # (else if multiple <head> tags found, only append tags to the first)
        } elseif ($ReplacementCount > 1) {
            $PageOutput = preg_replace(
                "#<head>#i",
                "<head>\n" . $Replacement,
                $PageOutput,
                1
            );
        } else {
            $PageOutput = $UpdatedPageOutput;
        }

        # add content to body
        $Replacement = $BodyContent[self::ORDER_FIRST];
        $PageOutput = preg_replace(
            "#<body([^>]*)>#i",
            "<body\\1>\n" . $Replacement,
            $PageOutput,
            1,
            $ReplacementCount
        );
        # (if no <body> tag was found, just append tags to page content)
        if ($ReplacementCount == 0) {
            $PageOutput = $PageOutput . $Replacement;
        }
        $Replacement = $BodyContent[self::ORDER_MIDDLE]
            . $BodyContent[self::ORDER_LAST];
        $UpdatedPageOutput = str_ireplace(
            "</body>",
            $Replacement . "\n</body>",
            $PageOutput,
            $ReplacementCount
        );
        # (if no </body> tag was found, just append tags to page content)
        if ($ReplacementCount == 0) {
            $PageOutput = $PageOutput . $Replacement;
            # (else if multiple </body> tags found, only prepend tag to the first)
        } elseif ($ReplacementCount > 1) {
            $PageOutput = preg_replace(
                "#</body>#i",
                $Replacement . "\n</body>",
                $PageOutput,
                1
            );
        } else {
            $PageOutput = $UpdatedPageOutput;
        }

        return $PageOutput;
    }

    /**
     * Add fingerprint to file name if appropriate (fingerprinting enabled and
     * supported, file exists, file is not on fingerprinting blacklist).
     * @param string $FileName File name to potentially modify.
     * @return string Possibly-fingerprinted file name.
     */
    private function addFingerprintToFileName(string $FileName): string
    {
        # return file name unchanged if fingerprinting is disabled or not supported
        if (!$this->urlFingerprintingEnabled()
                || !self::urlFingerprintingRewriteSupport()) {
            return $FileName;
        }

        # return file name unchanged if it appears to be a server-side inclusion
        if (preg_match('/\.(html|php)$/i', $FileName)) {
            return $FileName;
        }

        # return file name unchanged if file does not exist
        if (!file_exists($FileName)) {
            return $FileName;
        }

        # for each URL fingerprinting blacklist entry
        foreach ($this->UrlFingerprintBlacklist as $BlacklistEntry) {
            # if entry looks like a regular expression pattern
            if ($BlacklistEntry[0] == substr($BlacklistEntry, -1)) {
                # return file name unchanged if it matches regular expression
                if (preg_match($BlacklistEntry, $FileName)) {
                    return $FileName;
                }
            } else {
                # return file name unchanged if it matches entry
                if (basename($FileName) == $BlacklistEntry) {
                    return $FileName;
                }
            }
        }

        # retrieve file modification time
        $FileMTime = filemtime($FileName);

        # add timestamp fingerprint to file name
        $Fingerprint = sprintf(
            "%06X",
            ($FileMTime % 0xFFFFFF)
        );
        $FileName = preg_replace(
            "/^(.+)\.([a-z]+)$/",
            "$1." . $Fingerprint . ".$2",
            $FileName
        );

        # return fingerprinted file name
        return $FileName;
    }

    /**
     * Get HTML tag for loading specified CSS, JavaScript, or image file.
     * If the type of the specified file is unknown or unsupported, an empty
     * string is returned.
     * @param string $FileName UI file name, including leading path.
     * @param ?array $ExtraAttribs Any additional attributes that should
     *      be included in HTML tag, with attribute names for the index.
     *      Attributes with no value should be set to TRUE or FALSE and
     *      will only be included if the value is TRUE.  (OPTIONAL)
     * @return string Tag to load file, or empty string if file type was unknown
     *       or unsupported.
     */
    private function getUIFileLoadingTag(
        string $FileName,
        ?array $ExtraAttribs = null
    ): string {
        # if additional attributes supplied
        if ($ExtraAttribs !== null) {
            # escape attribute values and convert to name="value" format
            $MapFunc = function ($Key) use ($ExtraAttribs) {
                $Value = $ExtraAttribs[$Key];
                if (is_bool($Value)) {
                    return $Value ? $Key : "";
                }
                return $Key."=\"".htmlspecialchars($Value)."\"";
            };
            $FormattedAttribs = array_map($MapFunc, array_keys($ExtraAttribs));

            # asseemble attribute string
            $AttribString = " ".join(" ", $FormattedAttribs);
        } else {
            $AttribString = "";
        }

        # retrieve type of UI file
        $FileType = $this->getFileType($FileName);

        # construct tag based on file type
        switch ($FileType) {
            case self::FT_CSS:
                $Tag = "    <link rel=\"stylesheet\" type=\"text/css\""
                    . " media=\"all\" href=\"" . $FileName . "\""
                    . $AttribString . " />\n";
                break;

            case self::FT_JAVASCRIPT:
                $Tag = "    <script type=\"text/javascript\""
                    . " src=\"" . $FileName . "\""
                    . $AttribString . "></script>\n";
                break;

            case self::FT_IMAGE:
                $Tag = "<img src=\"" . $FileName . "\"" . $AttribString . ">";
                break;

            default:
                $Tag = "";
                break;
        }

        # return constructed tag to caller
        return $Tag;
    }

    /**
     * Load object file for specified class.
     * @param string $ClassName Name of class.
     */
    private function autoloadObjects(string $ClassName): void
    {
        # if we have a cached location for the class
        #       and the cached value indicates that a file could not be found
        $CacheKey = self::$DefaultUI.self::$ActiveUI.$ClassName;
        $CachedValueAvailable = (self::$ObjectLocationCacheInterval > 0)
                && array_key_exists($CacheKey, self::$ObjectLocationCache);
        if ($CachedValueAvailable && (self::$ObjectLocationCache[$CacheKey] === false)) {
            # quit without loading anything
            return;
        }

        # if we have cached location for the class and file at cached location is readable
        if ($CachedValueAvailable && is_readable(self::$ObjectLocationCache[$CacheKey])) {
            # use object location from cache
            require_once(self::$ObjectLocationCache[$CacheKey]);
            return;
        }

        # start out assuming that we will not find a file
        self::$ObjectLocationCache[$CacheKey] = false;

        # flag object location cache to be updated in database
        self::$SaveObjectLocationCache = true;

        # expand directory info list to include "local" entries and entries
        #       for parent interface directories
        $OriginList = [];
        $ExpDirList = $this->expandDirectoryList(
            array_keys(self::$ObjectDirectories),
            $OriginList
        );
        $DirInfo = [];
        foreach ($OriginList as $Dir => $OrigDir) {
            $DirInfo[$Dir] = self::$ObjectDirectories[$OrigDir];
        }

        # for each possible object file directory
        static $FileLists;
        $LocalNPLen = strlen(self::$LocalNamespacePrefix);
        foreach ($DirInfo as $Location => $Info) {
            # if directory looks valid
            if (is_dir((string)$Location)) {
                # pass class name through callback (if supplied)
                $ClassFileName = $ClassName;
                if (is_callable($Info["Callback"])) {
                    $ClassFileName = $Info["Callback"]($ClassFileName);
                }

                # strip off any default namespace prefix
                if (strpos($ClassFileName, self::$DefaultNamespacePrefix) === 0) {
                    $ClassFileName = substr(
                        $ClassFileName,
                        strlen(self::$DefaultNamespacePrefix)
                    );
                }

                # strip off any local namespace prefix
                if (($LocalNPLen > 0)
                        && (strpos((string)$Location, "local/") === 0)
                        && (strpos(
                            $ClassFileName,
                            self::$LocalNamespacePrefix
                        ) === 0)) {
                    $ClassFileName = substr($ClassFileName, $LocalNPLen);
                }

                # strip off any directory-specific namespace prefix
                foreach ($Info["NamespacePrefixes"] as $Prefix) {
                    $PrefixLen = strlen($Prefix);
                    if (($PrefixLen > 0) && (strpos($ClassFileName, $Prefix) === 0)) {
                        $ClassFileName = substr($ClassFileName, $PrefixLen);
                        break;
                    }
                }

                # strip off any leading namespace separator
                if (strpos($ClassFileName, "\\") === 0) {
                    $ClassFileName = substr($ClassFileName, 1);
                }

                # convert any namespace separators to directory separators
                $ClassFileName = str_replace("\\", "/", $ClassFileName);

                # finish building class file name
                $ClassFileName = $ClassFileName.".php";

                # read in directory tree if not already retrieved
                if (!isset($FileLists[$Location])) {
                    $FileLists[$Location] = self::readDirectoryTree(
                        (string)$Location,
                        '/^.+\.php$/i'
                    );
                }

                # if class file is found in directory tree
                if (in_array($ClassFileName, $FileLists[$Location])) {
                    # include class file
                    $FullClassFileName = $Location.$ClassFileName;
                    require_once($FullClassFileName);

                    # if our desired class/interface/trait now exists
                    if (class_exists($ClassName, false) ||
                        interface_exists($ClassName, false) ||
                        trait_exists($ClassName, false)) {
                        # save location to cache
                        self::$ObjectLocationCache[$CacheKey] = $FullClassFileName;
                        # stop looking
                        break 1;
                    }
                }
            }
        }
    }

    /**
     * Recursively read in list of file names matching pattern beginning at
     * specified directory, excluding hidden files (i.e. those with names
     * starting with a period, like .git or .svn).
     * @param string $Directory Directory at top of tree to search.
     * @param string $Pattern Regular expression pattern to match.
     * @return array Array containing names of matching files with relative paths.
     */
    private static function readDirectoryTree(string $Directory, string $Pattern)
    {
        if ($Directory[-1] == "/") {
            $Directory = substr($Directory, 0, -1);
        }

        # use SORT_NONE to skip sorting entries since we do not care about the order
        $DirEntries = scandir($Directory, SCANDIR_SORT_NONE);
        if ($DirEntries === false) {
            throw new Exception("scandir() call failed.");
        }

        $FileList = [];
        foreach ($DirEntries as $Entry) {
            if ($Entry[0] == ".") {
                continue;
            }

            $FullPath = $Directory."/".$Entry;
            if (is_dir($FullPath)) {
                $SubdirEntries = self::readDirectoryTree($FullPath, $Pattern);
                foreach ($SubdirEntries as $SubEntry) {
                    $FileList[] = $Entry."/".$SubEntry;
                }
            } elseif (preg_match($Pattern, $FullPath)) {
                $FileList[] = $Entry;
            }
        }

        return $FileList;
    }

    /**
     * Load any user interface functions available in interface include
     * directories (in F-FuncName.html or F-FuncName.php files).
     */
    private function loadUIFunctions(): void
    {
        $Dirs = [
            "local/interface/%ACTIVEUI%/include",
            "interface/%ACTIVEUI%/include",
            "local/interface/%DEFAULTUI%/include",
            "interface/%DEFAULTUI%/include",
        ];
        foreach ($Dirs as $Dir) {
            $Dir = str_replace(
                [ "%ACTIVEUI%", "%DEFAULTUI%" ],
                [ self::$ActiveUI, self::$DefaultUI ],
                $Dir
            );
            if (is_dir($Dir)) {
                $FileNames = scandir($Dir);
                if ($FileNames === false) {
                    throw new Exception("Unable to read interface directory \""
                            .$Dir."\".");
                }
                foreach ($FileNames as $FileName) {
                    if (preg_match(
                        "/^F-([A-Za-z0-9_]+)\.php/",
                        $FileName,
                        $Matches
                    )
                        || preg_match(
                            "/^F-([A-Za-z0-9_]+)\.html/",
                            $FileName,
                            $Matches
                        )) {
                        if (!function_exists($Matches[1])) {
                            include_once($Dir . "/" . $FileName);
                        }
                    }
                }
            }
        }
    }

    /**
     * Run periodic event if appropriate.
     * @param string $EventName Name of event.
     * @param callable $Callback Event callback.
     */
    private function processPeriodicEvent(string $EventName, callable $Callback): void
    {
        # retrieve last execution time for event if available
        $Signature = self::getCallbackSignature($Callback);
        $LastRun = $this->DB->queryValue("SELECT LastRunAt FROM PeriodicEvents"
            . " WHERE Signature = '" . addslashes($Signature) . "'", "LastRunAt");

        # determine whether enough time has passed for event to execute
        $ShouldExecute = (($LastRun === null)
            || (time() > (strtotime($LastRun) + $this->EventPeriods[$EventName])))
            ? true : false;

        # if event should run
        if ($ShouldExecute) {
            # add event to task queue
            $WrapperCallback = [
                "ScoutLib\ApplicationFramework",
                "runPeriodicEvent"
            ];
            $WrapperParameters = [
                $EventName,
                $Callback,
                [ "LastRunAt" => $LastRun ]
            ];
            $this->queueUniqueTask($WrapperCallback, $WrapperParameters);
        }

        # add event to list of periodic events
        $this->KnownPeriodicEvents[$Signature] = [
            "Period" => $EventName,
            "Callback" => $Callback,
            "Queued" => $ShouldExecute
        ];
    }

    /**
     * Generate and return signature for specified callback.
     * @param callable $Callback Callback to generate signature for.
     * @return string Signature string.
     */
    private static function getCallbackSignature(callable $Callback): string
    {
        if (is_string($Callback)) {
            return $Callback;
        } elseif (is_array($Callback) && count($Callback)) {
            if (is_object($Callback[0])) {
                return md5(serialize($Callback[0]));
            } else {
                return $Callback[0]."::".$Callback[1];
            }
        } else {
            throw new InvalidArgumentException("Invalid callback value.");
        }
    }

    /**
     * Prepare environment for eventual background task execution.
     * @return bool TRUE if there are tasks to be run, otherwise FALSE.
     */
    private function prepForTSR(): bool
    {
        # if HTML has been output and it's time to launch another task
        # (only TSR if HTML has been output because otherwise browsers
        #       may misbehave after connection is closed)
        if ((!$this->isRunningFromCommandLine())
                && ($this->JumpToPage || !$this->SuppressHTML)
                && $this->taskExecutionEnabled()
                && $this->getTaskQueueSize()) {
            # begin buffering output for TSR
            ob_start();

            # let caller know it is time to launch another task
            return true;
        } else {
            # let caller know it is not time to launch another task
            return false;
        }
    }

    /**
     * Attempt to close out page loading with the browser and then execute
     * background tasks.
     */
    private function launchTSR(): void
    {
        # set headers to close out connection to browser
        if (!$this->NoTSR) {
            ignore_user_abort(true);
            header("Connection: close");
            header("Content-Length: " . ob_get_length());

            if (PHP_SAPI == "fpm-fcgi") {
                fastcgi_finish_request();
            }
        }

        # output buffered content
        while (ob_get_level()) {
            ob_end_flush();
        }
        flush();

        # write out any outstanding data and end HTTP session
        session_write_close();

        # set flag indicating that we are now running in background
        $this->RunningInBackground = true;

        # set database slow query threshold for background execution
        Database::slowQueryThreshold(max(
            self::MIN_DB_SLOW_QUERY_THRESHOLD,
            self::databaseSlowQueryThresholdForBackground()
        ));

        # handle garbage collection for session data
        if (isset($this->SessionStorage) &&
            (rand() / getrandmax()) <= $this->SessionGcProbability) {
            # determine when sessions will expire
            $ExpiredTime = strtotime("-" . $this->sessionLifetime() . " seconds");

            # iterate over files in the session directory with a DirectoryIterator
            # NB: we cannot use scandir() here because it reads the
            # entire list of files into memory and may exceed the memory
            # limit for directories with very many files
            $DI = new DirectoryIterator($this->SessionStorage);
            while ($DI->valid()) {
                if ((strpos($DI->getFilename(), "sess_") === 0) &&
                    $DI->isFile() &&
                    $DI->getCTime() < $ExpiredTime) {
                    unlink($DI->getPathname());
                }
                $DI->next();
            }
            unset($DI);
        }

        # run qny queued tasks
        $this->runQueuedTasks();
    }

    /**
     * Called automatically at program termination to ensure output is written out.
     * (Not intended to be called directly, could not be made private to class because
     * of automatic execution method.)
     */
    public function onCrash(): void
    {
        # attempt to remove any memory limits
        $FreeMemory = StdLib::getFreeMemory();
        ini_set("memory_limit", "-1");

        # if there is a background task currently running
        if (isset($this->RunningTask)) {
            # add info about current page load
            $CrashInfo["ElapsedTime"] = $this->getElapsedExecutionTime();
            $CrashInfo["FreeMemory"] = $FreeMemory;
            $CrashInfo["REMOTE_ADDR"] = $_SERVER["REMOTE_ADDR"];
            $CrashInfo["REQUEST_URI"] = $_SERVER["REQUEST_URI"];
            if (isset($_SERVER["REQUEST_TIME"])) {
                $CrashInfo["REQUEST_TIME"] = $_SERVER["REQUEST_TIME"];
            }
            if (isset($_SERVER["REMOTE_HOST"])) {
                $CrashInfo["REMOTE_HOST"] = $_SERVER["REMOTE_HOST"];
            }

            # add info about error that caused crash (if available)
            if (function_exists("error_get_last")) {
                $CrashInfo["LastError"] = error_get_last();
            }

            # add info about current output buffer contents (if available)
            if (ob_get_length() !== false) {
                $CrashInfo["OutputBuffer"] = ob_get_contents();
            }

            # if backtrace info is available for the crash
            $Backtrace = debug_backtrace();
            if (count($Backtrace) > 1) {
                # discard the current context from the backtrace
                array_shift($Backtrace);

                # add the backtrace to the crash info
                $CrashInfo["Backtrace"] = $Backtrace;
                # else if saved backtrace info is available
            } elseif (isset($this->SavedContext)) {
                # add the saved backtrace to the crash info
                $CrashInfo["Backtrace"] = $this->SavedContext;
            }

            # save crash info for currently running task
            $DB = new Database();
            $DB->query("UPDATE RunningTasks SET CrashInfo = '"
                . addslashes(serialize($CrashInfo))
                . "' WHERE TaskId = " . intval($this->RunningTask["TaskId"]));
        }

        return;
    }

    /**
     * Add additional directory(s) to be searched for files.
     * Specified directory(s) will be searched in order.  If a directory is
     * already present in the list, it will be moved to end to be searched
     * last.  If SearchFirst is TRUE, all search order aspects are reversed,
     * with directories (new or already present) added to the front of the list
     * (to be searced first), and new directories searched in the reverse of
     * the order in which they are supplied.
     * @param array $DirList Current directory list.
     * @param array $NewDirs New directories to be searched.
     * @param bool $SearchFirst If TRUE, the directory(s) are searched after the entries
     *       current in the list, instead of before.
     * @return array Modified directory list.
     */
    private function addToDirList(
        array $DirList,
        array $NewDirs,
        bool $SearchFirst
    ) {
        # for each directory
        foreach ($NewDirs as $Dir) {
            # ensure directory has trailing slash
            if (substr($Dir, -1) != "/") {
                $Dir .= "/";
            }

            # remove directory from list if already present
            if (in_array($Dir, $DirList)) {
                $DirList = array_diff($DirList, [$Dir]);
            }

            # add directory to list of directories
            if ($SearchFirst) {
                array_unshift($DirList, $Dir);
            } else {
                array_push($DirList, $Dir);
            }
        }

        # return updated directory list to caller
        return $DirList;
    }

    /**
     * Add page output modification pattern or callback.
     * @param string $Pattern Regular expression to match against clean URL,
     *       with starting and ending delimiters.
     * @param string $Page Page (P= value) to load if regular expression matches.
     * @param string $SearchPattern Pattern to search for to locate text to be
     *      modified.  (Should use relative URLs.)
     * @param string $Replacement String to use in replacing found text.  (Should
     *      assume relative URLs.)
     * @param string|callable $Template Template to use to insert clean URLs
     *      in HTML output, or callback to perform replacement.
     */
    private function addOutputModification(
        string $Pattern,
        string $Page,
        string $SearchPattern,
        string $Replacement,
        $Template
    ): void {
        # construct absolute path version of search pattern
        $AbsSearchPrefix = preg_quote($this->baseUrl(), "/");
        $AbsSearchPattern = str_replace(
            "index\\.php\\?",
            $AbsSearchPrefix."index\\.php\\?",
            $SearchPattern
        );

        # if template is actually a callback
        if (is_callable($Template)) {
            # add relative absolute path to HTML output mod callbacks list
            $this->OutputModificationCallbacks[] = [
                "Pattern" => $Pattern,
                "Page" => $Page,
                "SearchPattern" => $SearchPattern,
                "Callback" => $Template,
            ];

            # add absolute path pattern to HTML output mod callbacks list
            $this->OutputModificationCallbacks[] = [
                "Pattern" => $Pattern,
                "Page" => $Page,
                "SearchPattern" => $AbsSearchPattern,
                "Callback" => $Template,
            ];
        } else {
            # add relative path version to HTML output modifications list
            $this->OutputModificationPatterns[] = $SearchPattern;
            $this->OutputModificationReplacements[] = $Replacement;

            # construct absolute path version of replacement string
            $AbsReplacement = str_replace(
                "href=\"",
                "href=\"".$this->baseUrl(),
                $Replacement
            );

            # add absolute path version to HTML output modifications list
            $this->OutputModificationPatterns[] = $AbsSearchPattern;
            $this->OutputModificationReplacements[] = $AbsReplacement;
        }
    }

    /**
     * Clear all current output modifications and callbacks.
     */
    private function clearAllOutputModifications(): void
    {
        $this->OutputModificationCallbacks = [];
        $this->OutputModificationPatterns = [];
        $this->OutputModificationReplacements = [];
    }

    /**
     * Callback function for output modifications that require a callback to
     * an external function.  This method is set up to be called
     * @param array $Matches Array of matched elements.
     * @return string Replacement string.
     */
    private function outputModificationCallbackShell($Matches): string
    {
        # call previously-stored external function
        return call_user_func(
            $this->OutputModificationCallbackInfo["Callback"],
            $Matches,
            $this->OutputModificationCallbackInfo["Pattern"],
            $this->OutputModificationCallbackInfo["Page"],
            $this->OutputModificationCallbackInfo["SearchPattern"]
        );
    }

    /**
     * Check to make sure output modification (via preg function) didn't fail
     * in a detectable fashion.
     * @param string $Original Original output.
     * @param string $Modified Modified output.
     * @param string $ErrorInfo Text to include in any logged error message.
     * @return string Version of output to use.
     */
    private function checkOutputModification(
        string $Original,
        string $Modified,
        string $ErrorInfo
    ): string {
        # if error was reported by regex engine
        if (preg_last_error() !== PREG_NO_ERROR) {
            # log error
            $this->logError(
                self::LOGLVL_ERROR,
                "Error reported by regex engine when modifying output."
                . " (" . $ErrorInfo . ")"
            );

            # use unmodified version of output
            $OutputToUse = $Original;
            # else if modification reduced output by more than threshold
        } elseif ((strlen(trim($Modified)) / strlen(trim($Original)))
            < self::OUTPUT_MODIFICATION_THRESHOLD) {
            # log error
            $this->logError(
                self::LOGLVL_WARNING,
                "Content reduced below acceptable threshold while modifying output."
                . " (" . $ErrorInfo . ")"
            );

            # use unmodified version of output
            $OutputToUse = $Original;
        } else {
            # use modified version of output
            $OutputToUse = $Modified;
        }

        # return output to use to caller
        return $OutputToUse;
    }

    /** Threshold below which page output modifications are considered to have failed. */
    const OUTPUT_MODIFICATION_THRESHOLD = 0.10;

    /**
     * Convenience function for getting/setting our string settings.
     * @param string $FieldName Name of database field used to store setting.
     * @param string $NewValue New value for setting.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to TRUE)
     * @return string Current value for setting.
     */
    public function updateStringSetting(
        string $FieldName,
        ?string $NewValue = null,
        bool $Persistent = true
    ): string {
        # if a new value was supplied
        static $LocalSettings;
        if ($NewValue !== null) {
            # if requested to be persistent
            if ($Persistent) {
                # update database and local value
                $LocalSettings[$FieldName] = $this->DB->updateValue(
                    $FieldName,
                    $NewValue
                );
            } else {
                # update local value only
                $LocalSettings[$FieldName] = $NewValue;
            }
        # else if we do not have a local value
        } elseif (!isset($LocalSettings[$FieldName])) {
            # load local value from database
            $LocalSettings[$FieldName] = $this->DB->updateValue($FieldName);
        }

        # return local value to caller
        return $LocalSettings[$FieldName];
    }

    /**
     * Convenience function for getting/setting our integer settings.
     * @param string $FieldName Name of database field used to store setting.
     * @param int $NewValue New value for setting.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to TRUE)
     * @return int Current value for setting.
     */
    public function updateIntSetting(
        string $FieldName,
        ?int $NewValue = null,
        bool $Persistent = true
    ): int {
        # if a new value was supplied
        static $LocalSettings;
        if ($NewValue !== null) {
            # if requested to be persistent
            if ($Persistent) {
                # update database and local value
                $LocalSettings[$FieldName] = $this->DB->updateIntValue(
                    $FieldName,
                    $NewValue
                );
            } else {
                # update local value only
                $LocalSettings[$FieldName] = $NewValue;
            }
        # else if we do not have a local value
        } elseif (!isset($LocalSettings[$FieldName])) {
            # load local value from database
            $LocalSettings[$FieldName] = $this->DB->updateIntValue($FieldName);
        }

        # return local value to caller
        return $LocalSettings[$FieldName];
    }

    /**
     * Convenience function for getting/setting our boolean settings.
     * @param string $FieldName Name of database field used to store setting.
     * @param bool $NewValue New value for setting.  (OPTIONAL)
     * @param bool $Persistent If TRUE the new value will be saved (i.e.
     *       persistent across page loads), otherwise the value will apply to
     *       just the current page load.  (OPTIONAL, defaults to TRUE)
     * @return bool Current value for setting.
     */
    public function updateBoolSetting(
        string $FieldName,
        ?bool $NewValue = null,
        bool $Persistent = true
    ): bool {
        # if a new value was supplied
        static $LocalSettings;
        if ($NewValue !== null) {
            # if requested to be persistent
            if ($Persistent) {
                # update database and local value
                $LocalSettings[$FieldName] = $this->DB->updateBoolValue(
                    $FieldName,
                    $NewValue
                );
            } else {
                # update local value only
                $LocalSettings[$FieldName] = $NewValue;
            }
        # else if we do not have a local value
        } elseif (!isset($LocalSettings[$FieldName])) {
            # load local value from database
            $LocalSettings[$FieldName] = $this->DB->updateBoolValue($FieldName);
        }

        # return local value to caller
        return $LocalSettings[$FieldName];
    }

    /**
     * Include specified file.  This is intended for use to provide a clean
     * context (variable scope landscape) when including a file from within
     * a function.
     * @param string $_AF_File Name of file (including relative path) to load.
     * @param array $_AF_ContextVars Array of variables to set before including
     *       file, with variable names for the index.  (OPTIONAL)
     * @return array Array of variables set after file was included.
     */
    private static function includeFile(
        string $_AF_File,
        array $_AF_ContextVars = []
    ) {
        # set up context
        foreach ($_AF_ContextVars as $_AF_VarName => $_AF_VarValue) {
            $$_AF_VarName = $_AF_VarValue;
        }
        unset($_AF_VarName);
        unset($_AF_VarValue);
        unset($_AF_ContextVars);

        # add variables to context that we assume are always available
        $AF = self::getInstance();

        # load file
        include($_AF_File);

        # return updated context
        $ContextVars = get_defined_vars();
        unset($ContextVars["_AF_File"]);
        return $ContextVars;
    }

    /**
     * Filter context (available variables) based on current settings.
     * @param int $Context Context to filter for.
     * @param array $ContextVars Context variables to filter.
     * @return array Filtered array of variables.
     */
    private function filterContext(int $Context, array $ContextVars): array
    {
        if (!isset($this->ContextFilters[$Context])
            || ($this->ContextFilters[$Context] == false)) {
            # clear all variables if no setting for context is available
            #       or setting is FALSE
            return [];
        } elseif ($this->ContextFilters[$Context] == true) {
            # keep all variables if setting for context is TRUE
            return $ContextVars;
        } else {
            # remove all variables with names that do not match supplied prefixes
            $Prefixes = $this->ContextFilters[$Context];
            $FilterFunc = function ($VarName) use ($Prefixes) {
                foreach ($Prefixes as $Prefix) {
                    if (strpos($VarName, $Prefix) === 0) {
                        return true;
                    }
                }
                return false;
            };
            return array_filter(
                $ContextVars,
                $FilterFunc,
                ARRAY_FILTER_USE_KEY
            );
        }
    }

    /** Default list of directories to search for user interface (HTML/TPL) files. */
    private $InterfaceDirList = [
        "interface/%ACTIVEUI%/",
        "interface/%DEFAULTUI%/",
    ];
    private $PageNameMapFuncs = [];
    /** Default list of directories to search for page (PHP) files. */
    private $PageFileDirList = [
        "pages/",
    ];
    /**
     * Default list of directories to search for UI include (CSS, JavaScript,
     * common HTML, common PHP, /etc) files.
     */
    private $IncludeDirList = [
        "interface/%ACTIVEUI%/include/",
        "interface/%ACTIVEUI%/objects/",
        "interface/%DEFAULTUI%/include/",
        "interface/%DEFAULTUI%/objects/",
    ];
    /** Default list of directories to search for image files. */
    private $ImageDirList = [
        "interface/%ACTIVEUI%/images/",
        "interface/%DEFAULTUI%/images/",
    ];
    /** Default list of directories to search for files containing PHP functions. */
    private $FunctionDirList = [
        "interface/%ACTIVEUI%/include/",
        "interface/%DEFAULTUI%/include/",
        "include/",
    ];

    private $ExpandedDirectoryListCache = [];
    private $ExpandedDirectoryListOriginCache = [];

    const NOVALUE = ".-+-.NO VALUE PASSED IN FOR ARGUMENT.-+-.";


    # ---- Page Building (Internal Methods) ----------------------------------

    /**
     * Find and load page PHP file.
     * @param string $PageName Name of page being loaded.
     * @return string Any output resulting from loading PHP file.
     * @throws InvalidArgumentException If no PHP file available for page.
     */
    private function loadPhpFileForPage(string $PageName): string
    {
        # look for PHP file for page
        $PageFile = $this->findFile(
            $this->PageFileDirList,
            $this->PageName,
            ["php"]
        );

        # signal PHP file load (providing opportunity to modify file name)
        $SignalResult = $this->signalEvent(
            "EVENT_PHP_FILE_LOAD",
            ["PageName" => $this->PageName,
                "FileName" => $PageFile
            ]
        );

        # if signal handler returned new file name
        if (($SignalResult["FileName"] != $PageFile)
            && strlen($SignalResult["FileName"])) {
            # if file name looks valid
            $NewPageFile = $SignalResult["FileName"];
            if (is_readable($NewPageFile)) {
                # use new value for PHP file name
                $PageFile = $NewPageFile;
            } else {
                # log error about bad file name
                $this->logError(
                    self::LOGLVL_ERROR,
                    "Bad PHP file name (\"" . $NewPageFile
                    . "\") returned from EVENT_PHP_FILE_LOAD."
                );
                return "";
            }
        } else {
            # bail out if no file found or file does not exist
            if (($PageFile === null) || !is_readable($PageFile)) {
                return "";
            }
        }

        # start buffering to capture any output from PHP file
        ob_start();

        # load PHP file
        $this->CurrentLoadingContext = self::includeFile($PageFile);

        # save name of PHP file loaded
        $this->PagePhpFile = $PageFile;

        # filter out any unwanted variables from loading context
        $this->CurrentLoadingContext = $this->filterContext(
            self::CONTEXT_PAGE,
            $this->CurrentLoadingContext
        );

        # save buffered output and return it to caller
        $PageFileOutput = ob_get_contents();
        if ($PageFileOutput === false) {
            throw new Exception("Retrieval of PHP file content failed"
                    ." because output buffering level was lost when loading file \""
                    .$this->PagePhpFile."\".");
        }
        ob_end_clean();
        return $PageFileOutput;
    }

    /**
     * Find and load interface (HTML) file for page.
     * @param string $PageName Name of page being loaded.
     * @return string Output resulting from loading file.
     */
    private function loadHtmlFileForPage(string $PageName): string
    {
        # look for HTML file for page
        $PageFile = $this->findFile(
            $this->InterfaceDirList,
            $this->PageName,
            [ "tpl", "html" ]
        );

        # signal HTML file load (providing opportunity to modify file name)
        $SignalResult = $this->signalEvent(
            "EVENT_HTML_FILE_LOAD",
            ["PageName" => $this->PageName,
                "FileName" => $PageFile
            ]
        );

        # if signal handler returned new file name
        if (($SignalResult["FileName"] != $PageFile)
            && strlen($SignalResult["FileName"])) {
            # if file name looks valid
            $NewPageFile = $SignalResult["FileName"];
            if (file_exists($NewPageFile)) {
                # use new value for PHP file name
                $PageFile = $NewPageFile;
            } else {
                # log error about bad file name
                $this->logError(
                    self::LOGLVL_ERROR,
                    "Bad HTML file name (\"" . $NewPageFile
                    . "\") returned from EVENT_HTML_FILE_LOAD."
                );
            }
            # else if signal handler returned new page name
        } elseif (($SignalResult["PageName"] != $this->PageName)
            && strlen($SignalResult["PageName"])) {
            # use new page name to try to find HTML file
            $PageFile = $this->findFile(
                $this->InterfaceDirList,
                $SignalResult["PageName"],
                [ "tpl", "html" ]
            );

            # log error if no HTML file found based on modified page name
            if ($PageFile === null) {
                $this->logError(
                    self::LOGLVL_INFO,
                    "No HTML file found for modified page name."
                    . "  (Original: " . $this->PageName
                    . "  Modified: " . $SignalResult["PageName"]
                    . "  PHP: " . $this->PagePhpFile . ")"
                );
            }
        }

        # begin buffering content
        ob_start();

        # if HTML file is available
        if (isset($PageFile) && file_exists($PageFile)) {
            # load HTML file
            $this->HtmlFileContext = $this->CurrentLoadingContext;
            $this->CurrentLoadingContext = self::includeFile(
                $PageFile,
                $this->CurrentLoadingContext
            );

            # filter out any unwanted variables from loading context
            $this->CurrentLoadingContext = $this->filterContext(
                self::CONTEXT_INTERFACE,
                $this->CurrentLoadingContext
            );

            # save name of HTML file loaded
            $this->PageHtmlFile = $PageFile;
        } else {
            # print error message indicating no HTML file found
            print str_replace("%PAGENAME%", $this->PageName, $this->NoHtmlFileFoundMsg);

            # if PHP file was loaded
            #       and it was not default PHP file or default PHP file was requested
            if ($this->PagePhpFile
                && (($this->PagePhpFile != "pages/" . $this->DefaultPage . ".php")
                    || ($this->PageName == $this->DefaultPage))) {
                # log error about no HTML file found
                $this->logError(
                    self::LOGLVL_ERROR,
                    "No HTML file found for page.  (Page: " . $this->PageName
                    . "  PHP: " . $this->PagePhpFile
                    . "  HTML: " . $PageFile . ")"
                );
            }

            # make sure current page is not cached
            $this->doNotCacheCurrentPage();
        }

        # signal HTML file load complete
        $this->signalEvent("EVENT_HTML_FILE_LOAD_COMPLETE");

        # stop buffering and capture output
        $PageContentOutput = ob_get_contents();
        if ($PageContentOutput === false) {
            throw new Exception("Retrieval of HTML file content failed"
                    ." because output buffering level was lost when loading file \""
                    .$this->PageHtmlFile."\".");
        }
        ob_end_clean();

        # return output to caller
        return $PageContentOutput;
    }

    /**
     * Load standard page start file if available.
     * @return string Any page content generated.
     */
    private function loadStandardPageStart(): string
    {
        $Output = "";
        $File = $this->findFile(
            $this->IncludeDirList,
            "Start",
            [ "tpl", "html" ],
            [ "StdPage", "StandardPage" ]
        );
        if ($File) {
            ob_start();
            $this->CurrentLoadingContext = self::includeFile(
                $File,
                $this->CurrentLoadingContext
            );
            $Output = ob_get_contents();
            if ($Output === false) {
                throw new Exception("Retrieval of standard page start content failed"
                        ." because output buffering level was lost when loading file \""
                        .$File."\".");
            }
            ob_end_clean();
        }
        $this->CurrentLoadingContext = $this->filterContext(
            self::CONTEXT_START,
            $this->CurrentLoadingContext
        );
        return $Output;
    }

    /**
     * Load standard page end file if available.
     * @return string Any page content generated.
     */
    private function loadStandardPageEnd()
    {
        $Output = "";
        $File = $this->findFile(
            $this->IncludeDirList,
            "End",
            [ "tpl", "html" ],
            [ "StdPage", "StandardPage" ]
        );
        if ($File) {
            ob_start();
            self::includeFile($File, $this->CurrentLoadingContext);
            $Output = ob_get_contents();
            if ($Output === false) {
                throw new Exception("Retrieval of standard page end content failed"
                        ." because output buffering level was lost when loading file \""
                        .$File."\".");
            }
            ob_end_clean();
        }
        return $Output;
    }

    /**
     * Perform all post-processing on full page output.
     * @param string $Output Page output.
     * @return string Processed page output.
     */
    private function postProcessPageOutput(string $Output): string
    {
        # perform any insertion keyword replacements
        $Output = $this->doInsertionKeywordReplacements($Output);

        # get list of any required files not loaded
        $RequiredFiles = $this->getRequiredFilesNotYetLoaded($this->PageHtmlFile);

        # add file loading tags to page
        $Output = $this->addFileTagsToPageOutput($Output, $RequiredFiles);

        # add any requested meta tags to page
        $Output = $this->addMetaTagsToPageOutput($Output);

        # make sure output modification patterns and callbacks are current
        $this->convertCleanUrlRequestsToOutputModifications();

        # perform any regular expression replacements in output
        $NewOutput = preg_replace(
            $this->OutputModificationPatterns,
            $this->OutputModificationReplacements,
            $Output
        );

        # check to make sure replacements didn't fail badly
        $Output = $this->checkOutputModification(
            $Output,
            $NewOutput,
            "regular expression replacements"
        );

        # for each registered output modification callback
        foreach ($this->OutputModificationCallbacks as $Info) {
            # set up data for callback
            $this->OutputModificationCallbackInfo = $Info;

            # perform output modification
            $NewOutput = preg_replace_callback(
                $Info["SearchPattern"],
                [ $this, "outputModificationCallbackShell" ],
                $Output
            );

            # check to make sure modification didn't fail
            $ErrorInfo = "callback info: " . print_r($Info, true);
            $Output = $this->checkOutputModification(
                $Output,
                $NewOutput,
                $ErrorInfo
            );
        }

        # provide the opportunity to modify full page output
        $SignalResult = $this->signalEvent(
            "EVENT_PAGE_OUTPUT_FILTER",
            [ "PageOutput" => $Output ]
        );
        if (isset($SignalResult["PageOutput"])
            && strlen(trim($SignalResult["PageOutput"]))) {
            $Output = $SignalResult["PageOutput"];
        }

        # if relative paths may not work because we were invoked via clean URL
        if ($this->CleanUrlRewritePerformed || self::wasUrlRewritten()) {
            $Output = $this->convertRelativePathsToAbsolute($Output);
        }

        return $Output;
    }

    /**
     * Attempt to convert any relative paths in page output to absolute, via the
     * <base> tag if possible, or by rewriting paths if <base> is not an option.
     * @param string $Output Page output.
     * @return string Processed page output.
     */
    private function convertRelativePathsToAbsolute(string $Output): string
    {
        $BaseUrl = $this->baseUrl();

        # if using the <base> tag is okay
        if ($this->UseBaseTag) {
            # add <base> tag to header
            $Output = str_ireplace(
                "<head>",
                "<head><base href=\"".$BaseUrl."\" />",
                $Output
            );

            # get absolute URL to current page
            $FullUrl = $BaseUrl.$this->getRelativeUrl();

            # convert HREF attribute values with just a fragment ID into
            # absolute paths since they don't work with the <base> tag because
            # they are relative to the current page/URL, not the site root
            $Replacements = [
                "%href=\"(#[^:\" ]+)\"%i"
                        => "href=\"".$FullUrl."$1\"",
                "%href='(#[^:' ]+)'%i"
                        => "href='".$FullUrl."$1'",
            ];
        } else {
            # try to convert any relative paths to absolute paths in output
            $SrcFileExtensions = "(js|css|gif|png|jpg|svg|ico)";
            $Replacements = [
                "%src=\"/?([^?*:;{}\\\\\" ]+)\.".$SrcFileExtensions."\"%i"
                        => "src=\"".$BaseUrl."$1.$2\"",
                "%src='/?([^?*:;{}\\\\' ]+)\.".$SrcFileExtensions."'%i"
                        => "src=\"".$BaseUrl."$1.$2\"",
                # (don't rewrite HREF attributes that are just fragment
                #   IDs because they are relative to the current page/URL,
                #   not the site root)
                "%href=\"/?([^#][^:\" ]*)\"%i"
                        => "href=\"".$BaseUrl."$1\"",
                "%href='/?([^#][^:' ]*)'%i"
                        => "href=\"".$BaseUrl."$1\"",
                "%action=\"/?([^#][^:\" ]*)\"%i"
                        => "action=\"".$BaseUrl."$1\"",
                "%action='/?([^#][^:' ]*)'%i"
                        => "action=\"".$BaseUrl."$1\"",
                "%@import\s+url\(\"/?([^:\" ]+)\"\s*\)%i"
                        => "@import url(\"".$BaseUrl."$1\")",
                "%@import\s+url\('/?([^:\" ]+)'\s*\)%i"
                        => "@import url('".$BaseUrl."$1')",
                "%src:\s+url\(\"/?([^:\" ]+)\"\s*\)%i"
                        => "src: url(\"".$BaseUrl."$1\")",
                "%src:\s+url\('/?([^:\" ]+)'\s*\)%i"
                        => "src: url('".$BaseUrl."$1')",
                "%@import\s+\"/?([^:\" ]+)\"\s*%i"
                        => "@import \"".$BaseUrl."$1\"",
                "%@import\s+'/?([^:\" ]+)'\s*%i"
                        => "@import '".$BaseUrl."$1'",
            ];
        }

        # perform path fix replacements in output
        $Patterns = array_keys($Replacements);
        $NewOutput = preg_replace($Patterns, $Replacements, $Output);

        # check to make sure path fixes didn't fail in a detectable manner
        $Output = $this->checkOutputModification(
            $Output,
            $NewOutput,
            ($this->UseBaseTag ? "HREF cleanup" : "relative path fixes")
        );

        return $Output;
    }

    # ---- Page Building (Internal Methods - Insertion Keywords) -------------

    /**
     * Perform insertion keyword replacements in page output.
     * @param string $Output Page output with potential keywords to be replaced.
     * @return string Page output with insertion keyword replacements done.
     */
    private function doInsertionKeywordReplacements(string $Output): string
    {
        # look for insertion keywords, with the keyword in $Matches[1] and the
        #       arguments (if any) in $Matches[2], and perform replacements
        $RegEx = '%(?<!\\\\){{([A-Z0-9-]+)(\|.+)*}}%i';
        $Output = preg_replace_callback(
            $RegEx,
            [$this, "replaceInsertionKeyword"],
            $Output
        );

        # undo any escaped keywords
        foreach ($this->EscapedInsertionKeywords as $Keyword) {
            $Output = str_replace('\\{{' . $Keyword, '{{' . $Keyword, $Output);
        }

        return $Output;
    }

    /**
     * Callback for replacing insertion keywords found.  Intended to be called
     * via preg_replace_callback().  If no callback has been registered for the
     * specified keyword or any argument required by any callback registered for
     * the keyword is not supplied, the full keyword text is returned unchanged.
     * @param array $Matches Matching patterns found by the regular expression.
     * @return string Replacement text for insertion keyword.
     * @see ApplicationFramework::doInsertionKeywordReplacement()
     */
    private function replaceInsertionKeyword(array $Matches): string
    {
        $FullKeywordString = $Matches[0];
        $Keyword = $Matches[1];
        $SuppliedArgs = isset($Matches[2])
            ? $this->parseInsertionKeywordArguments($Matches[2])
            : [];

        # if keyword has no registered callback
        if (!isset($this->InsertionKeywordCallbacks[$Keyword])) {
            $this->UnmatchedInsertionKeywords[] = $Keyword;
            return "";
        }

        $Output = "";
        foreach ($this->InsertionKeywordCallbacks[$Keyword] as $CallbackInfo) {
            if (count($CallbackInfo["Pages"])
                    && !in_array($this->PageName, $CallbackInfo["Pages"])) {
                continue;
            }
            $Result = $this->callInsertionKeywordCallback(
                $Keyword,
                $CallbackInfo["Callback"],
                $CallbackInfo["ReqArgs"],
                $CallbackInfo["OptArgs"],
                $SuppliedArgs
            );
            if ($Result === false) {
                return $FullKeywordString;
            }
            $Output .= $Result;
        }
        return $Output;
    }

    /**
     * Call insertion keyword callback with available arguments in registered order.
     * @param string $Keyword Insertion keyword.  (Used for error logging.)
     * @param callable $Callback Function or method to call.
     * @param array $ReqArgs Alphanumeric names of required arguments for callback.
     * @param array $OptArgs Alphanumeric names of optional arguments for callback.
     * @param array $SuppliedArgs Values of arguments supplied as part of insertion
     *      keyword usage, with argument names for the index.
     * @return string|false Text returned by callback or FALSE if callback was
     *      not called because one or more required arguments were not supplied.
     */
    private function callInsertionKeywordCallback(
        string $Keyword,
        callable $Callback,
        array $ReqArgs,
        array $OptArgs,
        array $SuppliedArgs
    ) {
        $Args = [];

        foreach ($ReqArgs as $ArgName) {
            if (!isset($SuppliedArgs[$ArgName])) {
                $this->logError(self::LOGLVL_INFO, "Required argument \"" . $ArgName
                    . "\" not found for insertion keyword \"" . $Keyword . "\".");
                return false;
            }
            $Args[] = $SuppliedArgs[$ArgName];
        }

        foreach ($OptArgs as $ArgName) {
            $Args[] = isset($SuppliedArgs[$ArgName]) ? $SuppliedArgs[$ArgName] : null;
        }

        return call_user_func_array($Callback, $Args);
    }

    /**
     * Pull arguments (to be passed to registered callback) out of insertion
     * keyword string, undoing any character escaping if necessary..
     * @param string $AllArgsString String containing all arguments, including
     *      delimiters (everything after the keyword and before the closing braces).
     * @return array Argument values, with argument names for the index.
     */
    private function parseInsertionKeywordArguments(string $AllArgsString): array
    {
        # strip off leading "|"
        $AllArgsString = substr($AllArgsString, 1);

        # split string into arguments ("|" chars can appear in args if escaped by "\")
        $ArgStrings = preg_split('%(?<!\\\\)\|%', $AllArgsString);
        if ($ArgStrings === false) {
            throw new Exception("Invalid insertion keyword arguments string (\""
                    .$AllArgsString."\").");
        }

        # for each argument
        $Args = [];
        foreach ($ArgStrings as $ArgString) {
            # separate out argument name and value (":" chars can appear in args
            #       if escaped by "\")
            $ArgPieces = preg_split('%(?<!\\\\)\:%', $ArgString);
            if (($ArgPieces === false) || (count($ArgPieces) != 2)) {
                throw new Exception("Invalid insertion keyword argument string (\""
                        .$ArgString."\").");
            }
            $ArgName = isset($ArgPieces[0]) ? $ArgPieces[0] : "";
            $ArgValue = isset($ArgPieces[1]) ? $ArgPieces[1] : null;

            # un-escape any special characters in value
            if ($ArgValue) {
                $ArgValue = str_replace(['\\:', '\\|'], [':', '|'], $ArgValue);
            }

            $Args[$ArgName] = $ArgValue;
        }

        return $Args;
    }

    # ---- Page Caching (Internal Methods) -----------------------------------

    /**
     * Check for and return cached page if one is available.  If caching is
     * enabled, X-ScoutAF-Cache is also set in the header to "HIT" or "MISS".
     * @param string $PageName Base name of current page.
     * @return string|null Cached page content or NULL if no cached page available.
     */
    private function getCachedPage(string $PageName)
    {
        # check whether page caching is enabled
        if (!$this->pageCacheEnabled()) {
            return null;
        }

        # assume no cached page will be found
        $CachedPage = null;

        # if caching has not been disallowed for current page
        if ($this->CacheCurrentPage) {
            # get fingerprint for requested page
            $DB = $this->DB;
            $EscapedPageFingerprint = $DB->escapeString(
                $this->getPageFingerprint($PageName)
            );

            # look for matching page in cache in database
            $DB->query("SELECT * FROM AF_CachedPages"
                    ." WHERE Fingerprint = '".$EscapedPageFingerprint."'");

            # if matching page found
            if ($DB->numRowsSelected() > 0) {
                # if cached page has expired
                $Row = $DB->fetchRow();
                $ExpirationTime = strtotime(
                    "-" . $this->getPageCacheExpirationPeriod() . " minutes"
                );
                if ((strtotime($Row["CachedAt"]) < $ExpirationTime)
                        || (($Row["ExpirationDate"] !== null)
                                && (strtotime($Row["ExpirationDate"]) < time()))) {
                    # clear all expired pages from cache
                    $this->clearExpiredPagesFromCache();
                } else {
                    # decompress cached data to get cached page
                    $CachedPage = gzuncompress($Row["PageContent"]);

                    # if decompression failed
                    if ($CachedPage === false) {
                        # clear data for this page from cache
                        $DB->query("DELETE FROM AF_CachedPages"
                                ." WHERE Fingerprint = '".$EscapedPageFingerprint."'");

                        # report no cached page available to caller
                        return null;
                    }

                    # save cache expiration time for page
                    $this->CurrentPageExpirationDate = $Row["ExpirationDate"];
                }
            }
        }

        # set cache status in HTTP header
        header("X-ScoutAF-Cache: " . ($CachedPage ? "HIT" : "MISS"));

        # return any cached page found to caller
        return $CachedPage;
    }

    /**
     * Clear all expired pages from page cache.
     */
    private function clearExpiredPagesFromCache(): void
    {
        $ExpirationPeriod = $this->getPageCacheExpirationPeriod();
        $ExpirationTime = strtotime("-".$ExpirationPeriod." minutes");
        if ($ExpirationTime === false) {
            throw new Exception("Invalid page cache expiration period ("
                    .$ExpirationPeriod." minutes).");
        }
        $ExpirationTimestamp = date(StdLib::SQL_DATE_FORMAT, $ExpirationTime);
        # (these DELETEs are done as two separate queries for each table
        #       because OR conditions can prevent indexes from being used)
        # (may benefit from being refactored to use a UNION)
        $this->DB->query("DELETE CP, CPTI FROM AF_CachedPages CP,"
                ." AF_CachedPageTagInts CPTI"
                ." WHERE CPTI.CacheId = CP.CacheId"
                ." AND CP.CachedAt < '".$ExpirationTimestamp."'");
        $this->DB->query("DELETE CP, CPTI FROM AF_CachedPages CP,"
                ." AF_CachedPageTagInts CPTI"
                ." WHERE CPTI.CacheId = CP.CacheId"
                ." AND CP.ExpirationDate IS NOT NULL"
                ." AND CP.ExpirationDate < NOW()");
        $this->DB->query("DELETE FROM AF_CachedPages "
                ." WHERE CachedAt < '".$ExpirationTimestamp."'");
        $this->DB->query("DELETE FROM AF_CachedPages "
                ." WHERE ExpirationDate IS NOT NULL"
                ." AND ExpirationDate < NOW()");
    }

    /**
     * Update the page cache for the current page.
     * @param string $PageName Name of page.
     * @param string $PageContent Full content of page.
     */
    private function updatePageCache(string $PageName, string $PageContent): void
    {
        # if page caching is enabled and current page should be cached
        if ($this->pageCacheEnabled()
            && $this->CacheCurrentPage) {
            # if page content looks invalid
            if (strlen(trim(strip_tags($PageContent))) == 0) {
                # log error
                $LogMsg = "Page not cached because content was empty."
                    . " (PAGE: " . $PageName . ", URL: " . $this->fullUrl() . ")";
                $this->logError(self::LOGLVL_ERROR, $LogMsg);
            } else {
                # escape page fingerprint
                $DB = $this->DB;
                $EscapedPageFingerprint = $DB->escapeString(
                    $this->getPageFingerprint($PageName)
                );

                # compress and escape page data
                $EscapedPageContent = $DB->escapeString(gzcompress($PageContent, 9));

                # if we have an expiration date for cached version of this page
                if ($this->CurrentPageExpirationDate !== null) {
                    # save cached page version with predetermined expiration date
                    $EscapedExpiration = $this->DB->escapeString(
                        $this->CurrentPageExpirationDate
                    );
                    $DB->query("INSERT INTO AF_CachedPages"
                            ." (Fingerprint, PageContent, ExpirationDate) VALUES"
                            ." ('".$EscapedPageFingerprint."', '"
                                    .$EscapedPageContent."', '"
                                    .$EscapedExpiration."')");
                } else {
                    # save cached page version with no predetermined expiration date
                    $DB->query("INSERT INTO AF_CachedPages"
                            ." (Fingerprint, PageContent) VALUES"
                            ." ('".$EscapedPageFingerprint."', '"
                                    .$EscapedPageContent."')");
                }
                $CacheId = $DB->getLastInsertId();

                # for each page cache tag that was added
                foreach ($this->PageCacheTags as $Tag => $Pages) {
                    # if current page is in list for tag
                    if (in_array("CURRENT", $Pages) || in_array($PageName, $Pages)) {
                        # look up tag ID
                        $TagId = $this->getPageCacheTagId($Tag);

                        # mark current page as associated with tag
                        $DB->query("INSERT INTO AF_CachedPageTagInts"
                            . " (TagId, CacheId) VALUES "
                            . " (" . intval($TagId) . ", " . intval($CacheId) . ")");
                    }
                }

                # if callback was registered for page cache hits
                if (isset($this->CallbackForPageCacheHits)) {
                    $CallbackData = $this->DB->escapeString(
                        serialize($this->CallbackForPageCacheHits)
                    );
                    $DB->query("INSERT INTO AF_CachedPageCallbacks"
                            ." (CacheId, Callbacks)"
                            ." VALUES (".$CacheId.", '".$CallbackData."')"
                            ." ON DUPLICATE KEY UPDATE Callbacks = '".$CallbackData."'");
                }
            }
        }
    }

    /**
     * Get ID for specified page cache tag.
     * @param string $Tag Page cache tag string.
     * @return int Page cache tag ID.
     */
    private function getPageCacheTagId(string $Tag): int
    {
        # if tag is a non-negative integer
        if (is_numeric($Tag) && ($Tag > 0) && (intval($Tag) == $Tag)) {
            # generate ID
            $Id = self::PAGECACHETAGIDOFFSET + intval($Tag);
        } else {
            # look up ID in database
            $Id = $this->DB->queryValue("SELECT TagId FROM AF_CachedPageTags"
                . " WHERE Tag = '" . addslashes($Tag) . "'", "TagId");

            # if ID was not found
            if ($Id === null) {
                # add tag to database
                $this->DB->query("INSERT INTO AF_CachedPageTags"
                    . " SET Tag = '" . addslashes($Tag) . "'");
                $Id = $this->DB->getLastInsertId();
            }
        }

        # return tag ID to caller
        return $Id;
    }

    /**
     * Get fingerprint string for current page.
     * @param string $PageName Name of current page.
     * @return string Fingerprint string.
     */
    private function getPageFingerprint(string $PageName): string
    {
        # build the environmental fingerprint only once so that it is consistent
        #       between page construction start and end
        static $EnvFingerprint;
        if (!isset($EnvFingerprint)) {
            $GetVars = $_GET;
            ksort($GetVars);
            $PostVars = $_POST;
            ksort($PostVars);
            $EnvData = json_encode($GetVars)
                . json_encode($PostVars)
                . $_SERVER["SERVER_NAME"];
            $EnvFingerprint = md5($EnvData);
        }

        # build page fingerprint and return it to caller
        return $PageName . "-" . $EnvFingerprint;
    }

    /**
     * Run any callback registered for a page cache hit on the specified page.
     * @param string $PageName Name of page to check for registered callback.
     */
    private function runCallbackForPageCacheHit(string $PageName): void
    {
        # check for callback for page
        $Fingerprint = $this->getPageFingerprint($this->PageName);
        $Callbacks = $this->DB->queryValue(
            "SELECT Callbacks"
                ." FROM AF_CachedPages CP, AF_CachedPageCallbacks CPC"
                ." WHERE CPC.CacheId = CP.CacheId"
                ." AND CP.Fingerprint = '".addslashes($Fingerprint)."'",
            "Callbacks"
        );

        # if callback were found
        if ($Callbacks !== null) {
            # if stored callback is still valid
            $Callbacks = unserialize($Callbacks);
            if (is_callable($Callbacks["Function"])) {
                # run callback for page
                $Callbacks["Function"](...array_values($Callbacks["Parameters"]));
            }
        }
    }


    # ---- Utility (Internal Methods) ----------------------------------------

    /**
     * If current session is in active use, update the
     * SessionLastUsed timestamp stored in the session. This is done
     * primarily to bump the CTime on the session file, so that we
     * don't prematurely clean up sessions.
     */
    private function updateLastUsedTimeForActiveSessions(): void
    {
        if ($this->SessionInUse) {
            $_SESSION["AF_SessionLastUsed"] = date(StdLib::SQL_DATE_FORMAT);
        } elseif (isset($_SESSION["AF_SessionLastUsed"])) {
            unset($_SESSION["AF_SessionLastUsed"]);
        }
    }

    /**
     * When PHP is run via a CGI interpreter rather than as a module,
     * variables defined in the .htaccess file have a prefix ("REDIRECT_")
     * added to the variable name.  This method attempts to detect and
     * correct that situation, so that the variables are available via the
     * intended names when running via CGI.
     */
    private static function adjustEnvironmentForCgi(): void
    {
        # if it appears we are running via a CGI interpreter
        if (isset($_SERVER["ORIG_SCRIPT_NAME"])) {
            # for each server environment variable
            foreach ($_SERVER as $Key => $Value) {
                # if variable appears the result of using CGI
                if (strpos($Key, "REDIRECT_") === 0) {
                    # if unmodified version of variable is not set
                    $KeyWithoutPrefix = substr($Key, 9);
                    if (!isset($_SERVER[$KeyWithoutPrefix])) {
                        # set unmodified version of variable
                        $_SERVER[$KeyWithoutPrefix] = $_SERVER[$Key];
                    }
                }
            }
        }
    }

    /**
     * Redirect to another page via (in descending order of preference) 303,
     * 301, or by outputting a blank page with a refresh meta tag.
     * @param string $Page Address (URL) to redirect to.
     */
    private static function redirectToPage(string $Page): void
    {
        # if client supports HTTP/1.1 or 2.0, use a 303 as it is most accurate
        if ($_SERVER["SERVER_PROTOCOL"] == "HTTP/1.1"
                || $_SERVER["SERVER_PROTOCOL"] == "HTTP/2.0") {
            header($_SERVER["SERVER_PROTOCOL"]." 303 See Other");
            header("Location: " . $Page);
        } else {
            # if the request was an HTTP/1.0 GET or HEAD, then
            #   use a 302 response code.
            # (both RFC 2616 (HTTP/1.1) and RFC1945 (HTTP/1.0)
            #   explicitly prohibit automatic redirection via a 302
            #   if the request was not GET or HEAD)
            if ($_SERVER["SERVER_PROTOCOL"] == "HTTP/1.0" &&
                ($_SERVER["REQUEST_METHOD"] == "GET" ||
                    $_SERVER["REQUEST_METHOD"] == "HEAD")) {
                header("HTTP/1.0 302 Found");
                header("Location: " . $Page);
                # otherwise, fall back to a meta refresh
            } else {
                print '<html><head><meta http-equiv="refresh" '
                    . 'content="0; URL=' . $Page . '">'
                    . '</head><body></body></html>';
            }
        }
    }

    /**
     * Determine if the current HTTP request is a Range request of a type that
     *   we support. Currently this includes single-part Range requests
     *   (needed by Safari for files in <video> tags) and open-ended range
     *   requests (sent by Chrome and Firefox for files in <video> tags).
     *   multi-part ranges and negative ranges are not supported.  (per
     *   RFC9110 sec 14 paragraph 2, servers may ignore the Range header and
     *   respond as if the GET had been provided without one, so this partial
     *   support is RFC compliant)
     * @return bool TRUE for supported Range requests, FALSE otherwise.
     * @see handleRangeRequest()
     */
    private static function isSupportedRangeRequest(): bool
    {
        return (bool)preg_match('%^bytes=[0-9]+-[0-9]*$%', $_SERVER["HTTP_RANGE"] ?? "");
    }

    /**
     * Handle HTTP GET requests that included a Range header. If the range is
     *   invalid, replies with HTTP 416. Otherwise replies with HTTP 206 and
     *   the requested chunk of the file.
     * @param resource $Handle Open file handle for the data file, as returned
     *     by `fopen()`.
     * @param int $FileSize Size of the source file.
     * @param int $BlockSize Block size to use when reading file.
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Range_requests
     */
    private static function handleRangeRequest(
        $Handle,
        int $FileSize,
        int $BlockSize
    ): void {
        $Protocol = $_SERVER["SERVER_PROTOCOL"];

        if (!preg_match('%^bytes=([0-9]+)-([0-9]*)$%', $_SERVER["HTTP_RANGE"], $Matches)) {
            throw new Exception(
                __FUNCTION__." called for non-Range request. "
            );
        }

        $Start = intval($Matches[1]);

        # if end was omitted, return up to two blocks of data
        # (per RFC9110 sec 15.3.7 paragraph 2, servers can opt to send
        # a subset of the requested data)
        $End = (strlen($Matches[2]) > 0) ?
            intval($Matches[2]) :
            min($FileSize - 1, $Start + 2 * $BlockSize);

        if ($Start < 0 || $End < $Start || $End >= $FileSize) {
            header($Protocol." 416 Range Not Satisfiable");
            return;
        }

        header($Protocol." 206 Partial Content");
        header("Content-Range: bytes ".$Start."-".$End."/".$FileSize);
        header("Content-Length: " . ($End - $Start + 1));

        $Position = $Start;
        fseek($Handle, $Position);
        while ($Position < $End) {
            $ThisBlockSize = max(1, min($BlockSize, $End - $Position + 1));

            print fread($Handle, $ThisBlockSize);
            flush();

            $Position += $ThisBlockSize;
        }
    }

    /**
     * Log slow page loads, if appropriate.
     */
    private function checkForAndLogSlowPageLoads(): void
    {
        if (!$this->DoNotLogSlowPageLoad
                && $this->logSlowPageLoads()
                && ($this->getElapsedExecutionTime()
                        >= ($this->slowPageLoadThreshold()))) {
            $Msg = "Slow page load ("
                    .intval($this->getElapsedExecutionTime())."s) for "
                    .$this->fullUrl()." from ".StdLib::getHostName();
            $this->logMessage(self::LOGLVL_INFO, $Msg);
        }
    }

    /**
     * Log high memory usage, if appropriate.
     */
    private function checkForAndLogHighMemoryUsage(): void
    {
        if ($this->logHighMemoryUsage()) {
            $PeakUsage = memory_get_peak_usage();
            $MemoryThreshold = ($this->highMemoryUsageThreshold()
                    * StdLib::getPhpMemoryLimit()) / 100;
            if ($PeakUsage >= $MemoryThreshold) {
                $HighMemUsageMsg = "High peak memory usage ("
                        .number_format($PeakUsage).") for "
                        .$this->fullUrl()." from ".StdLib::getHostName();
                $this->logMessage(self::LOGLVL_INFO, $HighMemUsageMsg);
            }
        }
    }

    /**
     * Display output from page file, if any.
     * @param string $Output Page file output.
     */
    private function displayPageFileOutput(string $Output): void
    {
        if (strlen($Output)) {
            if (!$this->SuppressHTML) {
                ?><table width="100%" cellpadding="5"
                style="border: 2px solid #666666;  background: #CCCCCC;
                        font-family: Courier New, Courier, monospace;
                        margin-top: 10px;"><tr><td><?PHP
            }
            if ($this->JumpToPage) {
                ?>
                <div style="color: #666666;"><span style="font-size: 150%;">
                <b>Page Jump Aborted</b></span>
                (because of error or other unexpected output)<br/>
                <b>Jump Target:</b>
                <i><?PHP print($this->JumpToPage); ?></i></div><?PHP
            }
            print $Output;
            if (!$this->SuppressHTML) {
                ?></td></tr></table><?PHP
            }
        }
    }

    /**
     * Generate a one-line summary of a stack frame from a backtrace.
     * @param array $Frame Stack frame from debug_backtrace().
     * @return string Stack frame summary.
     */
    private static function stackFrameSummary(array $Frame) : string
    {
        if (isset($Frame["file"]) && isset($Frame["line"])) {
            return basename($Frame["file"]).":".$Frame["line"];
        }

        if (isset($Frame["class"]) && isset($Frame["function"])) {
            return $Frame["class"]."::".$Frame["function"];
        }

        return preg_replace('/\v/', ' ', print_r($Frame, true));
    }

    /**
     * Read "RewriteBase" value from .htaccess file, if available.
     * @return string RewriteBase value or empty string if none found.
     */
    private static function getRewritebaseFromHtaccess(): string
    {
        $RewriteBase = "";
        if (is_readable(".htaccess")) {
            $Lines = file(".htaccess");
            if ($Lines !== false) {
                foreach ($Lines as $Line) {
                    if (preg_match("/\\s*RewriteBase\\s+/", $Line)) {
                        $Pieces = preg_split(
                            "/\\s+/",
                            $Line,
                            -1,
                            PREG_SPLIT_NO_EMPTY
                        );
                        if (($Pieces !== false) && (count($Pieces) >= 2)) {
                            $RewriteBase = $Pieces[1];
                        }
                    }
                }
            }
        }
        return $RewriteBase;
    }
}
