<?PHP
#
#   FILE:  SecureLoginHelper.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2004-2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

/**
 * Helper class to manage encryption keys used by the Secure Login feature.
 */
class SecureLoginHelper
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Print any content that should appear in HTML header area to support
     * secure logins.
     * @return void
     */
    public static function printHeaderContent(): void
    {
        if (self::shouldUseSecureLogin()) {
            # include the 'jsbn' javascript encryption library
            # (included inline rather than with RequireUIFile() so that
            #  the RSAKey js object will be defined before the js above
            #  the login form tries to use it)
            $LoginInterfaceFiles = [
                "prng4.js",
                "rng.js",
                "jsbn.js",
                "rsa.js",
                "base64.js"
            ];

            ApplicationFramework::getInstance()->includeUIFile($LoginInterfaceFiles);

            # get the public key parameters for the most recently generated keypair
            $PubKeyParams = self::getCryptKey();

            # define CryptPw javascript function to encrypt the user-supplied
            # password, pad it with 2 random bytes, then base64 the result for
            # transmission
            # (pages/UserLogin.php contains the companion decryption code)
            $Modulus = $PubKeyParams["Modulus"];
            $Exponent = $PubKeyParams["Exponent"];
            ?>
                <script type="text/javascript">
                    var RSA = new RSAKey();
                    RSA.setPublic("$Modulus", "$Exponent");
                    function CryptPw() {
                        var resp = hex2b64(RSA.encrypt($("input#Password").val() + "\t"
                        + rng_get_byte() + rng_get_byte()));
                        $("input#CryptPassword").val(resp);
                        $("input#Password").val("");
                    }
                </script>
            <?PHP
        }
    }

    /**
     * Get any content for "onSubmit" attribute of login form tag.
     * @return string Content for attribute.
     */
    public static function getLoginFormOnSubmitAction(): string
    {
        return self::shouldUseSecureLogin() ? "CryptPw();" : "";
    }

    /**
     * Print any content that should appear in the login form to support
     * secure logins.
     * @return void
     */
    public static function printLoginFormContent(): void
    {
        if (self::shouldUseSecureLogin()) {
            ?>
            <input type="hidden" id="UseSecure" name="UseSecure">
            <input type="hidden" id="CryptPassword" name="F_CryptPassword" value="">
            <?PHP
        }
    }

    /**
     * Generate and return a cryptographic keypair for user login, to
     * support the use of RSA encryption on the password field of login forms.
     * This function gets the most recently generated keypair, clearing out
     * keys older than 48 hours, and re-generating a new key if the most
     * recent one is older than 24 hours.
     * @return array containing "Modulus" and "Exponent" key parameters
     */
    public static function getCryptKey() : array
    {
        $DB = new Database();

        $MaxKeyAge = self::computeMaxKeyAge();

        # NOTE: One can not simply subtract two TIMESTAMPs and expect
        # a sane result from mysql.  Using a TIMESTAMP in numeric
        # context converts it to an int, but in YYYYMMDDHHMMSS format
        # rather than as a UNIX time.  Hence the use of
        # TIMESTAMPDIFF() below.

        # clear expired keys and replay protection tokens
        $DB->query("DELETE FROM UsedLoginTokens WHERE "
                   ."TIMESTAMPDIFF(SECOND, KeyCTime, NOW()) > ".$MaxKeyAge);

        $DB->query("LOCK TABLES LoginKeys WRITE");
        $DB->query("DELETE FROM LoginKeys WHERE "
                   ."TIMESTAMPDIFF(SECOND, CreationTime, NOW()) > ".$MaxKeyAge);

        # get the most recently generated key
        $DB->query("SELECT TIMESTAMPDIFF(SECOND, CreationTime, NOW()) as Age,"
                   ."KeyPair FROM LoginKeys "
                   ."ORDER BY Age ASC LIMIT 1");
        $Row = $DB->fetchRow();

        # if there is no key in the database, or the key is too old
        if (($Row === false) || ($Row["Age"] >= self::$KeyRegenInterval)) {
            # generate a new OpenSSL format keypair
            $KeyPair = self::generateAndSaveNewKeypair($DB);
        } else {
            # if we do have a current key in the database,
            #  convert it to openssl format for usage
            $KeyPair = openssl_pkey_get_private($Row["KeyPair"]);
        }
        $DB->query("UNLOCK TABLES");

        if ($KeyPair === false) {
            throw new Exception("Unable to locate secure login keypair.");
        }

        return self::extractPubKeyParameters($KeyPair);
    }

    /**
     * Decrypt an encrypted password.
     * @param string $UserName User logging in.
     * @param string $EncryptedPassword Encrypted and base64'd password
     *     provided by user.
     * @return string|false decrypted password or FALSE on error.
     */
    public static function decryptPassword(
        string $UserName,
        string $EncryptedPassword
    ) {
        $DB = new Database();
        $DB->query("SELECT CreationTime, KeyPair FROM LoginKeys");

        # start off assuming that nothing will decrypt properly
        $Password = false;

        # remove the base64 encoding sent on the cyphertext
        $Cyphertext = base64_decode($EncryptedPassword);

        # extract recently used login keys from the database
        $Keys = $DB->fetchRows();

        # iterate through them on the cyphertext, trying to decrypt it with
        # each key.  by default, 48 hr worth of keys are kept, and fresh keys
        # are generated every 24 hr.
        foreach ($Keys as $Key) {
            $KeyCTime = $Key["CreationTime"];
            $DBFormatKeyPair = $Key["KeyPair"];
            # extract the OpenSSL format private key from the database key format
            $Keypair = openssl_pkey_get_private($DBFormatKeyPair);

            if ($Keypair === false) {
                throw new Exception("Unable to load private key.");
            }

            # attempt to decrypt the cyphertext
            if (openssl_private_decrypt($Cyphertext, $Cleartext, $Keypair)) {
                # on success, extract the password portion and the random bytes
                $Data = explode("\t", $Cleartext);

                if (count($Data) == 2) {
                    list($Password, $Token) = $Data;

                    # check to see if we've seen these particular bytes
                    # before for this user and this key
                    $DB->query("SELECT * FROM UsedLoginTokens WHERE"
                               ." Token=\"".addslashes($Token)."\" AND"
                               ." KeyCTime=\"".$KeyCTime."\" AND"
                               ." UserName=\"".$UserName."\"");

                    if ($DB->numRowsSelected() > 0) {
                        # if so, this is a replay attack; fail the login
                        $Password = false;
                    } else {
                        # otherwise, record these bytes as seen, and attempt to login
                        # with the plaintext password
                        $DB->query("INSERT INTO UsedLoginTokens"
                                   ." (Token, KeyCTime, UserName) VALUES ("
                                   ."\"".addslashes($Token)."\","
                                   ."\"".addslashes($KeyCTime)."\","
                                   ."\"".addslashes($UserName)."\")");
                    }

                    # no need to try other keys when we've found one that
                    # works
                    break;
                }
            }
        }

        return $Password;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    # regenerate keys every day (24 * 60 * 60 = 86,400 seconds)
    private static $KeyRegenInterval = 86400;

    /**
     * Extract the modulus and exponent of the public key from an OpenSSL
     *   format keypair
     * @param \OpenSSLAsymmetricKey $KeyPair An openssl format keypair
     * @return array containing "Modulus" and "Exponent" key parameters
     */
    private static function extractPubKeyParameters($KeyPair) : array
    {
        $CSR = openssl_csr_new([], $KeyPair);
        if ($CSR === false) {
            throw new Exception(
                "Unable to generate ASCII CSR from keypair."
            );
        }

        # export the keypair as an ASCII signing request (which contains the data we want)
        openssl_csr_export(
            $CSR, // @phpstan-ignore-line
            $Export,
            false
        );

        $Modulus  = "";
        $Exponent = "";

        // @codingStandardsIgnoreStart
        // (ignore for line length)
        $Patterns = [
            '/Modulus \([0-9]+ bit\):(.*)Exponent: [0-9]+ \(0x([0-9a-f]+)\)/ms',
            '/Public-Key: \([0-9]+ bit\).*Modulus:(.*)Exponent: [0-9]+ \(0x([0-9a-f]+)\)/ms',
        ];
        // @codingStandardsIgnoreEnd

        foreach ($Patterns as $Pattern) {
            if (preg_match($Pattern, $Export, $Matches)) {
                $Modulus = $Matches[1];
                $Exponent = $Matches[2];
                break;
            }
        }

        # clean newlines and whitespace out of the modulus
        $Modulus = preg_replace("/[^0-9a-f]/", "", $Modulus);

        # return key material
        return [
            "Modulus" => $Modulus,
            "Exponent" => $Exponent,
        ];
    }

    /**
     * Compute the maximum age for keys that should be retained.
     * @return int max key age in seconds
     */
    private static function computeMaxKeyAge() : int
    {
        $AF = ApplicationFramework::getInstance();
        $MaxKeyAge = 3 * self::$KeyRegenInterval;

        # if the page cache was enabled, be sure to keep keys that were
        # current (i.e. not expired) as of the oldest cache entry
        if ($AF->pageCacheEnabled()) {
            $CacheInfo = $AF->getPageCacheInfo();
            if ($CacheInfo["NumberOfEntries"] > 0) {
                $MaxKeyAge += (time() - $CacheInfo["OldestTimestamp"]);
            }
        }

        return $MaxKeyAge;
    }

    /**
     * Generate a new keypair and save it in the database.
     * @param Database $DB Database to use
     * @return \OpenSSLAsymmetricKey An openssl format keypair
     */
    private static function generateAndSaveNewKeypair(Database $DB)
    {
        # generate a new key
        $KeySettings = [
            'private_key_bits' => 512, # make this a Sysadmin pref later?
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $KeyPair = openssl_pkey_new($KeySettings);

        if ($KeyPair === false) {
            throw new Exception("Unable to generate new private key.");
        }

        # serialize it for storage
        openssl_pkey_export($KeyPair, $KeyPairDBFormat);

        # stick it into the database
        $DB->query(
            "INSERT INTO LoginKeys (KeyPair, CreationTime) VALUES ("
            ."\"".addslashes($KeyPairDBFormat)."\",NOW())"
        );

        return $KeyPair;
    }

    /**
     * Report whether to use secure login mechanism.
     * @return bool TRUE if secure login should be used, otherwise FALSE.
     */
    private static function shouldUseSecureLogin(): bool
    {
        return isset($_SERVER["HTTPS"]) ? false : true;
    }
}
