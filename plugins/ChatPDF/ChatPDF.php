<?PHP
#
#   FILE: ChatPDF.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use CURLFile;
use Exception;
use Metavus\File;
use Metavus\FormUI;
use Metavus\HtmlButton;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugin;
use Metavus\Record;
use ScoutLib\ApplicationFramework;
use ScoutLib\Database;

/**
 * Leverage ChatPDF to enhance the creation and curation of metadata to
 * describe resources.
 */
class ChatPDF extends Plugin
{
    # ---- PUBLIC INTERFACE ----------------------------------------

    /**
     * Register information about the plugin.
     */
    public function register(): void
    {
        $this->Name = "ChatPDF";
        $this->Version = "1.0.1";
        $this->Description = "Generates record metadata by sending uploaded"
                ." PDF files to the <a href=\"https://chatpdf.com\">ChatPDF"
                ." service</a> for analysis by the ChatGPT LLM AI.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
        ];
        $this->EnabledByDefault = false;
    }

    /**
     * Initialize the plugin. This is called after all plugins have been loaded
     * but before any methods for this plugin (other than register() or
     * initialize()) have been called.
     * @return null|string NULL if initialization was successful, otherwise an
     *      error message indicating why initialization failed.
     */
    public function initialize(): ?string
    {
        # set up database
        $this->DB = new Database();

        # register observer for file field uploads in all schemas
        $AllSchemas = MetadataSchema::getAllSchemas();
        foreach ($AllSchemas as $Schema) {
            $FileFields = $Schema->getFields(MetadataSchema::MDFTYPE_FILE);
            foreach ($FileFields as $Index => $FileField) {
                MetadataField::registerObserver(
                    MetadataField::EVENT_ADD,
                    [$this, "observeFileUpload"],
                    $FileField->id()
                );
            }
        }

        # register observer to listen to record update
        Record::registerObserver(
            Record::EVENT_SET,
            [$this, "observeRecordUpdate"]
        );

        # register callback for inserting manual upload button to EditResource
        $AF = ApplicationFramework::getInstance();
        $AF->registerInsertionKeywordCallback(
            "EDITRESOURCE-TOP-BUTTONS",
            [$this, "addManualUploadButton"],
            ["RecordId"]
        );
        $AF->registerInsertionKeywordCallback(
            "EDITRESOURCE-BOTTOM-BUTTONS",
            [$this, "addManualUploadButton"],
            ["RecordId"]
        );

        return null;
    }

    /**
     * Perform any work required for installing plugin.
     * @return null|string NULL if successful, otherwise an error message.
     */
    public function install(): ?string
    {
        # create database tables, report result to caller
        return $this->createTables(self::SQL_TABLES);
    }

    /**
     * Perform any work required for uninstalling the plugin.
     * @return null|string NULL if successful, otherwise an error message.
     */
    public function uninstall(): ?string
    {
        # remove tables from database, report result to caller
        return $this->dropTables(self::SQL_TABLES);
    }

    /**
     * Hook event callbacks into the application framework.
     * @return array Associative array of events to be hooked.
     */
    public function hookEvents(): array
    {
        $Hooks = [
            "EVENT_DAILY" => "runDaily",
            "EVENT_HOURLY" => "runHourly",
            "EVENT_IN_HTML_HEADER" => "printHtmlHeaderContent",
        ];
        return $Hooks;
    }

    /**
     * Run our daily tasks:
     * 1) Prune DB records of files that were uploaded more than 24 hours ago.
     * 2) Prune DB records of asked questions for files that are no longer in
     * Metavus.
     * @param string|null $LastRunAt Timestamp of the last time this event ran
     *      or NULL if it has not run before.
     */
    public function runDaily($LastRunAt): void
    {
        $this->pruneRecordOfUploadedFiles();
        $this->pruneStaleAskedQuestions();
    }

    /**
     * Run our hourly tasks:
     * 1) Process any queued records.
     * @param string|null $LastRunAt Timestamp of the last time this event ran
     *      or NULL if it has not run before.
     */
    public function runHourly($LastRunAt): void
    {
        $this->processQueuedRecords();
    }

    /**
     * Set up plugin configuration options.
     * This method is called after install() or upgrade() methods are called.
     * @return string|null NULL if configuration setup succeeded, otherwise a
     *      string or array of strings containing error message(s) indicating
     *      why config setup failed.
     */
    public function setUpConfigOptions(): ?string
    {
        # list all file fields from all schemas
        $AllSchemas = MetadataSchema::getAllSchemas();
        $FileFields = [];
        foreach ($AllSchemas as $Schema) {
            $SchemaName = $Schema->name();
            $FileFieldsForSchema = $Schema->getFieldNames(MetadataSchema::MDFTYPE_FILE);
            foreach ($FileFieldsForSchema as $FileFieldId => $FileFieldName) {
                $FileFields[$FileFieldId] = $SchemaName . ": " . $FileFieldName;
            }
        }

        # define file types to possibly be allowed to upload
        $FileTypes = [
            "application/pdf" => "PDF (.pdf)",
            "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    => "Microsoft Word (.docx)",
            "application/msword" => "Microsoft Word (Legacy) (.doc)",
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    => "Microsoft Excel (.xlsx)",
            "application/vnd.ms-excel" => "Microsoft Excel (Legacy) (.xls)",
            "application/vnd.openxmlformats-officedocument.presentationml.presentation"
                    => "Microsoft Powerpoint (.pptx)",
            "application/vnd.ms-powerpoint" => "Microsoft Powerpoint (Legacy) (.ppt)",
            "application/csv" => "Comma-Separated Value (.csv)",
            "text/plain" => "Plain Text (.txt)",
            "text/markdown" => "Markdown (.md)",
            "text/html" => "HTML (.html)",
            "application/epub+zip" => "Ebook (.epub)",
            "message/rfc822" => "Email (.eml)",
            "application/vnd.ms-outlook" => "Email (Outlook) (.msg)",
        ];
        $DefaultFileTypes = [
            "application/pdf",
            "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            "application/msword",
            "application/vnd.openxmlformats-officedocument.presentationml.presentation",
            "application/vnd.ms-powerpoint",
            "text/plain",
            "text/markdown",
        ];

        $this->CfgSetup = [
            "Heading1" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Files and Actions"
            ],
            "FileFields" => [
                "Type" => FormUI::FTYPE_OPTION,
                "Label" => "File Fields",
                "Help" => "The File metadata fields that you want ChatPDF to"
                        ." analyze and answer questions about.<br/>"
                        ." <br/>"
                        ." If a File field contains multiple files, only the"
                        ." first file is sent to ChatPDF if the target field for"
                        ." the action is a Date or Timestamp field, otherwise all"
                        ." files are sent and the answers are concatenated.",
                "Options" => $FileFields,
                "AllowMultiple" => true,
            ],
            "Actions" => [
                "Type" => FormUI::FTYPE_PARAGRAPH,
                "Label" => "Actions",
                "Rows" => 10,
                "Columns" => 60,
                "Help" => "The questions for ChatPDF to answer, formatted in pairs"
                    ." of lines. The first line in each pair should begin with"
                    ."  \"Prompt:\" followed by the prompt for ChatPDF. The second line"
                    ." should begin with \"Field:\" followed by the fully-qualified name"
                    ." of the field that you want to be populated by the response to the"
                    ." corresponding prompt. Blank lines are ignored.<br/>"
                    ." Please note that the Resource schema does <b>not</b>"
                    ." include the schema name in fully-qualified names. All other schemas"
                    ." require the schema name. For example: <i>Pages: AI Summary</i><br>"
                    ." <br/>"
                    ." The currently-supported field types are <i>Text</i>,"
                    ." <i>Paragraph</i>, <i>Number</i>, <i>Date</i>, and"
                    ." <i>Timestamp</i>.<br/>"
                    ." <br/>"
                    ." An example action:<br/>"
                    ." <i>Prompt: Summarize the document in under 250 words.<br/>"
                    ." Field: AI Generated Description</i><br/>",
                "ValidateFunction" => [
                    "Metavus\\Plugins\\ChatPDF",
                    "validateActionsSetting"
                ],
                "Default" => "",
            ],
            "AutomaticUpload" => [
                "Type" => FormUI::FTYPE_FLAG,
                "Label" => "Automatic Upload",
                "Help" => "When this setting is enabled, newly-uploaded files are"
                        ." automatically sent to ChatPDF (and any answers"
                        ." returned placed in the configured target fields)"
                        ." when the record containing the files is saved,"
                        ." rather than uploads being manually triggered by a"
                        ." <i>ChatPDF</i> button.",
                "Default" => false,
            ],
            "Heading2" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "ChatPDF Configuration"
            ],
            "ApiKey" => [
                "Type" => FormUI::FTYPE_TEXT,
                "Default" => "",
                "Label" => "API Key",
                "Help" => "The API key used to connect to ChatPDF. This is provided"
                        ." by ChatPDF in the \"Developer\" settings under \"My Account\".",
                "Required" => true,
            ],
            "DailyUploads" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Daily Uploads",
                "Help" => "The number of uploads to perform in a given 24-hour period."
                    ." Set this to the quota allowed by your plan, or 0 for no upload"
                    ." limit. Records that have more than this number of files will"
                    ." not be processed by the plugin.",
                "Default" => 2,
            ],
            "DailyQuestions" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Daily Questions",
                "Help" => "The number of queries to perform in a given 24-hour period."
                    ." Set this to the quota allowed by your plan, or 0 for no query"
                    ." limit.",
                "Default" => 20,
            ],
            "MinRetryInterval" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Minimum Retry Interval",
                "Help" => "The minimum about of time, in minutes, between re-querying"
                    ." ChatPDF for the same file and record.",
                "Default" => 120,
            ],
            "MaxQuestionRetries" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Maximum Question Retries",
                "Help" => "The maximum number of times to retry the same question"
                    ." with the same file. A file is retried when a record is saved"
                    ." and the destination field(s) are empty so that existing file"
                    ." and question pairs can be retried. When a pair hits the retry"
                    ." limit from this setting, it will no longer be considered for"
                    ." ChatPDF, but the individual file and question may still be"
                    ." considered if they are part of other pairs.",
                "Default" => 10,
            ],
            "MaxFileSize" => [
                "Type" => FormUI::FTYPE_NUMBER,
                "Label" => "Max File Size",
                "Help" => "The maximum file size that ChatPDF supports, in MB.",
                "Default" => 32,
            ],
            "AllowedFileTypes" => [
                "Type" => FormUI::FTYPE_OPTION,
                "Label" => "Allowed File Types",
                "Help" => "Types of files that will be uploaded to be analyzed by"
                        ." the ChatPDF service.  This should include only file types"
                        ." that ChatPDF explicitly says are supported by their service",
                "AllowMultiple" => true,
                "Options" => $FileTypes,
                "Default" => $DefaultFileTypes,
            ],
            "Heading3" => [
                "Type" => FormUI::FTYPE_HEADING,
                "Label" => "Advanced Settings"
            ],
            "PromptAddendumForDates" => [
                "Type" => FormUI::FTYPE_PARAGRAPH,
                "Label" => "Date Prompt Addendum",
                "Rows" => 2,
                "Columns" => 60,
                "Help" => "Text to be added to the end of prompts for actions"
                        ." where the result is to be placed into a Date or"
                        ." Timestamp field.",
                "Default" => "Please respond with just the date and time in"
                        ." ISO 8601 (YYYY-MM-DD HH:MM:SS) format,"
                        ." with no other text.",
            ],
            "PromptAddendumForNumbers" => [
                "Type" => FormUI::FTYPE_PARAGRAPH,
                "Label" => "Number Prompt Addendum",
                "Rows" => 2,
                "Columns" => 60,
                "Help" => "Text to be added to the end of prompts for actions"
                        ." where the result is to be placed into a Number field.",
                "Default" => "Please respond with just the number,"
                        ." with no other text.",
            ],
            "RecordEditingPages" => [
                "Type" => FormUI::FTYPE_PARAGRAPH,
                "Label" => "Record Editing Pages",
                "Help" => "The list of pages that you want the ChatPDF button to"
                        ." appear in, formatted with one HTML page name per line."
                        ." To specify key-value pairs that must be present in the"
                        ." page's GET or POST arrays, format them as query"
                        ." parameters as shown in the following example. Note that"
                        ." empty values will ensure the key does not exist or is"
                        ." empty in the GET and POST arrays.<br/>"
                        ." For example:<br/>"
                        ." <i>HtmlPageName?Key1=Value1&Key2=Value2</i><br/>"
                        ." (This setting should primarily be of interest only to"
                        ." developers who are extending Metavus.)",
                "Default" => "EditResource?Submit=",
                "ValidateFunction" => [
                    "Metavus\\Plugins\\ChatPDF",
                    "validateRecordEditingPages"
                ],
            ],
        ];

        return null;
    }

    /**
     * Validation function for the Actions config setting.
     * @param string $SettingName Setting name.
     * @param string $Value Setting value.
     * @return string|null NULL on successful validation, error string otherwise.
     */
    public static function validateActionsSetting(
        string $SettingName,
        string $Value
    ): ?string {
        $Lines = self::splitTextIntoLinesWithContent($Value);
        $LineCount = count($Lines);

        # check if we have pairs of lines
        if (($LineCount % 2) !== 0) {
            return "There must be pairs of lines in the <i>Actions</i> setting.";
        }

        # check that the first line in each pair starts with "Prompt:"
        for ($I = 0; $I < $LineCount; $I += 2) {
            $Pos = strpos($Lines[$I], "Prompt:");
            if ($Pos === false || $Pos !== 0) {
                return "The first line in each pair must start with \"Prompt:\""
                    ." in the <i>Actions</i> setting.";
            }
        }

        # check that the second line in each pair starts with "Field:"
        # and verify that the field exists
        for ($I = 1; $I < $LineCount; $I += 2) {
            $Pos = strpos($Lines[$I], "Field:");
            if ($Pos === false || $Pos !== 0) {
                return "The second line in each pair must start with \"Field:\""
                    ." in the <i>Actions</i> setting.";
            }

            # get the fully-qualified field name
            $QualifiedFieldName = substr($Lines[$I], strlen("Field:"));
            $QualifiedFieldName = trim($QualifiedFieldName); # whitespace after colon

            # verify that field exists
            if (!MetadataSchema::fieldExistsInAnySchema($QualifiedFieldName)) {
                return "\"".$QualifiedFieldName."\" is not a fully-qualified"
                   ." metadata field name in the <i>Actions</i> setting.";
            }

            # verify field type is supported
            $FieldId = MetadataSchema::getCanonicalFieldIdentifier($QualifiedFieldName);
            $Field = MetadataField::getField($FieldId);
            $FieldType = $Field->type();
            if (($FieldType & self::SUPPORTED_MDFTYPES) === 0) {
                return "Metadata field type ".$Field->typeAsName()." is not"
                    ." supported for fields in the <i>Actions</i> setting.";
            }
        }
        return null;
    }

    /**
     * Validation function for the Record Editing Pages config setting.
     * @param string $SettingName Setting name.
     * @param string|null $Value Setting value.
     * @return string|null NULL on successful validation, error string otherwise.
     */
    public static function validateRecordEditingPages(
        $SettingName,
        $Value
    ): ?string {
        # split setting into lines and discard empty lines
        $Lines = self::splitTextIntoLinesWithContent($Value);
        $AF = ApplicationFramework::getInstance();

        # check if all pages in the field are valid and exist
        foreach ($Lines as $Line) {
            $Line_url = parse_url($Line);
            if ($Line_url === false) {
                return "The page ".$Line." is formatted incorrectly.";
            }
            $Page = (isset($Line_url["path"])) ? $Line_url["path"] : $Line;
            if (!$AF->isExistingPage($Page)) {
                return "The page ".$Line." is not a valid existing page.";
            }
        }
        return null;
    }

    /**
     * Observe files being uploaded to a file field. Adds a post processing
     * call to the callback method for processing the list of new file IDs for a
     * record by uploading the files to ChatPDF and saving its answers to our
     * configured questions to the record.
     * @param int $Event MetadataField::EVENT_* value.
     * @param int $RecordId The ID of the record to save ChatPDf responses to.
     * @param MetadataField $FileField The file field that contains the uploaded
     *      files.
     * @param array $FileIds The list of uploaded files.
     */
    public function observeFileUpload(
        int $Event,
        int $RecordId,
        MetadataField $FileField,
        array $FileIds
    ): void {
        # abort if not configured for automatic uploads
        if (!$this->getConfigSetting("AutomaticUpload")) {
            return;
        }

        # check if the file field is configured to be used, abort if not
        $CfgFileFields = $this->getConfigSetting("FileFields") ?? [];
        if (!in_array($FileField->id(), $CfgFileFields)) {
            return;
        }

        # add post processing call to add ChatPDF's responses AFTER all other
        # values from the record edit form have been saved to prevent responses
        # from being overwritten by destination form fields below the file field
        $Record = new Record($RecordId);
        (ApplicationFramework::getInstance())->addPostProcessingCall(
            [$this, "processAutomaticUploadsForRecord"],
            $Record,
            $FileIds
        );
    }

    /**
     * Observe a record being saved. This is used with automatic uploads, and
     * is used to populate any empty configured fields for the record. This
     * uses every file in each configured file field if every minimum retry
     * interval has passed.
     * @param int $Event MetadataField::EVENT_* value.
     * @param Record $Record The record to be processed.
     */
    public function observeRecordUpdate(int $Event, Record $Record): void
    {
        # abort if not configured for automatic uploads
        if (!$this->getConfigSetting("AutomaticUpload")) {
            return;
        }

        # get configured file IDs for this record
        $FileIds = $this->getFileIdsFromConfiguredFields($Record);

        # abort if we don't have any files to consider
        if (count($FileIds) === 0) {
            return;
        }

        # if ANY file's retry interval hasn't passed yet, queue this record
        # otherwise, process the record and its files
        $CanProcess = true;
        foreach ($FileIds as $FileId) {
            if ($this->fileIsWithinRetryInterval($FileId)) {
                $CanProcess = false;
                break;
            }
        }

        # queue record if we don't have enough execution time to process it,
        # give 30 seconds for a high limit
        $AF = ApplicationFramework::getInstance();
        if ($AF->getSecondsBeforeTimeout() < 30) {
            $CanProcess = false;
        }

        # either process or queue the record
        if ($CanProcess) {
            $this->processAutomaticUploadsForRecord($Record, $FileIds);
        } else {
            $this->enqueueRecord($Record);
        }
    }

    /**
     * Process files for automatic uploads, i.e. observers and dequeued
     * records. Saves results to the provided record.
     * @param Record $Record The record to process files for.
     * @param array $FileIds The files to process.
     * @param bool $QuotaChecked TRUE if the ChatPDF quota has already
     * been checked, FALSE otherwise. FALSE by default. The only case where
     * it will be TRUE is when we're processing a dequeued record.
     */
    public function processAutomaticUploadsForRecord(
        Record $Record,
        array $FileIds,
        bool $QuotaChecked = false
    ): void {
        # get list of usable prompts for this record
        $UsablePrompts = $this->getUsableFieldsAndPromptsForRecord($Record);

        # abort if we don't have any usable prompts
        if (count($UsablePrompts) === 0) {
            return;
        }

        # if we didn't already check quota for this record and its files or
        # total questions will exceed quota, add it to the queue
        if (!$QuotaChecked) {
            if ($this->willExceedDailyUploadLimit($FileIds) ||
                $this->willExceedDailyQuestionLimit($FileIds, $UsablePrompts)
            ) {
                $this->enqueueRecord($Record);
                return;
            }
        }

        # get responses from ChatPDF and save them to the record
        $Responses = $this->getResponsesFromChatPDF($Record, $FileIds, $UsablePrompts);
        foreach ($Responses as $FieldId => $Response) {
            $Record->set($FieldId, $Response);
        }
    }

    /**
     * Add JS variable to header listing the fields configured for processing
     * with ChatPDF.
     */
    public function printHtmlHeaderContent() : void
    {
        $AF = ApplicationFramework::getInstance();

        $PageName = $AF->getPageName();
        if (!$this->isConfiguredToDisplayOnPage($PageName)) {
            return;
        }

        # start off assuming no file fields
        $FieldIds = [];

        # based on the page, look up which fields apply to the record we are
        # adding or editing
        if ($PageName == "EditResource" && isset($_GET["ID"])) {
            if ($_GET["ID"] == "NEW") {
                $Schema = new MetadataSchema(
                    $_GET["SC"] ?? MetadataSchema::SCHEMAID_DEFAULT
                );
                $FieldIds = $this->getConfiguredFileFieldIdsForSchema($Schema);
            } elseif (is_numeric($_GET["ID"])) {
                $Record = Record::getRecord((int)$_GET["ID"]);
                $FieldIds = $this->getConfiguredFileFieldIdsForSchema(
                    $Record->getSchema()
                );
            }
        }

        $FieldNames = [];
        foreach ($FieldIds as $FieldId) {
            $Field = MetadataField::getField($FieldId);
            $FieldNames[] = $Field->name();
        }

        $AllowedFileTypes = $this->getConfigSetting("AllowedFileTypes");

        ?><script>
        var ChatPDF_ConfiguredFields = <?= json_encode($FieldNames) ?>;
        var ChatPDF_AllowedFileTypes = <?= json_encode($AllowedFileTypes) ?>;
        </script><?PHP
    }

    /**
     * Adds the manual upload button HTML. This is intended to be called as an
     * insertion keyword callback. This will process ALL files found in
     * configured file fields.
     * If we have already reached quota prior to this record's files, this
     * button will be disabled.
     * @param int $RecordId The ID of the record being edited.
     * @return string The HTML of the button, or an empty string if the button
     * shouldn't be added.
     */
    public function addManualUploadButton(int $RecordId): string
    {
        # abort if we allow automatic uploads
        $AutomaticUpload = $this->getConfigSetting("AutomaticUpload");
        if ($AutomaticUpload) {
            return "";
        }

        # require the JS file for the manual upload button
        $AF = ApplicationFramework::getInstance();

        # check to see if the ChatPDF button is configured to be displayed on the current page
        $PageName = $AF->getPageName();
        if (!$this->isConfiguredToDisplayOnPage($PageName)) {
            return "";
        }

        $AF->requireUIFile("ChatPDF_Main.js");

        $Record = new Record($RecordId);
        $UsablePrompts = $this->getUsableFieldsAndPromptsForRecord($Record);

        # don't display button if there are no prompts relating to the fields in this record
        if (count($UsablePrompts) == 0) {
            return "";
        }

        # disable the button if we have already exceeded quota
        $DisableButton = $this->willExceedDailyUploadLimit([]) ||
            $this->willExceedDailyQuestionLimit([], $UsablePrompts);

        # return HTML for button
        $Button = new HtmlButton("ChatPDF");
        $Button->setIcon("FileExport.svg");

        if (!$this->fileFieldsContainFiles($Record)) {
            $Button->hide();
        }

        if ($DisableButton) {
            $Button->disable();
            $Html = '<div style="float: right; margin-left: 3px;"'
               .' title="ChatPDF Quota has been reached.">';
            return $Html.$Button->getHtml().'</div>';
        } else {
            $Button->setTitle(
                "Process all uploaded files in configured fields for this"
                ." record with ChatPDF."
            );
            $Button->setOnclick("handleManualButton(event)");
            $Button->addClass("mv-p-chatpdf-upload-button");
            $LoadingImgFile = $AF->gUIFile("loading.gif");
            return $Button->getHtml()
                .'<span style="display: none;"><img src="'
                .$LoadingImgFile.'"></span>';
        }
    }

    /**
     * Handles pressing the manual upload button on EditResource.
     * ChatPDF's responses for the provided files are returned to the
     * page that invoked this function.
     * @param int $RecordId The ID of the record that we are processing.
     * @param array $FileIds The IDs of the files to process.
     * @return array Associative array mapping field names to ChatPDF's responses
     * for those fields.
     */
    public function handleManualButtonPress(int $RecordId, array $FileIds): array
    {
        # get ChatPDF's responses for this record
        $Record = new Record($RecordId);
        $FileIds = $this->getFileIdsApplicableToRecord($Record, $FileIds);
        return $this->processManualUploadsForRecord($Record, $FileIds);
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Processes files from the manual button press.
     * @param Record $Record The record to process files for.
     * @param array $FileIds The files to process.
     * @return array Returns an error message for the following scenarios:
     * 1) There are no configured file fields provided.
     * 2) There are no usable prompts for this record.
     * 3) The files provided will exceed quota if processed.
     * Otherwise, an associative array is returned. The key is the name
     * of the field for the corresonding prompt, and the value is the
     * response given for that prompt.
     */
    private function processManualUploadsForRecord(
        Record $Record,
        array $FileIds
    ): array {
        # abort if there's no files to process
        if (count($FileIds) === 0) {
            return [
                "status" => "Error",
                "message" => "No files from configured file fields are provided."
            ];
        }

        # get usable prompts
        $UsablePrompts = $this->getUsableFieldsAndPromptsForRecord($Record);

        # abort if we don't have usable prompts
        if (count($UsablePrompts) === 0) {
            return [
                "status" => "Error",
                "message" => "There are no usable prompts for this record."
            ];
        }

        # abort if these new files will exceed quota
        if ($this->willExceedDailyUploadLimit($FileIds) ||
            $this->willExceedDailyQuestionLimit($FileIds, $UsablePrompts)
        ) {
            return [
                "status" => "Error",
                "message" => "The provided files will exceed quota."
            ];
        }

        # get responses from ChatPDF for these files
        $Responses = $this->getResponsesFromChatPDF($Record, $FileIds, $UsablePrompts);

        # use field's DB name as key for manual button handler
        $FormattedResponses = [];
        foreach ($Responses as $FieldId => $Response) {
            $Field = MetadataField::getField($FieldId);
            $FormattedResponses[$Field->dBFieldName()] = $Response;
        }

        if (count($FormattedResponses) == 0) {
            return [
                "status" => "Error",
                "message" => "No responses from ChatPDF, so nothing was populated."
                    ." Additional error information may be available on the"
                    ." System Administration page."
            ];
        }

        return $FormattedResponses;
    }

    /**
     * Uploads files to ChatPDF and gathers responses for them on a prompt-
     * by-prompt basis. Deletes the files from ChatPDF once finished.
     * @param Record $Record The record to get responses for.
     * @param array $FileIds The files to upload.
     * @param array $UsablePrompts The prompts to ask ChatPDF. This is an
     * associative array where the keys are field IDs, and the values are the
     * corresponding prompts to those field IDs.
     * @return array An associative array where keys are field IDs, and the
     * values are the responses for their corresponding prompt.
     */
    private function getResponsesFromChatPDF(
        Record $Record,
        array $FileIds,
        array $UsablePrompts
    ): array {
        # upload each file to ChatPDF and map the file ID to the source ID
        $UploadedFiles = [];
        foreach ($FileIds as $FileId) {
            $File = new File($FileId);
            $SrcId = $this->uploadToChatPDF($File);
            if ($SrcId !== null) {
                $UploadedFiles[$FileId] = $SrcId;
            }
        }

        # ask our questions for each uploaded file and save responses to the record
        $Responses = [];
        $NumericFieldTypes = MetadataSchema::MDFTYPE_NUMBER
                | MetadataSchema::MDFTYPE_DATE
                | MetadataSchema::MDFTYPE_TIMESTAMP;
        foreach ($UsablePrompts as $FieldId => $Prompt) {
            $Field = MetadataField::getField($FieldId);
            $FieldType = $Field->type();

            # add addendum on to question if available
            switch ($FieldType) {
                case MetadataSchema::MDFTYPE_NUMBER:
                    $Addendum = $this->getConfigSetting("PromptAddendumForNumbers");
                    break;
                case MetadataSchema::MDFTYPE_DATE:
                case MetadataSchema::MDFTYPE_TIMESTAMP:
                    $Addendum = $this->getConfigSetting("PromptAddendumForDates");
                    break;
                default:
                    $Addendum = "";
                    break;
            }
            if (strlen($Addendum)) {
                $Prompt .= "  ".$Addendum;
            }

            # if field type is not numeric (number, date, or timestamp)
            if (($FieldType & $NumericFieldTypes) === 0) {
                # ask question about all files and concatenate together responses
                $Response = [];
                foreach ($UploadedFiles as $FileId => $SrcId) {
                    $Response[] = $this->askQuestion((int)$FileId, $SrcId, $Prompt);
                }
                if (count($Response) > 0) {
                    $Response = implode("\r\r", $Response);
                } else {
                    $Response = null;
                }
            } else {
                # ask about only first file and use that response
                if (count($UploadedFiles) > 0) {
                    reset($UploadedFiles);
                    $FileId = key($UploadedFiles);
                    $SrcId = $UploadedFiles[$FileId];
                    $Response = $this->askQuestion((int)$FileId, $SrcId, $Prompt);
                } else {
                    $Response = null;
                }
            }

            if ($Response !== null) {
                $Responses[$FieldId] = $Response;
            }
        }

        # delete the files from ChatPDF after asking our questions
        foreach ($UploadedFiles as $FileId => $SrcId) {
            $this->deleteFromChatPDF($SrcId);
        }

        return $Responses;
    }

    /**
     * Gets the usable prompts from the config Actions setting for a given
     * record. A prompt is considered usable if its corresponding field is
     * in the same schema as the record, and its value for the record is
     * empty.
     * @param Record $Record The record to get usable prompts for.
     * @return array An associative array where the key is the field ID
     *      for the record, and the value is the prompt string.
     */
    private function getUsableFieldsAndPromptsForRecord(Record $Record): array
    {
        $Actions = $this->getConfigSetting("Actions");
        $Lines = self::splitTextIntoLinesWithContent($Actions);
        $RecordSchema = $Record->getSchema();
        $UsablePrompts = [];
        for ($I = 1; $I < count($Lines); $I += 2) {
            # check if field is in the same schema as the record, skip if not
            $QualifiedFieldName = substr($Lines[$I], strlen("Field:"));
            $QualifiedFieldName = trim($QualifiedFieldName);
            $FieldId = MetadataSchema::getCanonicalFieldIdentifier($QualifiedFieldName);
            if (!$RecordSchema->fieldExists($FieldId)) {
                continue;
            }

            # if the field is empty for this record, map the field id to the prompt
            $FieldValue = $Record->get($FieldId);
            if ($FieldValue === null || $FieldValue === '') {
                $Prompt = substr($Lines[$I - 1], strlen("Prompt:"));
                $Prompt = trim($Prompt);
                $UsablePrompts[$FieldId] = $Prompt;
            }
        }
        return $UsablePrompts;
    }

    /**
     * Check if the record has files in at least one of the configured file
     * fields.
     * @param Record $Record The record to check for files.
     * @return bool TRUE if record has files in any one of the
     *              configured file fields.
     */
    private function fileFieldsContainFiles(Record $Record): bool
    {
        $FileFieldIds = $this->getConfiguredFileFieldIdsForSchema(
            $Record->getSchema()
        );
        foreach ($FileFieldIds as $FieldId) {
            $FileIds = array_keys($Record->get($FieldId));
            $FilteredFileIds = $this->filterOutDisallowedTypesOfFiles($FileIds);
            if (count($FilteredFileIds) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filter out any files that are not one of our allowed file types.
     * @param array $FileIds IDs of files to check.
     * @return array Filtered list of file IDs.
     */
    private function filterOutDisallowedTypesOfFiles(array $FileIds): array
    {
        $AllowedFileTypes = $this->getConfigSetting("AllowedFileTypes");
        $FilterFunc = function ($FileId) use ($AllowedFileTypes) {
            $File = new File($FileId);
            return in_array($File->getMimeType(), $AllowedFileTypes);
        };
        return array_filter($FileIds, $FilterFunc);
    }

    /**
     * Get the IDs of all configured File fields for a specified schema.
     * @param MetadataSchema $Schema The schema to get file field IDs for.
     * @return array The list of file field IDs for the schema.
     */
    private function getConfiguredFileFieldIdsForSchema(MetadataSchema $Schema): array
    {
        $FileFieldIds = array_keys($Schema->getFields(MetadataSchema::MDFTYPE_FILE));
        $CfgFileFields = $this->getConfigSetting("FileFields") ?? [];
        return array_intersect($FileFieldIds, $CfgFileFields);
    }

    /**
     * Get the file IDs only for the fields that are configured for a record.
     * This is for manual file uploads and filters down the input array since it
     * contains file IDs from every file field for the record, configured or not.
     * @param Record $Record The record to filter for.
     * @param array $FileIdsForFields An associative array where indices are
     *      metadata field names, and values are arrays of file IDs that belong
     *      to the respective field indices.
     * for the record. Maps field names to an array of file IDs.
     * @return array A flat array of the file IDs that are from configured file fields.
     */
    private function getFileIdsApplicableToRecord(
        Record $Record,
        array $FileIdsForFields
    ): array {
        $Schema = $Record->getSchema();
        $CfgFileFieldIds = $this->getConfiguredFileFieldIdsForSchema(
            $Schema
        );
        $ApplicFileIds = [];
        foreach ($FileIdsForFields as $FieldName => $FileIds) {
            $FieldId = $Schema->getFieldIdByName($FieldName);
            if (in_array($FieldId, $CfgFileFieldIds)) {
                $ApplicFileIds = array_merge(
                    $ApplicFileIds,
                    array_map("intval", $FileIds)
                );
            }
        }
        $ApplicFileIds = $this->filterOutDisallowedTypesOfFiles($ApplicFileIds);
        return $ApplicFileIds;
    }

    /**
     * Get the IDs of all relevant files from a record, i.e. files from fields
     * that are configured for processing by this plugin.
     * @param Record $Record The record to get the file IDs for.
     * @return array The list of configurd file IDs for the record.
     */
    private function getFileIdsFromConfiguredFields(Record $Record): array
    {
        # get the file field IDs for this record
        $FileFieldIds = $this->getConfiguredFileFieldIdsForSchema(
            $Record->getSchema()
        );

        # get all file IDs from the list of configured fields
        $FileIds = [];
        foreach ($FileFieldIds as $FileFieldId) {
            $FileIds = array_merge($FileIds, array_keys($Record->get($FileFieldId)));
        }
        return $FileIds;
    }

    /**
     * Function to check if the current page should display the ChatPDF button based
     * on the RecordEditingPages configuration setting.
     * @param string $PageName The name of the page to check for.
     * @return bool TRUE if the button should be displayed
     */
    private function isConfiguredToDisplayOnPage(string $PageName): bool
    {
        # loop through each line in the RecordEditingPages configuration setting
        $Lines = explode("\n", $this->getConfigSetting("RecordEditingPages") ?? "");
        foreach ($Lines as $Line) {
            $Line_url = parse_url($Line);

            # check if PageName exists on the list
            $Page = (isset($Line_url["path"])) ? $Line_url["path"] : $Line;
            if ($Page == $PageName) {
                # return true if the query params are not set
                if (!isset($Line_url["query"])) {
                    return true;
                }

                # iterate through key value pairs to check if query values are set
                # to null or if the GET or POST arrays contain all of the key value
                # pairs in the query params
                parse_str($Line_url["query"], $KeyValuePairs);
                foreach ($KeyValuePairs as $Key => $Value) {
                    if ($Value === "" && !isset($_POST[$Key]) && !isset($_GET[$Key])) {
                        continue;
                    }
                    if ((isset($_POST[$Key]) && $_POST[$Key] == $Value)
                        || (isset($_GET[$Key]) && $_GET[$Key] == $Value)) {
                        continue;
                    }

                    # prevent returning true and move on to next line in the config setting
                    continue 2;
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Upload the PDF file to ChatPDF and get its source ID from the API
     * response.
     * @param File $File The file to upload.
     * @return string|null The uploaded file's source ID returned from ChatPDF, or
     * NULL if the file is bigger than the configured max size or the upload failed.
     */
    private function uploadToChatPDF(File $File): ?string
    {
        # return null if this file exceeds the configured size limit
        $MaxFileSize = $this->getConfigSetting("MaxFileSize");
        $FileSize = $File->getLength();
        # convert to bytes to check file size
        if ($FileSize > $MaxFileSize * (2 ** 20)) {
            return null;
        }

        $AF = ApplicationFramework::getInstance();

        # check that file exists, bail if not.
        if (!file_exists($File->getNameOfStoredFile())) {
            $AF->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "ChatPDF: ".$File->getNameOfStoredFile()." for file ID "
                .$File->id()." could not be uploaded because it does not exist."
            );
            return null;
        }

        # make request to ChatPDF
        $Url = self::CHATPDF_API_BASE_URL."sources/add-file";
        $CurlFile = new CURLFile(
            $File->getNameOfStoredFile(),
            $File->getMimeType()
        );
        $Data = ["file" => $CurlFile];
        $Result = $this->restCommand($Url, $Data);

        # if upload failed, log a message and return null
        if ($Result === null) {
            $AF->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "ChatPDF: Upload failed for file with ID ".$File->id()."."
            );
            return null;
        }

        # if upload succeeded but reply did not contain a sourceId,
        # log a message and return null
        if (!isset($Result["sourceId"])) {
            $AF->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "ChatPDF: Did not receive source ID in response to"
                ." upload of file with ID ".$File->id()."."
                ." Reply was: ".print_r($Result, true)
            );
            return null;
        }

        # record this upload in the DB and return the source ID
        $SrcId = $Result["sourceId"];
        $Query = "INSERT INTO ChatPDF_UploadedFiles VALUES"
            ." (".$File->id().", '".$SrcId."', NOW());";
        $this->DB->query($Query);

        return $SrcId;
    }

    /**
     * Deletes an uploaded PDF file from ChatPDF by its source ID.
     * @param string $SrcId The uploaded file's source ID to be deleted.
     */
    private function deleteFromChatPDF(string $SrcId): void
    {
        $Url = self::CHATPDF_API_BASE_URL."sources/delete";
        $Data = json_encode(["sources" => [$SrcId]]);

        # abort if we can't encode this source ID
        if ($Data === false) {
            return;
        }

        # attempt to delete file
        $Response = $this->restCommand($Url, $Data);

        # will get empty string on successful requests
        if ($Response !== "") {
            (ApplicationFramework::getInstance())->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "ChatPDF: Failed to delete file from ChatPDF."
                ." Response was: ".print_r($Response, true)
            );
        }
    }

    /**
     * Asks ChatPDF a single question for a single file.
     * @param int $FileId The ID of the file to ask about.
     * @param string $SrcId The source ID of the file.
     * @param string $Question The question to ask ChatPDF for this file.
     * @return string ChatPDF's answer to the question.
     */
    private function askQuestion(
        int $FileId,
        string $SrcId,
        string $Question
    ): string {
        # get the question ID for this question
        $QuestionId = $this->getQuestionId($Question);

        # return empty string if this file and question pair has reached the
        # max question retry limit
        $MaxQuestionRetries = (int) $this->getConfigSetting("MaxQuestionRetries");
        $Retries = $this->getRetryCountForFileAndQuestion($FileId, $QuestionId);
        if ($Retries === $MaxQuestionRetries) {
            return "";
        }

        $Url = self::CHATPDF_API_BASE_URL."chats/message";
        $Data = json_encode([
            "sourceId" => $SrcId,
            "messages" => [
                [
                    "role" => "user",
                    "content" => $Question
                ]
            ]
        ]);
        # abort if encoding failed
        if ($Data === false) {
            return "";
        }

        # record this inquiry in AskedQuestions
        $this->DB->query(
            "INSERT INTO ChatPDF_AskedQuestions VALUES "
            ."(".$FileId.", ".$QuestionId.", NOW())"
        );

        # fetch ChatPDF's response to this question
        $Response = $this->restCommand($Url, $Data);

        # return empty string and log a message if there's an error
        if ($Response === null) {
            (ApplicationFramework::getInstance())->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "ChatPDF: Failed to ask ChatPDF \""
                .$Question."\" for file ID ".$FileId."."
            );
            return "";
        }

        # return empty string and log a message if no content was returned
        if (!isset($Response["content"])) {
            (ApplicationFramework::getInstance())->logMessage(
                ApplicationFramework::LOGLVL_WARNING,
                "ChatPDF: Repponse to question \""
                .$Question."\" for file ID ".$FileId
                ." had no 'content' element."
                ." Response was: ".print_r($Response, true)."."
            );
            return "";
        }

        # otherwise, return the answer
        return $Response["content"];
    }

    /**
     * Get the question ID stored in the DB for a question. If the question
     * doesn't exist in the DB, an entry is added for it, and we get its
     * new question ID.
     * @param string $Question The exact text for the question.
     * @return int The question ID from the DB.
     */
    private function getQuestionId(string $Question): int
    {
        # lock the Questions table
        $this->DB->query("LOCK TABLES ChatPDF_Questions WRITE;");

        # attempt to get the question ID for this question
        $this->DB->query(
            "SELECT QuestionId FROM ChatPDF_Questions WHERE QuestionText = '"
            .$Question
            ."';"
        );

        if ($this->DB->numRowsSelected() === 0) {
            # add this question to the Questions table, then get its new ID
            $this->DB->query(
                "INSERT INTO ChatPDF_Questions (QuestionText) VALUES ('"
                .$Question
                ."');"
            );
            $QuestionId = $this->DB->getLastInsertId();
        } else {
            # retrieve the question ID from the selected row
            $Row = $this->DB->fetchRow();
            $QuestionId = (int) $Row["QuestionId"];
        }

        $this->DB->query("UNLOCK TABLES;");
        return $QuestionId;
    }

    /**
     * Performs a cURL POST request with a specified URL and request body.
     * @param string $Url The URL to send the POST request to.
     * @param string|array $Data The body for the POST request, either a JSON string
     *      or an array.
     * @return mixed The decoded JSON response.
     */
    private function restCommand(string $Url, $Data): mixed
    {
        # save cURL instance since it's expensive to create one for every request
        static $Context;
        if (!isset($Context)) {
            $Context = curl_init();
        }

        # cURL configuration
        $HttpHeader = ["x-api-key: ".$this->getConfigSetting("ApiKey")];
        if (strpos($Url, "add-file") === false) {
            # requests other than uploads have a JSON body
            $HttpHeader[] = "Content-type: application/json";
        }
        $CurlParams = [
            CURLOPT_URL => $Url,
            CURLOPT_HTTPHEADER => $HttpHeader,
            CURLOPT_POST => true, # send POST request
            CURLOPT_RETURNTRANSFER => true, # get results as string instead of STDOUT
            CURLOPT_POSTFIELDS => $Data # POST data
        ];
        curl_setopt_array($Context, $CurlParams);

        # send request, handle errors
        $CurlResponse = curl_exec($Context);
        if ($CurlResponse === false) {
            $Errno = curl_errno($Context);
            (ApplicationFramework::getInstance())->logMessage(
                ApplicationFramework::LOGLVL_ERROR,
                "ChatPDF: Unable to make CURL request. "
                ."CURL errno: ".$Errno
            );
            return null;
        }

        # if response was empty, return empty string
        if ($CurlResponse == "") {
            return "";
        }

        # otherwise, decode and return response
        return json_decode((string)$CurlResponse, true);
    }

    /**
     * Deletes the DB record of uploaded files that were uploaded more than 24
     * hours ago. The table is used to monitor the number of daily uploads to
     * keep it under the limit enforced by the user's ChatPDF plan.
     */
    private function pruneRecordOfUploadedFiles(): void
    {
        $this->DB->query(
            "DELETE FROM ChatPDF_UploadedFiles WHERE "
            ."DateUploaded <= NOW() - INTERVAL 1 DAY;"
        );
    }

    /**
     * Deletes rows from ChatPDF_AskedQuestions for files that no longer exist,
     * i.e. files that don't have a row in the Files table.
     */
    private function pruneStaleAskedQuestions(): void
    {
        $this->DB->query(
            "DELETE FROM ChatPDF_AskedQuestions WHERE "
            ."FileId NOT IN (SELECT Files.FileId FROM Files);"
        );
    }

    /**
     * Process as many records from the queue as we can, i.e. upload their
     * relevant files to ChatPDF and ask questions about them. This is limited
     * by the file upload and question quotas.
     * A record can be dequeued only if:
     * 1) The number of files the record needs to process, combined with the
     * number of files uploaded within the past 24 hours, is within the daily
     * upload quota, and
     * 2) The number of questions we need to ask for this record, combined
     * with the number of questions asked within the past 24 hours, is within
     * the daily question quota.
     */
    private function processQueuedRecords(): void
    {
        # get all record IDs from queue
        $this->DB->query("SELECT * FROM ChatPDF_RecordQueue");
        $RecordIds = $this->DB->fetchColumn("RecordId");
        $RecordIds = array_map("intval", $RecordIds);

        # go through each record ID
        # if we can process it now, dequeue it and process it
        # otherwise, break because we can't process more right now
        foreach ($RecordIds as $RecordId) {
            $Record = new Record($RecordId);
            $FileIds = $this->getFileIdsForQueuedRecord($Record);

            # only dequeue and process the record if we're within both quotas
            $WithinUploadQuota = !$this->willExceedDailyUploadLimit($FileIds);
            $UsableQuestions = $this->getUsableFieldsAndPromptsForRecord($Record);
            $WithinQuestionQuota = !$this->willExceedDailyQuestionLimit(
                $FileIds,
                $UsableQuestions
            );
            if ($WithinUploadQuota && $WithinQuestionQuota) {
                $this->dequeueRecord($RecordId);
                $this->processAutomaticUploadsForRecord($Record, $FileIds, true);
            } else {
                break;
            }
        }
    }

    /**
     * Gets the file IDs from configured fields for a queued record.
     * @param Record $Record The record to check.
     * @return array An array of file IDs for this record, minus
     * the file IDs that are within the retry interval.
     */
    private function getFileIdsForQueuedRecord(Record $Record): array
    {
        # get the configured file IDs for this record
        $FileIds = $this->getFileIdsFromConfiguredFields($Record);

        # get the file IDs of the questions asked in the min retry interval
        $AskedFileIds = $this->getFileIdsForRecentlyAskedQuestions();

        # the files that we can process for this record are the ones that haven't
        # been asked in the past min retry interval
        return array_diff($FileIds, $AskedFileIds);
    }

    /**
     * Get the file IDs of the questions asked during the configured minimum
     * retry interval.
     * @return array The array of file IDs.
     */
    private function getFileIdsForRecentlyAskedQuestions(): array
    {
        $CfgMinRetryInterval = (int) $this->getConfigSetting("MinRetryInterval");
        $Query = "SELECT FileId FROM ChatPDF_AskedQuestions WHERE DateAsked >= NOW()"
            ." - INTERVAL ".$CfgMinRetryInterval." MINUTE;";
        $this->DB->query($Query);
        $AskedFileIds = $this->DB->fetchColumn("FileId");
        return array_map("intval", $AskedFileIds);
    }

    /**
     * Determine whether uploading a set of files will exceed our daily upload
     * limit.
     * @param array $Files The files to be uploaded.
     * @return bool TRUE if we will be over the limit, FALSE otherwise.
     */
    private function willExceedDailyUploadLimit(array $Files): bool
    {
        # get configured limit, skip check if it's 0 (unlimited)
        $CfgDailyUploadLimit = (int) $this->getConfigSetting("DailyUploads");
        if ($CfgDailyUploadLimit === 0) {
            return false;
        }

        # get number of currently uploaded files
        $UploadCount = $this->getCountOfRecentlyUploadedFiles();

        return $UploadCount + count($Files) > $CfgDailyUploadLimit;
    }

    /**
     * Determine whether uploading a set of files will exceed our daily
     * question limit.
     * @param array $Files The files to be uploaded.
     * @param array $Questions The questions to be asked.
     * @return bool TRUE if we will be over the limit, FALSE otherwise.
     */
    private function willExceedDailyQuestionLimit(
        array $Files,
        array $Questions
    ): bool {
        # get configured limit, skip check if it's 0 (unlimited)
        $CfgDailyQuestionLimit = (int) $this->getConfigSetting("DailyQuestions");
        if ($CfgDailyQuestionLimit === 0) {
            return false;
        }

        # get number of questions asked in the past 24 hours
        $QuestionCount = $this->getCountOfRecentlyAskedQuestions();

        return $QuestionCount + (count($Files) * count($Questions))
            > $CfgDailyQuestionLimit;
    }

    /**
     * Gets the number of files uploaded to ChatPDF in the last 24 hours.
     * @return int The number of uploaded files.
     */
    private function getCountOfRecentlyUploadedFiles(): int
    {
        return (int) $this->DB->queryValue(
            "SELECT COUNT(*) AS N FROM ChatPDF_UploadedFiles",
            "N"
        );
    }

    /**
     * Gets the number of questions asked in the past 24 hours.
     * @return int The number of questions asked.
     */
    private function getCountOfRecentlyAskedQuestions(): int
    {
        return (int) $this->DB->queryValue(
            "SELECT COUNT(*) AS N FROM ChatPDF_AskedQuestions"
            ." WHERE DateAsked >= NOW() - INTERVAL 1 DAY;",
            "N"
        );
    }

    /**
     * Queue a record by its ID to be processed later.
     * @param Record $Record The record to add to the queue.
     */
    private function enqueueRecord(Record $Record): void
    {
        $this->DB->query(
            "INSERT INTO ChatPDF_RecordQueue VALUES ("
            .$Record->id().");"
        );
    }

    /**
     * Dequeue a record by its ID in the DB.
     * @param int $RecordId The ID of the record to dequeue.
     */
    private function dequeueRecord(int $RecordId): void
    {
        # remove the first row of this record ID from ChatPDF_RecordQueue
        $this->DB->query(
            "DELETE FROM ChatPDF_RecordQueue WHERE RecordId = "
            .$RecordId." LIMIT 1;"
        );
    }

    /**
     * Determines whether a file is within its minimum retry interval.
     * @param int $FileId The ID of the file to check.
     * @return bool TRUE if the file is within the retry interval, FALSE otherwise.
     */
    private function fileIsWithinRetryInterval(int $FileId): bool
    {
        return in_array($FileId, $this->getFileIdsForRecentlyAskedQuestions());
    }

    /**
     * Gets the number of retries for a file and question pair.
     * @param int $FileId The ID of the file.
     * @param int $QuestionId The ID of the question.
     * @return int The number of retries for this pair.
     */
    private function getRetryCountForFileAndQuestion(
        int $FileId,
        int $QuestionId
    ): int {
        # get the number of queries for this file x question pair
        $QueryCount = $this->DB->query(
            "SELECT COUNT(*) AS N FROM ChatPDF_AskedQuestions WHERE "
            ."FileId = ".$FileId." AND QuestionId = ".$QuestionId.";",
            "N"
        );

        # the number retries is the query count - 1
        return $QueryCount - 1;
    }

    /**
     * Split supplied text into an array of lines, removing any leading or
     * trailing whitespace and discarding any empty or whitespace-only lines.
     * @return array Lines of text, with a sequential numerical index.
     */
    private static function splitTextIntoLinesWithContent(string $Text): array
    {
        # split text into lines
        $Lines = preg_split('/\R/', $Text);
        if ($Lines === false) {
            throw new Exception("Splitting lines failed (should not be possible).");
        }

        # remove any leading or trailing whitespace on lines
        $Lines = array_map("trim", $Lines);

        # filter out any empty lines
        $Lines = array_filter($Lines);

        # reindex so that lines have a sequential numerical index
        $Lines = array_values($Lines);

        return $Lines;
    }

    private $DB;

    # base URL for accessing ChatPDF's API
    private const CHATPDF_API_BASE_URL = "https://api.chatpdf.com/v1/";

    # supported metadata field types for the destination fields to be
    #   populated with ChatPDF's responses
    private const SUPPORTED_MDFTYPES = MetadataSchema::MDFTYPE_TEXT |
        MetadataSchema::MDFTYPE_PARAGRAPH |
        MetadataSchema::MDFTYPE_NUMBER |
        MetadataSchema::MDFTYPE_DATE |
        MetadataSchema::MDFTYPE_TIMESTAMP;

    private const SQL_TABLES = [
        "UploadedFiles" => "CREATE TABLE IF NOT EXISTS ChatPDF_UploadedFiles (
            FileId INT NOT NULL,
            SrcId TEXT NOT NULL,
            DateUploaded TIMESTAMP NOT NULL
        );",
        "AskedQuestions" => "CREATE TABLE IF NOT EXISTS ChatPDF_AskedQuestions (
            FileId INT NOT NULL,
            QuestionId INT NOT NULL,
            DateAsked TIMESTAMP NOT NULL
        );",
        "Questions" => "CREATE TABLE IF NOT EXISTS ChatPDF_Questions (
            QuestionId INT NOT NULL AUTO_INCREMENT,
            QuestionText TEXT NOT NULL,
            PRIMARY KEY (QuestionId)
        );",
        "RecordQueue" => "CREATE TABLE IF NOT EXISTS ChatPDF_RecordQueue (
            RecordId INT NOT NULL
        );",
    ];
}
