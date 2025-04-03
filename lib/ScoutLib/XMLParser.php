<?PHP
#
#   FILE:  XMLParser.php
#
#   Part of the ScoutLib application support library
#   Copyright 2005-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#

namespace ScoutLib;

class XMLParser
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Object constructor.
     * @param string $Encoding Character encoding to use.
     */
    public function __construct($Encoding = "UTF-8")
    {
        # set default debug output level
        $this->DebugLevel = 0;

        # create XML parser and tell it about our methods
        $this->Parser = xml_parser_create($Encoding);
        xml_set_object($this->Parser, $this);
        xml_set_element_handler($this->Parser, "OpenTag", "CloseTag");
        xml_set_character_data_handler($this->Parser, "ReceiveData");

        # initialize tag storage arrays
        $this->TagNames = array();
        $this->TagAttribs = array();
        $this->TagData = array();
        $this->TagParents = array();

        # initialize indexes for parsing and retrieving data
        $this->CurrentParseIndex = -1;
        $this->CurrentSeekIndex = -1;
        $this->NameKeyCache = array();
    }

    /**
     * Parse text stream and store result.
     * @param string $Text Text stream to parse.
     * @param bool $LastTextToParse If TRUE, this is the last incoming text.
     */
    public function parseText($Text, $LastTextToParse = true)
    {
        # pass text to PHP XML parser
        xml_parse($this->Parser, $Text, $LastTextToParse);
    }

    /**
     * Move current tag pointer to specified item.  Arguments may be
     * tag names or indexes.
     * @return int Count of tags found at location, or NULL on failure.
     */
    public function seekTo()
    {
        # perform seek based on arguments passed by caller
        $SeekResult = $this->PerformSeek(func_get_args(), true);

        # if seek successful
        if ($SeekResult !== null) {
            # retrieve item count at seek location
            $ItemCount = count($this->CurrentItemList);
        } else {
            # return null value to indicate that seek failed
            $ItemCount = null;
        }

        # return count of tags found at requested location
        if ($this->DebugLevel > 0) {
            print("XMLParser->SeekTo(");
            $Sep = "";
            $DbugArgList = "";
            foreach (func_get_args() as $Arg) {
                $DbugArgList .= $Sep . "\"" . $Arg . "\"";
                $Sep = ", ";
            }
            print($DbugArgList . ") returned " . intval($ItemCount)
                . " items starting at index " . $this->CurrentSeekIndex . "\n");
        }
        return $ItemCount;
    }

    /**
     * Move seek pointer up one level.
     * @return string Tag name or NULL if no parent.
     */
    public function seekToParent()
    {
        # if we are not at the root of the tree
        if ($this->CurrentSeekIndex >= 0) {
            # move up one level in tree
            $this->CurrentSeekIndex = $this->TagParents[$this->CurrentSeekIndex];

            # clear item list
            unset($this->CurrentItemList);

            # return name of new tag to caller
            $Result = $this->TagNames[$this->CurrentSeekIndex];
        } else {
            # return NULL indicating that no parent was found
            $Result = null;
        }

        # return result to caller
        if ($this->DebugLevel > 0) {
            print("XMLParser->SeekToParent() returned " . $Result . "<br>\n");
        }
        return $Result;
    }

    /**
     * Move seek pointer to first child of current tag.
     * @param int $ChildIndex Index to find.
     * @return string Tag name or NULL if no children.
     */
    public function seekToChild($ChildIndex = 0)
    {
        # look for tags with current tag as parent
        $ChildTags = array_keys($this->TagParents, $this->CurrentSeekIndex);

        # if child tag was found with requested index
        if (isset($ChildTags[$ChildIndex])) {
            # set current seek index to child
            $this->CurrentSeekIndex = $ChildTags[$ChildIndex];

            # clear item list info
            unset($this->CurrentItemList);

            # return name of new tag to caller
            $Result = $this->TagNames[$this->CurrentSeekIndex];
        } else {
            # return NULL indicating that no children were found
            $Result = null;
        }

        # return result to caller
        if ($this->DebugLevel > 0) {
            print("XMLParser->SeekToChild() returned " . $Result . "<br>\n");
        }
        return $Result;
    }

    /**
     * Move seek pointer to root of tree.
     */
    public function seekToRoot()
    {
        $this->CurrentSeekIndex = -1;
    }

    /**
     * Move to next tag at current level.
     * @return string Tag name or NULL if no next tag.
     */
    public function nextTag()
    {
        # get list of tags with same parent as this tag
        $LevelTags = array_keys(
            $this->TagParents,
            $this->TagParents[$this->CurrentSeekIndex]
        );

        # find position of next tag in list
        $NextTagPosition = array_search($this->CurrentSeekIndex, $LevelTags) + 1;

        # if there is a next tag
        if (count($LevelTags) > $NextTagPosition) {
            # move seek pointer to next tag at this level
            $this->CurrentSeekIndex = $LevelTags[$NextTagPosition];

            # rebuild item list

            # return name of tag at new position to caller
            return $this->TagNames[$this->CurrentSeekIndex];
        } else {
            # return NULL to caller to indicate no next tag
            return null;
        }
    }

    /**
     * Move to next instance of current tag.
     * @return int Index or NULL if no next.
     */
    public function nextItem()
    {
        # set up item list if necessary
        if (!isset($this->CurrentItemList)) {
            $this->RebuildItemList();
        }

        # if there are items left to move to
        if ($this->CurrentItemIndex < ($this->CurrentItemCount - 1)) {
            # move item pointer to next item
            $this->CurrentItemIndex++;

            # set current seek pointer to next item
            $this->CurrentSeekIndex =
                $this->CurrentItemList[$this->CurrentItemIndex];

            # return new item index to caller
            $Result = $this->CurrentItemIndex;
        } else {
            # return NULL value to caller to indicate failure
            $Result = null;
        }

        # return result to caller
        return $Result;
    }

    /**
     * Move to previous instance of current tag.
     * @return int Index or NULL on fail.
     */
    public function previousItem()
    {
        # set up item list if necessary
        if (!isset($this->CurrentItemList)) {
            $this->RebuildItemList();
        }

        # if we are not at the first item
        if ($this->CurrentItemIndex > 0) {
            # move item pointer to previous item
            $this->CurrentItemIndex--;

            # set current seek pointer to next item
            $this->CurrentSeekIndex =
                $this->CurrentItemList[$this->CurrentItemIndex];

            # return new item index to caller
            return $this->CurrentItemIndex;
        } else {
            # return NULL value to caller to indicate failure
            return null;
        }
    }

    /**
     * Retrieve tag name from current seek point.
     * @return string Tag name or NULL if no tag found.
     */
    public function getTagName()
    {
        if (isset($this->TagNames[$this->CurrentSeekIndex])) {
            return $this->TagNames[$this->CurrentSeekIndex];
        } else {
            return null;
        }
    }

    /**
     * Retrieve data from current seek point.
     * @return string Data or NULL if no data found.
     * @see PerformSeek()
     */
    public function getData()
    {
        # assume that we will not be able to retrieve data
        $Data = null;

        # if arguments were supplied
        if (func_num_args()) {
            # retrieve index for specified point
            $Index = $this->PerformSeek(func_get_args(), false);

            # if valid index was found
            if ($Index !== null) {
                # retrieve data at index to be returned to caller
                $Data = $this->TagData[$Index];
            }
        } else {
            # if current seek index points to valid tag
            if ($this->CurrentSeekIndex >= 0) {
                # retrieve data to be returned to caller
                $Data = $this->TagData[$this->CurrentSeekIndex];
            }
        }

        # return data to caller
        if ($this->DebugLevel > 0) {
            print("XMLParser->GetData(");
            if (func_num_args()) {
                $ArgString = "";
                foreach (func_get_args() as $Arg) {
                    $ArgString .= "\"" . $Arg . "\", ";
                }
                $ArgString = substr($ArgString, 0, strlen($ArgString) - 2);
                print($ArgString);
            }
            print(") returned " . ($Data ? "\"" . $Data . "\"" : "NULL") . "<br>\n");
        }
        return $Data;
    }

    /**
     * Retrieve specified attribute from current seek point or specified
     * point below.  First argument is attribute name and subsequent optional
     * arguments tell where to seek to.
     * @return string Specified tag or NULL if no such attribute for current.
     */
    public function getAttribute()
    {
        # retrieve attribute
        $Args = func_get_args();
        $Attrib = $this->PerformGetAttribute($Args, false);

        # return requested attribute to caller
        if ($this->DebugLevel > 0) {
            print("XMLParser->GetAttribute() returned " . $Attrib . "<br>\n");
        }
        return $Attrib;
    }

    /**
     * Retrieve specified attributes from current seek point or specified
     * point below.  First argument is attribute name and subsequent optional
     * arguments tell where to seek to.
     * @return string Specified tags or NULL if no such attribute for current.
     */
    public function getAttributes()
    {
        # retrieve attribute
        $Args = func_get_args();
        $Attribs = $this->PerformGetAttribute($Args, true);

        # return requested attribute to caller
        if ($this->DebugLevel > 0) {
            print("XMLParser->GetAttributes() returned " . count($Attribs)
                . " attributes<br>\n");
        }
        return $Attribs;
    }

    /**
     * Set current debug output level (0-9).
     * @param int $NewLevel New debug output level.
     */
    public function setDebugLevel($NewLevel)
    {
        $this->DebugLevel = $NewLevel;
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $CurrentItemCount;
    private $CurrentItemIndex;
    private $CurrentItemList;
    private $CurrentParseIndex;
    private $CurrentSeekIndex;
    private $DebugLevel;
    private $NameKeyCache;
    private $Parser;
    private $TagAttribs;
    private $TagData;
    private $TagNames;
    private $TagParents;

    /**
     * Callback function for handling open tags.
     * @param object $Parser XML parser.
     * @param string $ElementName Name of element.
     * @param array $ElementAttribs Attributes.
     */
    private function openTag($Parser, $ElementName, $ElementAttribs): void
    {
        # add new tag to list
        $NewTagIndex = count($this->TagNames);
        $this->TagNames[$NewTagIndex] = $ElementName;
        $this->TagAttribs[$NewTagIndex] = $ElementAttribs;
        $this->TagParents[$NewTagIndex] = $this->CurrentParseIndex;
        $this->TagData[$NewTagIndex] = null;

        # set current tag to new tag
        $this->CurrentParseIndex = $NewTagIndex;
    }

    /**
     * Callback function for receiving data between tags.
     * @param object $Parser XML parser.
     * @param string $Data Data found.
     */
    private function receiveData($Parser, $Data): void
    {
        # add data to currently open tag
        $this->TagData[$this->CurrentParseIndex] .= $Data;
    }

    /**
     * Callback function for handling close tags.
     * @param object $Parser XML parser.
     * @param string $ElementName Name of element.
     */
    private function closeTag($Parser, $ElementName): void
    {
        # if we have an open tag and closing tag matches currently open tag
        if (($this->CurrentParseIndex >= 0)
            && ($ElementName == $this->TagNames[$this->CurrentParseIndex])) {
            # set current tag to parent tag
            $this->CurrentParseIndex = $this->TagParents[$this->CurrentParseIndex];
        }
    }

    /**
     * Perform seek to point in tag tree and update seek pointer (if requested).
     * @param array $SeekArgs Parameters for seek.
     * @param bool $MoveSeekPointer If TRUE, seek pointer will be updated.
     * @return int New index or NULL on failure.
     */
    private function performSeek($SeekArgs, $MoveSeekPointer)
    {
        # for each tag name or index in argument list
        $NewSeekIndex = $this->CurrentSeekIndex;
        foreach ($SeekArgs as $Arg) {
            # if argument is string
            if (is_string($Arg)) {
                # look for tags with given name and current tag as parent
                $Arg = strtoupper($Arg);
                if (!isset($this->NameKeyCache[$Arg])) {
                    $this->NameKeyCache[$Arg] = array_keys($this->TagNames, $Arg);
                    $TestArray = array_keys($this->TagNames, $Arg);
                }
                $ChildTags = array_keys($this->TagParents, $NewSeekIndex);
                $NewItemList = array_values(
                    array_intersect($this->NameKeyCache[$Arg], $ChildTags)
                );
                $NewItemCount = count($NewItemList);

                # if matching tag found
                if ($NewItemCount > 0) {
                    # update local seek index
                    $NewSeekIndex = $NewItemList[0];

                    # save new item index
                    $NewItemIndex = 0;
                } else {
                    # report seek failure to caller
                    return null;
                }
            } else {
                # look for tags with same name and same parent as current tag
                $NameTags = array_keys(
                    $this->TagNames,
                    $this->TagNames[$NewSeekIndex]
                );
                $ChildTags = array_keys(
                    $this->TagParents,
                    $this->TagParents[$NewSeekIndex]
                );
                $NewItemList = array_values(array_intersect($NameTags, $ChildTags));
                $NewItemCount = count($NewItemList);

                # if enough matching tags were found to contain requested index
                if ($NewItemCount > $Arg) {
                    # update local seek index
                    $NewSeekIndex = $NewItemList[$Arg];

                    # save new item index
                    $NewItemIndex = $Arg;
                } else {
                    # report seek failure to caller
                    return null;
                }
            }
        }

        # if caller requested that seek pointer be moved to reflect seek
        if ($MoveSeekPointer) {
            # update seek index
            $this->CurrentSeekIndex = $NewSeekIndex;

            # update item index and list
            $this->CurrentItemIndex = $NewItemIndex;
            $this->CurrentItemList = $NewItemList;
            $this->CurrentItemCount = $NewItemCount;
        }

        # return index of found seek
        return $NewSeekIndex;
    }

    /**
     * Retrieve attribute.
     * @param array $Args Arguments for seeking.
     * @param bool $GetMultiple Whether to retrieve a single attribute or
     *       multiple attributes.
     * @return string Attributes or NULL if retrieval fails.
     */
    private function performGetAttribute($Args, $GetMultiple)
    {
        # assume that we will not be able to retrieve attribute
        $ReturnVal = null;

        # retrieve attribute name and (possibly) seek arguments
        if (!$GetMultiple) {
            $AttribName = strtoupper(array_shift($Args));
        }

        # if arguments were supplied
        if (count($Args)) {
            # retrieve index for specified point
            $Index = $this->PerformSeek($Args, false);

            # if valid index was found
            if ($Index !== null) {
                # if specified attribute exists
                if (isset($this->TagAttribs[$Index][$AttribName])) {
                    # retrieve attribute(s) at index to be returned to caller
                    if ($GetMultiple) {
                        $ReturnVal = $this->TagAttribs[$Index];
                    } else {
                        $ReturnVal = $this->TagAttribs[$Index][$AttribName];
                    }
                }
            }
        } else {
            # if current seek index points to valid tag
            $SeekIndex = $this->CurrentSeekIndex;
            if ($SeekIndex >= 0) {
                # if specified attribute exists
                if (isset($this->TagAttribs[$SeekIndex][$AttribName])) {
                    # retrieve attribute(s) to be returned to caller
                    if ($GetMultiple) {
                        $ReturnVal = $this->TagAttribs[$SeekIndex];
                    } else {
                        $ReturnVal = $this->TagAttribs[$SeekIndex][$AttribName];
                    }
                }
            }
        }

        # return requested attribute to caller
        return $ReturnVal;
    }

    /**
     * Rebuild internal list of tags with the same tag name and same parent
     * as current.
     */
    private function rebuildItemList(): void
    {
        # get list of tags with the same parent as current tag
        $SameParentTags = array_keys(
            $this->TagParents,
            $this->TagParents[$this->CurrentSeekIndex]
        );

        # get list of tags with the same name as current tag
        $SameNameTags = array_keys(
            $this->TagNames,
            $this->TagNames[$this->CurrentSeekIndex]
        );

        # intersect lists to get tags with both same name and same parent as current
        $this->CurrentItemList = array_values(
            array_intersect($SameNameTags, $SameParentTags)
        );

        # find and save index of current tag within item list
        $this->CurrentItemIndex = array_search(
            $this->CurrentSeekIndex,
            $this->CurrentItemList
        );

        # save length of item list
        $this->CurrentItemCount = count($this->CurrentItemList);
    }

    /**
     * Internal method for debugging.
     */
    private function dumpInternalArrays(): void
    {
        foreach ($this->TagNames as $Index => $Name) {
            printf(
                "[%03d] %-12.12s %03d %-30.30s \n",
                $Index,
                $Name,
                $this->TagParents[$Index],
                trim($this->TagData[$Index])
            );
        }
    }
}
