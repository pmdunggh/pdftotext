<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class CaptureRectangleDefinition -
        A shape for capturing text in rectangle areas.

  ==============================================================================================================*/

class CaptureRectangleDefinition extends CaptureShapeDefinition
{
    /*--------------------------------------------------------------------------------------------------------------

        CONSTRUCTOR -
        Analyzes the contents of a <rectangle> XML node, which contains <page> child node giving the
        applicable pages and the rectangle dimensions.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct(\SimpleXMLElement $node)
    {
        parent::__construct(self::SHAPE_RECTANGLE);

        $this->GetAttributes($node);

        // Loop through node's children
        /** @var \SimpleXMLElement $child */
        foreach ($node->children() as $child) {
            $tag_name = $child->getName();

            switch (strtolower($tag_name)) {
                // <page> tag : applicable page(s)
                case    'page' :
                    // Retrieve the specified attributes
                    $page_attributes = CaptureDefinitions::GetNodeAttributes(
                        $child,
                        [
                            'number' => true,
                            'left' => true,
                            'right' => false,
                            'top' => true,
                            'bottom' => false,
                            'width' => false,
                            'height' => false
                        ]
                    );

                    $page_number = $page_attributes ['number'];

                    // Add this page to the list of applicable pages for this shape
                    $this->ApplicablePages->Add($page_number, $page_attributes);

                    break;

                // Other tag : throw an exception
                default :
                    error(new CaptureException("Invalid tag <$tag_name> found in root tag <rectangle>."));
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

         ExtractAreas -
        Extracts text contents from the document fragments.

     *-------------------------------------------------------------------------------------------------------------*/
    public function ExtractAreas($document_fragments)
    {
        $result = [];

        // Loop through document fragments
        foreach ($document_fragments as $page => $page_contents) {
            $fragments = $page_contents ['fragments'];

            // Ignore pages that are not applicable
            if (!isset($this->ApplicablePages->PageMap [$page])) {
                continue;
            }

            // Loop through each text fragment of the page
            foreach ($fragments as $fragment) {
                $this->GetFragmentData($fragment, $text, $left, $top, $right, $bottom);

                // Only handle text fragments that are within the specified area
                /** @noinspection PhpUndefinedMethodInspection */
                if ($this->Areas [$page]->Contains($left, $top, $right, $bottom)) {
                    // Normally, rectangle shapes are used to capture a single line...
                    if (!isset($result [$page])) {
                        $result [$page] = new CapturedRectangle($page, $this->Name, $text, $left, $top, $right, $bottom, $this);
                    } else {
                        // ... but you can also use them to capture multiple lines ; in this case, the "separator" attribute of the <rectangle> tag will
                        // be used to separate items
                        $existing_area = $result [$page];

                        $existing_area->Top = max($existing_area->Top, $top);
                        $existing_area->Bottom = min($existing_area->Bottom, $bottom);
                        $existing_area->Left = min($existing_area->Left, $left);
                        $existing_area->Right = max($existing_area->Right, $right);
                        $existing_area->Text .= $this->Separator . $text;
                    }
                }
            }
        }


        // Provide empty values for pages which did not capture a rectangle shape
        $added_missing_pages = false;

        foreach ($this->ApplicablePages as $page => $applicable) {
            if (!isset($result [$page])) {
                $result [$page] = new CapturedRectangle($page, $this->Name, '', 0, 0, 0, 0, $this);
                $added_missing_pages = true;
            }
        }

        if ($added_missing_pages) {    // Sort by page number if empty values were added
            ksort($result);
        }

        // All done, return
        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

         SetPageCount -
        Ensures that an Area is created for each related page.

     *-------------------------------------------------------------------------------------------------------------*/
    public function SetPageCount($count)
    {
        parent::SetPageCount($count);

        // Create a rectangle area for each page concerned - this can only be done when the number of pages is known
        // (and the ApplicablePages object updated accordingly)
        foreach ($this->ApplicablePages->ExtraPageMapData as $page => $data) {
            $this->Areas [$page] = new CaptureArea($data);
        }
    }
}
