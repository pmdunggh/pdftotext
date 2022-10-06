<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    PdfTexterCharacterMap -
        The PdfTexterFont class is not supposed to be used outside the context of the PdfToText class.
    Describes a character map.
    No provision has been made to design this class a a general purpose class ; its utility exists only in
    the scope of the PdfToText class.

  ==============================================================================================================*/

abstract class PdfTexterCharacterMap extends PdfObjectBase implements \ArrayAccess, \Countable
{
    // Object id of the character map
    public $ObjectId;
    // Number of hex digits in a character represented in hexadecimal notation
    public $HexCharWidth;
    // Set to true if the values returned by the array access operator can safely be cached
    public $Cache = false;


    public function __construct($object_id)
    {
        parent::__construct();
        $this->ObjectId = $object_id;
    }


    /*--------------------------------------------------------------------------------------------------------------

        CreateInstance -
            Creates a PdfTexterCharacterMap instance of the correct type.

     *-------------------------------------------------------------------------------------------------------------*/
    public static function CreateInstance($object_id, $definitions, $extra_mappings)
    {
        if (preg_match('# (begincmap) | (beginbfchar) | (beginbfrange) #ix', $definitions)) {
            return (new PdfTexterUnicodeMap($object_id, $definitions));
        } elseif (stripos($definitions, '/Differences') !== false) {
            return (new PdfTexterEncodingMap($object_id, $definitions, $extra_mappings));
        } else {
            return (false);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

            Interface implementations.

     *-------------------------------------------------------------------------------------------------------------*/
    public function offsetSet($offset, $value): void
    {
        error(new DecodingException("Unsupported operation."));
    }

    public function offsetUnset($offset): void
    {
        error(new DecodingException("Unsupported operation."));
    }
}
