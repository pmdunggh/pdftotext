<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class CaptureShapeDefinition -
        Base class for capturing shapes.

  ==============================================================================================================*/

abstract class CaptureShapeDefinition //extends  Object
{
    const    SHAPE_RECTANGLE = 1;
    const    SHAPE_COLUMN = 2;
    const    SHAPE_LINE = 3;

    // Capture name
    public $Name;
    // Capture type - one of the SHAPE_* constants, assigned by derived classes.
    public $Type;
    // Applicable pages for this capture
    public $ApplicablePages;
    // Areas per page for this shape
    public $Areas = [];
    // Separator used when multiple elements are covered by the same shape
    public $Separator = " ";


    /*--------------------------------------------------------------------------------------------------------------

         Constructor -
        Initializes the base capture class.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($type)
    {
        $this->Type = $type;
        $this->ApplicablePages = new CaptureApplicablePages();
    }


    /*--------------------------------------------------------------------------------------------------------------

         SetPageCount -
        Sets the page count, so that all the applicable pages can be determined.
        Derived classes can implement this function if some additional work is needed.

     *-------------------------------------------------------------------------------------------------------------*/
    public function SetPageCount($count)
    {
        $this->ApplicablePages->SetPageCount($count);
    }


    /*--------------------------------------------------------------------------------------------------------------

         GetFragmentData -
        Extracts data from a text fragment (text + coordinates).

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetFragmentData($fragment, &$text, &$left, &$top, &$right, &$bottom)
    {
        $left = ( double )$fragment ['x'];
        $top = ( double )$fragment ['y'];
        $right = $left + ( double )$fragment ['width'] - 1;
        $bottom = $top - ( double )$fragment ['font-height'];
        $text = $fragment ['text'];
    }


    /*--------------------------------------------------------------------------------------------------------------

         GetAttributes -
        Retrieves the attributes of the given XML node. Processes the following attributes, which are common to
        all shapes :
        - Name
        - Separator

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetAttributes($node, $attributes = [])
    {
        $attributes = array_merge($attributes, ['name' => true, 'separator' => false]);
        $shape_attributes = CaptureDefinitions::GetNodeAttributes($node, $attributes);
        $this->Name = $shape_attributes ['name'];

        if ($shape_attributes ['separator'] !== false) {
            $this->Separator = PdfToText::Unescape($shape_attributes ['separator']);
        }

        return ($shape_attributes);
    }


    /*--------------------------------------------------------------------------------------------------------------

         ExtractAreas -
        Extracts text contents from the document fragments.

     *-------------------------------------------------------------------------------------------------------------*/
    abstract public function ExtractAreas($document_fragments);
}
