<?PHP
#
#   FILE:  ResourceSummary_BlogEntry.php (Blog plugin)
#
#   Part of the Metavus digital collections platform
#   Copyright 2021-2022 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus;

use Metavus\User;
use Metavus\Plugins\Blog\Entry;
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
    public function display()
    {
        static $Blog;
        $PluginMgr = PluginManager::getInstance();
        $AF = ApplicationFramework::getInstance();

        $AF->RequireUIFile("P_Blog.css");

        if (!isset($Blog)) {
            $Blog = $PluginMgr->getPlugin("Blog");
            $Blog->SetCurrentBlog($this->Resource->GetBlogId());
        }

        $SafeId = defaulthtmlentities($this->Resource->Id());
        $SafeUrl = defaulthtmlentities($this->Resource->EntryUrl());
        $SafeTitle = $this->Resource->TitleForDisplay();
        $SafeAuthor = defaulthtmlentities($this->Resource->AuthorForDisplay());
        $SafeEditor = defaulthtmlentities($this->Resource->EditorForDisplay());
        $SafeCreationDate = defaulthtmlentities($this->Resource->CreationDateForDisplay());
        $SafeModificationDate = defaulthtmlentities($this->Resource->ModificationDateForDisplay());
        $SafePublicationDate = defaulthtmlentities($this->Resource->PublicationDateForDisplay());
        $SafePublicationDatePrefix = defaulthtmlentities(
            $this->Resource->PublicationDateDisplayPrefix()
        );
        $SafePublicationDateForParsing = defaulthtmlentities(
            $this->Resource->PublicationDateForParsing()
        );
        $SafeNumberOfComments = defaulthtmlentities($this->Resource->NumberOfComments());
        $Teaser = self::getEntryTeaser($this->Resource, $Blog->MaxTeaserLength());
        $Categories = $this->Resource->CategoriesForDisplay();
        $PrintMoreLink = strlen($this->Resource->get("Body")) > strlen($Teaser);
        $EditLink = str_replace('$ID', $SafeId, $this->Resource->getSchema()->editPage());
        ?>
        <article class="blog-entry blog-short" itemscope="itemscope"
            itemtype="http://schema.org/BlogPosting">
        <link itemprop="url" href="<?= $SafeUrl; ?>" />
        <header class="blog-header">
            <?PHP if ($this->Resource->UserCanEdit(User::getCurrentUser())) { ?>
            <div class="container-fluid">
                <div class="row">
                    <div class="col">
                        <h3 class="blog-title">
                            <a href="index.php?P=P_Blog_Entry&amp;ID=<?= $SafeId; ?>">
                                <span itemprop="headline"><?= $SafeTitle; ?></span>
                            </a>
                            <a class="btn btn-sm btn-primary mv-button-iconed"
                                href="<?= $EditLink ?>"><img class="mv-button-icon" src="<?=
                                    $AF->GUIFile('Pencil.svg') ?>"/> Edit</a>
                        </h3>

                    </div>
                </div>
            </div>
            <?PHP } else { ?>
            <h3 class="blog-title">
                <a href="index.php?P=P_Blog_Entry&amp;ID=<?= $SafeId; ?>">
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
            <?PHP $PluginMgr->getPlugin("SocialMedia")->DisplaySmallShareButtons(
                $this->Resource
            ); ?>
        </section>
        <?PHP } ?>

        <?PHP if (count($Categories) && $PrintMoreLink) { ?>
        <section class="share" aria-label="sharing buttons">
            <?PHP $PluginMgr->getPlugin("SocialMedia")->DisplaySmallShareButtons(
                $this->Resource
            ); ?>
        </section>
        <?PHP } ?>

        <?PHP if ($PrintMoreLink || $Blog->EnableComments()) {  ?>
        <p>
            <a class="blog-more" href="index.php?P=P_Blog_Entry&amp;ID=<?= $SafeId; ?>">
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
