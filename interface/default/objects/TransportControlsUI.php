<?PHP
#
#   FILE:  TransportControlsUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2015-2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\StdLib;

/**
 * Class to provide support for transport controls (used for paging back
 * and forth through a list) in the user interface.  This is a child class,
 * that provides just the constants defining the $_GET variable names for
 * values and the method that actually prints the HTML for the controls.
 * The intent is to provide the ability to customize that HTML by replacing
 * just this child class in a different (custom, active) interface.
 */
class TransportControlsUI extends TransportControlsUI_Base
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Generate and print HTML for transport controls.
     * @return void
     */
    public function display(): void
    {
        # make sure all needed values have been set
        if (!isset($this->ItemCount)) {
            throw new Exception("Item count must be set before displaying.");
        }
        if (!isset($this->BaseLink)) {
            throw new Exception("Base link for URLs must be set before displaying.");
        }

        # make sure indexes are within bounds
        $this->checkIndexes();

        if (isset($this->ItemTypeName)) {
            $TypeName = StdLib::pluralize($this->ItemTypeName);
        }

        $LayoutType = ($this->showAnyReverseButtons() ? "R" : "")
            .($this->showAnyForwardButtons() ? "F" : "") ;

        switch ($LayoutType) {
            case "F":
                $NCols = 10;
                $Align = "left";
                break;

            case "R":
                $NCols = 10;
                $Align = "right";
                break;

            case "RF":
                $NCols = 8;
                $Align = "center";
                break;

            default:
                $NCols = 12;
                $Align = "center";
                break;
        }

        $GSTitleAttrib = "Go to first page";
        if (isset($TypeName)) {
            $GSTitleAttrib .= " of ".$TypeName;
        }
        $FRVTitleAttrib = "Jump back";
        if (isset($TypeName)) {
            $FRVTitleAttrib .= " in ".$TypeName;
        }
        $RVTitleAttrib = "Go to previous page";
        if (isset($TypeName)) {
            $RVTitleAttrib .= " of ".$TypeName;
        }
        if (strlen($this->Message)) {
            $Message = $this->Message;
        } else {
            $ItemsLabel = StdLib::pluralize($this->itemTypeName());
            $RangeStart = min($this->startingIndex() + 1, $this->itemCount());
            $RangeEnd = min(
                ($this->startingIndex() + $this->itemsPerPage()),
                $this->itemCount()
            );
            $Message = $ItemsLabel." <b>".number_format($RangeStart)
                    ."</b> - <b>".number_format($RangeEnd)
                    ."</b> of <b>".number_format($this->itemCount())."</b>";
        }
        $FWTitleAttrib = "Go to next page";
        if (isset($TypeName)) {
            $FWTitleAttrib .= " of ".$TypeName;
        }
        $FFWTitleAttrib = "Jump forward";
        if (isset($TypeName)) {
            $FFWTitleAttrib .= " in ".$TypeName;
        }
        $GETitleAttrib = "Go to last page";
        if (isset($TypeName)) {
            $GETitleAttrib .= " of ".$TypeName;
        }

        ?>
        <div class="container mv-transport-controls">
        <div class="row">
        <?PHP  if ($this->showAnyReverseButtons()) {  ?>
            <div class="col-2 text-start">
            <a class="btn btn-primary btn-sm"
                href="<?= $this->goToStartLink() ?>"
                title="<?= $GSTitleAttrib ?>">&#124;<span>&lt;</span></a><?PHP

                if ($this->showFastReverseButton()) {
                    ?><a class="btn btn-primary btn-sm"
                    href="<?= $this->fastReverseLink() ?>"
                    title="<?= $FRVTitleAttrib ?>">&lt;&lt;</a><?PHP
                }

                if ($this->showReverseButton()) {
                    ?><a class="btn btn-primary btn-sm "
                        href="<?= $this->reverseLink() ?>"
                        title="<?= $RVTitleAttrib ?>">&lt;</a>
                <?PHP  }  ?>
            </div>
        <?PHP  }  ?>

        <div class="col-<?= $NCols?> text-<?= $Align ?>"><?= $Message ?></div>

        <?PHP  if ($this->showAnyForwardButtons()) {  ?>
            <div class="col-2 text-end">
            <?PHP  if ($this->showForwardButton()) {  ?>
                <a class="btn btn-primary btn-sm"
                        href="<?= $this->forwardLink() ?>"
                        title="<?= $FWTitleAttrib ?>">&gt;</a><?PHP
            }

            if ($this->showFastForwardButton()) {
                ?><a class="btn btn-primary btn-sm"
                    href="<?= $this->fastForwardLink() ?>"
                    title="<?= $FFWTitleAttrib ?>">&gt;&gt;</a><?PHP
            }

            ?><a class="btn btn-primary btn-sm"
                href="<?= $this->goToEndLink() ?>"
                title="<?= $GETitleAttrib ?>">&gt;<span>&#124;</span></a>
            </div>
        <?PHP  }  ?>

        </div>
        </div>
        <?PHP
    }
}
