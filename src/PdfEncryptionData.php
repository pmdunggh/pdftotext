<?php
namespace VanXuan\PdfToText;

/**************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************
 ******                                                                                                  ******
 ******                                                                                                  ******
 ******                                      ENCRYPTION MANAGEMENT                                       ******
 ******                                                                                                  ******
 ******                                                                                                  ******
 **************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************/
/*==============================================================================================================

    class EncryptionData -
        Holds encryption data and allows for decryption.

  ==============================================================================================================*/

class PdfEncryptionData extends PdfObjectBase
{
    const        DEBUG = 0;
    // Encryption modes
    const        PDFMODE_UNKNOWN = 0;
    const        PDFMODE_STANDARD = 1;

    // Encryption algorithms
    const        PDFCRYPT_ALGORITHM_RC4 = 0;
    const        PDFCRYPT_ALGORITHM_AES = 1;
    const        PDFCRYPT_ALGORITHM_AES256 = 2;

    // A 32-bytes hardcoded padding used when computing encryption keys
    const        PDF_ENCRYPTION_PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    // Permission bits for encrypted files. Comments come from the PDF specification
    const        PDFPERM_PRINT = 0x0004;        // bit 3 :
    //  (Revision 2) Print the document.
    //  (Revision 3 or greater) Print the document (possibly not at the highest quality level,
    //  depending on whether bit 12 is also set).
    const        PDFPERM_MODIFY = 0x0008;        // bit 4 :
    //  Modify the contents of the document by operations other than those controlled by bits 6, 9, and 11.
    const        PDFPERM_COPY = 0x0010;        // bit 5 :
    //  (Revision 2) Copy or otherwise extract text and graphics from the document, including extracting text
    //  and graphics (in support of accessibility to users with disabilities or for other purposes).
    //  (Revision 3 or greater) Copy or otherwise extract text and graphics from the document by operations
    //  other than that controlled by bit 10.
    const        PDFPERM_MODIFY_EXTRA = 0x0020;        // bit 6 :
    //  Add or modify text annotations, fill in interactive form fields, and, if bit 4 is also set,
    //  create or modify interactive form fields (including signature fields).
    const        PDFPERM_FILL_FORM = 0x0100;        // bit 9 :
    //  (Revision 3 or greater) Fill in existing interactive form fields (including signature fields),
    //  even if bit 6 is clear.
    const        PDFPERM_EXTRACT = 0x0200;        // bit 10 :
    //  (Revision 3 or greater) Fill in existing interactive form fields (including signature fields),
    //  even if bit 6 is clear.
    const        PDFPERM_ASSEMBLE = 0x0400;        // bit 11 :
    //  (Revision 3 or greater) Assemble the document (insert, rotate, or delete pages and create bookmarks
    //  or thumbnail images), even if bit 4 is clear.
    const        PDFPERM_HIGH_QUALITY_PRINT = 0x0800;        // bit 12 :
    //  (Revision 3 or greater) Print the document to a representation from which a faithful digital copy of
    //  the PDF content could be generated. When this bit is clear (and bit 3 is set), printing is limited to
    //  a low-level representation of the appearance, possibly of degraded quality.

    public $FileId;                            // File ID, as specified by the /ID flag
    public $ObjectId;                            // Object id and text contents
    private $ObjectData;
    private $UnSupportedEncryptionAlgorithm;
    public $Mode;                                // Encryption mode - currently, only the "Standard" keyword is accepted
    public $EncryptionAlgorithm;                        // Encryption algorithm - one of the PDFCRYPT_* constants
    public $AlgorithmVersion,                        // Encryption algorithm version & revision
        $AlgorithmRevision;
    public $Flags;                            // Protection flags, when an owner password has been specified - one of the PDFPERM_* constants
    public $KeyLength;                            // Encryption key length
    public $UserKey,                            // User and owner password keys
        $OwnerKey;
    public $UserEncryptionString,                        // Not sure yet of the real usage of these ones
        $OwnerEncryptionString;
    public $EncryptMetadata;                        // True if metadata is also encrypted
    public $FileKeyLength;                        // Key length / 5

    protected $Decrypter;                            // Decrypter object

    private $UnsupportedEncryptionAlgorithm = false;        // True if the encryption algorithm used in the PDF file is not yet supported


