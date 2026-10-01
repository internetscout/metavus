<?PHP
#
#   FILE:  CachingHttpClient.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2026 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace ScoutLib;
use Exception;
use InvalidArgumentException;

/**
 * An HTTP client that includes a cache that stores successful (200 OK) http
 * responses. There is no caching of other responses. The cache is indexed by
 * the URL of the request. Cached responses are stored for two weeks after
 * their expiration to allow re-validation using a conditional HTTP request if
 * they are accessed again in that time. Conditional requests include HTTP
 * headers that tell the server about the version of the response we
 * have. Servers can either reply with '200 OK' and give a (potentially
 * updated) body or '304 Not Modified' with no response body.
 *
 * There is no limit imposed on either the number of entries in the cache or
 * the size of any individual entry. Callers are expected to use this class
 * for fetching a reasonable number of small to moderately sized objects.
 *
 * The cache control portions of the HTTP Caching RFCs are respected -- If
 * Expires or Max-Age information is provided by the remote server, then
 * responses are used from cache without revalidation while they are still
 * fresh (so, no HTTP requests issued at all). When no expiry information is
 * provided or after responses have expired, conditional requests are used to
 * re-validate and potentially update the cached data. If the remote server
 * gives a 'no-cache' header then the response will be revalidated with a
 * conditional request on every use. If the remote server gives a 'no-store'
 * header then the response will not be cached at all.
 *
 * In the terms of the MDN "HTTP caching" document referenced below, this is a
 * "private cache" that operates similar to a browser cache.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Caching
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Conditional_requests
 */
class CachingHttpClient
{
    /**
     * Constructor.
     * @param string $UserAgentPrefix Prefix for the User Agent used for
     *         requests.
     */
    public function __construct(string $UserAgentPrefix)
    {
        if (self::$Cache === null) {
            self::$Cache = new DataCache(__CLASS__."-");
        }

        $this->UserAgentPrefix = $UserAgentPrefix;
    }

    /**
     * Fetch a provided url.
     * @param string $Url Url to fetch
     * @return string|false Response body as a string on success, false on failure.
     * @throws InvalidArgumentException when a non-http(s) URL is provided.
     * @throws Exception When a 304 response is received without cached data.
     */
    public function fetchUrl(string $Url)
    {
        $UrlScheme = strtolower((string)parse_url($Url, PHP_URL_SCHEME));
        if (!in_array($UrlScheme, ["http", "https"])) {
            throw new InvalidArgumentException(
                "Unsupported URL scheme - must be http or https."
            );
        }

        $RequestHeaders = [];

        # generate cache key from the request URL
        # (assumes there will not be hash collisions)
        $CacheKey = str_replace("/", "_", base64_encode(hash("sha256", $Url, true)));

        $CachedResponse = self::$Cache->get($CacheKey);
        if ($CachedResponse !== null) {
            # if we have a cached response that has not expired, and we are allowed
            # to use it without revalidation, return it
            if ($CachedResponse["MaxAge"] !== null
                    && (time() - $CachedResponse["FetchedAt"]) <= $CachedResponse["MaxAge"]
                    && $CachedResponse["NoCache"] == 0) {
                return $CachedResponse["Body"];
            }

            # otherwise add headers so we can do a conditional request
            # @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Conditional_requests
            if ($CachedResponse["ETag"] !== null) {
                $RequestHeaders[] = "If-None-Match: ".$CachedResponse["ETag"];
            }
            if ($CachedResponse["LastModified"] !== null) {
                $RequestHeaders[] = "If-Modified-Since: ".$CachedResponse["LastModified"];
            }
        }

        $UserAgent = $this->UserAgentPrefix
            ." PHP/".PHP_VERSION;

        # set up our curl instance
        $Context = curl_init();
        curl_setopt_array(
            $Context,
            [
                CURLOPT_COOKIEFILE => "",
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADER => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_URL => $Url,
                CURLOPT_USERAGENT => $UserAgent,
            ]
        );
        if (count($RequestHeaders) !== 0) {
            curl_setopt($Context, CURLOPT_HTTPHEADER, $RequestHeaders);
        }

        # perform the fetch
        $Result = curl_exec($Context);
        if ($Result === false) {
            return false;
        }
        if ($Result === true) {
            throw new Exception(
                "Body not returned from curl_exec() despite setting"
                ." CURLOPT_RETURNTRANSFER (should be impossible)."
            );
        }

        $HeaderSize = curl_getinfo($Context, CURLINFO_HEADER_SIZE);

        # read headers from result file
        $Headers = $this->parseHeaders(
            substr($Result, 0, $HeaderSize)
        );

        # if not modified, return cached data and update cache row
        if ($Headers["status-line"]["ResponseCode"] == 304) {
            if ($CachedResponse === null) {
                throw new Exception(
                    "Got 304 reply when there was no cached data"
                    ." (should be impossible)."
                );
            }

            # per https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Status/304
            # a 304 must include the same headers that would have been sent
            # with an equivalent 200, so we're good to update our cache from this response
            $this->updateCache($CacheKey, $CachedResponse, $Headers, null);

            return $CachedResponse["Body"];
        }

        # read in the response body
        $Body = substr($Result, $HeaderSize);

        # on failures, return false
        if ($Headers["status-line"]["ResponseCode"] != 200) {
            return false;
        }

        # on success, see if we're allowed to store this response
        if (isset($Headers["cache-control"]["no-store"])) {
            # if not, and if we had cache data, delete it
            if ($CachedResponse !== null) {
                self::$Cache->delete($CacheKey);
            }
        } else {
            # otherwise, updated cached data
            $this->updateCache(
                $CacheKey,
                $CachedResponse ?? [],
                $Headers,
                $Body
            );
        }

        # and return the body
        return $Body;
    }

