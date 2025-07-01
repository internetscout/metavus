<?PHP
#
#   FILE:  FolderDisplayUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Folders;
use Metavus\HtmlButton;
use Metavus\MetadataSchema;
use Metavus\Plugins\Folders;
use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\StdLib;
use Exception;

# ----- LOCAL FUNCTIONS ------------------------------------------------------

class FolderDisplayUI
{
    /**
     * Print the given folder.
     * @param int $ResourceFolderId the ID of the user's root folder
     * @param Folder $Folder the folder to print
     * @param Folder|null $Previous previous folder or NULL if at the beginning
     * @param Folder|null $Next next folder or NULL if at the end
     * @param boolean $IsSelected whether or not the folder is selected
     * @return void
     */
    public static function printFolder(
        int $ResourceFolderId,
        Folder $Folder,
        ?Folder $Previous = null,
        ?Folder $Next = null,
        bool $IsSelected = false
    ): void {
        $AF = ApplicationFramework::getInstance();

        $ItemIds = $Folder->getItemIds();
        $HasItems = (count($ItemIds) > 0) ? true : false ;
        $IsShared = $Folder->isShared();

        $HasPrevious = !is_null($Previous);
        $SafePreviousId = ($HasPrevious) ? intval($Previous->id()) : null;

        $HasNext = !is_null($Next);
        $SafeNextId = ($HasNext) ? intval($Next->id()) : null;

        $SafeResourceFolderId = intval($ResourceFolderId);
        $SafeFolderId = intval($Folder->id());
        $SafeFolderName = defaulthtmlentities($Folder->name());
        $TruncatedName = StdLib::neatlyTruncateString($Folder->name(), 18);
        $SafeTruncatedName = defaulthtmlentities($TruncatedName);
        $SafeFolderDescForRollover = defaulthtmlentities(trim(
            strip_tags($Folder->note())
        ));
        $Offset = isset($_GET["FO".$Folder->id()]) ? intval($_GET["FO".$Folder->id()]) : 0 ;

        $FoldersPlugin = Folders::getInstance();
        $CanTransferFolder = ($FoldersPlugin->getConfigSetting("PrivsToTransferFolders"))
            ->meetsRequirements(User::getCurrentUser());

        $ItemsPerPage = 500;
        $Overlap = 50;
        $Paginate = count($ItemIds) > $ItemsPerPage;
        $UrlFiltered = preg_replace(
            '/\&FO'.$Folder->id().'=[^&]+/',
            '',
            $_SERVER["REQUEST_URI"]
        );

        $FirstPageButton = new HtmlButton("|<");
        $FirstPageButton->setSize(HtmlButton::SIZE_SMALL);
        $FirstPageButton->setLink($UrlFiltered);

        $PageLeftButton = new HtmlButton("<");
        $PageLeftButton->setSize(HtmlButton::SIZE_SMALL);
        $PageLeftButton->setLink($UrlFiltered . "&FO" . $Folder->id() . "="
            . ($Offset - $ItemsPerPage));

        $PageRightButton = new HtmlButton(">");
        $PageRightButton->setSize(HtmlButton::SIZE_SMALL);
        $PageRightButton->setLink($UrlFiltered . "&FO" . $Folder->id() . "="
            . ($Offset + $ItemsPerPage));

        $LastPageButton = new HtmlButton(">|");
        $LastPageButton->setSize(HtmlButton::SIZE_SMALL);
        $LastPageButton->setLink($UrlFiltered . "&FO" . $Folder->id() . "="
            . (count($ItemIds) - ($ItemsPerPage + $Overlap)));

        $SelectButton = new HtmlButton("Select");
        $SelectButton->setSize(HtmlButton::SIZE_SMALL);
        $SelectButton->setIcon("Check.svg");
        $SelectButton->setTitle("Select this folder as the current folder");
        $SelectButton->addClass("mv-folders-select-button");
        $SelectButton->addAttributes([
            "data-folderid" => $SafeFolderId,
        ]);
        $SelectButton->setOnclick("Folders.handleSelectButtonClick()");

        $TransferButton = new HtmlButton("Transfer");
        $TransferButton->setSize(HtmlButton::SIZE_SMALL);
        $TransferButton->setIcon("Exchange.svg");
        $TransferButton->setTitle("Transfer this folder to other user");
        $TransferButton->addClass("cw-folder-transfer-folder-button");
        $TransferButton->addSemanticClass("btn-danger");
        $TransferButton->addAttributes([
            "data-folderid" => $SafeFolderId,
            "data-foldername" => $SafeFolderName
        ]);
        $TransferButton->setLink("index.php?P=P_Folders_ConfirmFolderTransfer&FID=".$SafeFolderId);

        $DuplicateButton = new HtmlButton("Duplicate");
        $DuplicateButton->setSize(HtmlButton::SIZE_SMALL);
        $DuplicateButton->setIcon("Copy.svg");
        $DuplicateButton->setTitle("Create a duplicate of this folder");
        $DuplicateButton->setLink("index.php?P=P_Folders_DuplicateFolders&FID=".$SafeFolderId);

        $SettingsButton = new HtmlButton("Settings");
        $SettingsButton->setSize(HtmlButton::SIZE_SMALL);
        $SettingsButton->setIcon("cog.png");
        $SettingsButton->setTitle("Edit folder");
        $SettingsButton->addAttributes(["data-folderid" => $SafeFolderId]);
        $SettingsButton->setLink("index.php?P=P_Folders_EditFolderSettings&ID=".$SafeFolderId);

        $DeleteButton = new HtmlButton("Delete");
        $DeleteButton->setSize(HtmlButton::SIZE_SMALL);
        $DeleteButton->setIcon("Delete.svg");
        $DeleteButton->setTitle("Delete this folder");
        $DeleteButton->setLink("index.php?P=P_Folders_ConfirmDeleteFolder&FolderId=".$SafeFolderId);

        $ClearButton = new HtmlButton("Clear");
        $ClearButton->setSize(HtmlButton::SIZE_SMALL);
        $ClearButton->setIcon("Broom.svg");
        $ClearButton->setTitle("Remove all resources from this folder");
        $ClearButton->addClass("mv-folders-clear-confirmlink");
        $ClearButton->addSemanticClass("btn-danger");
        $ClearButton->setId("mv-folders-folderlink".$SafeFolderId);
        $ClearButton->addAttributes([
            "data-parentfolderid" => $SafeFolderId,
            "data-folderid" => $SafeFolderId
        ]);
        $ClearButton->setLink("index.php?P=P_Folders_RemoveAllItems&FolderId=".$SafeFolderId);

        $FolderCssClasses = "mv-folders-folder"
            .(!$IsShared ? " mv-notpublic" : "")
            .($IsSelected ? " mv-folders-selected" : "");
        // @codingStandardsIgnoreStart
        ?>

    <div id="Folders_Errors<?= $SafeFolderId ?>"></div>
    <div class="border rounded mv-section mv-section-elegant mv-html5-section <?= $FolderCssClasses; ?>" data-parentfolderid="<?= $SafeResourceFolderId ?>" data-folderid="<?= $SafeFolderId ?>" data-itemid="<?= $SafeFolderId ?>">
      <div class="mv-section-header" title="<?= $SafeFolderDescForRollover ?>">
        <div class="container-fluid border p-1 rounded bg-light">
          <div class="row">
            <div class="col">
              <img class="mv-folders-selectedfoldericon" src="<?PHP $AF->pUIFile("asterisk_yellow.png"); ?>" alt="(Current Folder)" />
              <a id="mv-folders-nametitle<?= $SafeFolderId ?>" class="mv-folders-folder-name" href="index.php?P=P_Folders_ViewFolder&amp;FolderId=<?= $SafeFolderId ?>" data-folderid="<?= $SafeFolderId ?>">
              <?= StdLib::neatlyTruncateString($SafeFolderName, 40) ?>  (<?= count($ItemIds) ?>)<!-- the whitespace adds to the rendered anchor
              --></a>
              <div class="cw-folder-actions float-end">
                       <?PHP
                         # Paginate very long folder listings, allowing some overlap from one page to the next
                         #  so that items can be dragged between pages
                         if ($Paginate) {
                           print '<span>';
                           if ($Offset > 0) {
                               print $FirstPageButton->getHtml();
                               print $PageLeftButton->getHtml();
                           }
                           print " ".$Offset."-".($Offset+($ItemsPerPage+$Overlap) )." ";

                           if ($Offset + ($ItemsPerPage+$Overlap) < count($ItemIds)) {
                               print $PageRightButton->getHtml();
                               print $LastPageButton->getHtml();
                           }
                         } ?>
                                <?PHP if ($AF->getPageName() == "P_Folders_ManageFolders") { ?>
                  <span title="Making this folder public will allow anyone to view it, including users who are not logged in.To share a folder when it's public, click the folder name to view the folder page and share the URL listed beneath the folder name">
                  <input type="checkbox" id="ShareFolder_<?= $SafeFolderId ?>" name="Share" data-folderid="<?= $SafeFolderId; ?>" <?PHP if ($IsShared) print 'checked="true"'; ?> />
                  <label for="ShareFolder_<?= $SafeFolderId ?>">public</label>
                </span>
                <?= $SelectButton->getHtml(); ?>
                <?PHP if ($CanTransferFolder) { ?>
                    <?= $TransferButton->getHtml(); ?>
                <?PHP } ?>
                <?= $DuplicateButton->getHtml(); ?>
                <?= $SettingsButton->getHtml(); ?>
                <?= $DeleteButton->getHtml(); ?>
                <?PHP if ($HasItems) {?>
                    <?= $ClearButton->getHtml(); ?>
                <div class="cw-dialog-confirm cw-no-close" id="mv-folders-folderclear<?= $SafeFolderId?>" title="Clear <?= $SafeTruncatedName; ?>">
                Clear contents of folder <em><?= $SafeFolderName; ?></em>?
                </div>
              <?PHP }?>
                <?PHP $AF->signalEvent("EVENT_HTML_INSERTION_POINT",
                  [
                      "PageName" => $AF->getPageName(),
                      "Location" => "Folder Buttons",
                      "Context" => ["FolderId" => $Folder->id()]
                  ]); ?>
               <?PHP } ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="mv-section-body">
        <ul class="list-group list-group-flush mv-folders-items mv-folders-items-mini" data-folderid="<?= $SafeFolderId; ?>">
                                                                            <?PHP if (!$HasItems) { ?>
          <li class="mv-folders-noitems">There are no items in this folder.</li>
          <?PHP } else {
           # if we're not at the beginning of the folder, put a hidden placeholder item
           #  so that dragging things to the top of what is currently displayed doesn't
           # put them all the way at the beginning of the folder
           if ($Offset>0)
               self::printFolderItem($Folder->id(), $ItemIds[$Offset-1], TRUE);

           # slice off the items to display, show them
           $ItemIds = array_slice($ItemIds, $Offset, 550);
           foreach ($ItemIds as $ItemId)
               self::printFolderItem($Folder->id(), $ItemId);
                                                                                 } ?>
        </ul>
      </div>
    </div>
                         <?PHP
                         // @codingStandardsIgnoreEnd
    }


