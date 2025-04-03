<?PHP
#
#   FILE:  RSSClient.php
#
#   Part of the ScoutLib application support library
#   Copyright 2002-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu/
#
# @scout:phpstan

namespace ScoutLib;
use ScoutLib\Database;
use Returns;
use ScoutLib\XMLParser;

/**
 * Implements an RSS client for fetching, parsing, and caching RSS feeds.
 */
class RSSClient
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     * @param string $ServerUrl URL to the RSS feed.
     * @param Database $CacheDB Database object to use for storage and retrieval
     *      of cached RSS feeds. The default value is NULL.
     * @param int $RefreshTime Time in seconds for how long the cache of an RSS
     *      should remain valid. The default value is 600.
     * @param string $Encoding The character encoding of the RSS feed. The
     *     default value is UTF-8.
     * @param int $DebugLevel The level of verbosity of debug messages. The
     *     default value is 0.
     */
    public function __construct(
        string $ServerUrl,
        $CacheDB = null,
        int $RefreshTime = 600,
        string $Encoding = "UTF-8",
        int $DebugLevel = 0
    ) {

        # set default debug level
        $this->DebugLevel = $DebugLevel;

        # set default encoding
        $this->Encoding = $Encoding;

        # save cache details
        $this->CacheDB = $CacheDB;
        $this->RefreshTime = $RefreshTime;

        if ($CacheDB) {
            # query server (or cache) for XML text
            $this->XmlText = $this->queryServerWithCaching(
                $ServerUrl,
                $CacheDB,
                $RefreshTime
            );
        } else {
            $this->XmlText = $this->getXmlInfo($ServerUrl)[0];
        }

        # create XML parser and parse text
        $this->Parser = new XMLParser($this->Encoding);
        if ($this->DebugLevel > 3) {
            $this->Parser->SetDebugLevel($this->DebugLevel - 3);
        }
        $this->Parser->ParseText($this->XmlText);

        if ($this->DebugLevel) {
            print("RSSClient->RSSClient() returned ".strlen($this->XmlText)
                    ." characters from server query<br>\n");
        }
    }

    /**
     * Get or set the RSS feed URL.
     * @param string $NewValue New RSS feed URL. This parameter is optional.
     * @return string Returns the current RSS feed URL.
     */
    public function serverUrl($NewValue = null): string
    {
        # if new RSS server URL supplied
        if (($NewValue != null) && ($NewValue != $this->ServerUrl)) {
            # save new value
            $this->ServerUrl = $NewValue;

            # re-read XML from server at new URL
            $this->XmlText = $this->queryServerWithCaching(
                $NewValue,
                $this->CacheDB,
                $this->RefreshTime
            );

            # create new XML parser and parse text
            $this->Parser = new XMLParser();
            if ($this->DebugLevel > 3) {
                $this->Parser->SetDebugLevel($this->DebugLevel - 3);
            }
            $this->Parser->ParseText($this->XmlText);
        }

        # return RSS server URL to caller
        return $this->ServerUrl;
    }

    /**
     * Get or set the character encoding of the RSS feed.
     * @param string $NewValue New character encoding of the RSS feed. This
     *     parameter is optional.
     * @return string Returns the current character encoding of the RSS feed.
     */
    public function encoding($NewValue = null): string
    {
        # if new encoding supplied
        if (($NewValue != null) && ($NewValue != $this->Encoding)) {
            # save new value
            $this->Encoding = $NewValue;

            # re-read XML from server
            $this->XmlText = $this->queryServerWithCaching(
                $this->ServerUrl,
                $this->CacheDB,
                $this->RefreshTime
            );

            # create new XML parser and parse text
            $this->Parser = new XMLParser($this->Encoding);
            if ($this->DebugLevel > 3) {
                $this->Parser->SetDebugLevel($this->DebugLevel - 3);
            }
            $this->Parser->ParseText($this->XmlText);
        }

        # return encoding to caller
        return $this->Encoding;
    }

    /**
     * Try to automatically detect and set the encoding of the RSS feed. The
     * precedence is as follows: encoding declared in the XML file, charset
     * parameter in the Content-Type HTTP response header, then ISO-8859-1.
     */
    public function autodetectEncoding(): void
    {
        # if neither the XML file nor the HTTP response headers specify an
        # encoding, there is an overwhelming chance that it's ISO-8859-1, so
        # use it as the default
        $Encoding = "ISO-8859-1";

        # only get up to the the encoding portion of the XML declartion
        # http://www.w3.org/TR/2006/REC-xml-20060816/#sec-prolog-dtd
        $S = '[ \t\r\n]';
        $Eq = "{$S}?={$S}?";
        $VersionNum = '1.0';
        $EncName = '[A-Za-z]([A-Za-z0-9._]|-)*';
        $VersionInfo = "{$S}version{$Eq}('{$VersionNum}'|\"{$VersionNum}\")";
        $EncodingDecl = "{$S}encoding{$Eq}('{$EncName}'|\"{$EncName}\")";
        $XMLDecl = "<\?xml{$VersionInfo}({$EncodingDecl})?";
        $RegEx = "/{$XMLDecl}/";

        # try to find the encoding, index 3 will be set if encoding is declared
        preg_match($RegEx, $this->XmlText, $Matches);

        # give precedence to the encoding specified within the XML file since
        # a RSS feed publisher might not have access to HTTP response headers
        if (count($Matches) >= 4) {
            # also need to strip off the quotes
            $Encoding = trim($Matches[3], "'\"");
            # then give precedence to the charset parameter in the Content-Type response header
        } elseif ($this->CacheDB) {
            # create cache table if it doesn't exist
            $DB = $this->CacheDB;
            $ServerUrl = addslashes($this->ServerUrl);

            # get the cache value
            $DB->Query("
                SELECT * FROM RSSClientCache
                WHERE ServerUrl = '".$ServerUrl."'");
            $Exists = ($DB->NumRowsSelected() > 0);
            $Cache = $DB->FetchRow();

            # if cached and charset parameter was given in the response headers
            if ($Exists && strlen($Cache["Charset"])) {
                $Encoding = $Cache["Charset"];
            }
        }

        $this->encoding($Encoding);
    }

    /**
     * Retrieve the RSS items from the RSS feed. The first channel of the feed
     * will be used if not specified.
     * @param int $NumberOfItems Number of items to return from the field. All of
     *     the items are returned by default.
     * @param string $ChannelName Channel to retrieve if not the first one.
     * @return array Returns the items from the RSS feed.
     */
    public function getItems(
        ?int $NumberOfItems = null,
        ?string $ChannelName = null
    ): array {
        # start by assuming no items will be found
        $Items = array();

        # move parser to area in XML with items
        $Parser = $this->Parser;
        $Parser->SeekToRoot();
        $Result = $Parser->SeekTo("rss");
        if ($Result === null) {
            $Result = $Parser->SeekTo("rdf:RDF");
        } else {
            $Parser->SeekTo("channel");
        }

        # if items are found
        $ItemCount = $Parser->SeekTo("item");
        if ($ItemCount) {
            # for each record
            $Index = 0;
            do {
                # retrieve item info
                $Items[$Index]["title"] = $Parser->GetData("title");
                $Items[$Index]["description"] = $Parser->GetData("description");
                $Items[$Index]["link"] = $Parser->GetData("link");
                $Items[$Index]["enclosure"] = $Parser->GetAttributes("enclosure");

                $Index++;
            } while ($Parser->NextItem()
            && (($NumberOfItems == null) || ($Index < $NumberOfItems)));
        }

        # return records to caller
        return $Items;
    }

    /**
     * Return whether or not this client has a valid URL, used for form validation
     * @return bool whether or not the given URL is valid
     */
    public function isValid(): bool
    {
        $this->loadChannelInfo();
        return (isset($this->ChannelTitle)
                && isset($this->ChannelLink)
                && isset($this->ChannelDescription));
    }

    /**
     * Retrieve the channel title as given in the RSS feed.
     * @return string Returns the channel title.
     */
    public function getChannelTitle(): string
    {
        if (!isset($this->ChannelTitle)) {
            $this->loadChannelInfo();
        }
        return $this->ChannelTitle;
    }

    /**
     * Retrive the URL to the site of the channel in the RSS feed.
     * @return string Returns the URL to the site of the channel in the RSS feed.
     */
    public function getChannelLink(): string
    {
        if (!isset($this->ChannelLink)) {
            $this->loadChannelInfo();
        }
        return $this->ChannelLink;
    }

    /**
     * Get the description of the channel as given in the RSS feed.
     * @return string Returns the description of the channel as given in the RSS feed.
     */
    public function getChannelDescription(): string
    {
        if (!isset($this->ChannelDescription)) {
            $this->loadChannelInfo();
        }
        return $this->ChannelDescription;
    }

    /**
     * Determine whether the RSS client is using cached data.
     * @returns bool Returns TRUE if the RSS client is using cached data.
     */
    public function usedCachedData(): bool
    {
        return $this->CachedDataWasUsed;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $CacheDB;
    private $RefreshTime;
    private $ServerUrl;
    private $MetadataPrefix;
    private $SetSpec;
    private $DebugLevel;
    private $Encoding;
    private $XmlText;
    private $Parser;
    private $ChannelTitle;
    private $ChannelLink;
    private $ChannelDescription;
    private $CachedDataWasUsed;

    /**
     * Set the current level of verbosity for debug output. The valid levels are
     * between 0-9 inclusive.
     * @param int $NewLevel New level of verbosity for debug output.
     */
    private function setDebugLevel($NewLevel): void
    {
        $this->DebugLevel = $NewLevel;
    }

    /**
     * Get the XML text at the given URL, along with the type and character
     * encoding of the text.
     * @param string $Url URL to the XML text.
     * @return array XML text or FALSE on failure,
     *                Type or NULL on failure or if not set,
     *                Charset or NULL on failure or if not set)
     */
    private function getXmlInfo($Url)
    {
        $Text = @file_get_contents($Url);
        $Type = null;
        $Charset = null;

        # get the type and charset if the fetch was successful
        if ($Text !== false) {
            # this must come after file_get_contents() and before any other remote
            # fetching is done
            $Headers = $http_response_header;

            # http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.17
            $LWS = '([ \t]*|\r\n[ \t]+)';
            $Token = '[!\x23-\x27*+-.\x30-\x39\x41-\x5A\x5E-\x7A|~]+';
            $QuotedPair = '\\[\x00-\x7F]';
            $QdText = "([^\\x00-\\x1F\\x7F\"]|{$LWS})";
            $QuotedString = "\"({$QdText}|{$QuotedPair})*\"";
            $Value = "({$Token}|{$QuotedString})";
            $Parameter = "{$Token}{$LWS}={$LWS}{$Value}";

            # these make the Content-Type regex specific to Content-Type
            # values with charset parameters in them, but make capturing
            # the charset much easier
            $BasicParameter = "(;{$LWS}{$Parameter})*";
            $CharsetParameter = "(;{$LWS}charset{$LWS}={$LWS}{$Value})";
            $ModParameter = "{$BasicParameter}{$CharsetParameter}{$BasicParameter}";
            $MediaType = "({$Token}{$LWS}\\/{$LWS}{$Token}){$LWS}{$ModParameter}";

            # back to the spec
            $ContentType = "Content-Type{$LWS}:{$LWS}{$MediaType}{$LWS}";
            $RegEx = "/^{$ContentType}$/i";

            foreach ($Headers as $Header) {
                preg_match($RegEx, $Header, $Matches);

                if (isset($Matches[3]) && isset($Matches[19])) {
                    $Type = $Matches[3];
                    $Charset = $Matches[19];
                    break;
                }
            }
        }

        return array($Text, $Type, $Charset);
    }

    /**
     * Load the XML of an RSS feed from the cache, if available, or from the
     * server.
     * @param string $ServerUrl URL to the RSS feed.
     * @param Database|null $CacheDB Database object to use for storage and retrieval
     *      of cached RSS feeds. The default value is NULL.
     * @param int $RefreshTime Time in seconds for how long the cache of an RSS
     *      should remain valid. The default value is 600.
     * @return string Returns the XML of the RSS feed.
     */
    private function queryServerWithCaching($ServerUrl, $CacheDB, $RefreshTime)
    {
        # save RSS server URL
        $this->ServerUrl = $ServerUrl;

        # save caching info (if any)
        if ($CacheDB) {
            $this->CacheDB = $CacheDB;
        }

        # if caching info was supplied
        $QueryResult = "";
        if ($this->CacheDB) {
            $DB = $this->CacheDB;

            # look up cached information for this server
            $QueryTimeCutoff = date("Y-m-d H:i:s", (time() - $RefreshTime));
            $DB->Query("
                SELECT * FROM RSSClientCache
                WHERE ServerUrl = '".addslashes($ServerUrl)."'
                AND LastQueryTime > '".$QueryTimeCutoff."'");

            # if we have cached info that has not expired
            if ($CachedXml = $DB->FetchField("CachedXml")) {
                # use cached info
                $QueryResult = $CachedXml;
                $this->CachedDataWasUsed = true;
            } else {
                $this->CachedDataWasUsed = false;

                # query server for XML text
                list($Text, $Type, $Charset) = $this->getXmlInfo($ServerUrl);
                $QueryResult = "";

                # if query was successful
                if ($Text !== false) {
                    $QueryResult = $Text;

                    # clear out any old cache entries
                    $DB->Query("
                        DELETE FROM RSSClientCache
                        WHERE ServerUrl = '".addslashes($ServerUrl)."'");

                    # save info in cache
                    $DB->Query("
                        INSERT INTO RSSClientCache
                        (ServerUrl, CachedXml, Type, Charset, LastQueryTime)
                        VALUES (
                            '".addslashes($ServerUrl)."',
                            '".addslashes($Text)."',
                            '".addslashes($Type)."',
                            '".addslashes($Charset)."',
                            NOW())");
                }
            }
        }

        # return query result to caller
        return $QueryResult;
    }

    /**
     * Load information from the current RSS channel. The information includes
     * the channel title, site URL, and channel description.
     */
    private function loadChannelInfo(): void
    {
        $Parser = $this->Parser;
        $Parser->SeekToRoot();
        $Result = $Parser->SeekTo("rss");
        if ($Result === null) {
            $Result = $Parser->SeekTo("rdf:RDF");
        }
        $Parser->SeekTo("channel");
        $this->ChannelTitle = $Parser->GetData("title");
        $this->ChannelLink = $Parser->GetData("link");
        $this->ChannelDescription = $Parser->GetData("description");
        # empty tag returns null, description can be an empty tag
        if (!isset($this->ChannelDescription)
                && isset($this->ChannelTitle)
                && isset($this->ChannelLink)) {
            $this->ChannelDescription = "";
        }
    }
}
