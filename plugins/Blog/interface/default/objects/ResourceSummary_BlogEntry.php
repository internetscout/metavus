<?PHP
#
#   FILE:  ResourceSummary_BlogEntry.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\Plugins\Blog;
use Metavus\Plugins\Blog\Entry;
use Metavus\Plugins\SocialMedia;
use ScoutLib\ApplicationFramework;
use ScoutLib\PluginManager;

/**
 * Class for blog entry summary display.
 */
class ResourceSummary_BlogEntry extends \Metavus\ResourceSummary
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Constructor
     * @param int $RecordId ID of record to summarize
     */
    public function __construct(int $RecordId)
    {
        $this->Resource = new Entry($RecordId);
        parent::__construct($RecordId);
    }

    /**
     * Display (output HTML) for resource summary.
     */
    public function display(): void
    {
        static $Blog;
        $Entry = $this->Resource;
        $PluginMgr = PluginManager::getInstance();
        $AF = ApplicationFramework::getInstance();

        $AF->RequireUIFile("P_Blog.css");

        if (!isset($Blog)) {
            $Blog = Blog::getInstance();
            $Blog->SetCurrentBlog($Entry->GetBlogId());
        }

        $SafeId = defaulthtmlentities($Entry->Id());
        $SafeUrl = defaulthtmlentities($Entry->EntryUrl());
        $SafeTitle = $Entry->TitleForDisplay();
        $SafeAuthor = defaulthtmlentities($Entry->AuthorForDisplay());
        $SafeEditor = defaulthtmlentities($Entry->EditorForDisplay());
        $SafeCreationDate = defaulthtmlentities($Entry->CreationDateForDisplay());
        $SafeModificationDate = defaulthtmlentities($Entry->ModificationDateForDisplay());
        $SafePublicationDate = defaulthtmlentities($Entry->PublicationDateForDisplay());
        $SafePublicationDatePrefix = defaulthtmlentities(
            $Entry->PublicationDateDisplayPrefix()
        );
        $SafePublicationDateForParsing = defaulthtmlentities(
            $Entry->PublicationDateForParsing()
        );
        $SafeNumberOfComments = defaulthtmlentities($Entry->NumberOfComments());
        $Teaser = self::getEntryTeaser($Entry, $Blog->MaxTeaserLength());
        $Categories = $Entry->CategoriesForDisplay();
        $PrintMoreLink = strlen($Entry->get("Body")) > strlen($Teaser);
        $EditLink = str_replace('$ID', $SafeId, $Entry->getSchema()->getEditPage());

        $EditButton = new HtmlButton("Edit");
        $EditButton->setIcon("Pencil.svg");
        $EditButton->setSize(HtmlButton::SIZE_SMALL);
        $EditButton->setLink(str_replace('$ID', $SafeId, $Entry->getSchema()->getEditPage()));
        ?>
        <article class="blog-entry blog-short" itemscope="itemscope"
            itemtype="http://schema.org/BlogPosting">
        <link itemprop="url" href="<?= $SafeUrl; ?>" />
        <header class="blog-header">
            <?PHP if ($Entry->UserCanEdit(User::getCurrentUser())) { ?>
            <div class="container-fluid">
                <div class="row">
                    <div class="col">
                        <h3 class="blog-title">
                            <a href="index.php?P=FullRecord&amp;ID=<?= $Entry->id() ?>">
                                <span itemprop="headline"><?= $SafeTitle; ?></span>
                            </a>
                            <?= $EditButton->getHtml(); ?>
                        </h3>

                    </div>
                </div>
            </div>
            <?PHP } else { ?>
            <h3 class="blog-title">
                <a href="index.php?P=FullRecord&amp;ID=<?= $Entry->id() ?>">
                    <span itemprop="headline"><?= $SafeTitle; ?></span>
                </a>
            </h3>
            <?PHP } ?>
            <p class="blog-pubinfo">
                <time class="blog-date" itemprop="datePublished"
                    datetime="<?= $SafePublicationDateForParsing; ?>">
                    <?= $SafePublicationDate; ?>
                </time>
                <?PHP if ($Blog->ShowAuthor()) { ?>
                by <span class="blog-author" itemprop="author" itemscope="itemscope"
                        itemtype="http://schema.org/Person">
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
            <?PHP SocialMedia::getInstance()->DisplaySmallShareButtons(
                $Entry
            ); ?>
        </section>
        <?PHP } ?>

        <?PHP if (count($Categories) && $PrintMoreLink) { ?>
        <section class="share" aria-label="sharing buttons">
            <?PHP SocialMedia::getInstance()->DisplaySmallShareButtons(
                $Entry
            ); ?>
        </section>
        <?PHP } ?>

        <?PHP if ($PrintMoreLink || $Blog->EnableComments()) {  ?>
        <p>
            <a class="blog-more" href="index.php?P=FullRecord&amp;ID=<?= $Entry->id() ?>">
                <span class="blog-bullet">&raquo;</span>
                <?PHP if ($PrintMoreLink) { ?>
                Read More
                <?PHP } ?>
                <?PHP if ($PrintMoreLink && $Blog->EnableComments()) { ?>
                or
                <?PHP } ?>
                <?PHP if ($Blog->EnableComments()) { ?>
                Comment
                <?PHP } ?>
            </a>
        </p>
        <?PHP } ?>
        </article>
        <?PHP
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Get the blog entry teaser with the image inserted in it, if it is available.
     * @param Entry $Entry Blog entry.
     * @param int $MaxLength The maximum length of the teaser.
     * @return string Returns the blog entry teaser with the image inserted
     *      into it, if it is available.
     */
    private static function getEntryTeaser(Entry $Entry, int $MaxLength): string
    {
        $Teaser = $Entry->Teaser($MaxLength);
        $Image = $Entry->Image();

        # if there is no image associated with the blog entry
        if (is_null($Image)) {
            return $Teaser;
        }

        # create the image tag
        $SafeImage = defaulthtmlentities($Entry->ThumbnailForDisplay());
        $SafeImageAlt = defaulthtmlentities($Entry->ImageAltForDisplay());
        $ImageInsert = '<div class="blog-image-wrapper">';
        $ImageInsert .= $Image->getHtml("mv-image-thumbnail");
        $ImageInsert .= '</div>';

        # return the teaser with the image inserted at the beginning
        return substr_replace($Teaser, $ImageInsert, 0, 0);
    }
}
