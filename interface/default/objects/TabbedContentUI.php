<?PHP
#
#   FILE:  TabbedContentUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2018-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use InvalidArgumentException;
use ScoutLib\ApplicationFramework;

/**
* Class to provide a user interface for displaying content in a tabbed format.
*/
class TabbedContentUI
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
    * Begin content for tab.  After this is called, all content being
    * output will be captured for the current tab, until either EndTab() or
    * Display() are called.
    * @param string $TabLabel Name to display on tab.
    * @throws InvalidArgumentException If specified tab name is a duplicate.
    * @throws InvalidArgumentException If tab has already been started with
    *       specified name.
    * @return void
    */
    public function beginTab(string $TabLabel): void
    {
        # check to make sure tab label is not a duplicate or already started
        if (isset($this->CurrentTab[$TabLabel])) {
            throw new InvalidArgumentException(
                "Duplicate tab name (\"".$TabLabel."\")."
            );
        }
        if ($TabLabel == $this->CurrentTab) {
            throw new InvalidArgumentException(
                "Tab \"".$TabLabel."\" already started."
            );
        }

        # if another tab is currently started
        if (isset($this->CurrentTab)) {
            # end current tab
            $this->endTab();
        }

        # set default active tab if no active tab already set
        if (!isset($this->ActiveTab)) {
            $this->ActiveTab = $TabLabel;
        }

        # beginning buffering content for new tab
        $this->CurrentTab = $TabLabel;
        ob_start();
    }

    /**
    * End current tab.  This is optional, as both BeginTab() and Display()
    * will end the current tab before starting a new one or displaying the
    * tabbed content, respectively.  It would normally only be called when
    * there is a need to output other content while building tabbed content.
    * @throws Exception If no tab is currently started.
    * @return void
    */
    public function endTab(): void
    {
        # check to make sure tab has been started
        if (!isset($this->CurrentTab)) {
            throw new Exception("No tab currently in progress.");
        }

        # stop buffering and save content for tab
        $this->TabContent[$this->CurrentTab] = ob_get_contents();
        ob_end_clean();

        # note that no tab is currently under way
        unset($this->CurrentTab);
    }

    /**
    * Get/set tab to be active (i.e. initially displayed).  If this is not
    * called, the first tab will be active by default.
    * @param string $NewValue Name of tab to be made active.  (OPTIONAL)
    * @return string Current tab name.
    */
    public function activeTab(?string $NewValue = null): string
    {
        if ($NewValue !== null) {
            $this->ActiveTab = $NewValue;
        }
        return $this->ActiveTab;
    }

    /**
    * Output HTML for tabbed content.
    * @param string $Id CSS ID for tabs.  (OPTIONAL, defaults to "mv-tabs")
    * @throws Exception If the active tab setting does not match any existing tab.
    * @return void
    */
    public function display(string $Id = "mv-tabs"): void
    {
        print $this->getHtml($Id);
    }

    /**
    * Get HTML for tabbed content.
    * @param string $Id CSS ID for tabs.  (OPTIONAL, defaults to "mv-tabs")
    * @return string HTML for tabbed content block.
    * @throws Exception If the active tab setting does not match any existing tab.
    */
    public function getHtml(string $Id = "mv-tabs"): string
    {
        # if tab is currently started
        if (isset($this->CurrentTab)) {
            # end current tab
            $this->endTab();
        }

        # check to make sure active tab is valid
        if (!isset($this->TabContent[$this->ActiveTab])) {
            throw new Exception("Active tab (.\"".$this->ActiveTab."\") is invalid.");
        }

        # make sure JavaScript and CSS needed for tabbed interface is loaded
        $AF = ApplicationFramework::getInstance();
        $AF->requireUIFile('jquery-ui.css', ApplicationFramework::ORDER_FIRST);
        $AF->requireUIFile('jquery-ui.js');

        # begin tabbed content
        ob_start();
        ?><div class="mv-tab-container"><div id="<?= $Id ?>">
        <?PHP

        # begin tab navigation
        ?><ul class="mv-tab-nav">
        <?PHP

        # for each tab
        foreach ($this->TabContent as $Label => $Content) {
            # add navigation for tab
            $Suffix = self::getIdSuffix($Label);
            ?><li><a href="#mv-tabs-<?= $Suffix ?>"><b><?=
                    htmlspecialchars($Label) ?></b></a></li><?PHP
        }

        # end tab navigation
        ?></ul>
        <?PHP

        # for each tab
        $TabIndex = 0;
        $ActiveTabIndex = 0;
        foreach ($this->TabContent as $Label => $Content) {
            # add content section for tab
            $Suffix = self::getIdSuffix($Label);
            ?><div id="mv-tabs-<?= $Suffix ?>"><?= $Content ?></div>
            <?PHP

            # if tab is active save tab index for later use
            if ($Label == $this->ActiveTab) {
                $ActiveTabIndex = $TabIndex;
            }
            $TabIndex++;
        }

        # end tabbed content
        ?></div></div>
        <?PHP

        # add JavaScript to select active tab
        ?><script type='text/javascript'>
            jQuery(document).ready(function() {
                    jQuery('#<?= $Id ?>').tabs({active: '<?= $ActiveTabIndex ?>'}); });
        </script>
        <?PHP

        # return generated HTML to caller
        $Html = ob_get_clean();
        if ($Html === false) {
            throw new Exception("Unabled to retrieve buffered HTML.");
        }
        return $Html;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $ActiveTab;
    private $CurrentTab;
    private $TabContent;

    /**
    * Generate suffix for CSS tag from supplied string.
    * @param string $Text String to use to generate suffix.
    * @return string Suffix string.
    */
    private static function getIdSuffix($Text)
    {
        return strtolower(preg_replace("/[^a-z]+/i", "", $Text));
    }
}
