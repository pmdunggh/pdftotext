<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================
    class LinesCapture -
        Represents a lines capture, without indexation to their page number.
  ==============================================================================================================*/

class LinesCapture extends Capture
{
    /*--------------------------------------------------------------------------------------------------------------
        Constructor -
        "flattens" the supplied object list, by removing the CapturedLines class level, so that lines
        can be iterated whatever their page number is.
     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($objects)
    {
        $new_objects = [];

        foreach ($objects as $object) {
            foreach ($object as $line) {
                $new_objects [] = $line;
            }
        }

        parent::__construct($new_objects);
    }
}