    /**
     * Parse headers from a string.
     * @param string $HeaderString Headers as a string.
     * @return array Key/Value array of HTTP Headers, with keys normalized to
     *   lowercase.
     */
    private function parseHeaders(string $HeaderString): array
    {
        $Headers = [];
        foreach (explode("\r\n", $HeaderString) as $Line) {
            # skip the blank line at the end of the headers
            if (strlen(trim($Line)) == 0) {
                continue;
            }

            # check if this is a named header, extract it if so
            if (strpos($Line, ":") !== false) {
                list($Key, $Val) = explode(":", $Line, 2);
                $Key = strtolower(trim($Key));
                if ($Key == "cache-control") {
                    if (!isset($Headers[$Key])) {
                        $Headers[$Key] = [];
                    }

                    $Headers[$Key] += $this->parseCacheControl($Val);
                    continue;
                }

                $Headers[$Key] = trim($Val);
                continue;
            }

            # reset headers on each status line (handles redirects)
            $Headers = [];
            $StatusParts = explode(" ", $Line, 3);
            $Headers["status-line"] = [
                "HttpVersion" => $StatusParts[0],
                "ResponseCode" => $StatusParts[1],
                "ReasonPhrase" => $StatusParts[2] ?? ""
            ];
        }

        return $Headers;
    }

    /**
     * Parse a cache control header.
     * @param string $HeaderData Data from the HTTP headers.
     * @return array Key/Value array of cache control data, with `true` for
     *     the values of attributes like no-store or no-cache that do not come
     *     with a value.
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Cache-Control
     *
     */
    private function parseCacheControl($HeaderData): array
    {
        $CacheControl = [];

        $Pieces = explode(",", strtolower($HeaderData));
        foreach ($Pieces as $Piece) {
            $Piece = trim($Piece);
            if (strpos($Piece, "=") === false) {
                $CacheControl[$Piece] = true;
                continue;
            }

            $SubPieces = explode("=", $Piece, 2);
            $Key = trim($SubPieces[0]);
            $Val = trim($SubPieces[1]);

            $CacheControl[$Key] = $Val;
        }

        return $CacheControl;
    }

    /**
     * Update the cache for a given cache key based on a new HTTP response.
     * @param string $CacheKey Cache key to update
     * @param array $CacheRow Current cache data.
     * @param array $Headers HTTP headers from the updated response.
     * @param ?string $Body Response body.
     */
    private function updateCache(
        string $CacheKey,
        array $CacheRow,
        array $Headers,
        ?string $Body
    ): void {
        # if our cache-control header now says that we should not store this response,
        # delete it and bail
        if (isset($Headers["cache-control"]["no-store"])) {
            self::$Cache->delete($CacheKey);
            return;
        }

        # get max age if there was one
        $MaxAge = isset($Headers["cache-control"]["max-age"]) ?
            (int)$Headers["cache-control"]["max-age"] : null;

        # if no max-age in cache-control, look for expires instead and use
        # that to compute a MaxAge
        # @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Expires
        if ($MaxAge === null && isset($Headers["expires"])) {
            $ExpiryAge = strtotime($Headers["expires"]) - time();
            if ($ExpiryAge > 0) {
                $MaxAge = $ExpiryAge;
            }
        }

        $NoCache = isset($Headers["cache-control"]["no-cache"]) ? "1" : "0";

        $CacheRow["FetchedAt"] = time();
        $CacheRow["NoCache"] = $NoCache;
        $CacheRow["MaxAge"] = $MaxAge;
        $CacheRow["ETag"] = $Headers["etag"] ?? null;
        $CacheRow["LastModified"] = $Headers["last-modified"] ?? null;

        if ($Body !== null) {
            $CacheRow["Body"] = $Body;
        }

        # update our cache row
        self::$Cache->set(
            $CacheKey,
            $CacheRow,
            (int)$MaxAge + 14 * 86400
        );
    }

    private $UserAgentPrefix = "";
    private static $Cache = null;
}
