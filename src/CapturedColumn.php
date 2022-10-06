<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

     class CapturedColumn -
         Implements a text captured by a lines/column shape.
     Actually behaves like the CapturedRectangle class

   ==============================================================================================================*/

class CapturedColumn extends CapturedText
{
    public function __construct($page, $name, $text, $left, $top, $right, $bottom, $definition)
    {
        parent::__construct($page, $name, $text, $left, $top, $right, $bottom, $definition);
    }


    public function Contains($left, $top, $right, $bottom)
    {
        error(new CaptureException("Unsupported operation."));
    }

    public function __tostring()
    {
        return ($this->Text);
    }
}
