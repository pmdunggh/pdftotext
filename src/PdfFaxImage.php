<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class PdfFaxImage -
        Handles encoded CCITT Fax images.

  ==============================================================================================================*/

class PdfFaxImage extends PdfImage
{
    public function __construct($image_data)
    {
        parent::__construct($image_data);
    }


    protected function CreateImageResource($image_data)
    {
        warning(new DecodingException("Decoding of CCITT Fax image format is not yet implemented."));
        //return ( imagecreatefromstring ( $image_data ) ) ;
    }
}
