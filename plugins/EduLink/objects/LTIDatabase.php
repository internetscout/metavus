<?PHP
#
#   FILE:  LTIDatabase.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\EduLink;

use Exception;
use Metavus\Plugins\EduLink;
use ScoutLib\Database;

/**
 * Implementation of the \IMSGlobal\LTI\Database superclass that knows how to
 * locate LTI Registrations stored by our plugin.
 * @see https://github.com/IMSGlobal/lti-1-3-php-library#accessing-registration-data
 */
class LTIDatabase implements \IMSGlobal\LTI\Database
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->DB = new Database();
    }

    /**
     * Look up registration by issuer.
     * @param string $iss Issuer provided by the remote site.
     * @param ?string $client_id Client Id provided by the remote site.
     * @return \IMSGlobal\LTI\LTI_Registration Registration info.
     */
    // @codingStandardsIgnoreLine
    public function find_registration_by_issuer($iss, ?string $client_id)
    {
        $Query = "SELECT * FROM EduLink_Registrations "
            ."WHERE Issuer = '".addslashes($iss)."'";

        if ($client_id !== null) {
            $Query .= " AND ClientId = '".addslashes($client_id)."'";
        };


        # needs to return an LTI\LTI_Registration
        $this->DB->query($Query);

        if ($this->DB->numRowsSelected() == 0) {
            throw new Exception(
                "Issuer '".$iss."' not found"
                ." with Client Id '".$client_id."'"
            );
        }

        $Row = $this->DB->fetchRow();

        $Result = \IMSGlobal\LTI\LTI_Registration::new();

        $Setters = [
            "Issuer" => "set_issuer",
            "ClientId" => "set_client_id",
            "AuthLoginUrl" => "set_auth_login_url",
            "AuthTokenUrl" => "set_auth_token_url",
            "KeySetUrl" => "set_key_set_url",
        ];
        foreach ($Setters as $Column => $SetFn) {
            if (strlen($Row[$Column])) {
                $Result->$SetFn($Row[$Column]);
            }
        }

        $Result->set_tool_private_key(
            EduLink::getInstance()
            ->getConfigSetting("PrivateKey")
        );

        return $Result;
    }

    /**
     * Look up LTI deployment by issuer and deployment id.
     * @param string $iss Issuer provided by the remote site.
     * @param string $deployment_id Deployment id provided by the remote site.
     * @return \IMSGlobal\LTI\LTI_Deployment Deployment info.
     */
    // @codingStandardsIgnoreLine
    public function find_deployment($iss, $deployment_id)
    {
        return \IMSGlobal\LTI\LTI_Deployment::new()
            ->set_deployment_id($deployment_id);
    }

    private $DB;
}
