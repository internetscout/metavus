<?PHP
#
#   FILE:  UserEditingUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;
use ScoutLib\Date;
use ScoutLib\StdLib;

/**
* Class supplying standard methods that process changes to user
* entered via HTML forms.
*/
class UserEditingUI extends RecordEditingUI
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
    * Set up a new UserEditingUI.
    * @param User $TargetUser User to operate on
    * @param array $AdditionalFormFields Additional fields to prepend to the
    *   user editing form, in the format required by FormUI.
    */
    public function __construct(
        User $TargetUser,
        array $AdditionalFormFields
    ) {
        $this->User = $TargetUser;
        $this->AdditionalFormFields = $AdditionalFormFields;

        $Record = $this->User->getResource();
        if (is_null($Record)) {
            throw new Exception("No user record available");
        }

        parent::__construct($Record);
    }

    /**
     * Get the number of status messages that will be displayed over this form.
     * @return int Message count
     */
    public function statusMessageCount(): int
    {
        return count(self::$StatusMessages);
    }

    /**
     * Display editing form.
     * @param string $TableId CSS ID for table element. (OPTIONAL)
     * @param string $TableStyle CSS styles for table element. (OPTIONAL)
     * @param string $TableCssClass Additional CSS class for table element. (OPTIONAL)
     * @return void
     */
    public function displayFormTable(
        ?string $TableId = null,
        ?string $TableStyle = null,
        ?string $TableCssClass = null
    ): void {
        # if the changes the user made should only display the StatusMessages
        # without showing the form, bail
        if (!$this->DisplayForm) {
            return;
        }

        parent::displayFormTable($TableId, $TableStyle, $TableCssClass);
    }

    /**
     * Determine if any form fields will be visible when displayFormTable() is
     *   called.
     * @param bool $NewValue TRUE to display the form, FALSE to hide it (OPTIONAL)
     * @return bool TRUE when form is visible
     */
    public function isFormVisible(?bool $NewValue = null): bool
    {
        if (!is_null($NewValue)) {
            $this->DisplayForm = $NewValue;
        }

        return $this->DisplayForm;
    }

    /**
     * Log a status message to be displayed above the editing form.
     * @param string $Message Message to display.
     * @return void
     */
    public function logStatusMessage(string $Message): void
    {
        self::$StatusMessages[] = $Message;
    }

    # ---- PUBLIC STATIC INTERFACE -------------------------------------------

    /**
     * Display HTML block with status messages.
     * @return void
     */
    public static function displayStatusBlock(): void
    {
        # display any status messages
        if (count(self::$StatusMessages)) {
            print "<ul class='alert alert-primary'>";
            foreach (self::$StatusMessages as $Message) {
                print "<li>".$Message."</li>";
            }
            print "</ul>";
        }
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $User;
    private $DisplayForm = true;

    /**
     * Set up form elements for user editing.
     * @return array Form fields in the first array element, form values in
     *   the second.
     */
    protected function getFormFieldConfiguration(): array
    {
        $FormFields = $this->AdditionalFormFields;

        # load up all user interface options
        # use regex to filter out plugins' interfaces, more specifically,
        # drop interfaces whose path is "$PATH/$TO/$HERE/plugins/interface/$NAME"
        # i.e.the "interface" folder has a parent folder with the name "plugins"
        $UserInterfaceOptions = ApplicationFramework::getInstance()->getUserInterfaces(
            "/^(?![a-zA-Z0-9\/]*plugins\/)[a-zA-Z0-9\/]*interface\/[a-zA-Z0-9%\/]+/"
        );

        $FormFields += [
            "HEADING-Preferences" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Preferences",
            ],
        ];

        if (SystemConfiguration::getInstance()->getBool("AllowMultipleUIsEnabled") ||
            User::getCurrentUser()->hasPriv(PRIV_SYSADMIN)) {
            $FormFields += [
                "ActiveUI" => [
                    "Type" => FormUI::FTYPE_OPTION,
                    "Label" => "Active User Interface",
                    "Options" => $UserInterfaceOptions,
                ],
            ];
        }

        $FormFields += [
            "SearchResultsPerPage" => [
                "Type" => FormUI::FTYPE_OPTION,
                "Label" => "Resources Per Page",
                "Options" => [5 => 5, 10 => 10, 20 => 20, 30 => 30, 50 => 50, 100 => 100],
            ],
        ];

        # add fields from parent
        $FormFields += parent::getFormFieldConfiguration();

        return $FormFields;
    }

    /**
     * Get the value for a specified form field from the underlying User.
     * @param string $FormFieldName Field to retrieve a value for
     * @return mixed Form field value
     */
    protected function getFormFieldValue(string $FormFieldName)
    {
        # if this was an additional FormUI value, we want to use
        # the value specified in our field params for it
        # (note that we can't use FormUI::getFieldValue() because we're being called from
        #  RecordEditingUI::__construct() before FormUI::__construct() is called,
        #  so $this->FieldParams won't be set yet)
        if (array_key_exists($FormFieldName, $this->AdditionalFormFields)) {
            # use the Value or Default setting for this field if found
            foreach (["Value", "Default"] as $Key) {
                if (isset($this->AdditionalFormFields[$FormFieldName][$Key])) {
                    return $this->AdditionalFormFields[$FormFieldName][$Key];
                }
            }
            return null;
        }

        # start off assuming no value will be found
        $Value = null;

        switch ($FormFieldName) {
            case "Email":
                $Value = $this->User->get("EMail");
                break;

            case "ActiveUI":
                $Value = $this->User->get("ActiveUI")
                    ? $this->User->get("ActiveUI")
                    : SystemConfiguration::getInstance()->getString("DefaultActiveUI");
                break;

            case "SearchResultsPerPage":
                $Value = $this->User->get("RecordsPerPage")
                    ? $this->User->get("RecordsPerPage")
                    : InterfaceConfiguration::getInstance()->getInt("DefaultRecordsPerPage");
                break;

            default:
                $Value = parent::getFormFieldValue($FormFieldName);
                break;
        }

        return $Value;
    }

    /**
     * Determine if a provided field is valid for our form.
     * @param string $Field Field name
     * @return bool TRUE for valid fields, FALSE otherwise
     */
    protected function isFormFieldValid(string $Field): bool
    {
        if (in_array($Field, ["Email", "ActiveUI", "SearchResultsPerPage"])) {
            return true;
        }

        if (array_key_exists($Field, $this->AdditionalFormFields)) {
            return true;
        }

        return parent::isFormFieldValid($Field);
    }

    /**
     * Set a value for a user field.
     * @param string $FieldName Field to set
     * @param mixed $Values Values from form
     * @return bool TRUE if values were changed in the user resource, FALSE
     *   otherwise
     */
    protected function saveValueFromFormField($FieldName, $Values): bool
    {
        # if this was an additional FormUI field, skip it because it should be
        # handled by the application code that added the field
        if (array_key_exists($FieldName, $this->AdditionalFormFields)) {
            return false;
        }

        # start off assuming that no MetadataFields will be changed
        $Result = false;

        switch ($FieldName) {
            case "SearchResultsPerPage":
                if (!in_array($Values, [5, 10, 20, 30, 50, 100])) {
                    $Values = 5;
                }
                $this->User->set("RecordsPerPage", $Values);
                break;

            case "ActiveUI":
                # when no value provided, use the default
                if (empty($Values)) {
                    $Values = SystemConfiguration::getInstance()->getString("DefaultActiveUI");
                }
                $this->User->set("ActiveUI", $Values);
                break;

            default:
                # set metadata field value
                $Result = parent::saveValueFromFormField($FieldName, $Values);
                if ($Result) {
                    # if value was changed, also call User::set() so that any
                    # needed mirroring into APUsers columns is also done
                    $this->User->setLegacyColumn($FieldName, $Values);
                }
                break;
        }

        return $Result;
    }

    # ---- PRIVATE STATIC INTERFACE ------------------------------------------

    private static $StatusMessages = [];
    private $AdditionalFormFields = [];
}
