<?PHP
#
#   FILE:  Email.php
#
#   Part of the ScoutLib application support library
#   Copyright 2012-2022 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#
# @scout:phpstan

namespace ScoutLib;

use Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Electronic mail message.
 * \nosubgrouping
 */
class Email
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /** @name Sending */ /*@(*/

    /**
     * Mail the message.  If no recipients have been specified or all
     * recipients were disallowed via whitelisting, successful execution is
     * still reported by returning TRUE.
     * @return bool TRUE if message was successfully accepted for delivery,
     *       otherwise FALSE.
     */
    public function send(): bool
    {
        # if whitelist set
        if (count(self::$RecipientWhitelist)) {
            # save recipient list and then pare it down based on whitelist
            $SavedTo = $this->To;
            $NewTo = array();
            foreach ($this->To as $To) {
                foreach (self::$RecipientWhitelist as $White) {
                    $White = trim($White);
                    if (StdLib::substr($White, 0, 1) != StdLib::substr($White, -1)) {
                        $White = "/".preg_quote($White, "/")."/";
                    }
                    if (preg_match($White, $To)) {
                        $NewTo[] = $To;
                        continue 2;
                    }
                }
            }
            $this->To = $NewTo;
        }

        # if there are recipients
        $Result = true;
        if (count($this->To)) {
            # send message
            $Result = $this->assembleAndSendMessage();

            # log if a message was sent
            if ($Result && self::$LoggingFunc !== null) {
                call_user_func(self::$LoggingFunc, $this, $this->LogData);
            }
        }

        # if recipient list saved
        if (isset($SavedTo)) {
            # restore recipient list
            $this->To = $SavedTo;
        }

        # report to caller whether message was sent
        return $Result;
    }

    /**
     * Set whitelist of acceptable recipient addresses.  If no whitelist
     * patterns are set, all addresses are acceptable.  (Pass in an empty
     * array to clear whitelist.)
     * @param array $NewValue Array of regular expression patterns to match
     *       acceptable email addresses.  (OPTIONAL)
     * @return array Array of current whitelist entries.
     */
    public static function toWhitelist(array $NewValue = null): array
    {
        if ($NewValue !== null) {
            self::$RecipientWhitelist = $NewValue;
        }
        return self::$RecipientWhitelist;
    }

    /**
     * Register a logging callback.
     * @param callable $NewValue Function to call just before an Email
     * is sent. The Email will be provided as the first parameter.
     */
    public static function registerLoggingFunction($NewValue)
    {
        if (!is_callable($NewValue)) {
            throw new Exception("Invalid logging function provided.");
        }
        self::$LoggingFunc = $NewValue;
    }

    /**
     * Get errors reported when sending the most recent message.
     * @return string|false Error string when the most recent message produced
     *   an error, FALSE when there is no error to report.
     */
    public function getErrorInfo()
    {
        return $this->ErrorInfo;
    }

    /**
     * Register a callback that will print a notice to display when an email
     * whitelist is in use. The whitelist will be provided as the first
     * parameter to the callback, when it is called.
     * @param callable $NewValue Function to call.
     */
    public static function registerWhitelistNoticeCallback(callable $NewValue)
    {
        if (!is_callable($NewValue)) {
            throw new Exception("Invalid whitelist notice function provided.");
        }
        self::$WhitelistNoticeFunc = $NewValue;
    }

    /**
     * If both a recipient whitelist and a whitelist notice callback have been
     * provided, invoke the callback.
     */
    public static function printWhitelistNotice()
    {
        if (is_null(self::$WhitelistNoticeFunc)) {
            return;
        }

        if (count(self::$RecipientWhitelist) == 0) {
            return;
        }

        call_user_func(self::$WhitelistNoticeFunc, self::$RecipientWhitelist);
    }

    /**
     * Provide additional data that should be included when a message
     * is logged.
     * @param array $LogData Associative array of data.
     */
    public function addLogData(array $LogData)
    {
        $this->LogData = $LogData;
    }

    /** @name Message Attributes */ /*@(*/

    /**
     * Get/set message body.
     * @param string $NewValue New message body.  (OPTIONAL)
     * @return string Current message body.
     */
    public function body(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->Body = $NewValue;
        }
        return $this->Body;
    }

    /**
     * Get/set the plain-text alternative to the body.
     * @param string $NewValue New plain-text alternative.  (OPTIONAL)
     * @return string Returns the current plain-text alternative, if any.
     */
    public function alternateBody(string $NewValue = null): string
    {
        # set the plain-text alternative if a parameter is given
        if (func_num_args() > 0) {
            $this->AlternateBody = $NewValue;
        }

        return $this->AlternateBody;
    }

    /**
     * Get/set message subject.
     * @param string $NewValue New message subject.  (OPTIONAL)
     * @return string Current message subject.
     */
    public function subject(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->Subject = $NewValue;
        }
        return $this->Subject;
    }

    /**
     * Get/set message sender.
     * @param string $NewAddress New message sender address.  (OPTIONAL, but
     *       required if NewName is specified.)
     * @param string $NewName New message sender name.  (OPTIONAL)
     * @return string Current message sender in RFC-2822 format ("user@example.com"
     *       or "User <user@example.com>" if name available).
     */
    public function from(string $NewAddress = null, string $NewName = null): string
    {
        if ($NewAddress !== null) {
            $NewAddress = trim($NewAddress);
            if ($NewName !== null) {
                $NewName = trim($NewName);
                $this->From = $NewName." <".$NewAddress.">";
            } else {
                $this->From = $NewAddress;
            }
        }
        return $this->From;
    }

    /**
     * Get/set default "From" address.  This address is used when no "From"
     * address is specified for a message.
     * @param string $NewValue New default address.  (OPTIONAL)
     * @return string Current default address.
     */
    public static function defaultFrom(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            self::$DefaultFrom = $NewValue;
        }
        return self::$DefaultFrom;
    }

    /**
     * Get/set message "Reply-To" address.
     * @param string $NewAddress New message "Reply-To" address.  (OPTIONAL, but
     *       required if NewName is specified.)
     * @param string $NewName New message "Reply-To" name.  (OPTIONAL)
     * @return string Current message "Reply-To" address in RFC-2822 format
     *       ("user@example.com" or "User <user@example.com>" if name available).
     */
    public function replyTo(string $NewAddress = null, string $NewName = null): string
    {
        if ($NewAddress !== null) {
            $NewAddress = trim($NewAddress);
            if ($NewName !== null) {
                $NewName = trim($NewName);
                $this->ReplyTo = $NewName." <".$NewAddress.">";
            } else {
                $this->ReplyTo = $NewAddress;
            }
        }
        return $this->ReplyTo;
    }

    /**
     * Get/set message recipient(s).
     * @param array|string $NewValue New message recipient or array of recipients,
     *       in RFC-2822 format ("user@example.com" or "User <user@example.com>"
     *       if name included).  (OPTIONAL)
     * @return array Array of current message recipient(s) in RFC-2822 format.
     */
    public function to($NewValue = null): array
    {
        if ($NewValue !== null) {
            if (!is_array($NewValue)) {
                $this->To = array($NewValue);
            } else {
                $this->To = $NewValue;
            }
        }
        return $this->To;
    }

    /**
     * Get/set message CC list.
     * @param array|string $NewValue New message CC recipient or array of CC
     *       recipients, in RFC-2822 format ("user@example.com" or "User
     *       <user@example.com>" if name included).  (OPTIONAL)
     * @return array Current message CC recipient(s) in RFC-2822 format.
     */
    public function CC($NewValue = null): array
    {
        if ($NewValue !== null) {
            if (!is_array($NewValue)) {
                $this->CC = array($NewValue);
            } else {
                $this->CC = $NewValue;
            }
        }
        return $this->CC;
    }

    /**
     * Get/set message BCC list.
     * @param array|string $NewValue New message BCC recipient or array of BCC
     *       recipients, in RFC-2822 format ("user@example.com" or "User
     *       <user@example.com>" if name included).  (OPTIONAL)
     * @return array Current message BCC recipient(s) in RFC-2822 format.
     */
    public function BCC($NewValue = null): array
    {
        if ($NewValue !== null) {
            if (!is_array($NewValue)) {
                $this->BCC = array($NewValue);
            } else {
                $this->BCC = $NewValue;
            }
        }
        return $this->BCC;
    }

    /**
     * Specify additional message headers to be included.
     * @param array $NewHeaders Array of header lines.
     */
    public function addHeaders(array $NewHeaders)
    {
        $HeadersToAdd = [];

        # check for headers that need special handling
        foreach ($NewHeaders as $ExtraHeader) {
            list($HeaderName, $HeaderData) = explode(":", $ExtraHeader, 2);
            switch (strtolower($HeaderName)) {
                case "cc":
                    $this->CC(explode(",", $HeaderData));
                    break;

                case "bcc":
                    $this->BCC(explode(",", $HeaderData));
                    break;

                case "content-type":
                    list($MimeType, $Charset) = explode(";", strtolower($HeaderData), 2);

                    if (in_array($MimeType, ["text/html", "text/plain"])) {
                        # if provided content type is one we know how to handle, do so
                        $this->isHtml($MimeType == "text/html");

                        $Charset = trim(str_replace("charset=", "", $Charset));
                        if (strlen($Charset)) {
                            $this->charSet($Charset);
                        }
                    } else {
                        # otherwise add it as an extra header
                        $HeadersToAdd[] = $ExtraHeader;
                    }
                    break;

                default:
                    $HeadersToAdd[] = $ExtraHeader;
            }
        }

        # add other new headers to list
        $this->Headers = array_merge($this->Headers, $HeadersToAdd);
    }

    /**
     * Specify a character encoding for the message. This is used to set the
     * PHPMailer::CharSet property.
     * @param string $NewValue New character encoding (OPTIONAL)
     * @return string Current character encoding.
     */
    public function charSet(string $NewValue = null): string
    {
        # set the plain-text alternative if a parameter is given
        if (func_num_args() > 0) {
            $this->CharSet = $NewValue;
        }

        return $this->CharSet;
    }

    /**
     * Get/set if the message is HTML.
     * @param bool $NewValue New setting (OPTIONAL)
     * @return bool TRUE for HTML messages
     */
    public function isHtml(bool $NewValue = null) : bool
    {
        if (!is_null($NewValue)) {
            $this->IsHtml = $NewValue;
        }

        return $this->IsHtml;
    }

    /**
     * Specify the character sequence that should be used to end lines.
     * @param string $NewValue Character sequence used to end lines.
     * @return string Current character sequence used to end lines.
     */
    public static function lineEnding(string $NewValue = null): string
    {
        if (!is_null($NewValue)) {
            self::$LineEnding = $NewValue;
        }

        return self::$LineEnding;
    }

    /**
     * Wrap HTML in an e-mail as necessary to get its lines less than some max
     * length. This does not guarantee that every line will be less than the max
     * length because it guarantees instead that the sematics of the HTML remain
     * unchanged.
     * @param string $Html HTML to wrap.
     * @param int $MaxLineLength Maximum length of each line. This parameter is
     *      optional.
     * @param string $LineEnding Line ending character sequence. This parameter
     *      is optional.
     * @return string Returns HTML that is wrapped as necessary.
     */
    public static function wrapHtmlAsNecessary(
        string $Html,
        int $MaxLineLength = 998,
        string $LineEnding = "\r\n"
    ): string {
        # normalize line endings
        $Html = preg_replace("/\r\n|\r|\n/", $LineEnding, $Html);

        # the regular expression used to find long lines
        $LongLineRegExp = '/[^\r\n]{'.($MaxLineLength + 1).',}/';

        # find all lines that are too long
        preg_match_all(
            $LongLineRegExp,
            $Html,
            $Matches,
            PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE
        );

        # no changes are necessary
        if (!count($Matches)) {
            return $Html;
        }

        # go backwards so that the HTML can be edited in place without messing
        # with the offsets
        for ($i = count($Matches[0]) - 1; $i >= 0; $i--) {
            # extract the line text and its offset within the string
            list($Line, $Offset) = $Matches[0][$i];

            # first try to get the line under the limit without being too
            # aggressive
            $BetterLine = self::convertHtmlWhiteSpace($Line, false, $LineEnding);
            $WasAggressive = "No";

            # if the line is still too long, be more aggressive with replacing
            # horizontal whitespace
            if (preg_match($LongLineRegExp, $BetterLine)) {
                $BetterLine = self::convertHtmlWhiteSpace($Line, true, $LineEnding);
                $WasAggressive = "Yes";
            }

            # tack on an HTML comment stating that the line was wrapped and give
            # some additional info
            $BetterLine = $LineEnding."<!-- Line was wrapped. Aggressive: "
                    .$WasAggressive.", Max: ".$MaxLineLength.", Actual: "
                    .strlen($Line)." -->".$LineEnding.$BetterLine;

            # replace the line within the HTML
            $Html = substr_replace($Html, $BetterLine, $Offset, strlen($Line));
        }

        return $Html;
    }

    /**
     * Test the line endings in a value to see if they all match the given line
     * ending. This only works with \\r (CR), \\n (LF), and \\r\\n (CRLF).
     * @param string $Value String to check.
     * @param string $LineEnding Line ending character sequence.
     * @return bool Returns TRUE if all the line endings match and FALSE otherwise.
     */
    public static function testLineEndings(string $Value, string $LineEnding): bool
    {
        # the number of \r in the string
        $NumCR = substr_count($Value, "\r");

        # LF
        if ($LineEnding == "\n") {
            return $NumCR === 0;
        }

        # the number of \n in the string
        $NumLF = substr_count($Value, "\n");

        # CR
        if ($LineEnding == "\r") {
            return $NumLF === 0;
        }

        # the number of \r\n in the string
        $NumCRLF = substr_count($Value, "\r\n");

        # CRLF. also check CRLF to make sure CR and LF appear together and in
        # the correct order
        return $NumCR === $NumLF && $NumLF === $NumCRLF;
    }

    /**
     * Try as best as possible to convert HTML to plain text.
     * @param string $Html The HTML to convert.
     * @return string Returns the HTML as plain text.
     */
    public static function convertHtmlToPlainText(string $Html): string
    {
        # remove newlines
        $Text = str_replace(array("\r", "\n"), "", $Html);

        # convert HTML breaks to newlines
        $Text = preg_replace('/<br\s*\/?>/', "\n", $Text);

        # strip remaining tags
        $Text = strip_tags($Text);

        # convert HTML entities to their plain-text equivalents
        $Text = html_entity_decode($Text);

        # single quotes aren't always handled
        $Text = str_replace('&#39;', "'", $Text);

        # remove HTML entities that have no equivalents
        $Text = preg_replace('/&(#[0-9]{1,6}|[a-zA-Z0-9]{1,6});/', "", $Text);

        # return the plain text version
        return $Text;
    }

    /** @name Mail Delivery Method */ /*@(*/

    /**
     * Get/set default mail delivery method.  If specified, the method must be one of
     * the predefined "METHOD_" constants.
     * @param int $NewValue New delivery method.  (OPTIONAL)
     * @return int Current delivery method.
     */
    public static function defaultDeliveryMethod(int $NewValue = null): int
    {
        if ($NewValue !== null) {
            self::$DefaultDeliveryMethod = $NewValue;
        }
        return self::$DefaultDeliveryMethod;
    }

    /** Deliver using PHP's internal mail() mechanism. */
    const METHOD_PHPMAIL = 1;
    /** Deliver using SMTP.  (Requires specifying SMTP settings.) */
    const METHOD_SMTP = 2;

    /**
     * Get/set default server for mail delivery.
     * @param string $NewValue New server.  (OPTIONAL)
     * @return string Current server.
     * @see server()
     */
    public static function defaultServer(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            self::$DefaultServer = $NewValue;
        }
        return self::$DefaultServer;
    }

    /**
     * Get/set default port number for mail delivery.
     * @param int $NewValue New port number.  (OPTIONAL)
     * @return int Current port number.
     * @see port()
     */
    public static function defaultPort(int $NewValue = null): int
    {
        if ($NewValue !== null) {
            self::$DefaultPort = $NewValue;
        }
        return self::$DefaultPort;
    }

    /**
     * Get/set default user name for mail delivery.
     * @param string $NewValue New user name.  (OPTIONAL)
     * @return string Current user name.
     * @see userName()
     */
    public static function defaultUserName(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            self::$DefaultUserName = $NewValue;
        }
        return self::$DefaultUserName;
    }

    /**
     * Get/set default password for mail delivery.
     * @param string $NewValue New password.  (OPTIONAL)
     * @return string Current password.
     * @see password()
     */
    public static function defaultPassword(string $NewValue = null): string
    {
        if ($NewValue !== null) {
            self::$DefaultPassword = $NewValue;
        }
        return self::$DefaultPassword;
    }

    /**
     * Get/set whether to use authentication for mail delivery by default.
     * @param bool $NewValue New authentication setting.  (OPTIONAL)
     * @return bool Current authentication setting.
     * @see useAuthentication()
     */
    public static function defaultUseAuthentication(bool $NewValue = null): bool
    {
        if ($NewValue !== null) {
            self::$DefaultUseAuthentication = $NewValue;
        }
        return self::$DefaultUseAuthentication;
    }

    /**
     * Get/set serialized (opaque text) version of default delivery settings.  This
     * method is intended to be used to store and retrieve all email delivery
     * settings for the class, in a form suitable to be saved to a database.
     * @param string $NewSettings New delivery settings values.
     * @return string Current delivery settings values.
     */
    public static function defaultDeliverySettings(string $NewSettings = null): string
    {
        if ($NewSettings !== null) {
            $Settings = unserialize($NewSettings);
            self::$DefaultDeliveryMethod = $Settings["DeliveryMethod"];
            self::$DefaultServer = $Settings["Server"];
            self::$DefaultPort = $Settings["Port"];
            self::$DefaultUserName = $Settings["UserName"];
            self::$DefaultPassword = $Settings["Password"];
            self::$DefaultUseAuthentication = $Settings["UseAuthentication"];
        } else {
            $Settings["DeliveryMethod"] = self::$DefaultDeliveryMethod;
            $Settings["Server"] = self::$DefaultServer;
            $Settings["Port"] = self::$DefaultPort;
            $Settings["UserName"] = self::$DefaultUserName;
            $Settings["Password"] = self::$DefaultPassword;
            $Settings["UseAuthentication"] = self::$DefaultUseAuthentication;
        }
        return serialize($Settings);
    }

    /**
     * Get/set mail delivery method.  If specified, the method must be one of
     * the predefined "METHOD_" constants.
     * @param int|null $NewValue New delivery method or NULL to clear local
     *   value. (OPTIONAL)
     * @return int Current delivery method.
     * @see defaultDeliveryMethod()
     */
    public function deliveryMethod($NewValue = null): int
    {
        if (func_num_args() > 0) {
            $this->DeliveryMethod = $NewValue;
        }

        return !is_null($this->DeliveryMethod)
                ? $this->DeliveryMethod
                : self::$DefaultDeliveryMethod;
    }

    /**
     * Get/set server for mail delivery. If no local value was
     * configured, the class-wide default will be returned.
     * @param string|null $NewValue New server or NULL to clear local value.
     *   (OPTIONAL)
     * @return string Current server.
     * @see defaultServer()
     */
    public function server($NewValue = null): string
    {
        if (func_num_args() > 0) {
            $this->Server = $NewValue;
        }

        return !is_null($this->Server)
                ? $this->Server
                : self::$DefaultServer;
    }

    /**
     * Get/set port number for mail delivery. If no local value was
     * configured, the class-wide default will be returned.
     * @param int $NewValue New port number or NULL to clear local
     *   value.  (OPTIONAL)
     * @return int Current port number.
     * @see defaultPort()
     */
    public function port($NewValue = null): int
    {
        if (func_num_args() > 0) {
            $this->Port = $NewValue;
        }

        return !is_null($this->Port)
                ? $this->Port
                : self::$DefaultPort;
    }

    /**
     * Get/set user name for mail delivery. If no local value was
     * configured, the class-wide default will be returned.
     * @param string $NewValue New user name or NULL to clear local
     *   value.  (OPTIONAL)
     * @return string Current user name.
     * @see defaultUserName()
     */
    public function userName($NewValue = null): string
    {
        if (func_num_args() > 0) {
            $this->UserName = $NewValue;
        }

        return !is_null($this->UserName)
                ? $this->UserName
                : self::$DefaultUserName;
    }

    /**
     * Get/set password for mail delivery. If no local value was
     * configured, the class-wide default will be returned.
     * @param string $NewValue New password or NULL to clear local
     *   value.  (OPTIONAL)
     * @return string Current password.
     * @see defaultPassword()
     */
    public function password($NewValue = null): string
    {
        if (func_num_args() > 0) {
            $this->Password = $NewValue;
        }

        return !is_null($this->Password)
                ? $this->Password
                : self::$DefaultPassword;
    }

    /**
     * Get/set whether to use authentication for mail delivery. If no
     * local value was configured, the class-wide default will be
     * returned.
     * @param bool $NewValue New authentication setting or NULL to
     *   clear local value.  (OPTIONAL)
     * @return bool Current authentication setting.
     * @see defaultUseAuthentication()
     */
    public function useAuthentication($NewValue = null): bool
    {
        if (func_num_args() > 0) {
            $this->UseAuthentication = $NewValue;
        }

        return !is_null($this->UseAuthentication)
                ? $this->UseAuthentication
                : self::$DefaultUseAuthentication;
    }

    /**
     * Test delivery settings and report their validity.  For example, if
     * the deliver method is set to SMTP it would test the server, port,
     * and (if authentication is indicated) user name and password.  If
     * delivery settings are not okay, then deliverySettingErrors() can be
     * used to determine (if known) which settings may have problems.
     * @return bool TRUE if delivery settings are okay, otherwise FALSE.
     */
    public function deliverySettingsOkay(): bool
    {
        # start out with error list clear
        self::$DeliverySettingErrorList = array();

        # test based on delivery method
        $SettingsOkay = false;
        switch ($this->deliveryMethod()) {
            case self::METHOD_PHPMAIL:
                # always report success
                $SettingsOkay = true;
                break;

            case self::METHOD_SMTP:
                # set up PHPMailer for test
                $PMail = new PHPMailer(true);
                $PMail->isSMTP();
                $PMail->SMTPAuth = $this->useAuthentication();
                $PMail->Host = $this->server();
                $PMail->Port = $this->port();
                $PMail->Username = $this->userName();
                $PMail->Password = $this->password();

                # test settings
                try {
                    $SettingsOkay = $PMail->smtpConnect();
                # if test failed
                } catch (\PHPMailer\PHPMailer\Exception $Except) {
                    # translate PHPMailer error message to possibly bad settings
                    switch ($Except->getMessage()) {
                        case 'SMTP Error: Could not authenticate.':
                            self::$DeliverySettingErrorList = array(
                                "UseAuthentication",
                                "UserName",
                                "Password",
                            );
                            break;

                        case 'SMTP Error: Could not connect to SMTP host.':
                            self::$DeliverySettingErrorList = array(
                                "Server",
                                "Port",
                            );
                            break;

                        case 'Language string failed to load: tls':
                            self::$DeliverySettingErrorList = array("TLS");
                            break;

                        default:
                            self::$DeliverySettingErrorList = array("UNKNOWN");
                            break;
                    }

                    # make sure failure is reported
                    $SettingsOkay = false;
                }
                break;
        }

        # report result to caller
        return $SettingsOkay;
    }

    /**
     * Return array with list of delivery setting errors (if any).
     * @return array Settings that are possibly bad.
     */
    public static function deliverySettingErrors(): array
    {
        return self::$DeliverySettingErrorList;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $AlternateBody = "";
    private $BCC = array();
    private $Body = "";
    private $CC = array();
    private $CharSet;
    private $From = "";
    private $Headers = array();
    private $ReplyTo = "";
    private $Subject = "";
    private $To = array();
    private $LogData = array();
    private $Whitelist = array();
    private $IsHtml = false;
    private $ErrorInfo = false;

    # delivery settings
    private $DeliveryMethod = null;
    private $Server = null;
    private $Port = null;
    private $UseAuthentication = null;
    private $UserName = null;
    private $Password = null;

    # defaults for settings that can vary per instance
    private static $DefaultDeliveryMethod = self::METHOD_PHPMAIL;
    private static $DefaultServer = "localhost";
    private static $DefaultPort = 25;
    private static $DefaultUseAuthentication = false;
    private static $DefaultUserName = "";
    private static $DefaultPassword = "";

    # shared settings that can't vary per instance
    private static $DeliverySettingErrorList = array();
    private static $DefaultFrom = "";
    private static $LineEnding = "\r\n";
    private static $RecipientWhitelist = array();
    private static $LoggingFunc = null;
    private static $WhitelistNoticeFunc = null;

    /**
     * Assemble and send the message.
     * @return bool Returns TRUE if message appeared successfully sent,
     *      otherwise FALSE.
     */
    private function assembleAndSendMessage()
    {
        # create and initialize PHPMailer
        $PMail = new PHPMailer();
        $PMail->Subject = $this->Subject;
        $PMail->Body = $this->Body;
        $PMail->isHTML($this->IsHtml);
        # use QP encoding to prevent Microsoft's "Exchange Online Protection"
        # from stripping out message bodies
        $PMail->Encoding = "quoted-printable";

        # default values for the sender's name and address
        $Name = "";
        $Address = $this->From;
        if (strlen($Address) == 0) {
            $Address = self::$DefaultFrom;
        }

        # if the address contains a name and address, they need to be
        #       extracted because PHPMailer requires that they are set
        #       as two different parameters
        if (preg_match("/(.*) <([^>]+)>/", $Address, $Matches)) {
            $Name = $Matches[1];
            $Address = $Matches[2];
        }

        # add the sender
        $PMail->setFrom($Address, $Name);

        # if a 'reply to' was provided
        if (strlen($this->ReplyTo)) {
            $Name = "";
            $Address = $this->ReplyTo;

            if (preg_match("/(.*) <([^>]+)>/", $Address, $Matches)) {
                $Name = $Matches[1];
                $Address = $Matches[2];
            }

            $PMail->addReplyTo($Address, $Name);
        }

        # add each recipient
        foreach ($this->To as $Recipient) {
            $PMail->addAddress($Recipient);
        }

        # add CC
        foreach ($this->CC as $Recipient) {
            $PMail->addCC($Recipient);
        }

        # add BCC
        foreach ($this->BCC as $Recipient) {
            $PMail->addBCC($Recipient);
        }

        # add any extra header lines
        foreach ($this->Headers as $ExtraHeader) {
            $PMail->addCustomHeader($ExtraHeader);
        }

        # add the charset if it's set
        if (isset($this->CharSet)) {
            $PMail->CharSet = strtolower($this->CharSet);
        }

        # add the alternate plain-text body if it's set
        if ($this->hasAlternateBody()) {
            $PMail->AltBody = $this->AlternateBody;
        }

        # set up SMTP if necessary
        if ($this->deliveryMethod() == self::METHOD_SMTP) {
            $PMail->isSMTP();
            $PMail->SMTPAuth = $this->useAuthentication();
            $PMail->Host = $this->server();
            $PMail->Port = $this->port();
            $PMail->Username = $this->userName();
            $PMail->Password = $this->password();
        }

        # send message
        $Result = $PMail->send();

        # update ErrorInfo for this message (false when there was no error)
        $this->ErrorInfo = $Result ? false : $PMail->ErrorInfo;

        # report to caller whether attempt to send succeeded
        return $Result;
    }

    /**
     * Build addressee (CC/BCC/Reply-To/etc) line for mail header.
     * @param string $Label Keyword for beginning of line (without ":").
     * @param array $Recipients Array of addresses to put on line.
     * @return string Generated header line.
     */
    private function buildAddresseeLine(string $Label, array $Recipients): string
    {
        $Line = "";
        if (count($Recipients)) {
            $Line .= $Label.": ";
            $Separator = "";
            foreach ($Recipients as $Recipient) {
                $Line .= $Separator.self::cleanHeaderValue($Recipient);
                $Separator = ", ";
            }
            $Line .= self::$LineEnding;
        }
        return $Line;
    }

    /**
     * Determine if the object has a plain-text alternative set.
     * @return bool TRUE if an plain-text alternative to the body is set.
     */
    private function hasAlternateBody(): bool
    {
        return isset($this->AlternateBody) && strlen(trim($this->AlternateBody)) > 0;
    }

    /**
     * Remove problematic content from values to be used in message header.
     * @param string $Value Value to be sanitized.
     * @return string Sanitized value.
     */
    private static function cleanHeaderValue(string $Value): string
    {
        # (regular expression taken from sanitizeHeaders() function in
        #       Mail PEAR package)
        return preg_replace(
            '=((<CR>|<LF>|0x0A/%0A|0x0D/%0D|\\n|\\r)\S).*=i',
            "",
            $Value
        );
    }

    /**
     * Normalize all line endings in a string.
     * @param string $Value Value in which all line endings will be normalized.
     * @param string $LineEnding Character sequence used as a line ending.
     * @return string String with all line endings normalized.
     */
    private static function normalizeLineEndings(string $Value, string $LineEnding): string
    {
        return preg_replace('/\r\n|\r|\n/', $LineEnding, $Value);
    }

    /**
     * Convert horizontal white space with no semantic value to vertical white
     * space when possible. Only converts white space between tag attributes by
     * default, but can also convert white space within tags if specified.
     * @param mixed $Html HTML string in which white space should be converted.
     * @param bool $Aggressive TRUE to also convert white space within tags in
     *      which horizontal whitespace has no semantic value. This should only
     *      be used when absolutely necessary because it can make the HTML hard
     *      to read. This parameter is optional.
     * @param string $LineEnding Character sequence to use as the line ending.
     *      This parameter is optional.
     * @return string HTML with its horizontal white space converted to vertical
     *      white space as specified in the parameters.
     */
    protected static function convertHtmlWhiteSpace(
        $Html,
        bool $Aggressive = false,
        string $LineEnding = "\r\n"
    ): string {
        # normalize the line ending characters in the HTML to "\n"
        $Html = preg_replace("/\r\n|\r|\n/", "\n", $Html);

        $HtmlLength = strlen($Html);

        # tags that should have their inner HTML left alone
        $IgnoredTags = array('script', 'style', 'textarea', 'title');

        # values for determining context
        $InTag = false;
        $InClosingTag = false;
        $InIgnoredTag = false;
        $InAttribute = false;
        $TagName = null;
        $IgnoredTagName = null;
        $AttributeDelimiter = null;

        # loop through each character of the string
        for ($i = 0; $i < $HtmlLength; $i++) {
            $Char = $Html[$i];

            # beginning of a tag
            if ($Char == "<" && !$InTag) {
                $InTag = true;
                $InAttribute = false;
                $AttributeDelimiter = null;

                # do some lookaheads to get the tag name and to see if the tag
                # is a closing tag
                list($InClosingTag, $TagName) = self::getTagInfo($Html, $i);

                # moving into an ignored tag
                if (!$InClosingTag && in_array($TagName, $IgnoredTags)) {
                    $InIgnoredTag = true;
                    $IgnoredTagName = $TagName;
                }

                continue;
            }

            # end of a tag
            if ($Char == ">" && $InTag && !$InAttribute) {
                # moving out of an ignored tag
                if ($InClosingTag && $InIgnoredTag && $TagName == $IgnoredTagName) {
                    $InIgnoredTag = false;
                    $IgnoredTagName = null;
                }

                $InTag = false;
                $InClosingTag = false;
                $InAttribute = false;
                $TagName = null;
                $AttributeDelimiter = null;

                continue;
            }

            # attribute delimiter characters
            if ($Char == "'" || $Char == '"') {
                # beginning of an attribute
                if (!$InAttribute) {
                    $InAttribute = true;
                    $AttributeDelimiter = $Char;
                    continue;
                }

                # end of the attribute
                if ($Char == $AttributeDelimiter) {
                    $InAttribute = false;
                    $AttributeDelimiter = null;
                    continue;
                }
            }

            # whitespace inside of a tag but outside of an attribute can be
            # safely converted to a newline
            if ($InTag && !$InAttribute && preg_match('/\s/', $Char)) {
                $Html[$i] = "\n";
                continue;
            }

            # whitespace outside of a tag can be safely converted to a newline
            # when not in one of the ignored tags, but only do so if horizontal
            # space is at a premium because it can make the resulting HTML
            # difficult to read
            if ($Aggressive && !$InTag && !$InIgnoredTag && preg_match('/\s/', $Char)) {
                $Html[$i] = "\n";
                continue;
            }
        }

        # convert to desired line ending if necessary
        return ($LineEnding == "\n") ? $Html : str_replace("\n", $LineEnding, $Html);
    }

    /**
     * Get the tag name and whether it's a closing tag from a tag that begins at
     * a specific offset within some HTML. This is really only useful to
     * convertHtmlWhiteSpace().
     * @param string $Html HTML string from which to get the information.
     * @param int $TagBegin Offset of where the tag begins.
     * @return array Returns an array containing the tag name and if it's a closing tag.
     */
    protected static function getTagInfo(string $Html, int $TagBegin): array
    {
        $HtmlLength = strlen($Html);

        # default return values
        $InClosingTag = false;
        $TagName = null;

        # if at the end of the string and lookaheads aren't possible
        if ($TagBegin + 1 >= $HtmlLength) {
            return array($InClosingTag, $TagName);
        }

        # do a lookahead for whether it's a closing tag
        if ($Html[$TagBegin + 1] == "/") {
            $InClosingTag = true;
        }

        # determine whether to offset by one or two to get the tag name
        $TagStart = $InClosingTag ? $TagBegin + 2 : $TagBegin + 1;

        # do a lookahead for the tag name
        for ($i = $TagStart; $i < $HtmlLength; $i++) {
            $Char = $Html[$i];

            # stop getting the tag name if whitespace is found and something is
            # available for the tag name
            if ($TagName !== null && strlen($TagName) && preg_match('/[\r\n\s]/', $Char)) {
                break;
            }

            # stop getting the tag name if the character is >
            if ($Char == ">") {
                break;
            }

            $TagName .= $Char;
        }

        # comment "tag"
        if (substr($TagName, 0, 3) == "!--") {
            return array($InClosingTag, "!--");
        }

        # remove characters that aren't part of a valid tag name
        $TagName = preg_replace('/[^a-zA-Z0-9]/', '', $TagName);

        return array($InClosingTag, $TagName);
    }
}
