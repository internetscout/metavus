<?PHP
#
#   FILE:  RestAPIHelper
#
#   Part of the ScoutLib application support library
#   Copyright 2017-2019 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu/cwis/
#

namespace ScoutLib;

/**
 * This class provides a general-purpose library for encrypted REST
 * calls and responses. It is intended both for use in CWIS and for use
 * in CWIS-companion plugins for other systems (e.g., the cwis_user
 * Drupal plugin that takes to the CWIS DrupalSync plugin). As such,
 * functions in this class should not use CWIS objects (StdLib,
 * Database, CWUser, etc) that won't be available in other
 * environments.
 */
class RestAPIHelper
{
    /**
     * Constructor.
     * @param string $APIUrl Url to which REST calls will be directed.
     * @param string $APIPassword Password for encrypting and
     *   authenticating rest calls.
     * @param callable $CheckForDuplicateFn Function to check for
     *   duplicated messages.
     * @param callable $RegisterMessageFn Function to register a
     *   message as received.
     */
    public function __construct(
        $APIUrl,
        $APIPassword,
        $CheckForDuplicateFn,
        $RegisterMessageFn
    ) {

        $this->APIUrl = $APIUrl;
        $this->APIPassword = $APIPassword;
        $this->CheckForDuplicateFn = $CheckForDuplicateFn;
        $this->RegisterMessageFn = $RegisterMessageFn;
    }

    /**
     * Run a REST API command against a remote site.
     * @param array $Params REST API call parameters (often from $_POST).
     * @return mixed response from the remote site (format depends on the command
     *   issued) or NULL on command failure.
     */
    public function doRestCommand($Params)
    {
        # build an encrypted message
        $PostData = $this->encodeEncryptedMessage($Params);

        # set up curl to do the post
        $Context = curl_init();

        # enable cookie handling
        curl_setopt($Context, CURLOPT_COOKIEFILE, '');

        # use our configured endpoint
        curl_setopt($Context, CURLOPT_URL, $this->APIUrl);

        # get results back as a string
        curl_setopt($Context, CURLOPT_RETURNTRANSFER, true);

        # send data in a POST
        curl_setopt($Context, CURLOPT_POST, true);

        # load the POST data
        curl_setopt($Context, CURLOPT_POSTFIELDS, http_build_query($PostData));

        # fetch the data
        $Data = curl_exec($Context);

        # attempt to parse the reply into an encrypted envelope
        $Result = json_decode($Data, true);
        if ($Result === null) {
            return array(
                "Status" => "Error",
                "Message" => "Could not parse PostData.",
                "Data" => $Data,
            );
        }

        # attempt to decode the encrypted envelope
        $Result = $this->decodeEncryptedMessage($Result);

        # if we decoded the envelope, return the message contents
        if ($Result["Status"] == "OK") {
            $Result = $Result["Data"];
        }

        return $Result;
    }

    /**
     * Construct an encrypted message packet from provided data.
     * @param array $Data Data to encapsulate
     * @return array Encrypted packet
     */
    public function encodeEncryptedMessage($Data)
    {
        # create an envelope for our message, put the provided data inside
        $Env = array();
        $Env["Version"] = "3";
        $Env["Timestamp"] = time();
        $Env["Cookie"] = base64_encode(random_bytes(16));
        $Env["Data"] = $Data;

        # generate full key from provided password
        $FullKey = hash("sha512", $this->APIPassword, true);

        # split into encryption and MAC keys
        # (DO NOT change these to StdLib:: calls. See the class docstring for why not)
        $EncKey = mb_substr($FullKey, 0, 32, '8bit');
        $MacKey = mb_substr($FullKey, 32, 32, '8bit');

        # generate a random IV (initialization vector), required by
        # AES (and for most ciphers) to provide some randomness in the
        # data and prevent identical messages from having identical
        # encrypted content

        $IV = random_bytes(16);

        # encrypt and base64 our payload
        $Payload = base64_encode(openssl_encrypt(
            json_encode($Env),
            "aes-256-cbc",
            $EncKey,
            OPENSSL_RAW_DATA,
            $IV
        ));

        # base64 encode our IV
        $IV = base64_encode($IV);

        # construct data we will POST
        $PostData = array(
            "IV" => $IV,
            "Payload" => $Payload,
            "MAC" => base64_encode(hash_hmac("sha256", $IV.":".$Payload, $MacKey, true)),
        );

        return $PostData;
    }

    /**
     * Decrypt an encrypted message packet.
     * @param array $PostData Encrypted data.
     * @return array Result, always has a "Status" member that will
     * either be "OK" on success or "Error".  In the case of an error,
     * there will also be a "Message" giving a description of the
     * issue.  On success, there will be a "Data" member giving the
     * decrypted payload.
     */
    public function decodeEncryptedMessage($PostData)
    {
        # verify that the provided POST data has the correct elements
        if (!isset($PostData["MAC"]) || !isset($PostData["Payload"]) ||
            !isset($PostData["IV"])) {
            return array(
                "Status" => "Error",
                "Message" => "PostData lacks required elements.");
        }

        # generate full key from provided password
        $FullKey = hash("sha512", $this->APIPassword, true);

        # split into encryption and MAC keys
        # (DO NOT change these to StdLib:: calls. See the class docstring for why not)
        $EncKey = mb_substr($FullKey, 0, 32, '8bit');
        $MacKey = mb_substr($FullKey, 32, 32, '8bit');

        # compute MAC
        $MAC = hash_hmac(
            "sha256",
            $PostData["IV"].":".$PostData["Payload"],
            $MacKey,
            true
        );

        # check MAC, bail if it was not valid
        if (!hash_equals($MAC, base64_decode($PostData["MAC"]))) {
            return array(
                "Status" => "Error",
                "Message" => "HMAC validation failure -- message is corrupted.");
        }

        # strip base64 encoding from payload and IV
        $Payload = base64_decode($PostData["Payload"]);
        $IV = base64_decode($PostData["IV"]);

        # decrypt the payload to get the envelope
        $Env = openssl_decrypt(
            $Payload,
            "aes-256-cbc",
            $EncKey,
            OPENSSL_RAW_DATA,
            $IV
        );

        # attempt to unserialize the envelope, bailing on failure
        $Env = json_decode($Env, true);
        if ($Env === null) {
            return array(
                "Status" => "Error",
                "Message" => "Could not decode message envelope.");
        }

        # check that the envelope contains all the required headers
        if (!isset($Env["Version"]) || !isset($Env["Timestamp"]) ||
            !isset($Env["Cookie"]) || !isset($Env["Data"])) {
            return array(
                "Status" => "Error",
                "Message" => "Payload did not include all required parameters.");
        }

        # check that this is an envelope in a version we understand
        if ($Env["Version"] != "3") {
            return array(
                "Status" => "Error",
                "Message" => "Message was not version 3.");
        }

        # check that this envelope isn't too old
        if (time() - $Env["Timestamp"] > 300) {
            return array(
                "Status" => "Error",
                "Message" => "Message is more than 5 minutes old.");
        }

        # check if this is a duplicate message
        if (call_user_func(
            $this->CheckForDuplicateFn,
            $Env["Timestamp"],
            $Env["Cookie"]
        )) {
            return array(
                "Status" => "Error",
                "Message" => "This is a duplicate message");
        }

        call_user_func(
            $this->RegisterMessageFn,
            $Env["Timestamp"],
            $Env["Cookie"]
        );

        return array(
            "Status" => "OK",
            "Data" => $Env["Data"]);
    }

    private $APIUrl;
    private $APIPassword;
    private $CheckForDuplicateFn;
    private $RegisterMessageFn;
}
