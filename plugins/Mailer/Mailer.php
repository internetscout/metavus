<?PHP
#
#   FILE:  Mailer.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2011-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use InvalidArgumentException;
use Metavus\FormUI;
use Metavus\InterfaceConfiguration;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Plugins\Mailer\StoredEmail;
use Metavus\Plugins\Rules\Rule;
use Metavus\Plugins\Rules\RuleFactory;
use Metavus\Plugins\SocialMedia;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;
use ScoutLib\Email;
use ScoutLib\PluginManager;

/**
 * Plugin for providing a common mechanism for sending emails to users
 * using templates.
 */
class Mailer extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

    /**
     * Set the plugin attributes.  At minimum this method MUST set $this->Name
     * and $this->Version.  This is called when the plugin is initially loaded.
     */
    public function register(): void
    {
        $this->Name = "Mailer";
        $this->Version = "1.4.0";
        $this->Description = "Provides support for generating and emaiing"
                ." messages containing record metadata to users based on"
                ." editable templates.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = ["MetavusCore" => "1.2.0"];
        $this->EnabledByDefault = true;

        $this->CfgSetup["BaseUrl"] = [
            "Type" => FormUI::FTYPE_TEXT,
            "Label" => "Base URL",
            "Help" => "This value overrides any automatically-determined"
                ." value for the X-BASEURL-X template keyword.",
        ];

        $this->CfgSetup["EmailTaskPriority"] = [
            "Type" => FormUI::FTYPE_OPTION,
            "Label" => "E-mail Task Priority",
            "Help" => "Priority of the e-mail sending tasks when using the task queue.",
            "AllowMultiple" => false,
            "Default" => ApplicationFramework::PRIORITY_LOW,
            "Options" => [
                ApplicationFramework::PRIORITY_BACKGROUND => "Background",
                ApplicationFramework::PRIORITY_LOW => "Low",
                ApplicationFramework::PRIORITY_MEDIUM => "Medium",
                ApplicationFramework::PRIORITY_HIGH => "High"
            ]
        ];

        $this->addAdminMenuEntry(
            "EditMessageTemplates",
            "Edit Email Templates",
            [ PRIV_COLLECTIONADMIN, PRIV_SYSADMIN ]
        );
        $this->addAdminMenuEntry(
            "ListQueuedEmail",
            "Mailer Approval Queue",
            [ PRIV_COLLECTIONADMIN, PRIV_SYSADMIN ]
        );
    }

    /**
     * Perform any work needed when the plugin is first installed (for example,
     * creating database tables).
     * @return string|null NULL if installation succeeded, otherwise a string containing
     *       an error message indicating why installation failed.
     */
    public function install(): ?string
    {
        $this->setConfigSetting("Templates", []);
        $Result = $this->createTables(self::SQL_TABLES);
        if ($Result !== null) {
            return $Result;
        }
        $this->addDefaultTemplates();
        return null;
    }

    /**
     * Load default templates for standard site emails
     */
    public function addDefaultTemplates(): void
    {
        $MailerTemplates = $this->getTemplateList();

        $TemplatesNeeded = [
            "Email Change Default" => [
                "Body" => "Someone (presumably you) has requested that the email "
                ."address registered at X-PORTALNAME-X ".
                "for the account X-USERNAME-X be changed from X-EMAILADDRESS-X "
                ."to X-NEWEMAILADDRESS-X.\n\n".
                "To confirm this change, please click on this link:\n\n".
                "   X-CHANGEURL-X\n\n".
                "If that link doesn't work you can also go to this address:\n\n".
                "   X-MANUALCHANGEURL-X\n\n".
                "And enter your user name and confirmation code:\n\n".
                "   Name: X-USERNAME-X\n\n".
                "   Code: X-CHANGECODE-X\n\n".
                "If you don't want to change your email, just ignore this ".
                "message and nothing will be done.\n\n".
                "For reference, the password change request came from address X-IPADDRESS-X ".
                "and this e-mail was sent to X-NEWEMAILADDRESS-X",
                "Subject" => "X-PORTALNAME-X mail address change request",
                "FunctionName" => "EmailChangeTemplateId"
            ],
            "Account Activation Default" => [
                "Body" => "Thank you for signing up on X-PORTALNAME-X!\n\n".
                "To activate your new login, please click on the link below:\n\n".
                "   X-ACTIVATIONURL-X\n\n".
                "If the link doesn't work you can also go to this address:\n\n".
                "   X-MANUALACTIVATIONURL-X\n\n".
                "And enter your user name and activation code:\n\n".
                "   Name: X-USERNAME-X\n\n".
                "   Code: X-ACTIVATIONCODE-X\n\n".
                "We're looking forward to seeing you online!",
                "Subject" => "New Login for X-PORTALNAME-X (X-USERNAME-X)",
                "FunctionName" => "ActivateAccountTemplateId"
            ],
            "Password Change Default" => [
                "Body" => "Someone (presumably you) has asked to change your password on ".
                "X-PORTALNAME-X for the account X-USERNAME-X.\n\n".
                "To change your password, please click on this link:\n\n".
                "   X-RESETURL-X\n\n".
                "If that link doesn't work you can also go to this address:\n\n".
                "   X-MANUALRESETURL-X\n\n".
                "And enter your user name and password reset code:\n\n".
                "   Name: X-USERNAME-X\n\n".
                "   Code: X-RESETCODE-X\n\n".
                "Your password will remain the same until changed via one of the above links.\n\n".
                "For reference, the password change request came from address X-IPADDRESS-X ".
                "and this e-mail was sent to X-EMAILADDRESS-X.",
                "Subject" => "X-PORTALNAME-X Password Change",
                "FunctionName" => "PasswordChangeTemplateId"
            ]
        ];

        foreach ($TemplatesNeeded as $Name => $Template) {
            if (!in_array($Name, $MailerTemplates)) {
                $BodyWithHtmlBreaks = nl2br($Template["Body"]);
                foreach ([
                    "X-RESETURL-X",
                    "X-MANUALRESETURL-X",
                    "X-CHANGEURL-X",
                    "X-MANUALCHANGEURL-X"
                ] as $UrlText) {
                    $BodyWithHtmlBreaks = str_replace(
                        $UrlText,
                        "<a href='" . $UrlText . "'>" . $UrlText . "</a>",
                        $BodyWithHtmlBreaks
                    );
                }
                $Function = $Template["FunctionName"];
                $TemplateId = $this->addTemplate(
                    $Name,
                    "X-PORTALNAME-X <X-ADMINEMAIL-X>",
                    $Template["Subject"],
                    $BodyWithHtmlBreaks,
                    "",
                    $Template["Body"],
                    ""
                );
                InterfaceConfiguration::getInstance()->setInt($Function, $TemplateId);
            }
        }
    }

    /**
     * Declare events.
     *
     * Mailer_EVENT_IS_TEMPLATE_IN_USE -- CHAIN event with parameters
     *   (int $TemplateId, array $TemplateUsers). Plugins making use of
     *   a given $TemplateId are expected to append a string describing
     *   where they use a template, ideally one including their
     *   $this->Name.
     *
     * Mailer_EVENT_MODIFY_KEYWORD_REPLACEMENTS -- CHAIN event with
     *   parameters (int $TemplateId, array $Resources, User|string
     *   $Recipient, array $Replacements). Plugins wishing to override
     *   or extend the replacements used when $TemplateId generates a
     *   message for $Recipient should add to the values in
     *   $Replacements. Array keys give the keyword (without the X- -X
     *   prefix/suffix) and values give the desired replacement.
     *
     * Mailer_EVENT_BEFORE_MESSAGE_SEND -- CHAIN event with parameters
     *  (Email $Message, array $Resources, int $TemplateId) that is signaled
     *  immediately before $Message is sent. Methods of $Message can be used
     *  to make any necessary modifications to $Message prior to sending it.
     *
     * @return array an array of the events this plugin provides.
     */
    public function declareEvents(): array
    {
        return [
            "Mailer_EVENT_IS_TEMPLATE_IN_USE" =>
                ApplicationFramework::EVENTTYPE_CHAIN,
            "Mailer_EVENT_MODIFY_KEYWORD_REPLACEMENTS" =>
                ApplicationFramework::EVENTTYPE_CHAIN,
            "Mailer_EVENT_BEFORE_MESSAGE_SEND" =>
                ApplicationFramework::EVENTTYPE_CHAIN,
        ];
    }


    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Retrieve list of currently available templates, with template IDs for
     * the indexes and template names for the values.
     * @return array Template names with template IDs for the index.
     */
    public function getTemplateList(): array
    {
        $Templates = $this->getConfigSetting("Templates");
        $TemplateList = [];
        if (count($Templates) > 0) {
            foreach ($Templates as $Id => $Template) {
                $TemplateList[$Id] = $Template["Name"];
            }
        }
        return $TemplateList;
    }

    /**
     * Add or update a template according to passed parameters.
     * the indexes and template names for the values.
     * @param string $Name The name of the template.
     * @param string $From The from parameter for the template
     * @param string $Subject The subject of the email to send.
     * @param string $Body The body of the email to send.
     * @param string $ItemBody The body of each individual iterable to send.
     * @param string $PlainTextBody Non-HTML version of the body
     * @param string $PlainTextItemBody Non-HTML version of the item body
     * @param string $Headers Any additional headers to send.  (OPTIONAL)
     * @param bool $CollapseBodyMargins Whether or not the margins should
     *      be collapsed.  (OPTIONAL, defaults to FALSE)
     * @param bool $EmailPerResource TRUE to send one message per resource,
     *      rather than a single message that contains a list of
     *      resources.  (OPTIONAL, defaults to FALSE)
     * @return int of template id.
     */
    public function addTemplate(
        $Name,
        $From,
        $Subject,
        $Body,
        $ItemBody,
        $PlainTextBody,
        $PlainTextItemBody,
        $Headers = "",
        $CollapseBodyMargins = false,
        $EmailPerResource = false
    ): int {
        $G_Templates = $this->getConfigSetting("Templates");

        # get next template ID
        if (($G_Templates === null) || (count($G_Templates) == 0)) {
            $TemplateId = 0;
        } else {
            $TemplateId = (int)max(array_keys($G_Templates)) + 1;
        }
        $G_Templates[$TemplateId] = [];
        $G_Templates[$TemplateId]["Name"] = $Name;
        $G_Templates[$TemplateId]["From"] = $From;
        $G_Templates[$TemplateId]["Subject"] = $Subject;
        $G_Templates[$TemplateId]["Body"] = $Body;
        $G_Templates[$TemplateId]["ItemBody"] = $ItemBody;
        $G_Templates[$TemplateId]["PlainTextBody"] = $PlainTextBody;
        $G_Templates[$TemplateId]["PlainTextItemBody"] = $PlainTextItemBody;
        $G_Templates[$TemplateId]["Headers"] = $Headers;
        $G_Templates[$TemplateId]["CollapseBodyMargins"] = $CollapseBodyMargins;
        $G_Templates[$TemplateId]["EmailPerResource"] = $EmailPerResource;

        $this->setConfigSetting("Templates", $G_Templates);
        return $TemplateId;
    }

    /**
     * Retrieve a Mailer template.
     * @param int $Id Desired template ID.
     * @return array Template information with keys "Name", "From", "Subject",
     *   "Body", "ItemBody", "PlainTextBody", "PlainTextItemBody", "Headers",
     *   "CollapseBodyMargins", and "EmailPerResource"
     * @throws InvalidArgumentException if invalid template ID is provided.
     */
    public function getTemplate(int $Id): array
    {
        $Templates = $this->getConfigSetting("Templates");
        if (!isset($Templates[$Id])) {
            throw new InvalidArgumentException(
                "Invalid Template ID :".$Id
            );
        }

        return $Templates[$Id];
    }

    /**
     * Update a Mailer template.
     * @param int $Id Desired template ID.
     * @param array $TemplateData Updated template data. May contain a subset of the keys
     *   returned by getTemplate().
     * @throws InvalidArgumentException if invalid template ID is provided.
     * @throws InvalidArgumentException if an invalid key is provided in TemplateData.
     */
    public function updateTemplate(int $Id, array $TemplateData): void
    {
        $Templates = $this->getConfigSetting("Templates");

        # throw exception if invalid ID provided
        if (!isset($Templates[$Id])) {
            throw new InvalidArgumentException(
                "Invalid Template ID :".$Id
            );
        }

        $InvalidKeys = array_diff(
            array_keys($TemplateData),
            array_keys($Templates[$Id])
        );

        # throw exception if invalid data provided
        if (count($InvalidKeys)) {
            throw new InvalidArgumentException(
                "Invalid template settings provided: "
                .implode(", ", $InvalidKeys)
            );
        }

        # update data and save
        foreach ($TemplateData as $Key => $Val) {
            $Templates[$Id][$Key] = $Val;
        }
        $this->setConfigSetting("Templates", $Templates);
    }

    /**
     * Send email to specified recipients using specified template.
     * @param int $TemplateId ID of template to use in generating emails.
     * @param User|int|string|array $Recipients User object or ID or email address, or an array
     *      of any of these to use as email recipients.
     * @param Record|array|int $Resources Resource or Resource ID or array of
     *      Resources or array of Resource IDs to be referred to within email
     *      messages.  (OPTIONAL)
     * @param array $ExtraValues Array of additional values to swap into template,
     *      with value keywords (without the "X-" and "-X") for the index
     *      and values for the values.  This parameter can be used to
     *      override the builtin keywords.  (OPTIONAL)
     * @param bool $ConfirmBeforeSending TRUE if messages should be queued for
     *      confirmation by an admin prior to sending. (OPTIONAL, default FALSE)
     * @param string $InterfaceName Name of UI to use when sending the message
     *      (OPTIONAL, defaults to the current UI when not provided)
     * @return int Number of email messages sent.
     */
    public function sendEmail(
        $TemplateId,
        $Recipients,
        $Resources = [],
        $ExtraValues = null,
        $ConfirmBeforeSending = false,
        $InterfaceName = null
    ): int {
        $AF = ApplicationFramework::getInstance();

        # initialize count of emails sent
        $MessagesSent = 0;

        # convert incoming recipients to arrays (if not already)
        if (!is_array($Recipients)) {
            $Recipients = [ $Recipients ];
        }

        # convert incoming parameters to arrays (if not already)
        if (!is_array($Resources)) {
            $Resources = [ $Resources ];
        }

        # convert any resource IDs to resource objects
        if (count($Resources) && is_numeric(reset($Resources))) {
            $NewResources = [];
            foreach ($Resources as $Id) {
                $NewResources[$Id] = Record::getRecord($Id);
            }
            $Resources = $NewResources;
        }

        # retrieve appropriate template
        $Templates = $this->getConfigSetting("Templates");
        $Template = $Templates[$TemplateId];
        $TemplateName = $Template["Name"];

        # if this templates sends one email per resource and we have
        # more than one on our list, call ourselves recursively for each
        # single resource
        if ($Template["EmailPerResource"] && count($Resources) > 1) {
            foreach ($Resources as $Resource) {
                $MessagesSent += $this->sendEmail(
                    $TemplateId,
                    $Recipients,
                    $Resource,
                    $ExtraValues,
                    $ConfirmBeforeSending,
                    $InterfaceName
                );
            }

            return $MessagesSent;
        }

        # set up parameters for keyword replacement callback
        $this->KRItemBody = $Template["ItemBody"];
        $this->KRResources = $Resources;
        $this->KRInterfaceName = $InterfaceName;
        unset($this->KRCurrentResource);

        # for each recipient
        foreach ($Recipients as $Recipient) {
            $SignalResult = $AF->signalEvent(
                "Mailer_EVENT_MODIFY_KEYWORD_REPLACEMENTS",
                [
                    "TemplateId" => $TemplateId,
                    "Resources" => $Resources,
                    "Recipient" => $Recipient,
                    "Replacements" => $ExtraValues,
                ]
            );
            $this->KRExtraValues = $SignalResult["Replacements"];

            # convert recipient to User if User ID supplied
            if (is_numeric($Recipient)) {
                if (!User::itemExists((int)$Recipient)) {
                    $AF->logMessage(
                        ApplicationFramework::LOGLVL_ERROR,
                        "Mailer: Unable to send email to user with invalid ID "
                        .$Recipient
                        ." Using template \"".$TemplateName."\" ("
                        .$TemplateId.")."
                    );
                    continue;
                }

                $Recipient = new User($Recipient);
                if ($Recipient->hasPriv(PRIV_USERDISABLED)) {
                    $AF->logMessage(
                        ApplicationFramework::LOGLVL_ERROR,
                        "Mailer: Refusing to send email to disabled user "
                        .$Recipient->get("UserName")
                        ." (Id=".$Recipient->get("UserId").")"
                        ." Using template \"".$TemplateName."\" ("
                        .$TemplateId.")."
                    );
                    continue;
                }
            }

            # if recipient is User object
            if ($Recipient instanceof User) {
                # retrieve destination address from user
                $Address = trim($Recipient->get("EMail"));

                # set up per-user parameters for keyword replacement callback
                $this->KRUser = $Recipient;
            } else {
                # assume recipient is just destination address
                $Address = $Recipient;

                # clear per-user parameters for keyword replacement callback
                unset($this->KRUser);
            }

            # skip if there is no destination address
            if (strlen($Address) == 0) {
                $AF->logMessage(
                    ApplicationFramework::LOGLVL_ERROR,
                    "Mailer: Refusing to send email with no destination address. "
                    ." Using template \"".$TemplateName."\" ("
                    .$TemplateId.")."
                );
                continue;
            }

            # create and set up the message to send
            $Msg = $this->createEmailMessage($TemplateId, $Address);

            $Result = $AF->signalEvent(
                "Mailer_EVENT_BEFORE_MESSAGE_SEND",
                [
                    "Message" => $Msg,
                    "Resources" => $Resources,
                    "TemplateId" => $TemplateId,
                ]
            );
            $Msg = $Result["Message"];

            # send message to the user
            if ($ConfirmBeforeSending) {
                StoredEmail::create(
                    $Msg,
                    $Resources,
                    $TemplateId
                );
            } else {
                $MsgResult = $Msg->send();

                if (!$MsgResult) {
                    $StoredMsg = StoredEmail::create(
                        $Msg,
                        $Resources,
                        $TemplateId
                    );

                    $AF->logMessage(
                        ApplicationFramework::LOGLVL_ERROR,
                        "Mailer: Unable to send email to "
                        .implode(",", $Msg->to())
                        ." Using template \"".$TemplateName."\" ("
                        .$TemplateId.")."
                        ." Error was: ".$Msg->getErrorInfo()."."
                        ." Email queued with ID ".$StoredMsg->id()."."
                    );
                }
            }
            $MessagesSent++;
        }

        # report number of emails sent back to caller
        return $MessagesSent;
    }

    /**
     * Send email to specified recipients using specified template but use
     * background tasks instead of trying to send all to all of the
     * recipients at once.
     * @param int $TemplateId ID of template to use in generating emails.
     * @param mixed $Recipients User object or ID or email address, or an array
     *       of any of these to use as email recipients.
     * @param int|Record|array $Resources Resource or Resource ID or array of Resources or
     *       array of Resource IDs to be referred to within email messages.
     *       (OPTIONAL, defaults to NULL)
     * @param array $ExtraValues Array of additional values to swap into template,
     *       with value keywords (without the "X-" and "-X") for the index
     *       and values for the values.  This parameter can be used to
     *       override the builtin keywords.  (OPTIONAL)
     * @param bool $ConfirmBeforeSending TRUE if messages should be queued for confirmation
     *       by an admin prior to sending. (OPTIONAL, default FALSE)
     * @return int Number of email messages to be sent.
     */
    public function sendEmailUsingTasks(
        $TemplateId,
        $Recipients,
        $Resources = [],
        $ExtraValues = null,
        $ConfirmBeforeSending = false
    ): int {
        # retrieve appropriate template and its name
        $Templates = $this->getConfigSetting("Templates");

        # don't send e-mail if the template is invalid
        if (!isset($Templates[$TemplateId])) {
            return 0;
        }

        # convert incoming parameters to arrays if necessary
        if (!is_array($Recipients)) {
            $Recipients = [$Recipients];
        }

        if (!is_array($Resources)) {
            $Resources = [$Resources];
        }

        # load resource IDs if necessary because they're better for serializing
        # for the unique task
        if (count($Resources) && is_object(reset($Resources))) {
            $NewResources = [];
            foreach ($Resources as $Resource) {
                $NewResources[] = $Resource->id();
            }

            $Resources = $NewResources;
        }

        # get the name of the template for the task description
        $TemplateName = $Templates[$TemplateId]["Name"];

        # variable to track how many e-mails are to be sent
        $NumToBeSent = 0;

        # for each user
        foreach ($Recipients as $Recipient) {
            # skip invalid users
            if (is_numeric($Recipient)) {
                if (!User::itemExists((int)$Recipient)) {
                    continue;
                }
                $Recipient = new User($Recipient);
            }

            # build task description
            $RecipientEmail = ($Recipient instanceof User)
                    ? $Recipient->get("EMail") : $Recipient;
            $TaskDescription = "Send e-mail to \""
                    .$RecipientEmail."\" using the \""
                    .$TemplateName."\" template.";

            # use user IDs rather than user objects because they are
            #       smaller to serialize when queueing tasks
            if (is_object($Recipient) && method_exists($Recipient, "id")) {
                $Recipient = $Recipient->id();
            }

            # queue the unique task
            ApplicationFramework::getInstance()->queueUniqueTask(
                [$this, "SendEmail"],
                [
                    $TemplateId,
                    $Recipient,
                    $Resources,
                    $ExtraValues,
                    $ConfirmBeforeSending,
                    ApplicationFramework::getInstance()->activeUserInterface()
                ],
                $this->getConfigSetting("EmailTaskPriority"),
                $TaskDescription
            );

            # increment the number of e-mail messages to be sent
            $NumToBeSent += $Templates[$TemplateId]["EmailPerResource"] ?
                count($Resources) : 1;
        }

        # return the number to be sent
        return $NumToBeSent;
    }

    /**
     * Get a list of template users.
     * @param int $TemplateId Template to check.
     * @return array of strings identifying template users.
     */
    public function findTemplateUsers($TemplateId): array
    {
        $Result = ApplicationFramework::getInstance()->signalEvent(
            "Mailer_EVENT_IS_TEMPLATE_IN_USE",
            [
                "TemplateId" => $TemplateId,
                "TemplateUsers" => []
            ]
        );

        return $Result["TemplateUsers"];
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    # values for use by KeywordReplacmentCallback()
    private $KRUser;
    private $KRItemBody;
    private $KRResources;
    private $KRCurrentResource;
    private $KRExtraValues;
    private $KRIsHtml;
    private $KRInterfaceName;

    /**
     * Replace keywords in the given body text.
     * @param string $Body Body text.
     * @param bool $IsHtml Set to TRUE to escape necessary characters in keywords.
     * @return string Returns the body text with keywords replaced.
     */
    protected function replaceKeywordsInBody($Body, $IsHtml = true): string
    {
        $NewBody = "";

        # flag whether the output is HTML
        $this->KRIsHtml = $IsHtml;

        # for each line of template
        foreach ($this->splitByLineEnding($Body) as $Line) {
            # replace any keywords in line and add line to message body
            # along with a newline to avoid line truncation by mail transfer
            # agents
            $NewBody .= $this->replaceKeywords($Line) . "\n";
        }

        return $NewBody;
    }

    /**
     * Split a string by its line endings, e.g., CR, LF, or CRLF.
     * @param string $Value String to split.
     * @return array Returns the string split by its line endings.
     */
    protected function splitByLineEnding($Value): array
    {
        $RetVal = preg_split('/\r\n|\r|\n/', $Value);
        return ($RetVal === false) ? [] : $RetVal;
    }

    /**
     * Process keyword replacements.
     * @param string $Line Text to process.
     * @return string modified text.
     */
    private function replaceKeywords($Line): string
    {
        return preg_replace_callback(
            "/X-([A-Z0-9:]+)-X/",
            [$this, "keywordReplacementCallback"],
            $Line
        );
    }

    /**
     * Callback to handle keyword repacements within a message.
     * @param array $Matches Array of matching keywords.
     * @return string modified text.
     */
    private function keywordReplacementCallback($Matches): string
    {
        static $FieldNameMappings;
        static $StdFieldNameMappings;
        static $InResourceList = false;
        static $ResourceNumber;

        # if extra value was supplied with keyword that matches the match string
        if (is_array($this->KRExtraValues)
                && isset($this->KRExtraValues[$Matches[1]])) {
            # return extra value to caller
            return $this->KRExtraValues[$Matches[1]];
        }

        # if current resource is not yet set then set default value if available
        if (!isset($this->KRCurrentResource) && count($this->KRResources)) {
            $this->KRCurrentResource = reset($this->KRResources);
        }

        $IntCfg = InterfaceConfiguration::getInstance($this->KRInterfaceName);

        # start out with assumption that no replacement text will be found
        $Replacement = $Matches[0];

        # switch on match string
        switch ($Matches[1]) {
            case "PORTALNAME":
                $Replacement = $IntCfg->getString("PortalName");
                break;

            case "BASEURL":
                $Replacement = strlen(trim($this->getConfigSetting("BaseUrl")))
                        ? trim($this->getConfigSetting("BaseUrl"))
                        : ApplicationFramework::baseUrl()."index.php";
                break;

            case "ADMINEMAIL":
                $Replacement = $IntCfg->getString("AdminEmail");
                break;

            case "LEGALNOTICE":
                $Replacement = $IntCfg->getString("LegalNotice");
                break;

            case "USERLOGIN":
            case "USERNAME":
                if (isset($this->KRUser)) {
                    $Value = $this->KRUser->get("UserName");
                    $Replacement = $this->KRIsHtml ? StripXSSThreats($Value) : $Value;
                }
                break;

            case "USERREALNAME":
                if (isset($this->KRUser)) {
                    $Value = $this->KRUser->get("RealName");

                    # if the user hasn't specified a full name
                    if (!strlen(trim($Value))) {
                        $Value = $this->KRUser->get("UserName");
                    }

                    $Replacement = $this->KRIsHtml ? StripXSSThreats($Value) : $Value;
                }
                break;

            case "USEREMAIL":
                if (isset($this->KRUser)) {
                    $Value = $this->KRUser->get("EMail");
                    $Replacement = $this->KRIsHtml ? StripXSSThreats($Value) : $Value;
                }
                break;

            case "RESOURCELIST":
                $Replacement = "";
                if ($InResourceList == false) {
                    $InResourceList = true;
                    $ResourceNumber = 1;
                    foreach ($this->KRResources as $Resource) {
                        $this->KRCurrentResource = $Resource;
                        $TemplateLines = $this->splitByLineEnding($this->KRItemBody);
                        foreach ($TemplateLines as $Line) {
                            $Replacement .= preg_replace_callback(
                                "/X-([A-Z0-9:]+)-X/",
                                [$this, "KeywordReplacementCallback"],
                                $Line
                            ) . "\n";
                        }
                        $ResourceNumber++;
                    }
                    $InResourceList = false;
                }
                break;

            case "RESOURCENUMBER":
                $Replacement = $ResourceNumber;
                break;

            case "RESOURCECOUNT":
                $Replacement = count($this->KRResources);
                break;

            case "RESOURCEID":
                if (!isset($this->KRCurrentResource)) {
                    break;
                }

                $Replacement = $this->KRCurrentResource->id();
                break;

            case "RESOURCEVIEWURL":
                if (!isset($this->KRCurrentResource)) {
                    break;
                }

                static $Schemas = [];
                static $BaseUrl;

                # retrieve view page for schema used for resource
                $SchemaId = $this->KRCurrentResource->getSchemaId();
                if (!array_key_exists($SchemaId, $Schemas)) {
                    $Schemas[$SchemaId] = new MetadataSchema($SchemaId);
                }
                $ViewPage = $Schemas[$SchemaId]->getViewPage();

                # make sure view page will work for clean URL substitution
                if (strpos($ViewPage, "index.php") !== 0) {
                    $ViewPage = "index.php".$ViewPage;
                }

                # insert resource ID into view page
                $ViewPage = preg_replace(
                    "%\\\$ID%",
                    $this->KRCurrentResource->id(),
                    $ViewPage
                );

                # get any clean URL for view page
                $AF = ApplicationFramework::getInstance();
                $ViewPage = $AF->getCleanRelativeUrlForPath($ViewPage);

                # add base URL to view page
                if (!isset($BaseUrl)) {
                    $BaseUrl = strlen(trim($this->getConfigSetting("BaseUrl") ?? ""))
                            ? trim($this->getConfigSetting("BaseUrl"))
                            : ApplicationFramework::baseUrl();
                }
                $Replacement = $BaseUrl.$ViewPage;
                break;

            default:
                # map to date/time values if appropriate
                $DateFormats = [
                    "DATE"            => "M j Y",
                    "TIME"            => "g:ia T",
                    "YEAR"            => "Y",
                    "YEARABBREV"      => "y",
                    "MONTH"           => "n",
                    "MONTHNAME"       => "F",
                    "MONTHABBREV"     => "M",
                    "MONTHZERO"       => "m",
                    "DAY"             => "j",
                    "DAYZERO"         => "d",
                    "DAYWITHSUFFIX"   => "jS",
                    "WEEKDAYNAME"     => "l",
                    "WEEKDAYABBREV"   => "D",
                    "HOUR"            => "g",
                    "HOURZERO"        => "h",
                    "MINUTE"          => "i",
                    "TIMEZONE"        => "T",
                    "AMPMLOWER"       => "a",
                    "AMPMUPPER"       => "A",
                ];

                # map the share format values if appropriate
                if (PluginManager::getInstance()->pluginEnabled("SocialMedia")) {
                    $ShareFormats = ["FACEBOOK", "TWITTER", "LINKEDIN"];
                } else {
                    $ShareFormats = [];
                }

                if (isset($DateFormats[$Matches[1]])) {
                    $Replacement = date($DateFormats[$Matches[1]]);
                } elseif (isset($this->KRCurrentResource)) {
                    # get the schema ID for the resource
                    $SchemaId = $this->KRCurrentResource->getSchemaId();

                    # load field name mappings (if not already loaded)
                    if (!isset($FieldNameMappings[$SchemaId])) {
                        $Schema = new MetadataSchema($SchemaId);
                        foreach ($Schema->getFields() as $Field) {
                            $NormalizedName = strtoupper(
                                preg_replace(
                                    "/[^A-Za-z0-9]/",
                                    "",
                                    $Field->name()
                                )
                            );
                            $FieldNameMappings[$SchemaId][$NormalizedName]
                                = $Field;

                            if ($Field->type() == MetadataSchema::MDFTYPE_USER) {
                                $Key = $NormalizedName.":SMARTNAME";
                                $FieldNameMappings[$SchemaId][$Key]
                                    = $Field;
                            }
                        }
                    }

                    # load standard field name mappings (if not already loaded)
                    if (!isset($StdFieldNameMappings[$SchemaId])) {
                        if (!isset($Schema) || $Schema->id() != $SchemaId) {
                            $Schema = new MetadataSchema($SchemaId);
                        }

                        foreach (MetadataSchema::getStandardFieldNames() as $Name) {
                            $Field = $Schema->getFieldByMappedName($Name);
                            if ($Field !== null) {
                                $NormalizedName = strtoupper(
                                    preg_replace("/[^A-Za-z0-9]/", "", $Name)
                                );

                                $StdFieldNameMappings[$SchemaId][$NormalizedName]
                                    = $Field;
                            }
                        }
                    }

                    # if keyword refers to known field
                    $KeywordIsField = preg_match(
                        "/^FIELD:([A-Z0-9:]+)/",
                        $Matches[1],
                        $SubMatches
                    );
                    $KeywordIsStdField = preg_match(
                        "/^STDFIELD:([A-Z0-9:]+)/",
                        $Matches[1],
                        $StdFieldSubMatches
                    );
                    $KeywordIsShare = preg_match(
                        "/^SHARE:([A-Z]+)/",
                        $Matches[1],
                        $ShareSubMatches
                    );

                    # if there's a match for a field keyword
                    // @codingStandardsIgnoreStart
                    if (($KeywordIsField
                         && isset($FieldNameMappings[$SchemaId][$SubMatches[1]])) ||
                        ($KeywordIsStdField
                         && isset($StdFieldNameMappings[$SchemaId][$StdFieldSubMatches[1]])) )
                    // @codingStandardsIgnoreEnd
                    {
                        # replacement is value from current resource
                        $Field = $KeywordIsField ?
                            $FieldNameMappings[$SchemaId][$SubMatches[1]] :
                            // @phpstan-ignore-next-line
                            $StdFieldNameMappings[$SchemaId][$StdFieldSubMatches[1]] ;

                        # if this is a User field and the SMARTNAME was requested
                        if ($KeywordIsField &&
                            $Field->Type() == MetadataSchema::MDFTYPE_USER &&
                            preg_match("/:SMARTNAME$/", $SubMatches[1])) {
                            # iterate over the users in this field
                            $Users = $this->KRCurrentResource->Get($Field, true);
                            $Replacement = [];
                            foreach ($Users as $User) {
                                # if they have a real name set, use that
                                # otherwise, fall back to UserName
                                $RealName = trim($User->Get("RealName"));
                                $Replacement[] = (strlen($RealName)) ? $RealName :
                                         $User->Get("UserName") ;
                            }
                        } else {
                            # otherwise just get the value
                            $Replacement = $this->KRCurrentResource->Get($Field);
                        }

                        # combine array values with commas
                        if (is_array($Replacement)) {
                            $Replacement = implode(", ", $Replacement);
                        }

                        if (!$Field->AllowHTML()) {
                            $Replacement = $this->KRIsHtml
                                ? htmlspecialchars($Replacement) : $Replacement;
                            $Replacement = wordwrap($Replacement, 78);
                        } elseif (strpos($Replacement, ">") === false
                                 && strpos($Replacement, "<") === false) {
                            # HTML is allowed but there isn't any HTML in the value,
                            # so wrapping is okay
                            $Replacement = wordwrap($Replacement, 78);
                        } elseif (!$this->KRIsHtml) {
                            # HTML is allowed and in the value but it shouldn't be
                            # used in the plain text version

                            # try as hard as possible to convert the HTML to plain text
                            $Replacement = Email::convertHtmlToPlainText($Replacement);

                            # wrap the body
                            $Replacement = wordwrap($Replacement, 78);
                        }

                        $Replacement = $this->KRIsHtml
                            ? StripXSSThreats($Replacement) : $Replacement;
                    } elseif ($KeywordIsShare
                              && in_array($ShareSubMatches[1], $ShareFormats)) {
                        # if there's a match for a share keyword
                        $Replacement = $this->getShareUrl(
                            $this->KRCurrentResource,
                            $ShareSubMatches[1]
                        );
                    }
                }
                break;
        }

        # return replacement string to caller
        return $Replacement;
    }

    /**
    * Get the share URL for the given resource to the given site, where site is
    * one of "Facebook", "Twitter", or "LinkedIn"
    * @param Record $Resource The resource for which to get the share URL.
    * @param string $Site The site for which to get the share URL.
    * @return string|null Returns the share URL for the resource and site or NULL if the
    *      SocialMedia plugin isn't available.
    */
    private function getShareUrl(Record $Resource, $Site): ?string
    {
        $PluginMgr = PluginManager::getInstance();
        # the social media plugin needs to be available
        if (!$PluginMgr->pluginEnabled("SocialMedia")) {
            return null;
        }

        # return the share URL from the social media plugin
        $SocialMediaPlugin = SocialMedia::getInstance();
        return $SocialMediaPlugin->getShareUrl($Resource, $Site);
    }


    /**
     * Build an Email object representing a message to be sent from a
     * template.
     * @param int $TemplateId Id of the template being used.
     * @param string $Address Destination email address.
     * @return Email Constructed message
     */
    private function createEmailMessage(
        int $TemplateId,
        string $Address
    ): Email {
        $AF = ApplicationFramework::getInstance();

        # get the requested template
        $Templates = $this->getConfigSetting("Templates");
        $Template = $Templates[$TemplateId];

        # get the message subject
        $Subject = trim($this->replaceKeywords($Template["Subject"]));

        $Msg = new Email();
        $Msg->addLogData([
            "TemplateId" => $TemplateId,
        ]);
        $Msg->charSet($AF->htmlCharset());
        $Msg->from($this->replaceKeywords($Template["From"]));
        $Msg->to($Address);
        $Msg->subject($Subject);

        # set up headers for message
        $Headers = [];
        $Headers[] = "Auto-Submitted: auto-generated";
        $Headers[] = "Precedence: list";
        $Headers[] = "X-SiteUrl: ". ApplicationFramework::baseUrl();
        if (strlen($Template["Headers"])) {
            $ExtraHeaders = $this->splitByLineEnding($Template["Headers"]);
            foreach ($ExtraHeaders as $Line) {
                if (strlen(trim($Line))) {
                    $Headers[] = $this->replaceKeywords($Line);
                }
            }
        }

        # add the headers to the message
        $Msg->addHeaders($Headers);

        # check if we have an html body
        if (strlen(trim(strip_tags($Template["Body"]))) == 0) {
            # if there was no html body, then construct one based
            # on the plain text body, replacing keywords as we go
            $Body = "<pre>"
                .$this->replaceKeywordsInBody(
                    $Template["PlainTextBody"],
                    false
                )
                ."</pre>";
        } else {
            # otherwise, just do keyword replacement on the HTML body
            $Body = $this->replaceKeywordsInBody($Template["Body"]);
        }

        # if body contains HTML
        if (strlen(strip_tags($Body)) != strlen($Body)) {
            $Msg->isHtml(true);

            # wrap HTML where necessary to keep it below 998 characters
            $Body = Email::wrapHtmlAsNecessary($Body);

            # construct the style attribute necessary for collapsing the body
            # margins, if instructed to do so
            $MarginsStyle = $Template["CollapseBodyMargins"]
            ? ' style="margin:0;padding:0;"' : "";

            # wrap the message in boilerplate HTML
            $Body = '<!DOCTYPE html>'
                .'<html lang="en"'.$MarginsStyle.'>'
                .'<head><meta charset="'.$AF->htmlCharset().'" /></head>'
                .'<body'.$MarginsStyle.'>'.$Body.'</body>'
                .'</html>';
        }

        # add the body to the message
        $Msg->body($Body);

        # plain text body and item body are set
        if (strlen(trim($Template["PlainTextBody"])) > 0) {
            $this->KRItemBody = $Template["PlainTextItemBody"];

            # start with the body from the template and replace keywords
            $PlainTextBody = $this->replaceKeywordsInBody(
                $Template["PlainTextBody"],
                false
            );

            # wrap body where necessary to keep it below 998 characters
            $PlainTextBody = wordwrap($PlainTextBody, 998, "\n", true);

            # add the alternate body to the message
            $Msg->alternateBody($PlainTextBody);
        }

        return $Msg;
    }

    public const SQL_TABLES = [
        "StoredEmails" => "CREATE TABLE IF NOT EXISTS Mailer_StoredEmails (
                Mailer_StoredEmailId       INT NOT NULL AUTO_INCREMENT,
                Mailer_StoredEmailName     TEXT,
                FromAddr                   TEXT,
                ToAddr                     TEXT,
                TemplateId                 INT,
                Email                      LONGBLOB,
                NumResources               INT,
                ResourceIds                BLOB,
                DateCreated                DATETIME,
                INDEX           Index_I (Mailer_StoredEmailId)
            );",
    ];
}
