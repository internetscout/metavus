<?PHP
#
#   FILE:  ResourceSummary_Default.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2023 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\EduLink;
use ScoutLib\ApplicationFramework;
use ScoutLib\SearchParameterSet;

/**
* Class for default resource summary display.
*/
class ResourceSummary_DeepLinking extends ResourceSummary_Default
{
    const MAX_DESCRIPTION_LENGTH = 160;

    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Display (output HTML) for compact resource summary.
     * @return void
     */
    public function displayCompact(): void
    {
        if (self::$DescriptionLength === false) {
            self::$DescriptionLength = EduLink::getInstance()
                ->getConfigSetting("DescriptionLength");
        }

        $Resource = $this->Resource;

        # retrieve user currently logged in
        $User = User::getCurrentUser();

        # retrieve and format title
        $Schema = new MetadataSchema($Resource->getSchemaId());
        $TitleField = $Schema->getFieldByMappedName("Title");
        $Title = $Resource->userCanViewField($User, $TitleField) ?
            StripXSSThreats($this->getFieldValue($TitleField)) :
            "";

        $RecordUrl = $Resource->getViewPageUrl();

        # retrieve and format description
        $Description = $this->getFormattedFieldValue(
            "Description",
            self::$DescriptionLength
        );

        ?>
        <div class="container-fluid table-responsive">
          <table class="table-sm mv-content-resourcesummary">
            <tbody>
              <tr>
                <td class="mv-screenshot-cell">
                  <?PHP $this->displayScreenshot($Title, "", "") ?>
                </td><td>
                  <?PHP if (isset($Title)) { ?>
                  <div class="mv-resource-title">
                    <strong><a href="<?= $RecordUrl?>" target="_blank"><?= $Title; ?></a></strong>
                  </div>
                  <?PHP } ?>
                  <?PHP if ($Description) { ?>
                  <div class="mv-resource-description"><?= $Description ?></div>
                  <?PHP } ?>
                  <div class="mv-resource-categories">
                    <?PHP $this->displayCategories(false); ?>
                  </div>
                </td>
            </tbody>
          </table>
        </div>
        <?PHP
    }

    /**
     * Display screenshot associated with resource.
     * @param string $Title Title of resource.
     * @param string $GoButtonOpenTag ?
     * @param string $GoButtonCloseTag ?
     * @return void
     */
    protected function displayScreenshot(
        string $Title,
        string $GoButtonOpenTag,
        string $GoButtonCloseTag
    ): void {
        $ScreenshotField = $this->Resource->getSchema()->getFieldByMappedName("Screenshot");

        if (is_null($ScreenshotField) ||
            !$this->Resource->userCanViewField(User::getCurrentUser(), $ScreenshotField)) {
            return;
        }

        $Screenshot = $this->getFieldValue($ScreenshotField);

        # if $Screenshot is an array of images, use the first one as thumbnail
        if (is_array($Screenshot)) {
            if (count($Screenshot) == 0) {
                return;
            }

            $Screenshot = array_shift($Screenshot);
            if (!($Screenshot instanceof \Metavus\Image)) {
                return;
            }
        }
        ?>
      <div class="mv-content-resourcesummary-screenshot">
        <?PHP if ($this->ShowScreenshot) { ?>
            <?PHP if ($Screenshot instanceof \Metavus\Image) { ?>
                <?= $Screenshot->getHtml("mv-image-screenshot") ?>
            <?PHP } else { ?>
                <?= $Screenshot; ?>
            <?PHP } ?>
        <?PHP } ?>
       </div>
        <?PHP
    }

    /**
     * Display hierarchical (Tree) values associated with resource.
     * @param bool $LinkTerms TRUE to link terms to search pages, FALSE
     *   otherwise (OPTIONAL, default TRUE).
     * @return void
     */
    protected function displayCategories(bool $LinkTerms = true): void
    {
        if (self::$CategoryField === false) {
            self::$CategoryField = EduLink::getInstance()
                ->getConfigSetting("CategoryField");

            if (is_null(self::$CategoryField)
                || strlen(self::$CategoryField) == 0) {
                $TreeFields = $this->Resource->getSchema()->getFields(
                    MetadataSchema::MDFTYPE_TREE
                );

                if (count($TreeFields) > 0) {
                    self::$CategoryField = reset($TreeFields);
                }
            }
        }

        if (is_null(self::$CategoryField)) {
            return;
        }

        $Terms = $this->Resource->get(self::$CategoryField);

        $SearchUrlBase = ApplicationFramework::baseUrl()
            ."index.php?P=SearchResults";

        $TermHtml = [];
        foreach ($Terms as $Term) {
            $SearchParams = new SearchParameterSet();
            $SearchParams->addParameter(
                "=".$Term,
                self::$CategoryField
            );

            $Term = htmlspecialchars($Term);
            $Term = str_replace("--", "&mdash;", $Term);

            $TermHtml[] = $LinkTerms ?
                '<a href="'.$SearchUrlBase
                .'&amp;'.$SearchParams->urlParameterString()
                .'">'.$Term.'</a>' :
                $Term;
        }

        print (implode(" &bull; ", $TermHtml));
    }

    private static $DescriptionLength = false;
    private static $CategoryField = false;
}
