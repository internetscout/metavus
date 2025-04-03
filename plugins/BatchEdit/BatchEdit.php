<?PHP
#
#   FILE:  BatchEdit.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2014-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Metavus\FormUI;
use Metavus\HtmlButton;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\Folders\Folder;
use Metavus\User;
use ScoutLib\Plugin;
use ScoutLib\ApplicationFramework;

class BatchEdit extends Plugin
{
    /**
     * Register information about this plugin.
     * @return void
     */
    public function register(): void
    {
        $this->Name = "Batch Editing";
        $this->Version = "1.0.2";
        $this->Description = "Allows resources in a folder to be edited en masse.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "Folders" => "1.0.7"
        ];
        $this->EnabledByDefault = true;
    }

    /**
     * Set up configuration options.
     * @return NULL on success, error string otherwise.
     */
    public function setUpConfigOptions(): ?string
    {
        # (can't use AF::getPageName() because page name is not yet set when
        # we are called)
        $OnConfigPage = (isset($_GET["P"]) && $_GET["P"] == "PluginConfig" &&
                         isset($_GET["PN"]) && $_GET["PN"] == $this->getBaseName());

        $AllowedFields = !$OnConfigPage ? $this->getConfigSetting("CachedFieldList") : null;
        if (is_null($AllowedFields)) {
            $AllowedFields = [];

            # iterate over all schemas, constructing a list of editable
            # fields in each
            $AllSchemas = MetadataSchema::getAllSchemas();
            foreach ($AllSchemas as $Schema) {
                # don't allow batch editing of user fields
                if ($Schema->id() == MetadataSchema::SCHEMAID_USER) {
                    continue;
                }

                # add a prefix for schemas that aren't the resource schema
                $Pfx = ($Schema->id() == MetadataSchema::SCHEMAID_DEFAULT) ?
                    "" : $Schema->Name().": ";
                $SchemaFields = $Schema->getFields($this->FieldTypes);
                foreach ($SchemaFields as $Field) {
                    if ($Field->editable()) {
                        $AllowedFields[$Field->id()] = $Pfx.$Field->Name();
                    }
                }
            }
            $this->setConfigSetting("CachedFieldList", $AllowedFields);
        }

        $this->CfgSetup["AllowedFields"] = [
            "Type" => "Option",
            "Label" => "Allowed Fields",
            "Help" => "Fields allowed for bulk editing",
            "AllowMultiple" => true,
            "Options" => $AllowedFields,
            "OptionType" => FormUI::OTYPE_LIST,
            "Rows" => count($AllowedFields),
            "Default" => array_keys($AllowedFields),
        ];

        $this->CfgSetup["RequiredPrivs"] = [
            "Type" => FormUI::FTYPE_PRIVILEGES,
            "Label" => "Required Privileges",
            "AllowMultiple" => true,
            "Help" => "Users with any of the selected privileges will "
                      ."be able to perform batch edits on fields they are otherwise "
                      ."able to edit (i.e.only those they could edit from the "
                      ."Edit Resource page)",
            "Default" => [PRIV_SYSADMIN],
        ];

        return null;
    }

    /**
     * Hook the events into the application framework.
     * @return array An array of events to be hooked into the application framework.
     */
    public function hookEvents(): array
    {
        return [
            "EVENT_HTML_INSERTION_POINT" => "insertButton",
            "EVENT_FIELD_ADDED" => "handleFieldAdded",
            "EVENT_PRE_FIELD_DELETE" => "handlePreFieldDelete",
        ];
    }

    /**
     * Handle field addition.
     * @param int $FieldId Identifier of newly added field.
     * @return void
     */
    public function handleFieldAdded(int $FieldId): void
    {
        $Field = MetadataField::getField($FieldId);
        if ($Field->schemaId() == MetadataSchema::SCHEMAID_USER) {
            return;
        }

        if (!$Field->editable()) {
            return;
        }

        if (($Field->type() & $this->FieldTypes) == 0) {
            return;
        }

        $AllowedFields = $this->getConfigSetting("AllowedFields");
        $AllowedFields[] = $FieldId;

        $this->setConfigSetting("AllowedFields", $AllowedFields);
    }

    /**
     * Handle field deletion.
     * @param int $FieldId Identifier of field that is about to be deleted.
     * @return void
     */
    public function handlePreFieldDelete($FieldId): void
    {
        $AllowedFields = $this->getConfigSetting("AllowedFields");

        if (!in_array($FieldId, $AllowedFields)) {
            return;
        }

        $AllowedFields = array_diff($AllowedFields, [$FieldId]);

        $this->setConfigSetting("AllowedFields", $AllowedFields);
    }

    /**
     * HTML insertion point handler, used to add a 'bulk edit' button
     * to the manage folders page.
     * @param string $PageName Name of currently loaded page.
     * @param string $Location Location where HTML can be inserted.
     * @param mixed $Context Context containing page-defined context data.
     * @return void
     */
    public function insertButton($PageName, $Location, $Context = null): void
    {
        $AF = ApplicationFramework::getInstance();

        if ($Location != "Folder Buttons") {
            return;
        }

        $User = User::getCurrentUser();
        if (!$User->isLoggedIn()) {
            return;
        }

        if (!$this->getConfigSetting("RequiredPrivs")->meetsRequirements($User)) {
            return;
        }

        $Folder = new Folder($Context["FolderId"]);
        if ($Folder->getItemCount() == 0) {
            return;
        }

        $Button = new HtmlButton($PageName == "P_Folders_ManageFolders" ? "Batch" : "Batch Edit");
        $Button->setIcon("MagicWand.svg");
        $Button->setSize(HtmlButton::SIZE_SMALL);
        $Button->addClass("bulk-edit-button");
        $Button->setLink("index.php?P=P_BatchEdit_Edit&FI={$Context["FolderId"]}");
        print $Button->getHtml();
    }

    # fields that can be batch edited
    private $FieldTypes =  MetadataSchema::MDFTYPE_TEXT |
        MetadataSchema::MDFTYPE_PARAGRAPH |
        MetadataSchema::MDFTYPE_NUMBER |
        MetadataSchema::MDFTYPE_DATE |
        MetadataSchema::MDFTYPE_TIMESTAMP |
        MetadataSchema::MDFTYPE_FLAG |
        MetadataSchema::MDFTYPE_TREE |
        MetadataSchema::MDFTYPE_CONTROLLEDNAME |
        MetadataSchema::MDFTYPE_OPTION |
        MetadataSchema::MDFTYPE_URL |
        MetadataSchema::MDFTYPE_REFERENCE;
}
