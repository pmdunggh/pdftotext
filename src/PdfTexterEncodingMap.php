<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    PdfTexterEncodingMap -
        A class for fonts having a character map specified with the /Encoding parameter.

  ==============================================================================================================*/

class PdfTexterEncodingMap extends PdfTexterCharacterMap
{
    // Possible encodings (there is a 5th one, MacExpertEncoding, but used for "expert fonts" ; no need to deal
    // with it here since we only want to extract text)
    // Note that the values of these constants are direct indices to the second dimension of the $Encodings table
    const    PDF_STANDARD_ENCODING = 0;
    const    PDF_MAC_ROMAN_ENCODING = 1;
    const    PDF_WIN_ANSI_ENCODING = 2;
    const    PDF_DOC_ENCODING = 3;

    // Correspondance between an encoding name and its corresponding character in the
    // following format : Standard, Mac, Windows, Pdf
    private static $GlobalEncodings = false;
    public $Encodings;
    // Encoding type (one of the PDF_*_ENCODING constants)
    public $Encoding;
    // Indicates whether this character map is a secondary one used for Unicode maps ; this must be set at
    // a higher level by the PdfTexterFont because at the time a character map is instantiated, we do not know
    // yet whether it will be a primary (normal) map, or a map secondary to an existing Unicode map
    public $Secondary;
    // Differences array (a character substitution table to the standard encodings)
    public $Map = [];
    // A secondary map for the Differences array, which only contains the differences ; this is used
    // for Unicode fonts that also have an associated /Differences parameter, which should not include the
    // whole standard Adobe character map but only the differences of encodings
    public $SecondaryMap = [];
    // Differences by position number
    public $DifferencesByPosition = [];


