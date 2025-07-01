<?PHP

#
#   FILE:  FullRecordHelper.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;
use Exception;
use ScoutLib\ApplicationFramework;

/**
 * Class to provide helper methods for constructing a full record
 * page. This class may add HTML generation methods to the UI-independent data
 * manipulation methods provided by FullRecord_Base.
 * @see FullRecord_Base
 */
class FullRecordHelper extends FullRecordHelper_Base
{
    # ---- PUBLIC INTERFACE --------------------------------------------------
    /**
     * Print out HTML for row containing metadata field label and value(s),
     * if there are one or more values available and field is not one of the
     * standard fields.
     * @param MetadataField $Field Field to display.
     * @param string|array $Value Value(s) to display.
     * @param Qualifier|array|false $Qual Qualifier(s) for value, or FALSE
     *      if no qualifier.
     */
    public function displayMFieldLabelAndValueRow(MetadataField $Field, $Value, $Qual): void
    {
        if (($Value == "") || (is_array($Value) && (count($Value) == 0))) {
            return;
        }

        if ($this->isStdField($Field)) {
            return;
        }

        $AddedButtons = ApplicationFramework::getInstance()->formatInsertionKeyword(
            "FIELDVIEW",
            [
                "FieldId" => $Field->id(),
                "RecordId" => self::$Record->id(),
            ]
        );
        if ($Field->updateMethod() == MetadataField::UPDATEMETHOD_BUTTON) {
            $AddedButtons .= $this->getButtonHtml(
                "Update",
                $this->getUpdateButtonLink($Field),
                "Update",
                "RefreshArrow"
            );
        }

        $ClassSuffix = $this->getCssClassSuffixForField($Field);
        ?><div class="row mv-mfield-row">
            <div class="col-4"><span class="mv-mfield-label mv-mfield-label-<?=
                    $ClassSuffix ?>"><?= $Field->getDisplayName() ?></span>
            </div>
            <div class="col-8"><span class="mv-mfield-value mv-mfield-value-<?=
                    $ClassSuffix ?>"><?PHP
                    $this->displayMFieldValue($Field, $Value, $Qual); ?>
                    </span> <?= $AddedButtons ?>
            </div>
       </div><?PHP
    }

    /**
     * Print out HTML for metadata field value.
     * @param MetadataField $Field Field to display.
     * @param string|array $Value Value(s) to display.
     * @param Qualifier|array|false $Qual Qualifier(s) for value, or FALSE
     *      if no qualifier.
     */
    public function displayMFieldValue(MetadataField $Field, $Value, $Qual): void
    {
        switch ($Field->type()) {
            case MetadataSchema::MDFTYPE_DATE:
            case MetadataSchema::MDFTYPE_EMAIL:
            case MetadataSchema::MDFTYPE_FLAG:
            case MetadataSchema::MDFTYPE_NUMBER:
            case MetadataSchema::MDFTYPE_PARAGRAPH:
            case MetadataSchema::MDFTYPE_POINT:
            case MetadataSchema::MDFTYPE_SEARCHPARAMETERSET:
            case MetadataSchema::MDFTYPE_TEXT:
            case MetadataSchema::MDFTYPE_TIMESTAMP:
                if (!is_string($Value)) {
                    throw new Exception("Unexpected non-string value encountered.");
                }
                if (($Qual !== false) && !($Qual instanceof Qualifier)) {
                    throw new Exception("Unexpected non-Qualifier qualifier encountered.");
                }
                print $Value;
                print $this->getQualifierHtml($Qual);
                break;

            case MetadataSchema::MDFTYPE_CONTROLLEDNAME:
            case MetadataSchema::MDFTYPE_OPTION:
            case MetadataSchema::MDFTYPE_REFERENCE:
            case MetadataSchema::MDFTYPE_TREE:
            case MetadataSchema::MDFTYPE_USER:
                if (!is_array($Value)) {
                    throw new Exception("Unexpected non-array value encountered.");
                }
                if (!is_array($Qual)) {
                    throw new Exception("Unexpected non-array qualifier encountered.");
                }
                print "<ul>";
                foreach ($Value as $ValueIndex => $ValueEntry) {
                    print "<li>" .$ValueEntry
                            .$this->getQualifierHtml($Qual[$ValueIndex]) ."</li>";
                }
                print "</ul>";
                break;

            case MetadataSchema::MDFTYPE_FILE:
            case MetadataSchema::MDFTYPE_URL:
                if (!is_array($Value)) {
                    throw new Exception("Unexpected non-array value encountered.");
                }
                if (!is_array($Qual)) {
                    throw new Exception("Unexpected non-array qualifier encountered.");
                }
                print "<ul>";
                foreach ($Value as $ValueIndex => $ValueEntry) {
                    print "<li><a href=\"" .$ValueIndex ."\">" .$ValueEntry ."</a>"
                            .$this->getQualifierHtml($Qual[$ValueIndex]) ."</li>";
                }
                print "</ul>";
                break;

            case MetadataSchema::MDFTYPE_IMAGE:
                if (!is_array($Value)) {
                    throw new Exception("Unexpected non-array value encountered.");
                }
                if (!is_array($Qual)) {
                    throw new Exception("Unexpected non-array qualifier encountered.");
                }
                $this->displayImageFieldValues($Field, $Value, $Qual);
                break;
        }
    }

