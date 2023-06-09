<?PHP
#
#   FILE:  Folder_FolderFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2012-2021 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Folders;

use Exception;

/**
 * Class used to add plugin-specific functionality to the FolderFactory class.
 */
class FolderFactory extends \Metavus\FolderFactory
{

    /**
     * @const string RESOURCE_FOLDER_NAME name given to root resource folders
     */
    const RESOURCE_FOLDER_NAME = "ResourceFolderRoot";

    /**
     * @const string DEFAULT_FOLDER_NAME name given to the default folder for
     *   resources created when no other folders exist
     */
    const DEFAULT_FOLDER_NAME = "Main Folder";

    /**
     * Ensure constructor is supplied with an owner ID.
     * @param int $OwnerId User ID.
     */
    public function __construct(int $OwnerId)
    {
        parent::__construct($OwnerId);
    }

    /**
     * Get the resource folder for the selected owner. Creates one if one does
     * not already exist.
     * @param mixed $OwnerId owner ID (optional); uses data member if not set
     * @return Folder resource folder object
     * @throws Exception if no owner ID is available
     */
    public function getResourceFolder($OwnerId = null)
    {
        # throws exception if it cannot get the owner ID
        $OwnerId = $this->getOwnerId($OwnerId);

        $Folder = $this->getResourceFolderForOwnerId($OwnerId);

        # folder already exists so just return it
        if ($Folder instanceof \Metavus\Folder) {
            return $Folder;
        }

        # create the folder and return it
        return $this->createResourceFolder($OwnerId);
    }

    /**
     * Create the resource folder for the given user. Won't create a new folder
     * if one already exists.
     * @param mixed $OwnerId owner ID (optional); uses data member if not set
     * @return Folder resource folder object
     * @throws Exception if no owner ID is available
     */
    public function createResourceFolder($OwnerId = null): Folder
    {
        # throws exception if it cannot get the owner ID
        $OwnerId = $this->getOwnerId($OwnerId);

        $Folder = $this->getResourceFolderForOwnerId($OwnerId);

        # folder already exists so just return it
        if ($Folder instanceof Folder) {
            return $Folder;
        }

        # create the folder and return it
        $NewFolder = Folder::create($OwnerId, "Folder");
        $NewFolder->name(self::RESOURCE_FOLDER_NAME);
        return $NewFolder;
    }

    /**
     * Get currently-selected folder for specified user.  If no folder is
     * currently selected, create a new folder and select it.
     * @param int $UserId User ID. (OPTIONAL, default is value supplied
     *      to constructor)
     * @return Folder Selected folder.
     */
    public function getSelectedFolder($UserId = null): Folder
    {
        if ($UserId === null) {
            $UserId = $this->OwnerId;
        }

        $FolderId = $this->DB->queryValue(
            "SELECT FolderId FROM Folders_SelectedFolders"
                    ." WHERE OwnerId = ".intval($UserId),
            "FolderId"
        );

        if ($FolderId === null) {
            $Folder = $this->createDefaultFolder($UserId);
            $this->selectFolder($Folder);
        } else {
            $Folder = new Folder($FolderId);
        }

        return $Folder;
    }

    /**
     * Select the given folder for the given owner ID.
     * @return Folder $Folder folder to select
     * @param mixed $OwnerId owner ID (optional); uses data member if not set
     * @return void
     * @throws Exception if no owner ID is available
     */
    public function selectFolder(Folder $Folder, $OwnerId = null)
    {
        # throws exception if it cannot get the owner ID
        $OwnerId = $this->getOwnerId($OwnerId);

        $ResourceFolder = $this->getResourceFolder($OwnerId);

        # select only if the folder is a resource folder and it belongs to the
        # resource folder (therefore, the user)
        if ($ResourceFolder->containsItem($Folder->id())) {
            # remove current selected folders for the user...
            $this->DB->query("
                DELETE FROM Folders_SelectedFolders
                WHERE OwnerId = '".addslashes($OwnerId)."'");

            # ...and select the given one
            $this->DB->query("
                INSERT INTO Folders_SelectedFolders
                (OwnerId, FolderId)
                VALUES
                (".intval($OwnerId).", ".intval($Folder->id()).")");
        }
    }

    /**
     * Create a default folder and add it to the root resource folder.
     * @param int $OwnerId owner ID (optional); uses data member if not set
     * @return Folder New folder.
     * @throws Exception if no owner ID is available
     */
    public function createDefaultFolder(int $OwnerId = null): Folder
    {
        # throws exception if it cannot get the owner ID
        $OwnerId = $this->getOwnerId($OwnerId);

        $DefaultFolder = Folder::create($OwnerId, "Resource");
        $DefaultFolder->name(self::DEFAULT_FOLDER_NAME);

        $ResourceFolder = $this->getResourceFolder($OwnerId);
        $ResourceFolder->prependItem($DefaultFolder->id());

        return $DefaultFolder;
    }

    /**
     * Get an owner ID if available.
     * @param mixed $OwnerId owner ID (optional); uses data member if not set
     * @return mixed owner ID
     * @throws Exception if no owner ID is available
     */
    protected function getOwnerId($OwnerId = null)
    {
        $OwnerId = !is_null($OwnerId) ? $OwnerId : $this->OwnerId;

        # need an owner ID to be able to get the resource folder
        if (!$OwnerId) {
            throw new Exception("No owner ID available");
        }

        return $OwnerId;
    }

    /**
     * Get the resource folder for the owner ID.
     * @param mixed $OwnerId owner ID
     * @return Folder|null folder object if found or NULL if one doesn't exist
     */
    protected function getResourceFolderForOwnerId($OwnerId)
    {
        $Folders = $this->getFolders(
            "Folder",
            $OwnerId,
            self::RESOURCE_FOLDER_NAME
        );

        return count($Folders) ? array_shift($Folders) : null;
    }

    /**
     * @var int|null $OwnerId owner ID
     */
    protected $OwnerId;
}
