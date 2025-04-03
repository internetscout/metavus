<?PHP
#
#   RSS.php
#   An Object to Support RSS 0.92 (Rich Site Summary) Output
#
#   Copyright 2002-2025 Axis Data
#   This code is free software that can be used or redistributed under the
#   terms of Version 2 of the GNU General Public License, as published by the
#   Free Software Foundation (http://www.fsf.org).
#
#   Part of the AxisPHP library v1.2.5
#   For more information see http://www.axisdata.com/AxisPHP/
#

namespace ScoutLib;

class RSS
{

    # ---- PUBLIC INTERFACE --------------------------------------------------

    public function __construct()
    {
        $this->ChannelCount = -1;

        # default encoding is UTF-8
        $this->Encoding = "UTF-8";
    }

    # required channel values
    public function addChannel($Title, $Link, $Description, $RssLink): void
    {
        $this->ChannelCount++;
        $this->ItemCounts[$this->ChannelCount] = -1;
        $this->Channels[$this->ChannelCount]["Title"] = $Title;
        $this->Channels[$this->ChannelCount]["Link"] = $Link;
        $this->Channels[$this->ChannelCount]["Description"] = $Description;
        $this->Channels[$this->ChannelCount]["RssLink"] = $RssLink;
        $this->Channels[$this->ChannelCount]["CategoryCount"] = 0;
    }

    public function setImage(
        $Url,
        $Height = null,
        $Width = null,
        $Description = null
    ): void {
        $this->Channels[$this->ChannelCount]["ImageUrl"] = $Url;
        $this->Channels[$this->ChannelCount]["ImageHeight"] = $Height;
        $this->Channels[$this->ChannelCount]["ImageWidth"] = $Width;
        $this->Channels[$this->ChannelCount]["ImageDescription"] = $Description;
    }

    # optional channel values
    public function setEncoding($Value): void
    {
        $this->Encoding = $Value;
    }

    public function setLanguage($Value): void
    {
        $this->Channels[$this->ChannelCount]["Language"] = $Value;
    }

    public function setCopyright($Value): void
    {
        $this->Channels[$this->ChannelCount]["Copyright"] = $Value;
    }

    public function setManagingEditor($Value): void
    {
        $this->Channels[$this->ChannelCount]["ManagingEditor"] = $Value;
    }

    public function setWebmaster($Value): void
    {
        $this->Channels[$this->ChannelCount]["Webmaster"] = $Value;
    }

    public function addCategory($Value): void
    {
        $this->Channels[$this->ChannelCount]["Category"][] = $Value;
    }

    public function setPicsRating($Value): void
    {
        $this->Channels[$this->ChannelCount]["PicsRating"] = $Value;
    }

    public function setPublicationDate($Value): void
    {
        $this->Channels[$this->ChannelCount]["PublicationDate"] = $this->formatDate($Value);
    }

    public function setLastChangeDate($Value): void
    {
        $this->Channels[$this->ChannelCount]["LastChangeDate"] = $this->formatDate($Value);
    }

    public function setTextInput($Title, $Description, $Name): void
    {
        $this->Channels[$this->ChannelCount]["TextInputTitle"] = $Title;
        $this->Channels[$this->ChannelCount]["TextInputDescription"] = $Description;
        $this->Channels[$this->ChannelCount]["TextInputName"] = $Name;
    }

    public function setSkipTimes($Days, $Hours): void
    {
        # ???
    }

    public function setCloud($Domain, $Port, $Path, $Procedure, $Protocol): void
    {
        # ???
    }

    # add item to channel
    public function addItem($Title = null, $Link = null, $Description = null, $Date = null): void
    {
        $this->ItemCounts[$this->ChannelCount]++;
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["Title"] = $Title;
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["Link"] = $Link;
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["Description"] = $Description;
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["Date"] = $this->formatDate($Date);
    }

    public function addItemAuthor($Email): void
    {
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["Author"] = $Email;
    }

    public function addItemCategory($Category, $Url = null): void
    {
        $this->CategoryCount++;
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["Category"][$this->CategoryCount] = $Category;
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["CategoryUrl"][$this->CategoryCount] = $Url;
    }

    public function addItemComments($Url): void
    {
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["Comments"] = $Url;
    }

    public function addItemEnclosure($Url, $Length, $Type): void
    {
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["EnclosureUrl"] = $Url;
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["EnclosureLength"] = $Length;
        $this->Items[$this->ChannelCount][$this->ItemCounts[$this->ChannelCount]]
                ["EnclosureType"] = $Type;
    }

