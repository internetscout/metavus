<?PHP
#
#   FILE:  BlogEntryUI.php
#
#   Part of the Metavus digital collections platform
#   Copyright 2020-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;
use Metavus\HtmlButton;
use Metavus\MetadataSchema;
use Metavus\Plugins\Blog;
use Metavus\Plugins\SocialMedia;
use Metavus\User;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;
use ScoutLib\StdLib;

/**
 * User interface class providing methods to display Blog entries or lists
 * thereof embedded in other pages.
 */
class BlogEntryUI
{
    /**
     * Print a blog entry.
     * @param Entry $Entry Blog entry to print.
     */
    public static function printBlogEntry(Entry $Entry): void
    {
        static $Blog;

        $AF = ApplicationFramework::getInstance();

        $AF->RequireUIFile("P_Blog.css");

        $PluginMgr = PluginManager::getInstance();
        if (!isset($Blog)) {
            $Blog = Blog::getInstance();
            $Blog->SetCurrentBlog($Entry->GetBlogId());
        }

        $SafeId = defaulthtmlentities($Entry->Id());
        $SafeUrl = defaulthtmlentities($Entry->EntryUrl());
        $SafeTitle = $Entry->TitleForDisplay();
        $SafeAuthor = defaulthtmlentities($Entry->AuthorForDisplay());
        $SafePublicationDate = defaulthtmlentities($Entry->PublicationDateForDisplay());
        $SafePublicationDateForParsing = defaulthtmlentities(
            $Entry->PublicationDateForParsing()
        );
        $Teaser = self::getEntryTeaser($Entry, $Blog->MaxTeaserLength());
        $Categories = $Entry->CategoriesForDisplay();
        $PrintMoreLink = strlen($Entry->get(Blog::BODY_FIELD_NAME)) > strlen($Teaser);
        $ArticleCssClasses = "blog-entry blog-short";
        if (!$Entry->userCanView(User::getAnonymousUser())) {
            $ArticleCssClasses .= " mv-notpublic";
        }

        $EditButton = new HtmlButton("Edit");
        $EditButton->setIcon("Pencil.svg");
        $EditButton->setSize(HtmlButton::SIZE_SMALL);
        $EditButton->setLink(str_replace('$ID', $SafeId, $Entry->getSchema()->getEditPage()));

    // @codingStandardsIgnoreStart
    ?>
<article class="<?= $ArticleCssClasses; ?>" itemscope="itemscope" itemtype="http://schema.org/BlogPosting">
  <link itemprop="url" href="<?= $SafeUrl; ?>" />
  <header class="blog-header">
    <?PHP if ($Entry->UserCanEdit(User::getCurrentUser())) { ?>
    <div class="container-fluid">
      <div class="row">
        <div class="col">
          <h1 class="blog-title">
            <a href="index.php?P=FullRecord&amp;ID=<?= $Entry->id() ?>">
              <span itemprop="headline"><?= $SafeTitle; ?></span>
            </a>
              <?= $EditButton->getHtml(); ?>
          </h1>

        </div>
      </div>
    </div>
    <?PHP } else { ?>
    <h1 class="blog-title">
      <a href="index.php?P=FullRecord&amp;ID=<?= $Entry->id() ?>">
        <span itemprop="headline"><?= $SafeTitle; ?></span>
      </a>
    </h1>
    <?PHP } ?>
    <p>
      <time class="blog-date" itemprop="datePublished" datetime="<?= $SafePublicationDateForParsing; ?>">
        <?= $SafePublicationDate; ?></time>
      <?PHP if ($Blog->ShowAuthor()) { ?>
      by <span class="blog-author" itemprop="author" itemscope="itemscope" itemtype="http://schema.org/Person">
        <span itemprop="name"><?= $SafeAuthor; ?></span>
      </span>
      <?PHP } ?>
    </p>
  </header>

  <div class="blog-teaser" itemprop="articleBody">
    <?= $Teaser; ?>
  </div>

  <?PHP if (!count($Categories) || !$PrintMoreLink) { ?>
  <section class="share" aria-label="sharing buttons">
    <?PHP SocialMedia::getInstance()->DisplaySmallShareButtons($Entry); ?>
  </section>
  <?PHP } ?>

  <?PHP if (count($Categories) && $PrintMoreLink) { ?>
  <section class="share" aria-label="sharing buttons">
    <?PHP SocialMedia::getInstance()->DisplaySmallShareButtons($Entry); ?>
  </section>
  <?PHP } ?>

  <?PHP if ($PrintMoreLink || $Blog->EnableComments()) {  ?>
  <p>
    <a class="blog-more" href="index.php?P=FullRecord&amp;ID=<?= $Entry->id() ?>">
      <span class="blog-bullet">&raquo;</span>
      <?PHP if ($PrintMoreLink) { ?> Read More <?PHP } ?>
      <?PHP if ($PrintMoreLink && $Blog->EnableComments()) { ?>or<?PHP } ?>
      <?PHP if ($Blog->EnableComments()) { ?> Comment <?PHP } ?>
    </a>
  </p>
  <?PHP } ?>
</article>
        <?PHP
        // @codingStandardsIgnoreEnd
    }

