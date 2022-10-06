<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

     class CapturedRectangle -
         Implements a text captured by a rectangle shape.

   ==============================================================================================================*/

class CapturedRectangle extends CapturedText
{
    public function __construct($page, $name, $text, $left, $top, $right, $bottom, $definition)
    {
        parent::__construct($page, $name, $text, $left, $top, $right, $bottom, $definition);
    }


    public function __tostring()
    {
        return (string)$this->Text;
    }
}
