<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    PdfTexterIdentityHCIDMap -
        A class for mapping IDENTITY-H CID fonts (or trying to...).

  ==============================================================================================================*/

class PdfTexterIdentityHCIDMap extends PdfTexterCIDMap
{
    public function __construct($object_id, $font_variant)
    {
        parent::__construct($object_id, 'IDENTITY-H', $font_variant);
    }
}