    /**
     * Print out HTML for Image metadata field value.
     * @param MetadataField $Field Field to display.
     * @param array $Values Value(s) to display.
     * @param array $Qual Qualifier(s) for value, or FALSE if no qualifier.
     */
    public function displayImageFieldValues(MetadataField $Field, array $Values, $Qual): void
    {
        ?><ul><?PHP
foreach ($Values as $ImageId => $Image) {
    $ImageLink = $Image->url("mv-image-preview");
    $AltText = htmlspecialchars($Image->altText());
    $FullImageLink = $this->getImageViewLink($Field, $Image);
    ?><li>
                <a href="<?= $FullImageLink ?>"><img src="<?=
                $ImageLink ?>" alt="<?= $AltText ?>"></a>
                <?= $this->getQualifierHtml($Qual[$ImageId]) ?>
            </li><?PHP
}
?></ul><?PHP
    }

    /**
     * Generate and return HTML for button.
     * @param string $Label Label for button.
     * @param string $Link Target URL for button.
     * @param string $Title Descriptive title for button.
     * @param string|null $IconName Base name of SVG file for icon, or NULL if
     *      no icon for button.
     * @param string $AdditionalCssClasses Additional CSS classes to include.
     *      (OPTIONAL)
     * @param array $Attributes Items to include in HTML attributes, keyed
     *      with the attribute name (OPTIONAL)
     * @return string Generated HTML.
     */
    public function getButtonHtml(
        string $Label,
        string $Link,
        string $Title,
        $IconName,
        string $AdditionalCssClasses = "",
        $Attributes = []
    ): string {
        $AF = ApplicationFramework::getInstance();

        $Button = new HtmlButton($Label);
        $Button->setSize(HtmlButton::SIZE_SMALL);
        $Button->setTitle($Title);
        $Button->addClass($AdditionalCssClasses);
        if ($IconName !== null) {
            $Button->setIcon($IconName.".svg");
        }

        if (strlen($Link) > 0) {
            $Button->setLink($Link);
        }

        if (isset($Attributes["onclick"])) {
            $OnClick = $Attributes["onclick"];
            unset($Attributes["onclick"]);

            $Button->setOnclick($OnClick);
        }
        $Button->addAttributes($Attributes);

        return $Button->getHtml();
    }

    /**
     * Generate and return HTML for qualifier.
     * @param Qualifier|false $Qual Qualifier to display or FALSE if no qualifier.
     * @return string Generated HTML.
     */
    public function getQualifierHtml($Qual): string
    {
        if ($Qual === false) {
            return "";
        }
        $Url = htmlspecialchars($Qual->url());
        $Name = htmlspecialchars($Qual->name());
        return " <small>(<a href=\"" .$Url ."\">" .$Name ."</a>)</small>";
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------
}
