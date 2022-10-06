<?php
namespace VanXuan\PdfToText;

/**************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************
 ******                                                                                                  ******
 ******                                                                                                  ******
 ******                                     CAPTURED TEXT MANAGEMENT                                     ******
 ******         (none of the classes listed here are meant to be instantiated outside this file)         ******
 ******                                                                                                  ******
 ******                                                                                                  ******
 **************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************/
/*==============================================================================================================

     class CapturedText -
         Base class for captured text enclosed by shapes.

   ==============================================================================================================*/

abstract class CapturedText //extends  Object
{
    // Shape name (as specified by the "name" attribute of the <rectangle> or <lines> tags, for example)
    public $Name;
    // Number of the page where the text was found (starts from 1)
    public $Page;
    // Shape type (one of the PfToTextCaptureShape::SHAPE_* constants)
    public $Type;
    // Shape definition object (not really used, but in case of...)
    private $ShapeDefinition;
    // Captured text
    public $Text;
    // Surrounding rectangle in the PDF file
    public $Left,
        $Top,
        $Right,
        $Bottom;


    /*--------------------------------------------------------------------------------------------------------------

        Constructor -
        Initializes a captured text object, whatever the original shape.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($page, $name, $text, $left, $top, $right, $bottom, $definition)
    {
        $this->Name = $name;
        $this->Page = $page;
        $this->ShapeDefinition = $definition;
        $this->Text = $text;
        $this->Left = $left;
        $this->Top = $top;
        $this->Right = $right;
        $this->Bottom = $bottom;
        $this->Type = $definition->Type;
    }
}