    /**
     * Display the 'Add All Search Results to the Current Folder' button.
     * @param boolean $TooManyResources whether the search results exceed the maximum
     *   number of resources that can be added to the current folder.
     * @param int $MaxResources the maximum number of resources that can be added
     *   to the current folder.
     * @param string $SearchParams Search parameters as a URL Parameter string.
     * @return void
     */
    public static function insertAllButtonHTML(
        bool $TooManyResources,
        int $MaxResources,
        string $SearchParams
    ): void {
        $ButtonStyleClasses = "btn btn-primary btn-sm";
        $AddAllToFolderButton = new HtmlButton("Add All to Folder");
        $AddAllToFolderButton->setIcon("FolderPlus.svg");
        $AddAllToFolderButton->setSize(HtmlButton::SIZE_SMALL);
        if (!$TooManyResources) {
            $AddAllToFolderButton->addClass("mv-folders-addallsearch mv-button-iconed");
            $AddAllToFolderButton->setTitle(
                "Add the results of this search to your current folder."
            );
            $AddAllToFolderButton->addAttributes([
                "data-buttonclasses" => $ButtonStyleClasses,
                "data-searchparams" => $SearchParams,
                "data-action" => "add",

            ]);
            $AddAllToFolderButton->setOnclick("Folders.handleSearchResultsActionButtonClick()");
        } else {
            $AddAllToFolderButton->addClass("mv-button-disabled");
            $AddAllToFolderButton->setTitle(
                "Only " . defaulthtmlentities($MaxResources)
                    . " search results can be added to a folder at a time."
                    . " Refine your search to reduce the amount of search results."
            );
        }
        ?>
        <!-- BEGIN FOLDER ALL BUTTON DISPLAY -->
        <?= $AddAllToFolderButton->getHtml(); ?>
        <!-- END FOLDER ALL BUTTON DISPLAY -->
        <?PHP
    }