    // Constructor -
    //  Analyzes the text contents of a CMAP and extracts mappings from the beginbfchar/endbfchar and
    //  beginbfrange/endbfrange constructs.
    public function __construct($object_id, $definitions, $extra_mappings)
    {
        // Ignore character variants whose names end with these suffixes
        static $IgnoredVariants =
        [
            '/\.scalt$/',
            '/\.sc$/',
            '/\.fitted$/',
            '/\.oldstyle$/',
            '/\.taboldstyle$/',
            '/\.alt$/',
            '/alt$/',
        ];

        parent::__construct($object_id);

        // Load the default Adobe character sets, if not already done
        if (self::$GlobalEncodings === false) {
            $charset_file = dirname(__FILE__) . '/Maps/adobe-charsets.map';
            /** @noinspection PhpIncludeInspection */
            include($charset_file);
            self::$GlobalEncodings = (isset($adobe_charsets)) ? $adobe_charsets : [];
        }

        $this->Encodings = array_merge(self::$GlobalEncodings, $extra_mappings);

        // Fonts using default Adobe character sets and hexadecimal representations are one-byte long
        $this->HexCharWidth = 2;

        if (PdfToText::$DEBUG) {
            echo "\n----------------------------------- ENCODING CMAP #$object_id\n";
            echo $definitions;
        }

        // Retrieve text encoding
        preg_match(
            '# / (?P<encoding> (WinAnsiEncoding) | (PDFDocEncoding) | (MacRomanEncoding) | (StandardEncoding) ) #ix',
            $definitions,
            $encoding_match
        );

        if (!isset($encoding_match ['encoding'])) {
            $encoding_match ['encoding'] = 'WinAnsiEncoding';
        }

        switch (strtolower($encoding_match ['encoding'])) {
            case    'pdfdocencoding'    :
                $this->Encoding = self::PDF_DOC_ENCODING;
                break;
            case    'macromanencoding'    :
                $this->Encoding = self::PDF_MAC_ROMAN_ENCODING;
                break;
            case    'standardencoding'    :
                $this->Encoding = self::PDF_STANDARD_ENCODING;
                break;
            case    'winansiencoding'    :
            default            :
                $this->Encoding = self::PDF_WIN_ANSI_ENCODING;
        }

        // Build a virgin character map using the detected encoding
        foreach ($this->Encodings as $code_array) {
            $char = $code_array [$this->Encoding];
            $this->Map [$char] = $char;
        }

        // Extract the Differences array
        preg_match('/ \[ \s* (?P<contents> [^\]]*?)  \s* \] /x', $definitions, $match);

        if (!isset($match ['contents'])) {
            return;
        }

        $data = trim(preg_replace('/\s+(\d+)/', '/$1', $match ['contents']));
        $items = explode('/', $data);
        $index = 0;

        for ($i = 0, $item_count = count($items); $i < $item_count; $i++) {
            $item = PdfToText::DecodeRawName(trim($items [$i]));

            // Integer value  : index of next character in map
            if (is_numeric($item)) {
                $index = ( integer )$item;
            } else {
                // String value : a character name, as defined by Adobe
                // Remove variant part of the character name
                $item = preg_replace($IgnoredVariants, '', trim($item));

                // Keyword (character name) exists in the encoding table
                if (isset($this->Encodings [$item])) {
                    $this->Map [$index] =
                    $this->SecondaryMap [$index] = $this->Encodings [$item] [$this->Encoding];
                } elseif (preg_match('/g (?P<value> \d+)/x', $item, $match)) {
                    // Not defined ; check if this is the "/gxx" notation, where "xx" is a number
                    $value = ( integer )$match ['value'];

                    // In my current state of investigations, the /g notation has the following characteristics :
                    // - The value 29 must be added to the number after the "/g" string (why ???)
                    // - The value after the "/g" string can be greater than 255, meaning that it could be Unicode codepoint
                    // This has to be carefully watched before revision
                    $value += 29;

                    $this->Map [$index] =
                    $this->SecondaryMap [$index] = $value;
                } elseif (preg_match('/uni (?P<value>  [0-9a-f]+)/ix', $item, $match)) {
                    // Some characters can be specified by the "/uni" prefix followed by a sequence of hex digits,
                    // which is not described by the PDF specifications. This sequence gives a Unicode code point.
                    $value = hexdec($match ['value']);

                    $this->Map [$index] =
                    $this->SecondaryMap [$index] = ( integer )$value;
                } else {
                    // Otherwise, put a quotation mark instead
                    if (PdfToText::$DEBUG) {
                        warning("Unknown character name found in a /Differences[] array : [$item]");
                    }

                    $this->Map [$index] =
                    $this->SecondaryMap [$index] = ord('?');
                }

                $this->DifferencesByPosition [$index] = $item;

                $index++;
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

            Interface implementations.

     *-------------------------------------------------------------------------------------------------------------*/
    public function count(): int
    {
        return (count($this->Map));
    }


    public function offsetExists($offset): bool
    {
        return ((!$this->Secondary) ?
            isset($this->Map [$offset]) :
            isset($this->SecondaryMap [$offset]));
    }


    public function offsetGet($offset): mixed
    {
        if (!$this->Secondary) {
            if (isset($this->Map [$offset])) {
                $ord = $this->Map [$offset];
            } else {
                $ord = $offset;
            }

            // Check for final character translations (concerns only a few number of characters)
            if ($this->Encoding == self::PDF_WIN_ANSI_ENCODING && isset(PdfTexterAdobeWinAnsiMap::$WinAnsiCharacterMap [0] [$ord])) {
                $ord = PdfTexterAdobeWinAnsiMap::$WinAnsiCharacterMap [0] [$ord];
            } elseif ($this->Encoding == self::PDF_MAC_ROMAN_ENCODING && isset(PdfTexterAdobeMacRomanMap::$MacRomanCharacterMap [0] [$ord])) {
                $ord = PdfTexterAdobeMacRomanMap::$MacRomanCharacterMap [0] [$ord];
            } elseif (isset($this->Encodings [$ord] [$this->Encoding])) {
                // As far as I have been able to see, the values expressed by the /Differences tag were the only ones used within the
                // Pdf document ; however, handle the case where some characters do not belong to the characters listed by /Differences,
                // and use the official Adobe encoding maps when necessary
                $ord = $this->Encodings [$ord] [$this->Encoding];
            }

            $result = $this->CodePointToUtf8($ord);
        } elseif (isset($this->SecondaryMap [$offset])) {
            $ord = $this->SecondaryMap [$offset];
            $result = $this->CodePointToUtf8($ord);
        } else {
            $result = false;
        }

        return ($result);
    }
}
