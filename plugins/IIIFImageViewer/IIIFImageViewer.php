<?PHP
#
#   FILE:  IIIFImageViewer.php
#
#   A plugin for the Metavus digital collections platform
#   Copyright 2024-2025 Edward Almasy and Internet Scout Research Group
#   http://metavus.net
#
# @scout:phpstan

namespace Metavus\Plugins;
use Exception;
use Metavus\Image;
use Metavus\Plugin;
use ScoutLib\ApplicationFramework;

/**
 * Provide support for using IIIF-based image viewers in interface code.
 */
class IIIFImageViewer extends Plugin
{
    # ---- STANDARD PLUGIN INTERFACE -----------------------------------------

   /**
    * Set the plugin attributes.
    */
    public function register(): void
    {
        $this->Name = "IIIF Image Viewer";
        $this->Version = "1.0.0";
        $this->Description = "International Image Interoperability Framework (IIIF) "
            ." Image viewer support.";
        $this->Author = "Internet Scout Research Group";
        $this->Url = "https://metavus.net";
        $this->Email = "support@metavus.net";
        $this->Requires = [
            "MetavusCore" => "1.2.0",
            "IIIFImageServer" => "1.0.0",
        ];
        $this->EnabledByDefault = false;
    }

    /**
     * Initialize the plugin.
     * @return string|null NULL on success, error string otherwise.
     */
    public function initialize(): ?string
    {
        $AF = ApplicationFramework::getInstance();

        # add lib directory to includes
        $BaseName = $this->getBaseName();
        $AF->addIncludeDirectories([
            "plugins/".$BaseName."/lib/openseadragon/",
        ]);

        return null;
    }

    # ---- CALLABLE METHODS --------------------------------------------------

    /**
     * Get HTML for IIIF Image Viewer.
     * @param int $Id ID of Image to display in viewer.
     * @param string $CSSSizeName Image size.
     * @return string HTML for image viewer.
     */
    public function getHtmlForImageViewer(int $Id, string $CSSSizeName): string
    {
        static $Count = 0;

        $AF = ApplicationFramework::getInstance();
        $AF->requireUIFile("openseadragon.js");
        $AF->requireUIFile("IIIFImageViewer.js");

        $Width = Image::getWidthForSize($CSSSizeName);
        $Height = Image::getHeightForSize($CSSSizeName);

        $Html = '<div id="iiif-viewer-'.$Count.'" class="mv-p-iiif-viewer" '
                .'data-imageid="'.$Id.'" '
                .'style="width: '.$Width.'px; height: '.$Height.'px"'
                .'></div>';

        $Count++;

        return $Html;
    }
}