    /**
     * Display the 'Remove All Search Results from the Current Folder' button.
     * @param string $SearchParams Search parameters as a URL Parameter string.
     * @param bool $ResultsInFolder whether any of the search results are in the folder.
     * @return void
     */
    public static function insertRemoveAllButtonHTML(
        string $SearchParams,
        bool $ResultsInFolder
    ): void {
        $ButtonStyleClasses = "btn btn-primary btn-sm mv-folders-removeallsearch";
        $RemoveAllFromFolderButton = new HtmlButton("Remove All From Folder");
        $RemoveAllFromFolderButton->setIcon("FolderMinus.svg");
        $RemoveAllFromFolderButton->setSize(HtmlButton::SIZE_SMALL);
        $RemoveAllFromFolderButton->addClass("mv-folders-removeallsearch");
        $RemoveAllFromFolderButton->setTitle(
            "Remove the results of this search from your current folder."
        );
        $RemoveAllFromFolderButton->addAttributes([
            "data-buttonclasses" => $ButtonStyleClasses,
            "data-searchparams" => $SearchParams,
            "data-action" => "remove",
        ]);
        $RemoveAllFromFolderButton->setOnclick("Folders.handleSearchResultsActionButtonClick()");
        if (!$ResultsInFolder) {
            $RemoveAllFromFolderButton->hide();
        }

        print $RemoveAllFromFolderButton->getHtml();
    }

