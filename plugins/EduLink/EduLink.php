<?PHP
#
#   FILE:  EduLink.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Exception;
use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Plugins\EduLink\LMSRegistration;
use Metavus\Plugins\EduLink\LMSRegistrationFactory;
use Metavus\Plugins\EduLink\LTICache;
use Metavus\Plugins\EduLink\LTIDatabase;
use Metavus\Plugins\Folders\Folder;
use Metavus\Plugins\Folders\FolderFactory;
use Metavus\Plugins\MetricsRecorder;
use Metavus\Record;
use Metavus\RecordFactory;
use Metavus\SearchParameterSet;
use Metavus\User;
use Metavus\UserFactory;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

/**
 * Plugin to support LTI Deep Linking, a protocol for embedding learning
 * resources from a digital repository directly into LMS courses. See
 * https://www.imsglobal.org/specs/lticiv1p0-intro for an introduction and
 * https://www.imsglobal.org/specs/lticiv1p0/specification for protocol
 * documentation.
 *
 * There are two types of LTI "Launches" (i.e. requests) that we handle:
 *
 * 1) "Deep Linking" requests in which an LMS course author is selecting
 *    resource(s) to embed in their course either by picking resources a la
 *    carte or by selecting a folder
 *
 * 2) "Resource" requests in which an LMS is asking us to display the
 *     resource(s) selected in a prior Deep Linking request
 *
 * For both types of requests, the LMS first has the User's browser POST an
 * OpenId Connect Login to ./pages/LTILogin.php (i.e. P_EduLink_LTILogin),
 * which generates a self-submitting form containing a response that the
 * Users's browser POSTs back into the LMS. This sequence of POSTs generates a
 * bearer token that is used for authentication of subsequent requests. If
 * the user accesses several records within the same browsing session then the
 * same bearer token can be reused for them all.
 *
 * In the case of a "Deep Linking" request:
 *
 *  a) the LMS has the User's browser POST an LTI Request to
 *     ./pages/Launch.php, which validates the LTI request and then redirects
 *     the user to ./pages/LTIHome.php.
 *
 *  b) On ./pages/LTIHome.php, the user is prompted for what resources
 *     they wish to include. This page POSTs to itself as the user searches
 *     for records and refines their results. If the user pushes the "Select
 *     Folder" button, they are sent to pages/SelectFolder.php instead. After
 *     a selection is made on either page, the page outputs a self-submitting
 *     form containing the LTI Response data that will be POST-ed back to the
 *     LMS.
 *
 *
 * In the case of a "Resource" request, the LMS has the User's browser POST an
 * LTI Request to ./pages/Launch.php. This request will contain the data that
 * we gave the LMS in 'b' above. We are expected to return HTML representing
 * the requested resource(s).
 *
 */