    # write out and RSS page
    public function printRSS(): void
    {
        # print opening elements
        header("Content-type: application/rss+xml; charset=".$this->Encoding, true);
        $this->fTOut("<?xml version='1.0' encoding='".$this->Encoding."' ?>");
        $this->fTOut("<rss version='2.0' xmlns:atom='http://www.w3.org/2005/Atom'>", 0);

        # for each channel
        for ($this->ChannelIndex = 0;
                $this->ChannelIndex <= $this->ChannelCount;
                $this->ChannelIndex++) {
            # open channel element
            $this->fTOut("<channel>");

            # print required channel elements
            $this->printChannelElement("Title", "title");
            $this->printChannelElement("Link", "link");
            $this->printChannelElement("Description", "description");
            $this->fTOut(
                "<atom:link href='"
                    .$this->Channels[$this->ChannelCount]["RssLink"]
                    ."' rel='self' type='application/rss+xml' />"
            );

            # print image element if set (url, title, link required)
            # title and link should be the same as those for the channel
            if ($this->isChannelElementSet("ImageUrl")) {
                $this->fTOut("<image>");
                $this->printChannelElement("ImageUrl", "url");
                $this->printChannelElement("Title", "title");
                $this->printChannelElement("Link", "link");
                $this->printChannelElement("ImageWidth", "width");
                $this->printChannelElement("ImageHeight", "height");
                $this->printChannelElement("ImageDescription", "description");
                $this->fTOut("</image>");
            }

            # print optional channel elements
            $this->printChannelElement("Language", "language");
            $this->printChannelElement("Copyright", "copyright");
            $this->printChannelElement("ManagingEditor", "managingEditor");
            $this->printChannelElement("Webmaster", "webMaster");
            $this->printChannelCategories();
            $this->printChannelElement("PicsRating", "rating");
            $this->printChannelElement("PublicationDate", "pubDate");
            $this->printChannelElement("LastChangeDate", "lastBuildDate");
            # ???  STILL TO DO:  SkipDays, SkipHours, Cloud
            $this->fTOut("<docs>http://www.rssboard.org/rss-2-0-1</docs>");

            # for each item in this channel
            for ($this->ItemIndex = 0;
                    $this->ItemIndex <= $this->ItemCounts[$this->ChannelCount];
                    $this->ItemIndex++) {
                # open item element
                $this->fTOut("<item>");

                # print item elements
                $this->printItemElement("Title", "title");
                $this->printItemElement("Link", "link");
                $this->printItemElement("Link", "guid");
                $this->printItemElement("Description", "description");
                $this->printItemElement("Date", "pubDate");
                $ItemInfo = $this->Items[$this->ChannelIndex][$this->ItemIndex];
                if (isset($ItemInfo["Author"])
                        && ($ItemInfo["Author"] != null)) {
                    $this->fTOut("<author>"
                            .$ItemInfo["Author"]
                            ."</author>");
                }
                if (isset($ItemInfo["Category"])) {
                    foreach ($ItemInfo["Category"] as $Count => $Category) {
                        if (isset($ItemInfo["CategoryUrl"][$Count])
                                && ($ItemInfo["CategoryUrl"][$Count])
                                != null) {
                            $this->fTOut("<category domain='"
                                    .$ItemInfo["CategoryUrl"][$Count]
                                    ."'>".$Category."</category>");
                        } else {
                            $this->fTOut("<category>".$Category."</category>");
                        }
                    }
                }
                if (isset($ItemInfo["Comments"])
                        && ($ItemInfo["Comments"] != null)) {
                    $this->fTOut("<comments>"
                            .$ItemInfo["Comments"]
                            ."</comments>");
                }
                if (isset($ItemInfo["EnclosureUrl"])
                        && ($ItemInfo["EnclosureUrl"] != null)) {
                    $this->fTOut("<enclosure "
                            ."url='".$ItemInfo["EnclosureUrl"]."' "
                            ."length='".$ItemInfo["EnclosureLength"]."' "
                            ."type='".$ItemInfo["EnclosureType"]."' />");
                }

                # close item element
                $this->fTOut("</item>");
            }

            # close channel element
            $this->fTOut("</channel>");
        }

        # print closing elements
        $this->fTOut("</rss>");
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $CategoryCount;
    private $ChannelCount;
    private $ChannelIndex;
    private $Channels;
    private $Encoding;
    private $ItemCounts;
    private $ItemIndex;
    private $Items;

    /**
     * Determine whether a channel element is set.
     * @param string $VarName channel element name
     * @return bool TRUE if the element is set, FALSE otherwise
     */
    private function isChannelElementSet(string $VarName): bool
    {
        return (isset($this->Channels[$this->ChannelIndex][$VarName])
                && $this->Channels[$this->ChannelIndex][$VarName] != null
                && strlen($this->Channels[$this->ChannelIndex][$VarName]));
    }

    /**
     * Determine whether an item element is set.
     * @param string $VarName item element name
     * @return bool TRUE if the element is set, FALSE otherwise
     */
    private function isItemElementSet(string $VarName): bool
    {
        return (isset($this->Items[$this->ChannelIndex][$this->ItemIndex][$VarName])
                && $this->Items[$this->ChannelIndex][$this->ItemIndex][$VarName] != null);
    }

    /**
     * Print a channel element if it is set.
     * @param string $VarName item element name
     * @param string $TagName item tag name
     */
    private function printChannelElement(string $VarName, string $TagName): void
    {
        # only print channel elements if set
        if (!$this->isChannelElementSet($VarName)) {
            return;
        }

        $InnerText = $this->escapeInnerText(
            $this->Channels[$this->ChannelIndex][$VarName]
        );

        $this->fTOut("<".$TagName.">".$InnerText."</".$TagName.">");
    }

    /**
     * Print the categories for a channel.
     */
    private function printChannelCategories(): void
    {
        # only print categories if there is at least one
        if (!isset($this->Channels[$this->ChannelIndex]["Category"])) {
            return;
        }

        foreach ($this->Channels[$this->ChannelIndex]["Category"] as $Category) {
            $InnerText = $this->escapeInnerText($Category);
            $this->fTOut("<category>".$InnerText."</category>");
        }
    }

    /**
     * Print an item element if it is set.
     * @param string $VarName item element name
     * @param string $TagName item tag name
     */
    private function printItemElement(string $VarName, string $TagName): void
    {
        # only print elements that are set
        if (!$this->isItemElementSet($VarName)) {
            return;
        }

        # do not escape inner text for description
        if ($VarName == "Description") {
            $InnerText = $this->Items[$this->ChannelIndex][$this->ItemIndex][$VarName];
        } else {
            $InnerText = $this->escapeInnerText(
                $this->Items[$this->ChannelIndex][$this->ItemIndex][$VarName]
            );
        }

        $this->fTOut("<".$TagName.">".$InnerText."</".$TagName.">");
    }

    /**
     * Transform dates to the format specified by the RSS specification and
     * best practices. See:
     * http://www.rssboard.org/rss-profile#data-types-datetime
     * @param string $Value date value
     * @return string formatted date value
     */
    private function formatDate(string $Value)
    {
        return date("D, j M Y H:i:s O", strtotime($Value));
    }

    /**
     * Escape text destined for a RSS element. See:
     * http://www.rssboard.org/rss-profile#data-types-characterdata
     * @param string $Text Text destined for an RSS element.
     * @return string Escaped text.
     */
    private function escapeInnerText(string $Text)
    {
        # remove control characters
        $Intermediate = preg_replace("/[\\x00-\\x1F]+/", "", $Text);

        # escape XML special characters for PHP version < 5.2.3
        if (version_compare(phpversion(), "5.2.3", "<")) {
            $Intermediate = htmlspecialchars(
                $Intermediate,
                ENT_QUOTES,
                $this->Encoding
            );
            # escape XML special characters for PHP version >= 5.2.3
        } else {
            $Intermediate = htmlspecialchars(
                $Intermediate,
                ENT_QUOTES,
                $this->Encoding,
                false
            );
        }

        # map named entities to their hex references
        $Replacements = array(
            "&amp;" => "&#x26;",
            "&lt;" => "&#x3C;",
            "&gt;" => "&#x3E;",
            "&quot;" => "&#x22;",
            "&rsquo;" => "&#x2019;",
            "&#039;" => "&#x27;"
        );

        # replace named entities with hex references for compatibility as
        # specified by the RSS spec/best practices
        $Intermediate = str_replace(
            array_keys($Replacements),
            array_values($Replacements),
            $Intermediate
        );

        return $Intermediate;
    }

    # (FTOut == Formatted Tag Output)
    private function fTOut($String, $NewIndent = null): void
    {
        static $Indent = 0;

        $IndentSize = 4;

        # decrease indent if string contains end tag and does not start with begin tag
        if (preg_match("/<\/[A-Za-z0-9]+>/", $String) && !preg_match("/^<[^\/]+/", $String)) {
            $Indent--;
        }

        # reset indent if value is supplied
        if ($NewIndent != null) {
            $Indent = $NewIndent;
        }

        # print string
        printf("%".($Indent * $IndentSize)."s\n", $String);

        # inrease indent if string starts with begin tag and does not contain end tag
        if (preg_match("/^<[^\/]+/", $String)
                && !preg_match("/<\/[A-Za-z0-9]+>/", $String)
                && !preg_match("/\/>$/", $String)) {
            $Indent++;
        }
    }
}