    /**
     * Display the 'Add to or Remove from Current Folder' button for a given resource.
     * @param boolean $InFolder whether the resource is in the currently selected folder.
     * @param int $FolderId the ID of the currently active folder.
     * @param int $ResourceId the ID of the resource for which we are generating the button.
     * @param string $Location the insertion location of the button -- classes depend on this.
     * @return void
     */
    public static function insertButtonHTML(
        bool $InFolder,
        int $FolderId,
        int $ResourceId,
        string $Location
    ): void {
        $AF = ApplicationFramework::getInstance();

        $RemoveButton = new HtmlButton("Remove");
        $RemoveButton->setIcon("FolderMinus.svg");
        $RemoveButton->addClass("mv-folders-removeresource");
        $RemoveButton->setTitle("Remove this resource from the currently selected folder");
        $RemoveButton->addAttributes([
            "data-itemid" => $ResourceId,
            "data-folderid" => $FolderId,
            "data-action" => "remove"
        ]);
        $RemoveButton->setOnclick("Folders.handleResourceActionButtonClick()");

        $AddButton = new HtmlButton("Add");
        $AddButton->setIcon("FolderPlus.svg");
        $AddButton->addClass("mv-folders-addresource");
        $AddButton->setTitle("Add this resource to the currently selected folder");
        $AddButton->addAttributes([
            "data-itemid" => $ResourceId,
            "data-folderid" => $FolderId,
            "data-action" => "add"
        ]);
        $AddButton->setOnclick("Folders.handleResourceActionButtonClick()");

        if ($InFolder) {
            $AddButton->hide();
        } else {
            $RemoveButton->hide();
        }

        if (($Location == "Resource Summary Buttons") ||
            ($Location == "Resource Display Buttons")) {
            $RemoveButton->setSize(HtmlButton::SIZE_SMALL);
            $AddButton->setSize(HtmlButton::SIZE_SMALL);
        }

        print '<!-- BEGIN FOLDER BUTTON DISPLAY -->';

        # some pages output buttons inside a <ul>, so in order to keep our HTML valid,
        # we need to wrap our output in <li> tags
        $ListContextPages = ["Home", "SearchResults", "P_Folders_ViewFolder",
            "BrowseResources", "RecommendResources"
        ];
        $InList = in_array($AF->getPageName(), $ListContextPages);
        if ($InList) {
            print '<li class="list-group-item">';
        }

        print '<span class="mv-folders-action-btn-container">'
            .$AddButton->getHtml()
            .$RemoveButton->getHtml()
            .'</span>';

        if ($InList) {
            print '</li>';
        }

        print '<!-- END FOLDER BUTTON DISPLAY -->';
    }


