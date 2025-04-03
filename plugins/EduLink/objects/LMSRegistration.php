<?PHP
#
#   FILE:  Registration.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\EduLink;

use Exception;
use Metavus\SearchParameterSet;
use Metavus\User;
use ScoutLib\Item;

/**
 * Stores information about LMSes that have been configured to talk to us.
 */
class LMSRegistration extends Item
{
    /**
     * Register an LTI Tool Consumer so that we will be able to talk to it.
     * All parameters are provided by the remote LMS to the LMS admin and will
     * need to be obtained from them.
     * @param array $Values Registration parameters. Must include values for
     *   Issuer, ClientId, AuthLoginUrl, AuthTokenUrl, and KeySetUrl. Values
     *   may also be provided for ContactEmail and LMS. The Issuer is the
     *   URL of the LMS and the ClientId is opaque strings provided by the
     *   LMS. The Url parameters indicate specific pages in the LMS to which
     *   certain LTI requests should be directed. Details can be found in the
     *   LMS specification at http://www.imsglobal.org/spec/lti/v1p3/
     * @return LMSRegistration newly created registration
     */
    public static function create(array $Values) : LMSRegistration
    {
        $RequiredKeys = [
            "Issuer",
            "ClientId",
            "AuthLoginUrl",
            "AuthTokenUrl",
            "KeySetUrl",
        ];

        $AllowedKeys = $RequiredKeys;
        $AllowedKeys[] = "ContactEmail";
        $AllowedKeys[] = "LMS";
        $AllowedKeys[] = "SearchParameters";

        # ensure all required values were provided
        foreach ($RequiredKeys as $Key) {
            if (!isset($Values[$Key])) {
                throw new Exception(
                    "A value for ".$Key." is required."
                );
            }
        }

        # ensure all provided keys were valid
        foreach (array_keys($Values) as $Key) {
            if (!in_array($Key, $AllowedKeys)) {
                throw new Exception(
                    $Key." is not a valid Registration parameter."
                );
            }
        }

        # extract search parameters if they were provided
        $SearchParams = null;
        if (isset($Values["SearchParameters"])) {
            $SearchParams = $Values["SearchParameters"];
            unset($Values["SearchParameters"]);
        }

        # create new registration
        $Result = static::createWithValues($Values);

        if (!is_null($SearchParams)) {
            $Result->setSearchParameters($SearchParams);
        }

        return $Result;
    }

    /**
     * Check if a registration already exists for a given remote site.
     * @param array $Values Parameters for the new registration (must contain
     *   Issuer), expected to be the same $Values used for a subsequent call
     *   to LMSRegistration::create().
     * @return bool TRUE for duplicates, FALSE otherwise
     * @see create() for the format of $Values.
     */
    public static function registrationExists(array $Values) : bool
    {
        if (!isset($Values["Issuer"])) {
            throw new Exception(
                "A value for Issuer is required."
            );
        }

        $Query = "Issuer='".addslashes($Values["Issuer"])."'";

        if (isset($Values["ClientId"])) {
            $Query .= " AND ClientId='".addslashes($Values["ClientId"])."'";
        }

        # count number of matching items
        $Count = (new LMSRegistrationFactory())
            ->getItemCount($Query);

        # report result to caller
        return ($Count > 0);
    }

    /**
     * Get the tool issuer.
     * @return string Current setting
     */
    public function getIssuer()
    {
        return $this->DB->updateValue("Issuer");
    }

    /**
     * Set the tool issuer.
     * @param string $NewValue Tool issuer.
     @return void
     */
    public function setIssuer(string $NewValue): void
    {
        $this->DB->updateValue("Issuer", $NewValue);
    }

    /**
     * Get the tool client identifier.
     * @return string Current setting
     */
    public function getClientId() : string
    {
        return $this->DB->updateValue("ClientId");
    }

