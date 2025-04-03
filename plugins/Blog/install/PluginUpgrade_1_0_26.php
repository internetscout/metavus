<?PHP
#
#   FILE:  PluginUpgrade_1_0_26.php (Blog plugin)
#
#   A plugin upgrade file for the Metavus digital collections platform
#   Copyright 2024 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins\Blog;

use Metavus\Plugins\Blog;
use ScoutLib\PluginUpgrade;

/**
 * Class for upgrading the Blog plugin to version 1.0.26.
 */
class PluginUpgrade_1_0_26 extends PluginUpgrade
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    /**
     * Perform actions necessary to upgrade plugin to version 1.0.26.
     * @return null|string Return NULL if upgrade succeeeded, or string
     *      containing error message if upgrade failed.
     */
    public function performUpgrade()
    {
        $this->updateImageHtmlForCaptions();
        return null;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    /**
     * Update blog entry HTML to restore captions below images. Only for use
     * from upgrade().
     */
    private function updateImageHtmlForCaptions() : void
    {
        $Plugin = Blog::getInstance(true);
        foreach ($Plugin->getBlogEntries() as $Entry) {
            $NewBody = $Entry->get("Body");

            # move images out of their containing paragraphs
            # (limit to 10 iterations; if anyone has 11 images in a paragraph
            # they will just be sad)
            $Iterations = 0;
            do {
                $NewBody = preg_replace(
                    '%<p>(.*?)(<img [^>]*class=["\']mv-form-image-'
                            .'(?:left|right)["\'][^>]*>)(.*?)</p>%',
                    '\2<p>\1\3</p>',
                    $NewBody,
                    -1,
                    $ReplacementCount
                );
                $Iterations++;
            } while ($ReplacementCount > 0 && $Iterations < 10);

            # replace naked <img> tags with new markup, including captions
            # (attribute order from the Insert buttons)
            $NewBody = preg_replace(
                '%<img src="([^"]*)" alt="([^"]*)" class="mv-form-image-(right|left)" />%',
                '<div class="mv-form-image-\3">'
                .'<img src="\1" alt="\2"/>'
                .'<div class="mv-form-image-caption" aria-hidden="true">\2</div>'
                .'</div>',
                $NewBody
            );
            # (CKEditor also sorts attributes alphabetically (thanks, CKEditor))
            $NewBody = preg_replace(
                '%<img alt="([^"]*)" class="mv-form-image-(right|left)" src="([^"]*)" />%',
                '<div class="mv-form-image-\2">'
                .'<img src="\3" alt="\1"/>'
                .'<div class="mv-form-image-caption" aria-hidden="true">\1</div>'
                .'</div>',
                $NewBody
            );
            $Entry->set("Body", $NewBody);
        }
    }
}