    /**
     * Display the resource note and either edit or add button for the note
     * for the resource in the folder.
     * @param ?string $ResourceNote the note for the resource if it exists, if not, empty string
     * @param int $FolderId the ID of the currently active folder
     * @param int $ResourceId the ID of the resource for which we are generating the button
     * @param string $EditResourceNoteUrl the url for editing the resource note.
     * @return void
     */
    public static function insertResourceNote(
        ?string $ResourceNote,
        int $FolderId,
        int $ResourceId,
        string $EditResourceNoteUrl
    ): void {
        $Action = (!is_null($ResourceNote) && strlen($ResourceNote) > 0)
            ? "Edit" : "Add";

        $NoteButton = new HtmlButton("$Action Note");
        $NoteButton->setIcon("note_edit.png");
        $NoteButton->addClass("mv-folders-editnote");
        $NoteButton->setSize(HtmlButton::SIZE_SMALL);
        $NoteButton->setLink($EditResourceNoteUrl);

        // @codingStandardsIgnoreStart
        ?>
        <!-- BEGIN FOLDER RESOURCE NOTE DISPLAY -->
        <p class="mv-folders-item-note" data-folderid="<?= defaulthtmlentities($FolderId); ?>"
           data-itemid="<?= defaulthtmlentities($ResourceId); ?>">
          <span class="mv-folders-item-notetext"><?= nl2br(defaulthtmlentities($ResourceNote)); ?></span>
        <?PHP if ($ResourceNote) { ?>
               <br />
        <?PHP } ?>
        <?= $NoteButton->getHtml(); ?>
      </p>
    <!-- END FOLDER RESOURCE NOTE DISPLAY -->
         <?PHP
        // @codingStandardsIgnoreEnd
    }

