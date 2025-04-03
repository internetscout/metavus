<?PHP
#
#   FILE:  ResourceSelectionUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\EduLink;

use Metavus\HtmlButton;
use Metavus\Record;
use Metavus\ResourceSummary_DeepLinking;
use ScoutLib\ApplicationFramework;
use Metavus\Plugins\EduLink;

/**
 * Resource Selection interface for the LTI Deep Linking popups.
*/
class ResourceSelectionUI
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Class constructor.
     * @param array $RecordList List of records to offer as selectable
     *   options.
     * @param array $SelectedRecords Records currently selected. May include
     *   records not in $RecordList (e.g., selections retained from previous pages)
     */
    public function __construct(array $RecordList, array $SelectedRecords)
    {
        $this->RecordList = $RecordList;
        $this->SelectedRecords = $SelectedRecords;
    }

    /**
     * Get HTML for the list of selected records.
     * @return string HTML for selected records.
     */
    public function selectedRecordListHtml() : string
    {
        $AF = ApplicationFramework::getInstance();

        $AF->doNotUrlFingerprint('ArrowUpInsideCircle.svg');
        $ArrowImagePath = $AF->gUIFile('ArrowUpInsideCircle.svg');

        # get the list of records that aren't selected
        $UnselectedRecords = array_diff(
            $this->RecordList,
            $this->SelectedRecords
        );

        # list of all records, with selections first
        $AllRecordIds = array_merge(
            $this->SelectedRecords,
            $UnselectedRecords
        );
        $SelectedRecords = array_fill_keys($this->SelectedRecords, true);

        $SendToLMSButton = new HtmlButton("Send to LMS");
        $SendToLMSButton->setIcon("ArrowUpInsideCircle.svg");
        $SendToLMSButton->addClass("mv-p-edulink-send-button");
        $SendToLMSButton->setValue("Select");

        $Result = "<div class='mv-p-edulink-selected-container'"
            .(count($this->SelectedRecords) == 0 ? ' style="display: none"' : '').">"
            . "<div class='float-end'>" . $SendToLMSButton->getHtml() . "</div>"
            ."<h2>".file_get_contents($ArrowImagePath)." Selected</h2>";

        $Result .= "<div class='mv-p-edulink-selected-records'>";
        foreach ($AllRecordIds as $RecordId) {
            $Record = new Record($RecordId);
            $RecordUrl = $Record->getViewPageUrl();
            $IsSelected = isset($SelectedRecords[$RecordId]);

            $Result .= '<div class="row"'.(!$IsSelected ? ' style="display: none"' : '').'>'
                .'<div class="col">'
                ."<p><b><a href='".$RecordUrl."' target='_blank'>"
                .$Record->getMapped("Title")."</a></b></p>"
                .'</div>'
                .'<div class="col-1 text-end">'
                .'<label class="mv-p-edulink-record-control mv-p-edulink-record-select btn btn-primary" '
                .'tabindex="0"'
                .'>X<input type="checkbox" '
                .'data-recordid="'.$RecordId.'" '
                .'name="F_Select_'.$RecordId.'" value="1"'
                .($IsSelected ? ' checked' : '').'/></label>'
                .'</div>'
                .'</div>';
        }
        $Result .= "</div>";

        $Result .= "</div>";

        return $Result;
    }

    /**
     * Get HTML for the record selection interface.
     * @return string HTML
     */
    public function recordListHtml() : string
    {
        if (count($this->RecordList) == 0) {
            return "<p><i>No resources matched the specified conditions.</i></p>";
        }

        $AF = ApplicationFramework::getInstance();
        $Plugin = EduLink::getInstance();

        $SelectedRecords = array_fill_keys($this->SelectedRecords, true);

        $ButtonClasses = "mv-p-edulink-record-control btn btn-sm btn-primary mv-button-iconed";

        $Result = "";
        foreach ($this->RecordList as $RecordId) {
            $IsSelected = isset($SelectedRecords[$RecordId]);

            $Summary = new ResourceSummary_DeepLinking($RecordId);

            $Result .= '<div id="mv-p-edulink-record-container-'.$RecordId.'" class="row">';
            $Result .= '<div class="col">';

            ob_start();
            $Summary->displayCompact();
            $Result .= ob_get_clean();

            $Result .= '<div class="mv-p-edulink-record" style="position: relative">';
            $Result .= '<label class="mv-p-edulink-record-remove '.$ButtonClasses.'" '
                .(!$IsSelected ? 'style="display: none" ' : '')
                .'tabindex="0" data-recordid="'.$RecordId.'" />'
                .'<img src="'.$AF->GUIFile('Minus.svg').'" alt=""/> Remove'
                .'</label>';
            $Result .= '<label class="mv-p-edulink-record-select '.$ButtonClasses.'" '
                .($IsSelected ? 'style="display: none" ' : '')
                .'tabindex="0" data-recordid="'.$RecordId.'" />'
                .'<img src="'.$AF->GUIFile('Plus.svg').'" alt=""/> Select'
                .'</label>';

            $Result .= '</div></div></div>';
        }

        return $Result;
    }

    /**
     * Get the current set of selected records either from POST or from a
     * cookie.
     * @return array Selected records
     */
    public static function getCurrentSelections() : array
    {
        # if POST was provided, collect selections from it
        if (count($_POST) > 0) {
            $SelectedRecordIds = [];
            foreach ($_POST as $Key => $Val) {
                if (!preg_match("%^F_Select_([0-9]+)$%", $Key, $Matches)) {
                    continue;
                }

                if ($Val != 1) {
                    continue;
                }

                $SelectedRecordIds[] = $Matches[1];
            }
            return $SelectedRecordIds;
        }

        # check for a value from a cookie
        if (isset($_COOKIE["LTISelections"]) && strlen($_COOKIE["LTISelections"]) > 0) {
            return explode("-", $_COOKIE["LTISelections"]);
        }

        # if neither was found, there are no selections
        return [];
    }


    # ---- PRIVATE INTERFACE -------------------------------------------------
    private $RecordList;
    private $SelectedRecords;
}
