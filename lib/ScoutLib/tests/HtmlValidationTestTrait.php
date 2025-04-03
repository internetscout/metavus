<?PHP

use ScoutLib\StdLib;

/**
 * Trait to add support for HTML validation
 *
 * Exhibiting classes can provide a preprocessHtml() method if needed.
 * @see HtmlValidationTestTrait::preprocessHtml()
 */
trait HtmlValidationTestTrait
{
    /**
     * Preprocess the provided HTML.
     *
     * This method is called by {@see HtmlValidationTestTrait::validateHtml()}
     * to allow child classes to modify how the HTML is formatted before being
     * passed to StdLib::validateXhtml.
     * If not overridden in the exhibiting class, no preprocessing will be done.
     *
     * @param string $InputSetHtml HTML to process
     * @return string processed HTML ready to be validated by StdLib::validateXhtml
     */
    private function preprocessHtml(string $InputSetHtml) : string
    {
        return $InputSetHtml;
    }

    /**
     * Test the given HTML is valid.
     *
     * We validate the HTML by first validating it against XHTML and then
     * filtering out error messages of errors that are not error in HTML.
     * We do this instead of just using DOMDocument::validate() because
     * DOMDocument::validate(), which internally uses libxml2's xmlValidateDocument(),
     *  will attempt to fetch DTD from W3C, which will then get blocked
     * (see https://www.w3.org/blog/systeam/2008/02/08/w3c_s_excessive_dtd_traffic/).
     * Fetching HTML4.01 DTD manually and then using it as a local DTD does not
     * solve the problem because it's using a DTD syntax that libxml2 cannot parse.
     * For HTML5, since it's no longer SGML based, there is no DTD to use.
     *
     * @param string $Html Target HTML string to check
     * @param array $ErrorsToIgnore array of regex pattern strings used to filter out errors.
     */
    protected function validateHtml(string $Html, array $ErrorsToIgnore) : void
    {
        $ProcessedHtml = $this->preprocessHtml($Html);

        # validate and build error message
        $ValidationErrors = StdLib::validateXhtml($ProcessedHtml, $ErrorsToIgnore);
        $Message = "getHtml() returned an invalid HTML; document errors:\n".
            "HTML Was:\n"
            .$ProcessedHtml
            ."Errors were:\n"
            .array_reduce(
                $ValidationErrors,
                function ($Carry, $Value) {
                    return $Carry . " {$Value->message}\n";
                },
                ""
            );
        $this->assertEmpty($ValidationErrors, $Message);
    }
}