    /**
     * Display the folders sidebar content html.
     * @return void
     */
    public static function printFolderSidebarContent(): void
    {
        $AF = ApplicationFramework::getInstance();

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        $FoldersPlugin = Folders::getInstance();

        $FolderFactory = new FolderFactory($User->id());
        $FolderId = $FolderFactory->getSelectedFolder()->id();
        $SelectedFolder = $FoldersPlugin->getSelectedFolder($User);
        $Name = $SelectedFolder->name();

        $FolderName = StdLib::neatlyTruncateString($Name, 18);

        # get absolute URLs to images/pages we will need
        # (must be absolute because the HTML generated here can be requested via
        # an ajax call and added to a page with a CleanURL that may contain slashes)
        $BaseUrl = $AF->baseUrl();
        $ViewFolderLink = $BaseUrl .
            "index.php?P=P_Folders_ViewFolder&amp;FolderId=" . $FolderId;
        $ManageFolderLink = $BaseUrl . "index.php?P=P_Folders_ManageFolders";
        $OpenFolderImgPath = $BaseUrl . $AF->gUIFile("OpenFolder.svg");

        $ItemIds = $SelectedFolder->getItemIds();
        $ItemCount = count($ItemIds);
        $HasResources = $ItemCount > 0;
        $NumDisplayedResources = $FoldersPlugin->getConfigSetting("NumDisplayedResources");
        $HasMoreResources = $ItemCount > $NumDisplayedResources;
        $ResourceInfo = [];

        foreach ($ItemIds as $ResourceId) {
            $Resource = new Record($ResourceId);
            $Title = Common::getSafeResourceTitle($Resource);
            $ResourceCSSClass = $Resource->userCanView(User::getAnonymousUser())
                ? ""
                : "mv-notpublic";

            # get the schema name associated with this resource
            $Schema = new MetadataSchema($Resource->getSchemaId());
            $SchemaName = $Schema->name();
            $SchemaCSSName = "mv-sidebar-resource-tag-".
                str_replace([" ", "/"], '', strtolower($SchemaName));
            $SchemaName = $Schema->abbreviatedName();

            $ResourceInfo[$ResourceId] = [
                "ResourceId" => $Resource->id(),
                "ResourceTitle" => StdLib::neatlyTruncateString($Title, 50),
                "ResourceSchemaName" => $SchemaName,
                "ResourceSchemaCSSName" => $SchemaCSSName,
                "ResourceCSSClass" => $ResourceCSSClass,
            ];

            # only display the first $NumDisplayedResources
            if (count($ResourceInfo) >= $NumDisplayedResources) {
                break;
            }
        }

        $EditButton = new HtmlButton("Edit");
        $EditButton->setIcon("Pencil.svg");
        $EditButton->setSize(HtmlButton::SIZE_SMALL);
        $EditButton->addClass("float-end");
        $EditButton->setLink($ManageFolderLink);

// @codingStandardsIgnoreStart
?>
  <!-- BEGIN FOLDERS SIDEBAR DISPLAY -->
  <div class="mv-section mv-section-simple mv-html5-section mv-folders-sidebar">
    <div class="mv-section-header mv-html5-header">
    <?= $EditButton->getHtml(); ?>
    <a href="<?= defaulthtmlentities($ViewFolderLink) ?>">
        <img src="<?= $OpenFolderImgPath ?>" alt="">
        <?PHP if($FolderName) { ?>
          <?=  defaulthtmlentities($FolderName) ?>
        <?PHP } else { ?>
          <strong>Current Folder</strong>
        <?PHP } ?>
      </a>
    </div>
    <div class="mv-section-body">
      <?PHP if ($HasResources) { ?>
        <ul class="mv-bullet-list">
          <?PHP foreach ($ResourceInfo as $Info) { ?>
            <li class="<?= $Info["ResourceCSSClass"] ?>">
              <span class="mv-sidebar-resource-tag <?= $Info["ResourceSchemaCSSName"] ?>"><?= $Info["ResourceSchemaName"] ?></span>
              <a href="index.php?P=FullRecord&amp;ID=<?= $Info["ResourceId"] ?>">
                <?= $Info["ResourceTitle"]; ?>
              </a>
            </li>
          <?PHP } ?>
        </ul>
        <?PHP if ($HasMoreResources) { ?>
        <a class="mv-folders-seeall" href="<?= $ViewFolderLink ?>">See all &rarr;</a>
        <?PHP } ?>
      <?PHP } else { ?>
        <p class="mv-folders-noitems">There are no resources in this folder.</p>
      <?PHP } ?>
    </div>
  </div>
  <!-- END FOLDERS SIDEBAR DISPLAY -->
<?PHP
        // @codingStandardsIgnoreEnd
    }

    /**
     * Print the folder item with the given ID.
     * @param int $FolderId ID of the folder in which the item is
     * @param int $ItemId item ID
     * @param bool $Hidden if the item should be hidden with css
     * @return void
     */
    private static function printFolderItem(
        int $FolderId,
        int $ItemId,
        bool $Hidden = false
    ): void {
        $Resource = new Record($ItemId);
        $PublicResource = $Resource->userCanView(User::getAnonymousUser());
        $SafeFolderId = defaulthtmlentities($FolderId);
        $SafeId = defaulthtmlentities($Resource->id());
        $SafeTitle = Common::getSafeResourceTitle($Resource);

        # get the schema name associated with this resource
        $Schema = new MetadataSchema($Resource->getSchemaId());
        $SchemaCSSName = "mv-resourcesummary-resourcetype-tag-".
                str_replace(' ', '', strtolower($Schema->name()));
        $SchemaItemName = $Schema->resourceName();
        $CssClasses = $PublicResource
            ? "list-group-item"
            : "list-group-item mv-notpublic";

        // @codingStandardsIgnoreStart
        ?>
      <li class="<?= $CssClasses ?>"
        data-parentfolderid="<?= $SafeFolderId ?>"
        data-itemid="<?= $SafeId ?>"
           <?PHP if ($Hidden) { ?> style="display: none;" <?PHP } ?> >
      <a href="index.php?P=FullRecord&amp;ID=<?= $SafeId ?>">
        <?= $SafeTitle ?>
      </a>
      <span class="<?= $SchemaCSSName ?> mv-resourcesummary-resourcetype-tag float-end"
        ><?= $SchemaItemName ?></span>
    </li>
<?PHP
        // @codingStandardsIgnoreEnd
    }
}
