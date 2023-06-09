<?PHP
#
#   FILE:  VocabularyFactory.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2007-2020 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

/**
 * Factory for manipulating Vocabulary objects.
 */
class VocabularyFactory
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * object constructor
     * @param string $Path Path to directory containing .voc controlled
     *       vocabulary files.
     */
    public function __construct(string $Path)
    {
        $this->Path = $Path;
    }

    /**
     * load vocabulary objects from files
     * @return array of Vocabulary objects.
     * @note Vocabularies are returned sorted by name.
     */
    public function getVocabularies(): array
    {
        # load vocabularies (if any)
        $Vocabularies = [];
        $VocFileNames = $this->getFileList();
        foreach ($VocFileNames as $FileName) {
            $Vocabularies[] = new Vocabulary($FileName);
        }

        # sort vocabularies by name
        $SortFunction = function ($VocA, $VocB) {
                $NameA = $VocA->Name();
                $NameB = $VocB->Name();
                return ($NameA == $NameB) ? 0 : (($NameA < $NameB) ? -1 : 1);
        };
        usort($Vocabularies, $SortFunction);

        # return array of vocabularies to caller
        return $Vocabularies;
    }

    /**
     * retrieve vocabulary object based on hash string
     * @param string $Hash Hash for Vocabulary (returned by Hash() method).
     * @return Vocabulary|null Vocabulary object corresponding to hash, or NULLu
     *      if no matching vocabulary found.
     */
    public function getVocabularyByHash(string $Hash)
    {
        # for each available vocabulary file
        $Vocab = null;
        $VocFileNames = $this->getFileList();
        foreach ($VocFileNames as $FileName) {
            # if hash for vocabulary file matches specified hash
            if (Vocabulary::hashForFile($FileName) == $Hash) {
                # load vocabulary and stop searching file list
                $Vocab = new Vocabulary($FileName);
                break;
            }
        }

        # return matching vocabulary (if any) to caller
        return $Vocab;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**  Path to directory containing vocabulary files. */
    private $Path;

    /**
     * Read in list of available vocabulary files.
     * @return Array with full paths to vocabulary files.
     */
    private function getFileList(): array
    {
        $VocFiles = [];
        if (is_dir($this->Path)) {
            $AllFiles = scandir($this->Path);
            if ($AllFiles === false) {
                throw new \Exception("Failed to find files for vocabulary.");
            }
            foreach ($AllFiles as $FileName) {
                if (preg_match("/\\.voc\$/i", $FileName)) {
                    $VocFiles[] = realpath($this->Path."/".$FileName);
                }
            }
        }
        return $VocFiles;
    }
}
