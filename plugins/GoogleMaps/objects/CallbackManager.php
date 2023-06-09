<?PHP
#
#   FILE:  CallbackManager.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\GoogleMaps;

use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

class CallbackManager
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->DB = new Database();

        # expire anything more than 90 days old
        $this->ExpirationAge = 90 * 86400;
    }

    /**
     * Register a callback for later use. If the callback is already
     * registered, update the LastUsed timestamp to indicate that this
     * callback is still being used.
     * @param callable $Callback Callback to register.
     * @param array $Params Callback parameters.
     * @return string Id of callback
     */
    public function registerCallback($Callback, $Params)
    {
        $EnvData = self::getEnvironmentSignature();

        ksort($Params);

        $CallbackSerial = serialize($Callback);
        $ParamsSerial = serialize($Params);
        $CallbackId = md5($CallbackSerial.$ParamsSerial);

        $PageSignature = md5($EnvData);

        $this->DB->Query(
            "LOCK TABLES "
            ."GoogleMaps_Callbacks WRITE, "
            ."GoogleMaps_CallbackOwners WRITE"
        );

        # add a record for this callback or update LastUsed if a record
        # already exists (per the MySQL docs at
        # https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
        # "If you specify an ON DUPLICATE KEY UPDATE clause and a row
        #  to be inserted would cause a duplicate value in a UNIQUE
        #  index or PRIMARY KEY, an UPDATE of the old row occurs.")
        # note also that we're tracking LastUsed for callbacks so that we can
        # avoid deleting un-owned callbacks that are still in use via
        # GetKML (hence the check against CallbackAge in deleteCallback())
        $this->DB->Query(
            "INSERT INTO GoogleMaps_Callbacks "
            ."(Id, Payload, Params, LastUsed) VALUES ("
            ."'".addslashes($CallbackId)."',"
            ."'".$this->DB->EscapeString($CallbackSerial)."',"
            ."'".$this->DB->EscapeString($ParamsSerial)."',"
            ."NOW()"
            .") ON DUPLICATE KEY UPDATE LastUsed=NOW()"
        );

        # add a record for this callback owner or update LastUsed if a record
        # already exists
        $this->DB->Query(
            "INSERT INTO GoogleMaps_CallbackOwners "
            ."(PageSignature, CallbackId, Environment, LastUsed) VALUES ("
            ."'".addslashes($PageSignature)."',"
            ."'".addslashes($CallbackId)."',"
            ."'".addslashes($EnvData)."',"
            ."NOW()"
            .") ON DUPLICATE KEY UPDATE LastUsed=NOW()"
        );

        $this->DB->Query("UNLOCK TABLES");

        return $CallbackId;
    }

    /**
     * Expire callback owners, then delete unowned callbacks that are also
     * unused. (if a callback is unowned but still in use, it will not be
     * deleted @see deleteCallback)
     */
    public function expireCallbacks()
    {
        $this->DB->Query(
            "LOCK TABLES "
            ."GoogleMaps_Callbacks WRITE, "
            ."GoogleMaps_CallbackOwners WRITE"
        );

        # delete old owner registrations
        $this->DB->Query(
            "DELETE FROM GoogleMaps_CallbackOwners "
            ."WHERE TIMESTAMPDIFF(SECOND, LastUsed, NOW()) > "
            .$this->ExpirationAge
        );

        # delete any callbacks that no longer have owners
        $this->DB->Query(
            "SELECT Id FROM GoogleMaps_Callbacks WHERE "
            ."Id NOT IN (SELECT CallbackId FROM GoogleMaps_CallbackOwners)"
        );
        $CallbackIds = $this->DB->FetchColumn("Id");
        foreach ($CallbackIds as $CallbackId) {
            $this->deleteCallback($CallbackId);
        }

        $this->DB->Query("UNLOCK TABLES");
    }

    /**
     * Delete a specified callback if it is no longer in use and has
     * no registered owners. For callbacks that are still being used
     * or that have registered owners, an error message is logged and
     * the callback is retained.
     * @param string $CallbackId Opaque identifier specifying the callback to delete.
     */
    private function deleteCallback($CallbackId)
    {
        $AF = ApplicationFramework::getInstance();

        # pull out info about this callback, build a description
        $this->DB->Query(
            "SELECT * FROM GoogleMaps_Callbacks "
            ."WHERE Id='".addslashes($CallbackId)."'"
        );
        $CallbackInfo =  $this->DB->FetchRow();
        $CallbackDescription =  $CallbackId." ("
            .$CallbackInfo["Payload"]." "
            .$CallbackInfo["Params"].")";

        # if the callback is still in use (i.e. "being accessed" NOT "still
        # has an owner")
        $CallbackAge = time() - strtotime($CallbackInfo["LastUsed"]);
        if ($CallbackAge < $this->ExpirationAge) {
            # log a message about it and refuse to delete the callback
            $AF->logMessage(
                ApplicationFramework::LOGLVL_DEBUG,
                "[GoogleMaps] Refusing to delete callback "
                .$CallbackDescription
                ." because it is still being used."
            );
            return;
        }

        # otherwise, delete the callback
        $AF->logMessage(
            ApplicationFramework::LOGLVL_DEBUG,
            "[GoogleMaps] Deleting callback "
            .$CallbackDescription
        );
        $this->DB->Query(
            "DELETE FROM GoogleMaps_Callbacks "
            ."WHERE Id='".addslashes($CallbackId)."'"
        );
    }

    /**
     * Construct a JSON signature that uniquely identifies the current
     * execution context.
     * @return string Page signature.
     */
    private static function getEnvironmentSignature()
    {
        $AF = ApplicationFramework::getInstance();

        # get a fingerprint for this page's environment
        if (is_null(self::$EnvironmentSignature)) {
            $GetVars = $_GET;
            ksort($GetVars);
            $PostVars = $_POST;
            ksort($PostVars);
            self::$EnvironmentSignature = json_encode([
                "Get" => $GetVars,
                "Post" => $PostVars,
                "Page" => $AF->getPageName(),
                "ActiveUI" => $AF->activeUserInterface(),
                "Server" => $_SERVER["SERVER_NAME"],
            ]);
        }

        return self::$EnvironmentSignature;
    }

    public static $SqlTables = [
        "Callbacks" =>
            "CREATE TABLE GoogleMaps_Callbacks (
            Id VARCHAR(32),
            Payload BLOB,
            LastUsed TIMESTAMP,
            Params BLOB,
            UNIQUE UIndex_I (Id) )",
        "CallbackOwners" =>
            "CREATE TABLE GoogleMaps_CallbackOwners (
            PageSignature VARCHAR(32),
            CallbackId VARCHAR(32),
            Environment MEDIUMBLOB,
            LastUsed TIMESTAMP,
            UNIQUE UIndex_PC (PageSignature, CallbackId))",
    ];

    private static $EnvironmentSignature = null;

    private $DB;
    private $ExpirationAge;
}