    /**
     * Set the tool client identifier.
     * @param string $NewValue Tool client identifier
     * @return void
     */
    public function setClientId(string $NewValue) : void
    {
        $this->DB->updateValue("ClientId", $NewValue);
    }

    /**
     * Get the auth login url.
     * @return string Current setting
     */
    public function getAuthLoginUrl() : string
    {
        return $this->DB->updateValue("AuthLoginUrl");
    }

    /**
     * Set the auth login url.
     * @param string $NewValue Tool auth login url (OPTIONAL)
     * @return void
     */
    public function setAuthLoginUrl(string $NewValue) : void
    {
        $this->DB->updateValue("AuthLoginUrl", $NewValue);
    }

    /**
     * Get the auth token url.
     * @return string Current setting
     */
    public function getAuthTokenUrl() : string
    {
        return $this->DB->updateValue("AuthTokenUrl");
    }

    /**
     * Set the auth token url.
     * @param string $NewValue Tool auth token url (OPTIONAL)
     * @return void
     */
    public function setAuthTokenUrl(string $NewValue) : void
    {
        $this->DB->updateValue("AuthTokenUrl", $NewValue);
    }

    /**
     * Get the key set url
     * @return string Current setting
     */
    public function getKeySetUrl() : string
    {
        return $this->DB->updateValue("KeySetUrl");
    }

    /**
     * Set the key set url
     * @param string $NewValue Tool key set url (OPTIONAL)
     * @return void
     */
    public function setKeySetUrl(string $NewValue)
    {
        $this->DB->updateValue("KeySetUrl", $NewValue);
    }


    /**
     * Get the LMS type.
     * @return string Current setting
     */
    public function getLms() : string
    {
        return $this->DB->updateValue("LMS");
    }

    /**
     * Set the LMS type.
     * @param string $NewValue LMS type (OPTIONAL)
     * @return void
     */
    public function setLms(string $NewValue) : void
    {
        $this->DB->updateValue("LMS", $NewValue);
    }

    /**
     * Get the contact email.
     * @return string Current setting
     */
    public function getContactEmail() : string
    {
        return $this->DB->updateValue("ContactEmail");
    }

    /**
     * Set the contact email.
     * @param string $NewValue Contact email
     * @return void
     */
    public function setContactEmail(string $NewValue) : void
    {
        $this->DB->updateValue("ContactEmail", $NewValue);
    }

    /**
     * Get additional search parameters for this registration.
     * @return SearchParameterSet Current setting
     */
    public function getSearchParameters() : SearchParameterSet
    {
        if (is_null($this->SearchParams)) {
            $Data = $this->DB->updateValue("SearchParameters");
            $this->SearchParams = ($Data === false) ?
                new SearchParameterSet() :
                new SearchParameterSet($Data);
        }

        return $this->SearchParams;
    }

    /**
     * Set additional search parameters for this registration.
     * @param SearchParameterSet $NewValue Search parameters.
     * @return void
     */
    public function setSearchParameters(SearchParameterSet $NewValue) : void
    {
        $this->SearchParams = $NewValue;
        $this->DB->updateValue("SearchParameters", $this->SearchParams->data());
    }

    /**
     * Determine if a user can edit this registration.
     * @param User $User User to check.
     * @return bool TRUE when item can be edited, FALSE otherwise.
     */
    public function userCanEdit(User $User) : bool
    {
        return $User->hasPriv(PRIV_SYSADMIN);
    }

    /**
     * Set the database access values (table name, ID column name, name column
     * name) for specified class.  This may be overridden in a child class, if
     * different values are needed.
     * @param string $ClassName Class to set values for.
     * @return void
     */
    protected static function setDatabaseAccessValues(string $ClassName): void
    {
        if (!isset(self::$ItemIdColumnNames[$ClassName])) {
            self::$ItemIdColumnNames[$ClassName] = "Id";
            self::$ItemNameColumnNames[$ClassName] = null;
            self::$ItemTableNames[$ClassName] = "EduLink_Registrations";
        }
    }

    private $SearchParams = null;
}