    /**************************************************************************************************************
     *
     * NAME
     * Constructor
     *
     * PROTOTYPE
     * obj    =  new  PdfEncryptionData ( $mode, $object_id, $object_data ) ;
     *
     * DESCRIPTION
     * Creates an instance of a PdfEncryptionData class, using the information parsed
     * from the supplied object data.
     *
     * PARAMETERS
     * $mode (integer) -
     * One of the PDFMODE_* constants.
     *
     * $object_id (integer) -
     * Id of the object containing enryption parameters.
     *
     * $object_data (string) -
     * Encryption parameters.
     *
     * AUTHOR
     * Christian Vigh, 03/2017.
     *
     * HISTORY
     * [Version : 1.0]    [Date : 2017-03-14]     [Author : CV]
     * Initial version.
     *************************************************************************************************************
     * @param $file_id
     * @param $mode
     * @param $object_id
     * @param $object_data
     */
    public function __construct($file_id, $mode, $object_id, $object_data)
    {
        parent::__construct();
        $this->FileId = $file_id;
        $this->ObjectId = $object_id;
        $this->ObjectData = $object_data;
        $this->Mode = $mode;

        // Encryption algorithm version & revision
        preg_match('#/V \s+ (?P<value> \d+)#ix', $object_data, $algorithm_match);
        $this->AlgorithmVersion = ( integer )$algorithm_match ['value'];

        preg_match('#/R \s+ (?P<value> \d+)#ix', $object_data, $algorithm_revision_match);
        $this->AlgorithmRevision = ( integer )$algorithm_revision_match ['value'];

        // Encryption flags
        preg_match('#/P \s+ (?P<value> \-? \d+)#ix', $object_data, $flags_match);
        $this->Flags = ( integer)$flags_match ['value'];

        // Key length (40 bits, if not specified)
        if (preg_match('#/Length \s+ (?P<value> \d+)#ix', $object_data, $key_length_match)) {
            $this->KeyLength = $key_length_match ['value'];
        } else {
            $this->KeyLength = 40;
        }

        // Owner and user passwords
        $this->UserKey = $this->GetStringParameter('/U', $object_data);
        $this->OwnerKey = $this->GetStringParameter('/O', $object_data);

        // Owner and user encryption strings
        $this->UserEncryptionString = $this->GetStringParameter('/UE', $object_data);
        $this->OwnerEncryptionString = $this->GetStringParameter('/OE', $object_data);

        // EncryptMetadata flag
        if (preg_match('# /EncryptMetadata (?P<value> (true) | (1) | (false) | (0) )#imsx', $object_data, $encryption_match)) {
            if (!strcasecmp($encryption_match ['value'], 'true') || !strcasecmp($encryption_match ['value'], 'false')) {
                $this->EncryptMetadata = true;
            } else {
                $this->EncryptMetadata = false;
            }
        } else {
            $this->EncryptMetadata = false;
        }

        // Now, try to determine the encryption algorithm to be used
        $user_key_length = strlen($this->UserKey);
        $owner_key_length = strlen($this->OwnerKey);
        //$user_encryption_string_length    =  strlen ( $this -> UserEncryptionString ) ;
        //$owner_encryption_string_length   =  strlen ( $this -> OwnerEncryptionString ) ;

        $error_unhandled_version = false;
        $error_unhandled_revision = false;

        switch ($this->AlgorithmVersion) {
            case    1 :
                switch ($this->AlgorithmRevision) {
                    case    2 :
                        if ($user_key_length != 32 && $owner_key_length != 32) {
                            if (PdfToText::$DEBUG) {
                                error(new DecryptionException("Invalid user and/or owner key length ($user_key_length/$owner_key_length)", $object_id));
                            }
                        }

                        $this->EncryptionAlgorithm = self::PDFCRYPT_ALGORITHM_RC4;
                        $this->FileKeyLength = 5;
                        break;

                    default :
                        $error_unhandled_revision = true;
                }
                break;

            default :
                $error_unhandled_version = true;
        }

        // Report unsupported versions/revisions
        if ($error_unhandled_version || $error_unhandled_revision) {
            if (PdfToText::$DEBUG) {
                error(new DecryptionException(
                    "Unsupported encryption algorithm version {$this -> AlgorithmVersion} revision {$this -> AlgorithmRevision}.",
                    $object_id
                ));
            }

            $this->UnSupportedEncryptionAlgorithm = true;

            return;
        }

        // Build the object key
        $this->Decrypter = PdfDecryptionAlgorithm::GetInstance($this);

        if ($this->Decrypter === false) {
            if (PdfToText::$DEBUG) {
                warning(new DecryptionException(
                    "Unsupported encryption algorithm #{$this -> EncryptionAlgorithm}, " .
                    "version {$this -> AlgorithmVersion} revision {$this -> AlgorithmRevision}.",
                    $object_id
                ));
            }

            $this->UnsupportedEncryptionAlgorithm = true;

            return;
        }
        //dump ( $this ) ;
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetInstance - Creates an instance of a PdfEncryptionData object.

        PROTOTYPE
            $obj        =  PdfEncryptionData::GetInstance ( $object_id, $object_data ) ;

        DESCRIPTION
            Returns an instance of encryption data

     *-------------------------------------------------------------------------------------------------------------*/
    public static function GetInstance($file_id, $object_id, $object_data)
    {
        // Encryption mode
        if (!preg_match('#/Filter \s* / (?P<mode> \w+)#ix', $object_data, $object_data_match)) {
            return (false);
        }

        switch (strtolower($object_data_match ['mode'])) {
            case    'standard' :
                $mode = self::PDFMODE_STANDARD;
                break;

            default :
                if (self::DEBUG > 1) {
                    error(new DecodingException("Unhandled encryption mode '{$object_data [ 'mode' ]}'", $object_id));
                }

                return (false);
        }

        // Basic checks have been performed, return an instance of encryption data
        return (new PdfEncryptionData($file_id, $mode, $object_id, $object_data));
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            Decrypt - Decrypts object data.

        PROTOTYPE
            $data       =  $this -> Decrypt ( $object_id, $object_data ) ;

        DESCRIPTION
            Decrypts object data, when the PDF file is password-protected.

        PARAMETERS
            $object_id (integer) -
                    Pdf object number.

        $object_data (string) -
            Object data.

        RETURN VALUE
            Returns the decrypted object data, or false if the encrypted object could not be decrypted.

     *-------------------------------------------------------------------------------------------------------------*/
    public function Decrypt(/** @noinspection PhpUnusedParameterInspection */
        $object_id,
        $object_data
    ) {
        if ($this->UnsupportedEncryptionAlgorithm) {
            return (false);
        }

        return (false);
        //return ( $this -> Decrypter -> Decrypt ( $object_data ) ) ;
        //return ( "BT (coucou)Tj ET" ) ;
    }
}
