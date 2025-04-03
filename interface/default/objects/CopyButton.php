<?PHP
#
#   FILE:  CopyButton.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use ScoutLib\ApplicationFramework;

/**
 * Convenience class for generating HTML for a 'copy to clipboard' button.
 */
class CopyButton extends HtmlButton
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param string $TargetId HTML ID of the element containing the text that
     *     should be copied.
     */
    public function __construct(string $TargetId)
    {
        parent::__construct("Copy");
        $this->setIcon("Copy.svg");
        $this->setOnclick("mv_handleCopyButtonClick(event)");

        $this->TargetId = $TargetId;
    }

    /**
     * Generate and return HTML to display button.
     * @return string HTML for button.
     */
    public function getHtml(): string
    {
        ApplicationFramework::getInstance()
            ->requireUIFile("CopyButton.js");

        return "<div data-target='".$this->TargetId."'>"
            .parent::getHtml()
            ."</div>";
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $TargetId = "";
}
