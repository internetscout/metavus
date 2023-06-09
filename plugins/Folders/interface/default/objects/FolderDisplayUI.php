<?PHP
#
#   FILE:  FolderDisplayUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2014-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Folders;

use Metavus\MetadataSchema;
use Metavus\Plugins\Folders\Common;
use Metavus\Plugins\Folders\Folder;
use Metavus\Record;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

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
     */
    public static function printFolder(
        int $ResourceFolderId,
        Folder $Folder,
        Folder $Previous = null,
        Folder $Next = null,
        bool $IsSelected = false
    ) {
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

        $FoldersPlugin = PluginManager::getInstance()->getPlugin("Folders");
        $CanTransferFolder = ($FoldersPlugin->ConfigSetting("PrivsToTransferFolders"))
            ->meetsRequirements(User::getCurrentUser());

        $ItemsPerPage = 500;
        $Overlap = 50;
        $Paginate = count($ItemIds) > $ItemsPerPage;
        $UrlFiltered = preg_replace(
            '/\&FO'.$Folder->id().'=[^&]+/',
            '',
            $_SERVER["REQUEST_URI"]
        );
        // @codingStandardsIgnoreStart
        ?>

    <div id="Folders_Errors<?= $SafeFolderId ?>"></div>
    <div class="border rounded mv-section mv-section-elegant mv-html5-section mv-folders-folder" data-parentfolderid="<?= $SafeResourceFolderId ?>" data-folderid="<?= $SafeFolderId ?>" data-itemid="<?= $SafeFolderId ?>">
      <div class="mv-section-header" title="<?= $SafeFolderDescForRollover ?>">
        <div class="container-fluid border p-1 rounded bg-light">
          <div class="row">
            <div class="col">
              <?PHP if ($IsSelected) { ?>
              <img class="mv-folders-selectedfoldericon" src="<?PHP $AF->PUIFile("asterisk_yellow.png"); ?>" alt="(Current Folder)" />
              <?PHP } ?>
              <a id="mv-folders-nametitle<?= $SafeFolderId ?>" class="mv-folders-folder-name" href="index.php?P=P_Folders_ViewFolder&amp;FolderId=<?= $SafeFolderId ?>" data-folderid="<?= $SafeFolderId ?>">
              <?= StdLib::neatlyTruncateString($SafeFolderName, 40) ?>  (<?= count($ItemIds) ?>)<!-- the whitespace adds to the rendered anchor
              --></a>
              <div class="cw-folder-actions float-right">
                       <?PHP
                         # Paginate very long folder listings, allowing some overlap from one page to the next
                         #  so that items can be dragged between pages
                         if ($Paginate) {
                           print '<span>';
                           if ($Offset > 0) {
                                print '<a href="'.$UrlFiltered.'" class="btn btn-primary btn-sm">&#124&lt;</a> ';
                                print '<a href="'.$UrlFiltered.'&amp;FO'.$Folder->id().'='.($Offset-$ItemsPerPage).
                                   '" class="btn btn-primary btn-sm"> &lt; </a>';
                           }
                           print " ".$Offset."-".($Offset+($ItemsPerPage+$Overlap) )." ";

                           if ($Offset + ($ItemsPerPage+$Overlap) < count($ItemIds)) {
                                print '<a href="'.$UrlFiltered.'&amp;FO'.$Folder->id().'='.($Offset+$ItemsPerPage).
                                   '" class="btn btn-primary btn-sm">&gt;</a> ';
                                print '<a href="'.$UrlFiltered.'&amp;FO'.$Folder->id().'='.(count($ItemIds) - ($ItemsPerPage+$Overlap)).
                                   '" class="btn btn-primary btn-sm">&gt&#124;</a>';
                           }
                           } ?>
                                <?PHP if ($AF->GetPageName() == "P_Folders_ManageFolders") { ?>
                  <span title="Making this folder public will allow anyone to view it, including users who are not logged in.To share a folder when it's public, click the folder name to view the folder page and share the URL listed beneath the folder name">
                  <input type="checkbox" id="ShareFolder_<?= $SafeFolderId ?>" name="Share" data-folderid="<?= $SafeFolderId; ?>" <?PHP if ($IsShared) print 'checked="true"'; ?> />
                  <label for="ShareFolder_<?= $SafeFolderId ?>">public</label>
                </span>
                <?PHP if (!$IsSelected) { ?>
                  <a class="mv-button-iconed btn btn-primary btn-sm mv-button-iconed"
                        href="index.php?P=P_Folders_SelectFolder&amp;FolderId=<?= $SafeFolderId ?>"
                        title="Select this folder as the current folder"><img
                        src="<?= $AF->GUIFile('Check.svg'); ?>" alt=""
                        class="mv-button-icon" /> Select</a>
                <?PHP } ?>
                <?PHP if ($CanTransferFolder) { ?>
                  <a class="btn btn-danger btn-sm cw-folder-transfer-folder-button mv-button-iconed"
                    href="index.php?P=P_Folders_ConfirmFolderTransfer&amp;FID=<?= $SafeFolderId ?>"
                    title="Transfer this folder to other user"
                    data-folderid="<?= $SafeFolderId ?>"
                    data-foldername="<?= $SafeFolderName ?>"><img
                    src="<?= $AF->GUIFile('Exchange.svg'); ?>" alt=""
                    class="mv-button-icon" /> Transfer</a>
                <?PHP } ?>
                <a class="btn btn-primary btn-sm mv-button-iconed"
                  href="index.php?P=P_Folders_DuplicateFolders&FID=<?= $SafeFolderId ?>"
                  title="Create a duplicate of this folder"><img
                  src="<?= $AF->GUIFile('Copy.svg'); ?>" alt=""
                  class="mv-button-icon" /> Duplicate</a>
                <a class="mv-button-iconed mv-folders-editnamelink btn btn-primary btn-sm mv-button-iconed"
                 href="index.php?P=P_Folders_ChangeFolderName&amp;FolderId=<?= $SafeFolderId ?>"
                 id="mv-folders-foldernamelink<?= $SafeFolderId ?>"
                 title="Edit the name of this folder"
                 data-folderid="<?= $SafeFolderId ?>"
                ><img class="mv-button-icon" src="<?PHP $AF->PUIFile("cog.png"); ?>" alt="" /> Settings</a>
                <div class="mv-folders-dialog-namechange cw-no-close" id="mv-folders-namechange<?= $SafeFolderId ?>"
                data-ajaxlink="index.php?P=P_Folders_UpdateFolderName&FolderId=<?= $SafeFolderId ?>&FolderName="
                data-folderid="<?= $SafeFolderId?>"
                data-foldername="<?= $SafeTruncatedName; ?>"
                title="Rename ">
                <input type="text" name="FolderName" value="<?= $SafeFolderName; ?>" required="true" />
                </div>
                <a class="mv-button-iconed btn btn-danger btn-sm mv-button-iconed "
                    href="index.php?P=P_Folders_ConfirmDeleteFolder&amp;FolderId=<?= $SafeFolderId; ?>"
                    title="Delete this folder"><img src="<?= $AF->GUIFile('Delete.svg'); ?>"
                    alt="" class="mv-button-icon" /> Delete</a>
                <?PHP if ($HasItems) {?>
                <a class="mv-button-iconed mv-folders-clear-confirmlink btn btn-danger btn-sm mv-button-iconed"
                 href="index.php?P=P_Folders_RemoveAllItems&amp;FolderId=<?= $SafeFolderId; ?>"
                 id="mv-folders-folderlink<?= $SafeFolderId; ?>"
                 title="Remove all resources from this folder"
                 data-parentfolderid="<?= $SafeFolderId; ?>"
                 data-folderid="<?= $SafeFolderId?>"><img src="<?= $AF->GUIFile('Broom.svg'); ?>"
                 alt="" class="mv-button-icon" /> Clear</a>
                <div class="cw-dialog-confirm cw-no-close" id="mv-folders-folderclear<?= $SafeFolderId?>" title="Clear <?= $SafeTruncatedName; ?>">
                Clear contents of folder <em><?= $SafeFolderName; ?></em>?
                </div>
              <?PHP }?>
                <?PHP $AF->SignalEvent("EVENT_HTML_INSERTION_POINT",
                  [
                      "PageName" => $AF->GetPageName(),
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
     * @param boolean $TooManyResources whether the search results exceed the maxium
     *   number of resources that can be added to the current folder.
     * @param int $MaxResources the maxium number of resources that can be added
     *   to the current folder.
     * @param string $AddAllUrl the string URL to add all search results to the current folder.
     */
    public static function insertAllButtonHTML(
        bool $TooManyResources,
        int $MaxResources,
        string $AddAllUrl
    ) {
        $AF = ApplicationFramework::getInstance();
        $ButtonStyleClasses = "btn btn-primary btn-sm";
        // @codingStandardsIgnoreStart
        ?>
        <!-- BEGIN FOLDER ALL BUTTON DISPLAY -->
        <?PHP if (!$TooManyResources) { ?>
            <a class="btn btn-primary btn-sm mv-folders-addallsearch mv-button-iconed"
                href="<?= defaulthtmlentities($AddAllUrl)?>"
                title="Add the results of this search to your current folder."
                data-buttonclasses="<?= $ButtonStyleClasses; ?>"><img
                class="mv-button-icon" src="<?= $AF->GUIFile("folder_add.png"); ?>"
                alt="" /> Add All to Folder</a>
        <?PHP } else { ?>
            <button class="btn btn-primary btn-sm mv-button-disabled mv-button-iconed"
                title="Only <?= defaulthtmlentities($MaxResources); ?> search results can be added to a folder at a time.Refine your search to reduce the amount of search results."><img class="mv-button-icon" src="<?= $AF->GUIFile("folder_add.png"); ?>" alt="" /> Add All to Folder</button>
        <?PHP } ?>
        <!-- END FOLDER ALL BUTTON DISPLAY -->
        <?PHP
        // @codingStandardsIgnoreEnd
    }

    /**
     * Display the 'Remove All Search Results from the Current Folder' button.
     * @param string $RemoveAllUrl the string URL to remove all search results
     *     to the current folder.
     * @param bool $ResultsInFolder whether any of the search results are in the folder.
     */
    public static function insertRemoveAllButtonHTML(string $RemoveAllUrl, bool $ResultsInFolder)
    {
        $AF = ApplicationFramework::getInstance();
        $ButtonStyleClasses = "btn btn-primary btn-sm mv-folders-removeallsearch ";
        ?>
        <a class="<?= $ButtonStyleClasses ?>" href="<?= defaulthtmlentities($RemoveAllUrl)?>"
           title="Remove the results of this search from your current folder."
           data-buttonclasses="<?= $ButtonStyleClasses ?>"
           style="<?= $ResultsInFolder ? "" : "display: none;" ?>">
           <img class="mv-button-icon" src="<?= $AF->GUIFile("cross.png"); ?>"
                alt="" /> Remove All From Folder</a>
           <?PHP
    }

    /**
     * Display the 'Add to or Remove from Current Folder' button for a given resource.
     * @param boolean $InFolder whether the resource is in the currently selected folder.
     * @param string $AddActionUrl the URL to complete the add action.
     * @param string $RemoveActionUrl the URL to complete the remove action.
     * @param int $FolderId the ID of the currently active folder.
     * @param int $ResourceId the ID of the resource for which we are generating the button.
     * @param string $Location the insertion location of the button -- classes depend on this.
     */
    public static function insertButtonHTML(
        bool $InFolder,
        string $AddActionUrl,
        string $RemoveActionUrl,
        int $FolderId,
        int $ResourceId,
        string $Location
    ) {
        $AF = ApplicationFramework::getInstance();
        $ButtonStyleClasses = "mv-button-iconed btn btn-primary";
        if (($Location == "Resource Summary Buttons") ||
            ($Location == "Resource Display Buttons")) {
            $ButtonStyleClasses .= " btn-sm";
        }

        # get absolute URLs to buttons so that they will work on pages where the
        # CleanURL contains additional slashes
        $AddImg = ApplicationFramework::baseUrl()
            .$AF->gUIFile("FolderPlus.svg");
        $RemoveImg = ApplicationFramework::baseUrl()
            . $AF->gUIFile("FolderMinus.svg");

        print '<!-- BEGIN FOLDER BUTTON DISPLAY -->';

        # some pages output buttons inside a <ul>, so in order to keep our HTML valid,
        # we need to wrap our output in <li> tags
        $ListContextPages = ["Home", "SearchResults", "P_Folders_ViewFolder", "BrowseResources"];
        $InList = in_array($AF->GetPageName(), $ListContextPages) ? true : false;
        if ($InList) {
            print '<li class="list-group-item">';
        }

        // @codingStandardsIgnoreStart
        if ($InFolder) { ?>
          <a class="<?= $ButtonStyleClasses ?> mv-folders-removeresource"
             title="Remove this resource from the currently selected folder"
             href="<?= defaulthtmlentities($RemoveActionUrl); ?>"
             data-itemid="<?= $ResourceId ?>" data-folderid="<?= $FolderId ?>"
             data-verb="Remove">
             <img class="mv-button-icon" src="<?= $RemoveImg ?>" alt=""/> Remove</a>
        <?PHP } else { ?>
           <a class="<?= $ButtonStyleClasses ?> mv-folders-addresource"
              title="Add this resource to the currently selected folder"
              href="<?= defaulthtmlentities($AddActionUrl); ?>"
              data-itemid="<?= $ResourceId ?>" data-folderid="<?= $FolderId ?>"
              data-verb="Add">
              <img class="mv-button-icon" src="<?= $AddImg ?>" alt="" /> Add</a>
         <?PHP
        }
        // @codingStandardsIgnoreEnd

        if ($InList) {
            print '</li>';
        }

        print '<!-- END FOLDER BUTTON DISPLAY -->';
    }


    /**
     * Display the resource note and either edit or add button for the note
     * for the resource in the folder.
     * @param string $ResourceNote the note for the resource if it exists, if not, empty string
     * @param int $FolderId the ID of the currently active folder
     * @param int $ResourceId the ID of the resource for which we are generating the button
     * @param string $EditResourceNoteUrl the url for editing the resource note.
     */
    public static function insertResourceNote(
        string $ResourceNote = null,
        int $FolderId,
        int $ResourceId,
        string $EditResourceNoteUrl
    ) {
        $AF = ApplicationFramework::getInstance();
        $Action = (!is_null($ResourceNote) && strlen($ResourceNote) > 0)
            ? "Edit" : "Add";
        // @codingStandardsIgnoreStart
        ?>
        <!-- BEGIN FOLDER RESOURCE NOTE DISPLAY -->
        <p class="mv-folders-item-note" data-folderid="<?= defaulthtmlentities($FolderId); ?>"
           data-itemid="<?= defaulthtmlentities($ResourceId); ?>">
          <span class="mv-folders-item-notetext"><?= nl2br(defaulthtmlentities($ResourceNote)); ?></span>
        <?PHP if ($ResourceNote) { ?>
               <br />
        <?PHP } ?>
        <a class="btn btn-primary btn-sm mv-folders-editnote mv-button-iconed"
           href="<?= defaulthtmlentities($EditResourceNoteUrl); ?>"
           ><img class="mv-button-icon" src="<?= $AF->GUIFile("note_edit.png"); ?>" alt="" />
               <?= $Action ?> Note</a>
      </p>
    <!-- END FOLDER RESOURCE NOTE DISPLAY -->
         <?PHP
        // @codingStandardsIgnoreEnd
    }

    /**
     * Display the folders sidebar content html.
     */
    public static function printFolderSidebarContent()
    {
        $AF = ApplicationFramework::getInstance();

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        $FoldersPlugin = PluginManager::getInstance()->getPlugin("Folders");
        $FolderFactory = new FolderFactory($User->Id());
        $FolderId = $FolderFactory->GetSelectedFolder()->Id();
        $SelectedFolder = $FoldersPlugin->GetSelectedFolder($User);
        $Name = $SelectedFolder->Name();

        $FolderName = StdLib::NeatlyTruncateString($Name, 18);

        # get absolute URLs to images/pages we will need
        # (must be absolute because the HTML generated here can be requested via
        # an ajax call and added to a page with a CleanURL that may contain slashes)
        $BaseUrl = $AF->baseUrl();
        $ViewFolderLink = $BaseUrl.
            "index.php?P=P_Folders_ViewFolder&amp;FolderId=".$FolderId;
        $ManageFolderLink = $BaseUrl
            ."index.php?P=P_Folders_ManageFolders";
        $OpenFolderImgPath = $BaseUrl
            .$AF->gUIFile("OpenFolder.svg");
        $PencilImgPath = $BaseUrl
            .$AF->gUIFile("Pencil.svg");

        $ItemIds = $SelectedFolder->GetItemIds();
        $ItemCount = count($ItemIds);
        $HasResources = $ItemCount > 0;
        $NumDisplayedResources = $FoldersPlugin->ConfigSetting("NumDisplayedResources");
        $HasMoreResources = $ItemCount > $NumDisplayedResources;
        $ResourceInfo = [];

        foreach ($ItemIds as $ResourceId) {
            $Resource = new Record($ResourceId);
            $Title = Common::GetSafeResourceTitle($Resource);

            # get the schema name associated with this resource
            $Schema = new MetadataSchema($Resource->getSchemaId());
            $SchemaName = $Schema->Name();
            $SchemaCSSName = "mv-sidebar-resource-tag-".
                str_replace([" ", "/"], '', strtolower($SchemaName));
            $SchemaName = $Schema->AbbreviatedName();

            $ResourceInfo[$ResourceId] = [
                "ResourceId" => $Resource->Id(),
                "ResourceTitle" => StdLib::NeatlyTruncateString($Title, 50),
                "ResourceSchemaName" => $SchemaName,
                "ResourceSchemaCSSName" => $SchemaCSSName
            ];

            # only display the first $NumDisplayedResources
            if (count($ResourceInfo) >= $NumDisplayedResources) {
                break;
            }
        }

// @codingStandardsIgnoreStart
?>
  <!-- BEGIN FOLDERS SIDEBAR DISPLAY -->
  <div class="mv-section mv-section-simple mv-html5-section mv-folders-sidebar">
    <div class="mv-section-header mv-html5-header">
    <a class="btn btn-primary btn-sm mv-button-iconed float-right"
        href="<?= $ManageFolderLink ?>"><img class="mv-button-icon" src="<?= $PencilImgPath ?>" alt=""> Edit</a>
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
            <li>
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
     */
    private static function printFolderItem(int $FolderId, int $ItemId, bool $Hidden = false)
    {
        $Resource = new Record($ItemId);
        $SafeFolderId = defaulthtmlentities($FolderId);
        $SafeId = defaulthtmlentities($Resource->id());
        $SafeTitle = Common::getSafeResourceTitle($Resource);

        # get the schema name associated with this resource
        $Schema = new MetadataSchema($Resource->getSchemaId());
        $SchemaCSSName = "mv-resourcesummary-resourcetype-tag-".
                str_replace(' ', '', strtolower($Schema->name()));
        $SchemaItemName = $Schema->resourceName();

        // @codingStandardsIgnoreStart
        ?>
      <li class="list-group-item"
        data-parentfolderid="<?= $SafeFolderId ?>"
        data-itemid="<?= $SafeId ?>"
           <?PHP if ($Hidden) { ?> style="display: none;" <?PHP } ?> >
      <a href="index.php?P=FullRecord&amp;ID=<?= $SafeId ?>">
        <?= $SafeTitle ?>
      </a>
      <span class="<?= $SchemaCSSName ?> mv-resourcesummary-resourcetype-tag float-right"
        ><?= $SchemaItemName ?></span>
    </li>
<?PHP
        // @codingStandardsIgnoreEnd
    }
}
