<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class RectangleCapture -
        Implements a rectangle capture, from the caller point of view.

  ==============================================================================================================*/

class RectangleCapture extends Capture
{
    /*--------------------------------------------------------------------------------------------------------------

        Constructor -
        Builds an object array indexed by page number.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($objects)
    {
        $new_objects = [];

        foreach ($objects as $object) {
            $new_objects [$object->Page] = $object;
        }

        parent::__construct($new_objects);
    }
}
