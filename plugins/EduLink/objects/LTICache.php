<?PHP
#
#   FILE:  LTICache.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

// @phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
namespace Metavus\Plugins\EduLink;

use Exception;
use ScoutLib\Database;

/**
 * Provide an implementation of an LTI cache that stores cached lonch data in
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

    // @phpstan-ignore-next-line (suppress 'no type specified') complaint
    public function cache_nonce($nonce): self
    {
        $this->DB->query(
            "INSERT INTO EduLink_Nonces (Nonce, SeenAt)"
            ." VALUES ('".$this->DB->escapeString($nonce)."', NOW())"
        );
        return $this;
    }

    // @phpstan-ignore-next-line (suppress 'no type specified') complaint
    public function check_nonce($nonce): bool
    {
        $N = $this->DB->queryValue(
            "SELECT COUNT(*) AS N FROM EduLink_Nonces"
            ." WHERE Nonce='".$this->DB->escapeString($nonce)."'",
            "N"
        );

        return ($N > 0);
    }

    private $DB;
}
