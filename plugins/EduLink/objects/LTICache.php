<?PHP
#
#   FILE:  LTICache.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

// @phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
namespace Metavus\Plugins\EduLink;

use Exception;
use ScoutLib\Database;

/**
 * Provide an implementation of an LTI cache that stores cached launch data in
 * our database.
 */
class LTICache extends \IMSGlobal\LTI\Cache
{
    public function __construct()
    {
        $this->DB = new Database();
    }

    // @phpstan-ignore-next-line (suppress 'no type specified') complaint
    public function get_launch_data($key): mixed
    {
        $Value = $this->DB->queryValue(
            "SELECT Value FROM EduLink_Launches "
            ."WHERE CacheKey='".$this->DB->escapeString($key)."'",
            "Value"
        );

        if (!is_null($Value)) {
            return unserialize($Value);
        }

        return null;
    }

    // @phpstan-ignore-next-line (suppress 'no type specified') complaint
    public function cache_launch_data($key, $jwt_body): self
    {
        $Data = serialize($jwt_body);

        $this->DB->query(
            "INSERT INTO EduLink_Launches (CacheKey, Value, CachedAt)"
            ." VALUES ("
            ."'".$this->DB->escapeString($key)."',"
            ."'".$this->DB->escapeString($Data)."',"
            ." NOW())"
        );

        return $this;
    }

    /**
     * Store a nonce for later validation.
     * @param string $nonce Nonce to store.
     * @see https://en.wikipedia.org/wiki/Cryptographic_nonce
     * @see https://www.imsglobal.org/spec/security/v1p0/#id-token
     */
    public function cache_nonce($nonce): self
    {
        $this->DB->query(
            "INSERT INTO EduLink_Nonces (Nonce, CreatedAt, SeenAt)"
            ." VALUES ('".$this->DB->escapeString($nonce)."', NOW(), NULL)"
        );

        return $this;
    }

    /**
     * Check that a provided nonce is valid (i.e. it's a nonce that we've
     *         previously cached but that has not yet been used).
     * @param string $nonce Nonce to validate.
     * @return bool TRUE for valid nonces, FALSE otherwise.
     */
    public function check_nonce($nonce): bool
    {
        $this->DB->query(
            "UPDATE EduLink_Nonces SET SeenAt=NOW()"
                ." WHERE Nonce='".$this->DB->escapeString($nonce)."' AND SeenAt IS NULL"
        );
        return ($this->DB->numRowsAffected() == 1) ? true : false;
    }

    private $DB;
}
