<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class PdfDecryptionAlgorithm -
        Base class for algorithm decrypters.

  ==============================================================================================================*/

abstract class PdfDecryptionAlgorithm //extends  Object
{
    protected $EncryptionData;
    protected $ObjectKey;
    protected $ObjectKeyBytes;
    protected $ObjectKeyLength;


    public function __construct($encryption_data)
    {
        $this->EncryptionData = $encryption_data;

        $objkey = '';

        for ($i = 0; $i < $this->EncryptionData->FileKeyLength; $i++) {
            $objkey .= $this->EncryptionData->FileId [$i];
        }

        $objkey .= chr(($this->EncryptionData->ObjectId) & 0xFF);
        $objkey .= chr(($this->EncryptionData->ObjectId >> 8) & 0xFF);
        $objkey .= chr(($this->EncryptionData->ObjectId >> 16) & 0xFF);
        $objkey .= chr(0);        // obj generation number & 0xFF
        $objkey .= chr(0);        // obj generation number >> 8  &  0xFF

        $md5 = md5($objkey, true);
        $this->ObjectKey = $md5;
        $this->ObjectKeyLength = 16;

        $this->ObjectKeyBytes = [];

        for ($i = 0; $i < $this->ObjectKeyLength; $i++) {
            $this->ObjectKeyBytes  [] = ord($this->ObjectKey [$i]);
        }
    }


    public static function GetInstance($encryption_data)
    {
        switch ($encryption_data->EncryptionAlgorithm) {
            case    PdfEncryptionData::PDFCRYPT_ALGORITHM_RC4 :
                return (new PdfRC4DecryptionAlgorithm($encryption_data));

            default :
                return (false);
        }
    }


    abstract public function Reset();

    abstract public function Decrypt($data);
}
