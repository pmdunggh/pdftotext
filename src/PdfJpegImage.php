<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class PdfJpegImage -
        Handles encoded JPG images.

  ==============================================================================================================*/

class PdfJpegImage extends PdfImage
{
    public function __construct($image_data, $autosave)
    {
        parent::__construct($image_data, $autosave);
    }


    protected function CreateImageResource($image_data)
    {
        return (imagecreatefromstring($image_data));
    }
}