    /**
     * Print given list or given amount of blog entries from given BlogId inside
     * of a table element
     *
     * @param int $BlogId ID of the blog which the entries are from
     * @param int|array $NumberOrIdsToPrint Array of blog IDs,
     *   or the number of entries to print (OPTIONAL)
     * @param User|null $User User for permissions checks (OPTIONAL, defaults
     *   to the anonymous user)
     */
    public static function printSummaryBlock(
        int $BlogId,
        $NumberOrIdsToPrint = null,
        ?User $User = null
    ): void {
        $AF = ApplicationFramework::getInstance();
        $BlogPlugin = Blog::getInstance();
        $AF->AddPageCacheTag(
            "ResourceList".$BlogPlugin->GetSchemaId()
        );
        $BlogEntryFactory = new EntryFactory($BlogId);
        $NumberToPrint = $BlogPlugin->BlogSetting($BlogId, "EntriesPerPage");
        $BlogName = $BlogPlugin->BlogSetting($BlogId, "BlogName");

        if ($NumberOrIdsToPrint !== null) {
            if (is_array($NumberOrIdsToPrint)) {
                $BlogIds = array_intersect(
                    $NumberOrIdsToPrint,
                    $BlogEntryFactory->GetItemIds()
                );
            } else {
                $NumberToPrint = $NumberOrIdsToPrint;
                $BlogIds = $BlogEntryFactory->getRecordIdsSortedBy(
                    Blog::PUBLICATION_DATE_FIELD_NAME,
                    false
                );
            }
        } else {
            $BlogIds = $BlogEntryFactory->getRecordIdsSortedBy(
                Blog::PUBLICATION_DATE_FIELD_NAME,
                false
            );
        }

        # get user for perms checks
        if (is_null($User)) {
            $User = User::getAnonymousUser();
        }

        $Printed = 0;

        $ExpirationTimestamp = false;

        print "<table class=\"BlogList\">\n<tbody>\n";
        while (
            ($Id = array_shift($BlogIds)) !== null
                 && $Printed < $NumberToPrint
        ) {
            $Entry = new Entry($Id);
            if ($Entry->UserCanView($User)) {
                ?><tr><td class="BlogEntrySummary"><?PHP
                self::printBlogEntry($Entry);
                $Printed++; ?>
                </td></tr><?PHP
            }

            $EntryExpirationDate = $Entry->getViewCacheExpirationDate();
            if ($EntryExpirationDate !== false) {
                $EntryExpirationTimestamp = strtotime((string)$EntryExpirationDate);
                if ($ExpirationTimestamp === false ||
                    $EntryExpirationTimestamp < $ExpirationTimestamp) {
                    $ExpirationTimestamp = $EntryExpirationTimestamp;
                }
            }
        }

        if ($Printed == 0) {
            print "<tr><td class='blog-noentries'>No ".htmlspecialchars($BlogName)
                          ." entries to display.</td></tr>";

            $BlogSchema = new MetadataSchema(
                $BlogPlugin->GetSchemaId()
            );
            if ($BlogSchema->UserCanAuthor(User::getCurrentUser())) {
                print "<tr><td><a href=\""
                    .str_replace(
                        '$ID',
                        "NEW&amp;SC=".$BlogSchema->id(),
                        $BlogSchema->getEditPage()
                    )."\">"
                    ."Add an entry</a></td></tr>";
            }
        }

        # update static page cache expiration if needed
        if ($ExpirationTimestamp !== false) {
            $PageExpirationDate = $AF->expirationDateForCurrentPage();
            if ($PageExpirationDate === false ||
                $ExpirationTimestamp < strtotime((string)$PageExpirationDate)) {
                $AF->expirationDateForCurrentPage(
                    date(StdLib::SQL_DATE_FORMAT, $ExpirationTimestamp)
                );
            }
        }

        print "\n</tbody>\n</table>\n";
    }

    /**
     * Get the blog entry teaser with the image inserted in it, if it is available.
     * @param Entry $Entry Blog entry.
     * @param int $MaxLength The maximum length of the teaser.
     * @return string Returns the blog entry teaser with the image inserted in it, if it is
     *      available.
     */
    private static function getEntryTeaser(Entry $Entry, int $MaxLength): string
    {
        $Teaser = $Entry->teaser($MaxLength);
        $Image = $Entry->image();

        # if there is no image associated with the blog entry
        if (is_null($Image)) {
            return $Teaser;
        }

        # create the image tag
        $ImageHtml = '<div class="blog-image-wrapper">'
            .$Image->getHtml("mv-image-preview");

        # add caption if needed
        if ($Entry->shouldDisplayCaptionInTeaser()) {
            $ImageHtml .= '<div class="blog-image-caption" aria-hidden="true">'
                .htmlspecialchars($Image->altText(), ENT_QUOTES |  ENT_HTML5)
                .'</div>';
        }

        $ImageHtml .= '</div>';

        # return the teaser with the image inserted at the beginning
        return $ImageHtml.$Teaser;
    }
}
