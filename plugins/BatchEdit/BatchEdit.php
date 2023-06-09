<?PHP
#
#   FILE:  BatchEdit.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2014-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;

use Metavus\FormUI;
use Metavus\MetadataField;
use Metavus\MetadataSchema;
use Metavus\Plugins\Folders\Folder;
use Metavus\PrivilegeSet;
use Metavus\User;
use ScoutLib\Plugin;
use ScoutLib\ApplicationFramework;

class BatchEdit extends Plugin
{
    /**
     * Register information about this plugin.
     */
    public function register()
    {
        $this->Name = "Batch Editing";
        $this->Version = "1.0.2";
        $this->Description = "Allows resources in a folder to be edited en masse.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.0.0",
            "Folders" => "1.0.7"
        ];
        $this->EnabledByDefault = true;
    }

    /**
     * Set up configuration options.
     * @return NULL on success, error string otherwise.
     */
    public function setUpConfigOptions()
    {
        # (can't use AF::getPageName() because page name is not yet set when
        # we are called)
        $OnConfigPage = (isset($_GET["P"]) && $_GET["P"] == "PluginConfig" &&
                         isset($_GET["PN"]) && $_GET["PN"] == $this->getBaseName());

        $AllowedFields = !$OnConfigPage ? $this->configSetting("CachedFieldList") : null;
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
            $this->configSetting("CachedFieldList", $AllowedFields);
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
     * Upgrade from a previous version.
     * @param string $PreviousVersion Previous version of the plugin.
     * @return null|string Returns NULL on success and an error message otherwise.
     */
    public function upgrade(string $PreviousVersion)
    {
        # upgrade from versions < 1.0.1 to 1.0.1
        if (version_compare($PreviousVersion, "1.0.1", "<")) {
            if (is_array($this->configSetting("RequiredPrivs"))) {
                $RequiredPrivs = new PrivilegeSet();
                $RequiredPrivs->addPrivilege($this->configSetting("RequiredPrivs"));
                $this->configSetting("RequiredPrivs", $RequiredPrivs);
            }
        }
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
     */
    public function handleFieldAdded(int $FieldId)
    {
        $Field = new MetadataField($FieldId);
        if ($Field->schemaId() == MetadataSchema::SCHEMAID_USER) {
            return;
        }

        if (!$Field->editable()) {
            return;
        }

        if (($Field->type() & $this->FieldTypes) == 0) {
            return;
        }

        $AllowedFields = $this->configSetting("AllowedFields");
        $AllowedFields[] = $FieldId;

        $this->configSetting("AllowedFields", $AllowedFields);
    }

    /**
     * Handle field deletion.
     * @param int $FieldId Identifier of field that is about to be deleted.
     */
    public function handlePreFieldDelete($FieldId)
    {
        $AllowedFields = $this->configSetting("AllowedFields");

        if (!in_array($FieldId, $AllowedFields)) {
            return;
        }

        $AllowedFields = array_diff($AllowedFields, [$FieldId]);

        $this->configSetting("AllowedFields", $AllowedFields);
    }

    /**
     * HTML insertion point handler, used to add a 'bulk edit' button
     * to the manage folders page.
     * @param string $PageName Name of currently loaded page.
     * @param string $Location Location where HTML can be inserted.
     * @param mixed $Context Context containing page-defined context data.
     */
    public function insertButton($PageName, $Location, $Context = null)
    {
        $AF = ApplicationFramework::getInstance();

        if ($Location != "Folder Buttons") {
            return;
        }

        $User = User::getCurrentUser();
        if (!$User->isLoggedIn()) {
            return;
        }

        if (!$this->configSetting("RequiredPrivs")->meetsRequirements($User)) {
            return;
        }

        $Folder = new Folder($Context["FolderId"]);
        if ($Folder->getItemCount() == 0) {
            return;
        }

        $Url = "index.php?P=P_BatchEdit_Edit&amp;FI=".$Context["FolderId"];
        $CssClasses = "btn btn-primary btn-sm bulk-edit-button mv-button-iconed";
        $Icon = "<img src='".$AF->GUIFile('MagicWand.svg')."' alt='' class='mv-button-icon' />";
        if ($PageName == "P_Folders_ManageFolders") {
            print '<a href="'.$Url.'" class="'.$CssClasses.'">'.$Icon.' Batch</a>';
        } elseif ($PageName == "P_Folders_ViewFolder") {
            print '<a href="'.$Url.'" class="'.$CssClasses.'">'.$Icon.' Batch Edit</a>';
        }
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
