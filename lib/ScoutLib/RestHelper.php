<?PHP
#
#   FILE:  RestHelper.php
#
#   Part of the ScoutLib application support library
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use InvalidArgumentException;

/**
 * Helper class to assist with and streamline the use of REST API calls.
 * Calls are assumed to return data in JSON format.
 */
class RestHelper
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param string $Url Endpoint URL for REST API calls.
     */
    public function __construct(string $Url)
    {
        # check that URL looks valid
        if (filter_var($Url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("Bad URL (\"".$Url."\").");
        }

        $this->Url = $Url;
    }

    /**
     * Issue REST API call.
     * @param array $Params Parameter values to pass for call, with
     *      parameter names for the index.
     * @param string $UrlSuffix Suffix to append to endpoint URL.
     * @return mixed Returned value(s) parsed from JSON data returned
     *      by call or NULL if call failed or data passed back was not
     *      parseable JSON data.  (NOTE: If JSON-encoded data that
     *      contains nothing more than "null" is a valid expected response,
     *      NULL will be returned and the response text will need to be
     *      retrieved via getCurlResponse() and examined to determine
     *      whether the call succeeded.)
     * @throws Exception if invalid access method has been set.
     */
    public function issueCall(array $Params, string $UrlSuffix = "")
    {
        # if cURL context has not yet been set
        if ($this->Context === null) {
            # initialize cURL context
            $this->Context = curl_init();

            # set data to be returned as a string
            curl_setopt($this->Context, CURLOPT_RETURNTRANSFER, true);

            $this->setCurlCookieFileLocation();
        }

        switch ($this->Method) {
            case self::METHOD_GET:
                # set cURL request method to GET
                curl_setopt($this->Context, CURLOPT_HTTPGET, true);

                # add parameter values to URL
                $UrlSuffix .= http_build_query($Params);
                break;

            case self::METHOD_POST:
                # set cURL request method to POST
                curl_setopt($this->Context, CURLOPT_POST, true);

                # add parameter values to context
                curl_setopt($this->Context, CURLOPT_POSTFIELDS, $Params);
                break;

            case self::METHOD_PUT:
                # set cURL request method to PUT
                curl_setopt($this->Context, CURLOPT_CUSTOMREQUEST, "PUT");

                # add parameter values to context
                curl_setopt($this->Context, CURLOPT_POSTFIELDS, $Params);
                break;

            default:
                throw new Exception("Unknown method (\"".$this->Method."\").");
        }

        # set target endpoint URL for calls
        $EndpointUrl = $this->Url.$UrlSuffix;
        if (empty($EndpointUrl) || !filter_var($EndpointUrl, FILTER_VALIDATE_URL)) {
            throw new Exception("Target endpoint URL appears invalid (\""
                    .$EndpointUrl."\").");
        }
        curl_setopt($this->Context, CURLOPT_URL, $EndpointUrl);

        # make sure any minimum required time between calls has elapsed
        $this->ensureMinimumTimeBetweenCalls();

        # get lock to prevent concurrent requests (if appropriate)
        if ($this->BlockConcurrentRequests) {
            $AF = ApplicationFramework::getInstance();
            $CallerInfo = StdLib::getCallerInfo(2);
            $LockName = pathinfo($CallerInfo["FileName"], PATHINFO_FILENAME);
            $AF->getLock($LockName);
        }

        # issue the REST API call
        $Response = curl_exec($this->Context);

        # parse response (curl_exec() returns FALSE for failure)
        if ($Response === false) {
            $ReturnValue = null;
            $this->CurlResponse = null;
            $this->CurlError = curl_error($this->Context);
        } else {
            # (json_decode() returns NULL when it fails)
            $ReturnValue = json_decode((string)$Response, true);
            $this->CurlResponse = $Response;
            $this->CurlError = "";
        }

        # release lock (if appropriate)
        if ($this->BlockConcurrentRequests) {
            $AF->releaseLock($LockName);
        }

        return $ReturnValue;
    }

    /**
     * Set method to be used for REST API access.  If not explicitly set,
     * the default is METHOD_POST.
     * @param string $Method Method to use (METHOD_ constant).
     */
    public function setMethod(string $Method): void
    {
        $this->Method = $Method;
    }

    /**
     * Set minimum number of seconds between calls for the URL
     * specified in the constructor.  If the specified time has not
     * elapsed since the last call, execution will be delayed the
     * necessary amount via sleep() or usleep().  The default is no
     * minimum time between calls.
     * @param float $Seconds Time in seconds.
     */
    public function setMinimumTimeBetweenCalls(float $Seconds): void
    {
        $this->MinTimeBetweenCalls = $Seconds;
    }

    /**
     * Retrieve text returned by cURL for the last call issued.
     * @return ?string Returned text or NULL if cURL invocation failed.
     */
    public function getCurlResponse(): ?string
    {
        return $this->CurlResponse;
    }

    /**
     * Retrieve error clear text for the last call cURL call issued.
     * @return string Returned error in clear text or an empty string if
     *      the last cURL invocation succeeded.
     */
    public function getCurlError(): string
    {
        return $this->CurlError;
    }

    /**
     * Reset context for REST API calls.  This will also have the effect of
     * any and all current internal cookies being saved to the cookie file.
     */
    public function resetContext(): void
    {
        $this->Context = null;
    }

    /**
     * Set name of file in which to store cookie information.  If no file
     * name is set via this method, one will be generated when issueCall() is
     * called, using the name of the file from where the call was issued and
     * the COOKIEFILE_* class constants.
     * @param string $CookieFileName Full name of file in which to store cookie
     *      info, including any leading directory path.
     */
    public function setCookieFileName(string $CookieFileName): void
    {
        $this->CookieFileName = $CookieFileName;
    }

    /**
     * Allow concurrent requests from the same caller.  If this method is not
     * called, concurrent requests are blocked using a lock based on the name
     * of the file from where issueCall() is called.
     */
    public function allowConcurrentRequests(): void
    {
        $this->BlockConcurrentRequests = false;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $BlockConcurrentRequests = true;
    private $Context = null;
    private $CookieFileName = null;
    private $CurlError = null;
    private $CurlResponse = null;
    private $Method = self::METHOD_POST;
    private $MinTimeBetweenCalls = 0;
    private $Url;

    private static $LastCallTimes = [];

    const COOKIEFILE_BASE_DIR = "local/data/caches";
    const COOKIEFILE_NAME = "cookies.txt";
    const METHOD_GET = "GET";
    const METHOD_POST = "POST";
    const METHOD_PUT = "PUT";

    /**
     * Add location of file in which to store cookies to current CURL context.
     * The context must be initialized before calling this method.  If no file
     * name has been set, one will be generated using the name of the caller's
     * caller and the COOKIEFILE_* class constants.
     */
    private function setCurlCookieFileLocation(): void
    {
        $CookieFileName = $this->CookieFileName;
        if ($CookieFileName === null) {
            # build name of directory to store cookie file in based on
            #       the name of our caller's caller
            $CallerInfo = StdLib::getCallerInfo(2);
            $CallerRootFileName = pathinfo($CallerInfo["FileName"], PATHINFO_FILENAME);
            $CookieFileDir = getcwd()."/".self::COOKIEFILE_BASE_DIR
                    ."/".$CallerRootFileName;

            # attempt to create directory if it does not already exist
            if (!file_exists($CookieFileDir)) {
                mkdir($CookieFileDir);
            }
            if (!is_dir($CookieFileDir)) {
                throw new Exception("Unable to create cookie file storage directory.");
            }

            # assemble cookie file name
            $CookieFileName = $CookieFileDir."/".self::COOKIEFILE_NAME;
        }

        curl_setopt($this->Context, CURLOPT_COOKIEFILE, $CookieFileName);
        curl_setopt($this->Context, CURLOPT_COOKIEJAR, $CookieFileName);
    }

    /**
     * If a minimum time between calls for this URL was specified, ensure
     * that it has elapsed since the last call.
     */
    private function ensureMinimumTimeBetweenCalls(): void
    {
        # return immediately if no minimum time is set
        if ($this->MinTimeBetweenCalls == 0) {
            return;
        }

        # if we have no record of a call to this URL
        if (!isset(self::$LastCallTimes[$this->Url])) {
            # record time of call and return immediately
            self::$LastCallTimes[$this->Url] = microtime(true);
            return;
        }

        # calculate time elapsed since last call
        $ElapsedTime = microtime(true) - self::$LastCallTimes[$this->Url];

        # if more than the minimum time has elapsed
        if ($ElapsedTime >= $this->MinTimeBetweenCalls) {
            # record time of call and return immediately
            self::$LastCallTimes[$this->Url] = microtime(true);
            return;
        }

        # sleep until minimum time has elapsed
        $TimeToSleep = $this->MinTimeBetweenCalls - $ElapsedTime;
        if ($TimeToSleep < 1) {
            usleep((int)($TimeToSleep * 1000000));
        } else {
            sleep((int)ceil($TimeToSleep));
        }

        # record time of call
        self::$LastCallTimes[$this->Url] = microtime(true);
    }
}