final class EduLink extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set plugin attributes.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "EduLink";
        $this->Version = "1.1.0";
        $this->Description = "Expose resources to Learning Management Systems using "
            ."the Learning Tools Interopability Deep Linking standard.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "MetricsRecorder" => "1.2.16",
        ];
        $this->EnabledByDefault = false;

        $this->CfgSetup["ServiceName"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Service Name",
            "Default" => "Metavus Deep Linking",
        ];

        $this->CfgSetup["ServiceDescription"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Service Description",
            "Default" => "Embed resources from a Metavus repository directly into "
                ."LMS courses.",
        ];

        $this->CfgSetup["LogoFileName"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Logo File Name",
            "Default" => "MetavusLogo.svg",
        ];

        $this->CfgSetup["AdminEmail"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Administrative Email",
            "Help" => "Contact email for questions about the service.",
        ];

        $this->CfgSetup["FooterText"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Footer Text",
            "Help" => "Footer text displayed at the bottom of the pop-up windows"
                ." displayed in the LMS. X-ADMINEMAIL-X will be replaced with the"
                ." configured Administrative Email and X-SERVICENAME-X with the"
                ." configured Service Name."
        ];

        $this->CfgSetup["LoginPrompt"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Login Prompt",
            "Help" => "Text displayed in the login popup.",
        ];

        $this->CfgSetup["RegistrationTitle"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Registration Page Title",
            "Default" => "Register Your LMS",
        ];

        $this->CfgSetup["RegistrationIntroText"] = [
            "Type" => FormUI::FTYPE_PARAGRAPH,
            "Label" => "Registration Page Introductory Text",
            "Help" => "Introductory text displayed at the top of the LMS Registration page.",
        ];

        $this->CfgSetup["ResourceCriteria"] = [
            "Type" => FormUI::FTYPE_SEARCHPARAMS,
            "Label" => "Resource Criteria",
            "Help" => "Criteria to select resources that should be offered for embedding "
                ." in LMS courses.",
        ];

        $this->CfgSetup["NewResourceCriteria"] = [
            "Type" => FormUI::FTYPE_SEARCHPARAMS,
            "Label" => "New Resource Criteria",
            "Help" => "Criteria for the initial list presented for record selection.",
        ];

        $this->CfgSetup["UserAvatarField"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "FieldTypes" => MetadataSchema::MDFTYPE_IMAGE,
            "SchemaId" => MetadataSchema::SCHEMAID_USER,
            "Label" => "User Avatar Field",
            "Help" => "Image field to use for user avatars / logos displayed"
                ." in the folder selection interface.",
        ];

        $this->CfgSetup["BrowsingField"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "FieldTypes" => MetadataSchema::MDFTYPE_TREE,
            "Label" => "Browsing Field",
            "Help" => "Field to display above 'New Resources' section on "
                ." the resource selection landing page."
        ];

        $this->CfgSetup["FacetFields"] = [
            "Type" => FormUI::FTYPE_OPTION,
            "OptionsFunction" => [$this, "getFacetFieldOptions"],
            "AllowMultiple" => true,
            "Label" => "Facet Fields",
            "Help" => "Fields to display as facets in record selection interface."
        ];

        $this->CfgSetup["PreferredFileField"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "FieldTypes" => MetadataSchema::MDFTYPE_FILE,
            "Label" => "Preferred File Field",
            "Help" => "File field to prefer for embedded content.",
        ];

        $this->CfgSetup["PreferredUrlField"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "FieldTypes" => MetadataSchema::MDFTYPE_URL,
            "Label" => "Preferred URL Field",
            "Help" => "Url field to prefer for embedded content.",
        ];

        $this->CfgSetup["CategoryField"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "FieldTypes" => MetadataSchema::MDFTYPE_TREE,
            "Label" => "Category Field",
            "Help" => "Field to use for categories in record summary.",
        ];

        $this->CfgSetup["SortField"] = [
            "Type" => FormUI::FTYPE_METADATAFIELD,
            "FieldTypes" =>
                MetadataSchema::MDFTYPE_DATE |
                MetadataSchema::MDFTYPE_NUMBER |
                MetadataSchema::MDFTYPE_TEXT |
                MetadataSchema::MDFTYPE_TIMESTAMP |
                MetadataSchema::MDFTYPE_URL,
            "Label" => "Sort Field",
            "Help" => "Field to use for sorting search results.",
        ];

        $this->CfgSetup["SortDescending"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Sort Direction",
            "Default" => true,
            "OnLabel" => "Descending",
            "OffLabel" => "Ascending",
            "Help" => "Search result sort order."
        ];

        $this->CfgSetup["DescriptionLength"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Description Length",
            "MinVal" => 100,
            "Default" => 300,
            "Help" => "Number of characters at which to truncate descriptions."
        ];

        $this->CfgSetup["FolderPublisherPrivs"] = [
            "Type" => FormUI::FTYPE_PRIVILEGES,
            "Label" => "Folder Publisher Privileges",
            "AllowMultiple" => true,
            "Help" => "Public folders owned by users with any of the selected privileges will "
                ."be listed for all users in the folder selection menus."
            ,
            "Default" => [PRIV_SYSADMIN],
        ];

        $this->CfgSetup["CacheTTL"] = [
            "Type" => FormUI::FTYPE_NUMBER,
            "Label" => "Cache Lifetime",
            "Units" => "minutes",
            "MinVal" => 0,
            "Default" => 240,
            "Help" => "How long internal caches will retain data."
        ];

        $this->CfgSetup["EnableCache"] = [
            "Type" => FormUI::FTYPE_FLAG,
            "Label" => "Enable Cache",
            "Default" => true,
            "Help" => "Use internal caches for HTML and search results."
                ." (Useful to disable on development sites.)"
        ];
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return null|string NULL if installation succeeded, otherwise a string
     *       containing an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        # if we don't yet have a keypair, create one
        #   (one may have been migrated from the LTIDeepLinking plugin)
        if ($this->getConfigSetting("PublicKey") === null) {
            # generate a keypair
            $Keypair = openssl_pkey_new([
                "digest_alg" => "sha512",
                "private_key_bits" => 4096,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($Keypair === false) {
                return "Unable to create OpenSSL kyepair.";
            }

            # extract and save the private key
            openssl_pkey_export($Keypair, $PrivateKey);
            $this->setConfigSetting("PrivateKey", $PrivateKey);

            # extract and save the public key
            $KeyDetails = openssl_pkey_get_details($Keypair);
            if ($KeyDetails === false) {
                return "Unable to extract public key from keypair";
            }

            $this->setConfigSetting("PublicKey", $KeyDetails["key"]);
        }

        return $this->createMissingTables($this->SqlTables);
    }

    /**
     * Perform any work needed when the plugin is uninstalled.
     * @return null|string NULL if uninstall succeeded, otherwise a string
     *       containing an error message indicating why uninstall failed.
     */
    public function uninstall(): ?string
    {
        return $this->dropTables($this->SqlTables);
    }

    /**
     * Startup initialization for plugin.
     * @return NULL if initialization was successful, otherwise a string
     *       containing an error message indicating why initialization failed.
     */
    public function initialize(): ?string
    {
        $AF = ApplicationFramework::getInstance();

        $UrlMap = [
            "lti/jwks" => "P_EduLink_JWKS",
            "lti/launch" => "P_EduLink_Launch",
            "lti/login" => "P_EduLink_LTILogin",
            "lti/select_folder" => "P_EduLink_SelectFolder",
            "lti/select_publisher" => "P_EduLink_SelectPublisher",
            "lti/home" => "P_EduLink_LTIHome",
            "lti/view_folder" => "P_EduLink_ViewFolder",
        ];

        foreach ($UrlMap as $CleanUrl => $Page) {
            $AF->addSimpleCleanUrl($CleanUrl, $Page);
        }

        $MetricsRecorder = MetricsRecorder::getInstance();

        $EventTypes = [
            "ViewRecord",
            "SelectRecord",
            "SearchRecords",
            "ListPublisherFolders",
            "ListFolderContents",
        ];
        foreach ($EventTypes as $EventType) {
            $MetricsRecorder->registerEventType(
                $this->Name,
                $EventType
            );
        }

        $this->addAdminMenuEntry(
            "ListRegistrations",
            "Learning Tools Interoperability (LTI) Registrations",
            [ PRIV_SYSADMIN, PRIV_COLLECTIONADMIN ]
        );

        return null;
    }

    /**
     * Hook the events into the application framework.
     * @return array Events to be hooked into the application framework.
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_FIELD_VIEW_PERMISSION_CHECK" => "fieldViewCheck",
        ];
    }


    # ---- HOOKED METHODS ----------------------------------------------------

    /**
     * Field view permission check.
     * @param MetadataField $Field that we're checking if the user can view.
     * @param Record|null $Record being checked.
     * @param User $User to check permissions for.
     * @param bool $CanView if the user can view this field.
     * @return array Parameters for next event in the chain.
     */
    public function fieldViewCheck(
        MetadataField $Field,
        ?Record $Record,
        User $User,
        bool $CanView
    ): array {
        static $PageName = false;

        # nothing to do when not checking a record or when record is not from
        # the Resource schema
        if ($Record === null
            || $Record->getSchemaId() != MetadataSchema::SCHEMAID_DEFAULT) {
            return ["CanView" => $CanView];
        }

        # get page name if we don't already have it
        if ($PageName === false) {
            $PageName = ApplicationFramework::getInstance()->getPageName();
        }

        # if we're on DownloadFile, allow any user to view
        # Preferred File Field to support embedding these files
        if ($PageName == "DownloadFile"
            && $Field->id() == $this->getConfigSetting("PreferredFileField")) {
            $CanView = true;
        }

        return ["CanView" => $CanView];
    }

    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Get footer text for the pop-up window displayed in LMSes.
     * @return string Footer text.
     */
    public function getFooterText() : string
    {
        $AdminEmail = $this->getConfigSetting("AdminEmail") ?? "";
        $ServiceName = $this->getConfigSetting("ServiceName") ?? "";
        $FooterText = $this->getConfigSetting("FooterText") ?? "";

        $Replacements = [
            "X-ADMINEMAIL-X" => $AdminEmail,
            "X-SERVICENAME-X" => $ServiceName,
        ];

        $FooterText = str_replace(
            array_keys($Replacements),
            array_values($Replacements),
            $FooterText
        );

        return $FooterText;
    }

    /**
     * Get the list of folders that should be displayed to all users.
     * @return array Folder IDs.
     */
    public function getFolderList() : array
    {
        $Privs = $this->getConfigSetting("FolderPublisherPrivs")->getPrivileges();
        if (count($Privs) == 0) {
            return [];
        }

        $UserIds = array_keys(
            (new UserFactory())->getUsersWithPrivileges($Privs)
        );
        if (count($UserIds) == 0) {
            return [];
        }

        $FolderIds = FolderFactory::getSharedFoldersOwnedByUsers(
            $UserIds
        );

        $FolderIds = $this->filterOutFoldersWithNoUsableItems(
            $FolderIds
        );

        return $FolderIds;
    }

    /**
     * Check if a given Url can be embedded in an iframe.
     * @param string $Url Url to check.
     * @return bool TRUE for embeddable URLs, FALSE otherwise.
     */
    public function canEmbedUrl(string $Url) : bool
    {
        $DB = new Database();

        $DB->query(
            "DELETE FROM Edulink_CanEmbedUrl"
            ." WHERE CheckedAt < (NOW() - INTERVAL 7 DAY)"
        );

        $Result = $DB->queryValue(
            "SELECT CanEmbed FROM EduLink_CanEmbedUrl"
            ." WHERE Url='".addslashes($Url)."'",
            "CanEmbed"
        );

        if ($Result !== null) {
            return (bool)$Result;
        }

        $Result = $this->checkEmbeddingHttpHeaders($Url);

        $DB->query(
            "INSERT INTO EduLink_CanEmbedUrl (Url, CanEmbed, CheckedAt)"
            ." VALUES ('".addslashes($Url)."',".intval($Result).",NOW())"
        );

        return $Result;
    }

    /**
     * Filter a list of records to remove those without a URL or associated file
     *     that can be embedded in an LMS.
     * @param array $RecordIds List of records to filter.
     * @return array Subset of $Records with URLs / Files suitable for embedding.
     */
    public function filterOutRecordsWithUnusableUrls(array $RecordIds) : array
    {
        $DB = new Database();
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);
        $UrlChecker = UrlChecker::getInstance();

        $FileFieldIds = [
            $Schema->getFieldIdByMappedName("File"),
        ];

        $PrefFileFieldId = $this->getConfigSetting("PreferredFileField");
        if (!is_null($PrefFileFieldId) && strlen($PrefFileFieldId) > 0) {
            $FileFieldIds[] = $PrefFileFieldId;
        }

        # get the list of records that have attached files
        $DB->query(
            "SELECT DISTINCT RecordId FROM Files"
                ." WHERE FieldId IN (".implode(",", $FileFieldIds).")"
                ." AND RecordId IN (".implode(",", $RecordIds).")"
        );
        $RecsWithFiles = $DB->fetchColumn("RecordId");

        # then generate the list of records where we have no attached file and will
        # need to fall back to a URL
        $RecsWithoutFiles = array_diff($RecordIds, $RecsWithFiles);

        if (count($RecsWithoutFiles) > 0) {
            $Urls = [];

            $PrefUrlFieldId = $this->getConfigSetting("PreferredUrlField");
            if (!is_null($PrefUrlFieldId) && strlen($PrefUrlFieldId) > 0) {
                $DBColName = $Schema->getField($PrefUrlFieldId)->dBFieldName();
                $DB->query(
                    "SELECT RecordId, ".$DBColName." AS Url FROM Records"
                        ." WHERE RecordId IN (".implode(",", $RecsWithoutFiles).")"
                        ." AND ".$DBColName." IS NOT NULL"
                        ." AND LENGTH(".$DBColName.") > 0"
                );
                $Urls += $DB->fetchColumn("Url", "RecordId");
            }

            # get the list of URLs
            $DBColName = $Schema->getFieldByMappedName("Url")->dBFieldName();
            $DB->query(
                "SELECT RecordId, ".$DBColName." AS Url FROM Records "
                    ."WHERE RecordId IN (".implode(",", $RecsWithoutFiles).")"
                    ." AND ".$DBColName." IS NOT NULL"
                    ." AND LENGTH(".$DBColName.") > 0"
            );
            $Urls += $DB->fetchColumn("Url", "RecordId");

            # iterate over the URLs
            $Records = array_fill_keys($RecordIds, true);
            foreach ($Urls as $RecordId => $Url) {
                # exclude if UrlChecker has recorded failures for this URL
                $UrlStatus = $UrlChecker->getHttpStatusCodeForUrl($Url);
                if ($UrlStatus != 0) {
                    unset($Records[$RecordId]);
                    continue;
                }

                # exclude if this is an http url that cannot be transparently upgraded
                # to https
                if (strpos($Url, "http://") === 0 &&
                    !$UrlChecker->checkIfTransparentHttpsUpgradeWorksForUrl($Url)) {
                    unset($Records[$RecordId]);
                }
            }
            $RecordIds = array_keys($Records);
        }

        return $RecordIds;
    }


    /**
     * Filter a list of folders to remove those with no items suitable for
     *     embedding in an LMS in them.
     * @param array $FolderIds List of folders.
     * @return array Folders that contain public items.
     */
    public function filterOutFoldersWithNoUsableItems(array $FolderIds)
    {
        $Result = [];
        $RFactory = new RecordFactory(MetadataSchema::SCHEMAID_DEFAULT);
        foreach ($FolderIds as $FolderId) {
            $Folder = new Folder($FolderId);

            # get the resources from the folder
            $ItemIdGroups = RecordFactory::buildMultiSchemaRecordList(
                $Folder->getItemIds()
            );
            $ItemIds = $ItemIdGroups[MetadataSchema::SCHEMAID_DEFAULT] ?? [];

            # filter out the non-public ones
            $ItemIds = $RFactory->filterOutUnviewableRecords(
                $ItemIds,
                User::getAnonymousUser()
            );
            if (count($ItemIds) == 0) {
                continue;
            }

            # filter out those that cannot be embedded
            $ItemIds = $this->filterOutRecordsWithUnusableUrls(
                $ItemIds
            );
            if (count($ItemIds) == 0) {
                continue;
            }

            # if any remain, then this folder is good to include
            $Result[] = $FolderId;
        }

        return $Result;
    }

    /**
     * Get the Public JSON Web Key Set for our site.
     * @retun string Public JSON Web Key Set.
     */
    public function getPublicJWKS() : string
    {
        $this->loadLtiLibraries();

        # (JWKS_Endpoint needs phpseclib\Crypt\RSA)
        $this->loadSecLib();

        $KeyId = $this->getKeyId();
        $PublicKey = $this->getConfigSetting("PublicKey");

        $Endpoint = \IMSGlobal\LTI\JWKS_Endpoint::new([
            $KeyId => $PublicKey,
        ]);

        ob_start();
        $Endpoint->output_jwks();
        $Result = ob_get_clean();

        return (string)$Result;
    }

    /**
     * Get key identifier corresponding to the public key of our tool.
     * @see getPublicJWKS()
     * @return string Key Id.
     */
    public function getKeyId() : string
    {
        static $KeyId = false;

        if ($KeyId === false) {
            $KeyId = md5($this->getConfigSetting("PublicKey"));
        }

        return $KeyId;
    }

    /**
     * Get the Client Id for our tool's Blackboard
     * registration. (Because of Blackboard's centralized registration
     * system, the BB Client Id is system-wide, unlike any of the
     * LMSes.)
     * @return ?string Blackboard Client Id or NULL when none is yet configured
     */
    public function getBlackboardClientId() : ?string
    {
        static $ClientId = false;

        if ($ClientId === false) {
            $Factory = new LMSRegistrationFactory();
            $ItemIds = $Factory->getItemIds(
                "Issuer='https://blackboard.com'"
            );

            if (count($ItemIds) == 0) {
                $ClientId = null;
            } else {
                $ClientId = (new LMSRegistration(reset($ItemIds)))
                    ->getClientId();
            }
        }

        return $ClientId;
    }

    /**
     * Get the LTI_Message_Launch object that represents a newly started LTI request.
     * @return \IMSGlobal\LTI\LTI_Message_Launch LTI object.
     */
    public function getNewLaunch()
    {
        $this->loadLtiLibraries();
        return \IMSGlobal\LTI\LTI_Message_Launch::new(
            new LTIDatabase(),
            new LTICache()
        );
    }

    /**
     * Get the LTI_Message_Launch object that represents a cached LTI request
     *   that we're continuing from a previous page.
     * @param string $LaunchId Opaque launch identifier provided by the LTI
     *   libraries.
     * @return \IMSGlobal\LTI\LTI_Message_Launch LTI object.
     */
    public function getCachedLaunch(string $LaunchId)
    {
        $this->loadLtiLibraries();

        $LTICache = new LTICache();
        $LTIDatabase = new LTIDatabase();

        if (is_null($LTICache->get_launch_data($LaunchId))) {
            return \IMSGlobal\LTI\LTI_Message_Launch::new(
                $LTIDatabase,
                $LTICache
            );
        }

        return \IMSGlobal\LTI\LTI_Message_Launch::from_cache(
            $LaunchId,
            new LTIDatabase(),
            new LTICache()
        );
    }

    /**
     * Get the LTI_OIDC_Login object that represents an OpenId Connect Login request.
     * @return \IMSGlobal\LTI\LTI_OIDC_Login OIDC object.
     */
    public function getLogin()
    {
        $this->loadLtiLibraries();
        return \IMSGlobal\LTI\LTI_OIDC_Login::new(
            new LTIDatabase()
        );
    }

    /**
     * Get the LMSRegistration corresponding to a provided LTI_Message_Launch.
     * @param \IMSGlobal\LTI\LTI_Message_Launch $Launch Launch to look up.
     * @return LMSRegistration Corresponding LMS registration.
     * @throws Exception if no registrations correspond to the given launch.
     * @throws Exception if multiple registrations correspond to the given launch.
     */
    public function getLmsRegistration(\IMSGlobal\LTI\LTI_Message_Launch $Launch)
    {
        $Data = @$Launch->get_launch_data();

        $Keys = [
            "ClientId" => "aud",
            "Issuer" => "iss",
        ];

        $Conditions = [];
        foreach ($Keys as $Col => $Key) {
            $Conditions[] = $Col."='".addslashes($Data[$Key])."'";
        }

        $Factory = new LMSRegistrationFactory();
        $ItemIds = $Factory->getItemIds(implode(" AND ", $Conditions));

        if (count($ItemIds) == 0) {
            throw new Exception(
                "No LMS Registration found for launch (should be impossible)."
            );
        }

        if (count($ItemIds) > 1) {
            throw new Exception(
                "Multiple LMS Registrations found for launch (should be impossible)."
            );
        }

        return $Factory->getItem($ItemIds[0]);
    }

    /**
     * Encode a list of Record Ids as an opaque string suitable for use as
     * part of a URL.
     * @param array $RecordList List of Record Ids.
     * @return string Encoded record list
     */
    public function encodeRecordList(
        array $RecordList
    ) : string {
        return "v1/".$this->packAndEncodeArray(
            self::PACK_INT_ARRAY,
            $RecordList
        );
    }

    /**
     * Decode a list of Record Ids from an opaque string as provided by
     * encodeRecordList().
     * @param string $Data.
     * @return array Record Ids.
     * @see encodeRecordList().
     */
    public function decodeRecordList(string $Data) : array
    {
        $B64Pattern = "[A-Za-z0-9_-]+=*";
        if (!preg_match("%^v[0-9]+/".$B64Pattern."$%", $Data)) {
            throw new Exception(
                "Invalid data provided for Record List."
            );
        }

        list($Version, $RecordIds) = explode("/", $Data);

        switch ($Version) {
            case "v1":
                return $this->unpackAndDecodeArray(
                    self::PACK_INT_ARRAY,
                    $RecordIds
                );

            default:
                throw new \Exception("Unsupported version");
        }
    }

    /**
     * Retrieve HTML for a given list of records.
     * @param array $RecordList Record IDs.
     * @return string|null Cached HTML or NULL if no value was available from cache.
     */
    public function getCachedRecordListHtml(
        array $RecordList
    ) : ?string {
        return $this->getFromHtmlCache("RecordList", $RecordList);
    }

    /**
     * Store HTML for a given list of records.
     * @param array $RecordList Record IDs.
     * @param string $Html HTML to store.
     */
    public function cacheRecordListHtml(
        array $RecordList,
        string $Html
    ) : void {
        $this->storeInHtmlCache("RecordList", $RecordList, $Html);
    }

    /**
     * Get HTML for the subject browse corresponding to a given list of records.
     * @param array $RecordList Record IDs.
     * @return string $Html Cached HTML or NULL if no value was available from cache.
     */
    public function getCachedSubjectListHtml(
        array $RecordList
    ) : ?string {
        return $this->getFromHtmlCache("SubjectList", $RecordList);
    }

    /**
     * Store HTML for the subject browse corresponding to a given list of records.
     * @param array $RecordList Record IDs.
     * @param string $Html HTML to store.
     */
    public function cacheSubjectListHtml(
        array $RecordList,
        string $Html
    ) : void {
        $this->storeInHtmlCache("SubjectList", $RecordList, $Html);
    }

    /**
     * Load search results from cache.
     * @param SearchParameterSet $Params Search Parameters.
     * @return array|null Cached search results or NULL if none available.
     */
    public function getCachedSearchResults(
        SearchParameterSet $Params
    ) : ?array {
        if ($this->getConfigSetting("EnableCache") === false) {
            return null;
        }

        $DB = new Database();
        $CacheTTL = $this->getConfigSetting("CacheTTL");

        $DB->query(
            "DELETE FROM Edulink_SearchResultsCache "
            ."WHERE CachedAt < (NOW() - INTERVAL ".$CacheTTL." MINUTE)"
        );

        $CacheKey = md5($Params->data());

        $Data = $DB->queryValue(
            "SELECT Content FROM EduLink_SearchResultsCache "
            ."WHERE Fingerprint='".$CacheKey."'",
            "Content"
        );

        return !is_null($Data) ? unserialize($Data) : null;
    }

    /**
     * Store search results in cache.
     * @param SearchParameterSet $Params Search parameters.
     * @param array $SearchResults Result value to cache.
     * @return void
     */
    public function cacheSearchResults(
        SearchParameterSet $Params,
        array $SearchResults
    ) : void {
        $CacheKey = md5($Params->data());
        $Data = serialize($SearchResults);

        $DB = new Database();

        $DB->query("LOCK TABLES EduLink_SearchResultsCache WRITE");
        $DB->query(
            "DELETE FROM EduLink_SearchResultsCache WHERE Fingerprint = '".$CacheKey."'"
        );

        $DB->query(
            "INSERT INTO EduLink_SearchResultsCache (Fingerprint, Content, CachedAt)"
            ." VALUES ('".$CacheKey."','".$DB->escapeString($Data)."', NOW())"
        );
        $DB->query("UNLOCK TABLES");
    }

    /**
     * Validation function for Client ID and Deployment ID parameters.
     * @param string $FieldName Name of field being validated.
     * @param string $FieldValue Value to validate.
     * @param array $AllFieldValues Containing values for all fields.
     * @return string|null NULL if field input is valid, otherwise error message.
     */
    public function validateIdParameter(
        string $FieldName,
        string $FieldValue,
        array $AllFieldValues
    ) : ?string {
        if (preg_match('%^[a-z0-9:/#\[\]@!$\'()*+,;._~-]*$%i', $FieldValue)) {
            return null;
        }

        return "Illegal characters in ".$FieldName."."
            ." Value must be allowed in a URL query parameter.";
    }

    /**
     * Get options for facet fields to display.
     * @return array Options keyed by field ID with field names as values.
     */
    public function getFacetFieldOptions() : array
    {
        $Schema = new MetadataSchema(MetadataSchema::SCHEMAID_DEFAULT);

        $Fields = $Schema->getFields(
            MetadataSchema::MDFTYPE_TREE |
            MetadataSchema::MDFTYPE_OPTION |
            MetadataSchema::MDFTYPE_CONTROLLEDNAME
        );

        $Options = [];
        foreach ($Fields as $Id => $Field) {
            if ($Field->enabled() && $Field->includeInFacetedSearch()) {
                $Options[$Id] = $Field->name();
            }
        }

        return $Options;
    }

    /**
     * Record metrics for record selection.
     * @param string $LaunchId Launch Id associated with the event.
     * @param array $RecordIds Record IDs associated with the event.
     */
    public function recordRecordSelection(
        string $LaunchId,
        array $RecordIds
    ): void {
        $this->recordEventForRecords(
            "SelectRecord",
            $LaunchId,
            $RecordIds
        );
    }

    /**
     * Record metrics for record viewing.
     * @param string $LaunchId Launch Id associated with the event.
     */
    public function recordRecordViewing(
        string $LaunchId,
        array $RecordIds
    ): void {
        $this->recordEventForRecords(
            "ViewRecord",
            $LaunchId,
            $RecordIds
        );
    }

    /**
     * Record metrics for when a user lists the collections from a publisher.
     * @param string $LaunchId Launch Id associated with the event.
     * @param int $PublisherId User ID of the publisher whose folders are
     *         displayed.
     */
    public function recordListPublisherFolders(
        string $LaunchId,
        int $PublisherId
    ): void {
        $this->recordEventForPageView(
            "ListPublisherFolders",
            $LaunchId,
            $PublisherId
        );
    }

    /**
     * Record metrics for when a user l-ts the contents of a folder.
     * @param string $LaunchId Launch Id associated with the event.
     * @param int $FolderId ID of the folder being displayed.
     */
    public function recordListFolderContents(
        string $LaunchId,
        int $FolderId
    ): void {
        $this->recordEventForPageView(
            "ListFolderContents",
            $LaunchId,
            $FolderId
        );
    }

    /**
     * Record metrics for when a user performs a search.
     * @param string $LaunchId Launch Id associated with the event.
     * @param SearchParameterSet $SearchParams Search that was performed.
     * @param int $Page Page of search results being viewed (included so that
     *         we can tell the difference between repeated searches for the
     *         same thing vs paging through search results).
     * @param int $NumResults Number of search results.
     */
    public function recordSearch(
        string $LaunchId,
        SearchParameterSet $SearchParams,
        int $Page,
        int $NumResults
    ): void {
        $MetricsRecorder = MetricsRecorder::getInstance();

        $PluginName = $this->getName();
        $UserId = User::getCurrentUser()->id();

        $SearchData = json_encode([
            "S" => $SearchParams->data(),
            "P" => $Page,
            "N" => $NumResults,
        ]);
        $MetricsRecorder->recordEvent(
            $PluginName,
            "SearchRecords",
            $SearchData,
            $LaunchId,
            $UserId
        );
    }

    # ---- PLUGIN COMMANDS ---------------------------------------------------

    /**
     * Implement the 'clearCaches' plugin command.
     * @param array $Args Arguments passed when the command was invoked.
     */
    public function commandClearCaches(array $Args) : void
    {
        $DB = new Database();
        $DB->query(
            "DELETE FROM EduLink_SearchResultsCache"
        );
        $DB->query(
            "DELETE FROM EduLink_HtmlCache"
        );
        print "Caches for EduLink cleared.\n";
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Get a cache key to identify a list of records.
     * @param string $CacheType Cache entry type.
     * @param array $RecordList Record IDs.
     * @return string Cache key.
     */
    private function getCacheKey(
        string $CacheType,
        array $RecordList
    ) : string {
        sort($RecordList);
        return md5($CacheType."/".implode("-", $RecordList));
    }

    /**
    * Get a blob of HTML from the cache that pertains to a specified set of records
    * @param string $CacheType Cache entry type.
    * @param array $RecordList Record IDs.
    * @return string Cache key.
    */
    private function getFromHtmlCache(
        string $CacheType,
        array $RecordList
    ) : ?string {
        if ($this->getConfigSetting("EnableCache") === false) {
            return null;
        }

        $DB = new Database();
        $CacheTTL = $this->getConfigSetting("CacheTTL");

        $DB->query(
            "DELETE FROM EduLink_HtmlCache "
                ."WHERE CachedAt < (NOW() - INTERVAL ".$CacheTTL." MINUTE)"
        );

        $CacheKey = $this->getCacheKey($CacheType, $RecordList);
        return $DB->queryValue(
            "SELECT Content FROM EduLink_HtmlCache "
                ."WHERE Fingerprint='".$CacheKey."'",
            "Content"
        );
    }

    /**
     * Store a blob of HTML in cache that pertains to a specified set of records.
     * @param string $CacheType Cache entry type.
     * @param array $RecordList Record IDs.
     * @param string $Html HTML to store.
     */
    private function storeInHtmlCache(
        string $CacheType,
        array $RecordList,
        string $Html
    ) : void {
        $CacheKey = $this->getCacheKey($CacheType, $RecordList);

        $DB = new Database();
        $DB->query("LOCK TABLES EduLink_HtmlCache WRITE");
        $DB->query(
            "DELETE FROM EduLink_HtmlCache WHERE Fingerprint = '".$CacheKey."'"
        );
        $DB->query(
            "INSERT INTO EduLink_HtmlCache (Fingerprint, Content, CachedAt)"
            ." VALUES ('".$CacheKey."','".$DB->escapeString($Html)."', NOW())"
        );
        $DB->query("UNLOCK TABLES");
    }

    /**
     * Encode an array as an opaque string suitable for incorporating into a URL.
     * @param string $Format Description of the format of the data in the
     *   array suitable for the `pack()` function. An array of ints should use
     *   self::PACK_INT_ARRAY. An array of bytes should use
     *   self::PACK_CHAR_ARRAY. See the PHP docs for `pack()` for other
     *   options.
     * @param array $Data Data to encode.
     * @return string Encoded data.
     */
    private function packAndEncodeArray(string $Format, array $Data) : string
    {
        # build an array of args for pack(), which expects to be called with
        # Format as the first argument and the data provided in the remaining
        # arguments with one datum per argument
        # (i.e., pack("XX", 1, 2, 3) rather than pack("XX", [1, 2, 3]) )
        $Args = $Data;
        array_unshift($Args, $Format);

        $Result = call_user_func_array('pack', $Args);

        $Result = gzencode($Result, 9);
        if ($Result === false) {
            throw new Exception("Failed to compress data.");
        }

        $Result = base64_encode($Result);

        $Result = str_replace(["+", "/"], ["-", "_"], $Result);

        return $Result;
    }

    /**
     * Take an opaque string provided in a URL and decoded it into an array of
     * data.
     * @param string $Format Description of the format in the array. Must match the format
     *   used to encode the data with `packAndEncodeArray()`.
     * @param string $Data Data to decode.
     * @return array Decoded data.
     */
    private function unpackAndDecodeArray(string $Format, string $Data) : array
    {
        $Result = str_replace(["-", "_"], ["+", "/"], $Data);

        $Result = base64_decode($Result);

        $Result = gzdecode($Result);
        if ($Result === false) {
            throw new Exception("Failed to decompress data.");
        }

        $Result = unpack($Format, $Result);
        if ($Result === false) {
            throw new Exception("Failed to unpack data.");
        }

        return $Result;
    }

    /**
     * Issue an HTTP HEAD request for a given url and check if the response
     * includes headers that will enable browsers to embed the page.
     * @param string $Url Url to check.
     * @return bool TRUE for pages that can be embedded, FALSE otherwise.
     * phpcs:disable
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/frame-ancestors
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/X-Frame-Options
     * phpcs:enable
     */
    private function checkEmbeddingHttpHeaders($Url): bool
    {
        $Context = curl_init();

        curl_setopt_array($Context, [
            CURLOPT_URL => $Url,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $Result = curl_exec($Context);

        # on curl errors, assume embedding will not succeed
        if ($Result === false) {
            return false;
        }

        # (placate phpstan)
        if ($Result === true) {
            throw new Exception(
                "Curl returned no data despite setting CURLOPT_RETURNTRANSFER "
                ."(should be impossible)."
            );
        }

        $Lines = preg_split("%\r\n%", $Result);
        if ($Lines === false) {
            return false;
        }

        # if response was not 200 OK, assume embedding will not succeed
        if (!preg_match("%^http/[0-9.]+ 200 %i", $Lines[0])) {
            return false;
        }

        # parse response headers
        foreach ($Lines as $Line) {
            # check for Content-Security-Policy
            if (preg_match("%^content-security-policy: (.*)$%i", $Line, $Matches)) {
                $Values = array_map('trim', explode(";", $Matches[1]));
                foreach ($Values as $Value) {
                    if ($Value == "frame-ancestors *") {
                        return true;
                    }
                }

                return false;
            }

            # check for X-Frame-Options
            if (preg_match("%^x-frame-options: (.*)$%i", $Line)) {
                # all possible values deny embedding
                print "frame options";
                return false;
            }
        }

        # otherwise, we found no embedding restrictions
        return true;
    }

    /**
     * Record metrics data for a specified type of event and list of records.
     * @param string $EventName Event to record.
     * @param string $LaunchId Launch Id that triggered the event. (Launch
     *         data can be retrieved with LTICache::get_launch_data($LaunchId).
     *         Details of the data contained can be found in the LTI Specs,
     *         e.g. https://www.imsglobal.org/spec/lti-dl/v2p0#deep-linking-response-example)
     * @param array $RecordIds Records associated with the event.
     */
    private function recordEventForRecords(
        string $EventName,
        string $LaunchId,
        array $RecordIds
    ): void {
        $MetricsRecorder = MetricsRecorder::getInstance();

        $PluginName = $this->getName();
        $UserId = User::getCurrentUser()->id();

        foreach ($RecordIds as $RecordId) {
            $MetricsRecorder->recordEvent(
                $PluginName,
                $EventName,
                $RecordId,
                $LaunchId,
                $UserId
            );
        }
    }

    /**
     * Record metrics data for a page view
     * @param string $EventName Event to record.
     * @param string $LaunchId Launch Id that triggered the event.
     * @param ?int $ItemId Item being viewed on this page or NULL when there
     *         is no specific item.
     */
    private function recordEventForPageView(
        string $EventName,
        string $LaunchId,
        ?int $ItemId
    ): void {
        $MetricsRecorder = MetricsRecorder::getInstance();

        $PluginName = $this->getName();
        $UserId = User::getCurrentUser()->id();

        $MetricsRecorder->recordEvent(
            $PluginName,
            $EventName,
            $ItemId,
            $LaunchId,
            $UserId
        );
    }

    /**
     * Load additional libraries needed for handling LTI messages.
     * @return void
     */
    private function loadLtiLibraries(): void
    {
        $LibLoaders = [
            "php-jwt/jwt.php",
            "lti/lti.php",
        ];
        foreach ($LibLoaders as $Loader) {
            require_once(__DIR__."/lib/".$Loader);
        }
    }

    /**
     * Load additional libraries for phpseclib.
     * @return void
     */
    private function loadSecLib(): void
    {
        $SecLibFiles = [
            "Math/BigInteger.php",
            "Crypt/Hash.php",
            "Crypt/RSA.php",
        ];

        foreach ($SecLibFiles as $File) {
            require_once(
                __DIR__."/lib/phpseclib/phpseclib/".$File
            );
        }
    }

    private $SqlTables = [
        "Registrations" => "CREATE TABLE EduLink_Registrations (
            Id INT NOT NULL AUTO_INCREMENT,
            LMS TEXT,
            ContactEmail TEXT,
            Issuer TEXT,
            ClientId TEXT,
            AuthLoginUrl TEXT,
            AuthTokenUrl TEXT,
            KeySetUrl TEXT,
            SearchParameters BLOB,
            INDEX Index_Id (Id),
            INDEX Index_Is (Issuer(32))
        )",
        "Launches" => "CREATE TABLE EduLink_Launches (
            Id INT NOT NULL AUTO_INCREMENT,
            CacheKey TEXT,
            Value MEDIUMBLOB,
            CachedAt TIMESTAMP,
            INDEX Index_I (Id),
            INDEX Index_CA (CachedAt),
            UNIQUE UIndex_K (CacheKey(32))
        )",
        "Nonces" => "CREATE TABLE EduLink_Nonces (
            Id INT NOT NULL AUTO_INCREMENT,
            Nonce TEXT,
            SeenAt TIMESTAMP,
            INDEX Index_I (Id),
            INDEX Index_SA (SeenAt),
            UNIQUE UIndex_N (Nonce(32))
        )",
        "CanEmbedUrl" => "CREATE TABLE EduLink_CanEmbedUrl (
            Id INT NOT NULL AUTO_INCREMENT,
            Url TEXT,
            CanEmbed INT,
            CheckedAt TIMESTAMP,
            INDEX Index_I (Id),
            INDEX Index_CA (CheckedAt),
            UNIQUE UIndex_U (Url(32))
        )",
        "HtmlCache" => "CREATE TABLE EduLink_HtmlCache (
            Id INT NOT NULL AUTO_INCREMENT,
            Fingerprint TEXT,
            Content MEDIUMBLOB,
            CachedAt TIMESTAMP,
            INDEX Index_I (Id),
            INDEX Index_CA (CachedAt),
            UNIQUE UIndex_F (Fingerprint(32))
        )",
        "SearchResultsCache" => "CREATE TABLE EduLink_SearchResultsCache (
            Id INT NOT NULL AUTO_INCREMENT,
            Fingerprint TEXT,
            Content MEDIUMBLOB,
            CachedAt TIMESTAMP,
            INDEX Index_I (Id),
            INDEX Index_CA (CachedAt),
            UNIQUE UIndex_F (Fingerprint(32))
        )",
    ];

    # format strings for pack
    private const PACK_INT_ARRAY = "L*";
}
