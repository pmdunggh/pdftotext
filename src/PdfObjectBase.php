<?php
namespace VanXuan\PdfToText;

/**
 * Base class for all PDF objects defined here.
 */
abstract class PdfObjectBase
{
    // Possible encoding types for streams inside objects ; "unknown" means that the object contains no stream
    const    PDF_UNKNOWN_ENCODING = 0;        // No stream decoding type could be identified
    const    PDF_ASCIIHEX_ENCODING = 1;        // AsciiHex encoding - not tested
    const    PDF_ASCII85_ENCODING = 2;        // Ascii85 encoding - not tested
    const    PDF_FLATE_ENCODING = 3;        // Flate/deflate encoding
    const    PDF_TEXT_ENCODING = 4;        // Stream data appears in clear text - no decoding required
    const    PDF_LZW_ENCODING = 5;        // Not implemented yet
    const    PDF_RLE_ENCODING = 6;        // Runtime length encoding ; not implemented yet
    const    PDF_DCT_ENCODING = 7;        // JPEG images
    const    PDF_CCITT_FAX_ENCODING = 8;        // CCITT Fax encoding - not implemented yet
    const    PDF_JBIG2_ENCODING = 9;        // JBIG2 filter encoding (black/white) - not implemented yet
    const    PDF_JPX_ENCODING = 10;        // JPEG2000 encoding - not implemented yet

    // Regular expression used for recognizing references to a font (this list is far from being exhaustive, as it seems
    // that you can specify almost everything - however, trying to recognize everything would require to develop a complete
    // parser)
    protected static $FontSpecifiers = '
		(/F \d+ (\.\d+)? )						|
		(/R \d+)							|
		(/f-\d+-\d+)							|
		(/[CT]\d+_\d+)							|
		(/TT \d+)							|
		(/OPBaseFont \d+)						|
		(/OPSUFont \d+)							|
		(/[0-9a-zA-Z])							|
		(/F\w+)								|
		(/[A-Za-z][A-Za-z0-9]* ( [\-+] [A-Za-z][A-Za-z0-9]* ))
		';

    // Maps alien Unicode characters such as special spaces, letters with ligatures to their ascii string equivalent
    protected static $UnicodeToSimpleAscii = false;


    /*--------------------------------------------------------------------------------------------------------------
       Constructor -
       Performs static initializations such as the Unicode to Ascii table.
    *-------------------------------------------------------------------------------------------------------------*/
    public function __construct()
    {
        if (self::$UnicodeToSimpleAscii === false) {
            $charset_file = dirname(__FILE__) . "/Maps/unicode-to-ansi.map";
            /** @noinspection PhpIncludeInspection */
            include($charset_file);
            self::$UnicodeToSimpleAscii = (isset($unicode_to_ansi)) ? $unicode_to_ansi : [];
        }

        // parent::__construct ( ) ;
    }


    /*--------------------------------------------------------------------------------------------------------------
       NAME
           CodePointToUtf8 - Encodes a Unicode codepoint to UTF8.

       PROTOTYPE
           $char    =  $this -> CodePointToUtf8 ( $code ) ;

       DESCRIPTION
           Encodes a Unicode codepoint to UTF8, trying to handle all possible cases.

       PARAMETERS
           $code (integer) -
                   Unicode code point to be translated.

       RETURN VALUE
           A string that contains the UTF8 bytes representing the Unicode code point.
    *-------------------------------------------------------------------------------------------------------------*/
    protected function CodePointToUtf8($code)
    {
        if ($code) {
            $result = '';

            while ($code) {
                $word = ($code & 0xFFFF);

                if (!isset(self::$UnicodeToSimpleAscii [$word])) {
                    $entity = "&#$word;";
                    $result .= mb_convert_encoding($entity, 'UTF-8', 'HTML-ENTITIES') . $result;
                } else {
                    $result .= self::$UnicodeToSimpleAscii [$word];
                }

                $code = ( integer )($code / 0xFFFF);    // There is no unsigned right-shift operator in PHP...
            }

            return ($result);
        } else {
            // No translation is apparently possible : use a placeholder to signal this situation
            if (strpos(PdfToText::$Utf8Placeholder, '%') === false) {
                return (PdfToText::$Utf8Placeholder);
            } else {
                return (sprintf(PdfToText::$Utf8Placeholder, $code));
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        DecodeRawName -
        Decodes a string that may contain constructs such as '#xy', where 'xy' are hex digits.

     *-------------------------------------------------------------------------------------------------------------*/
    public static function DecodeRawName($str)
    {
        return (rawurldecode(str_replace('#', '%', $str)));
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetEncodingType - Gets an object encoding type.

        PROTOTYPE
            $type   =  $this -> GetEncodingType ( $object_id, $object_data ) ;

        DESCRIPTION
            When an object is a stream, returns its encoding type.

        PARAMETERS
        $object_id (integer) -
            PDF object number.

            $object_data (string) -
                    Object contents.

        RETURN VALUE
            Returns one of the following values :

        - PdfToText::PDF_ASCIIHEX_ENCODING :
            Hexadecimal encoding of the binary values.
            Decoding algorithm was taken from the unknown contributor and not tested so far, since I
            couldn't find a PDF file with such an encoding type.

        - PdfToText::PDF_ASCII85_ENCODING :
            Obscure encoding format.
            Decoding algorithm was taken from the unknown contributor and not tested so far, since I
            couldn't find a PDF file with such an encoding type.

        - PdfToText::PDF_FLATE_ENCODING :
            gzip/deflate encoding.

        - PdfToText::PDF_TEXT_ENCODING :
            Stream data is unencoded (ie, it is pure ascii).

        - PdfToText::PDF_UNKNOWN_ENCODING :
            The object data does not specify any encoding at all. It can happen on objects that do not have
            a "stream" part.

        - PdfToText::PDF_DCT_ENCODING :
            a lossy filter based on the JPEG standard.

        The following constants are defined but not yet implemented ; an exception will be thrown if they are
        encountered somewhere in the PDF file :

        - PDF_LZW_ENCODING :
            a filter based on LZW Compression; it can use one of two groups of predictor functions for more
            compact LZW compression : Predictor 2 from the TIFF 6.0 specification and predictors (filters)
            from the PNG specification

        - PDF_RLE_ENCODING :
            a simple compression method for streams with repetitive data using the run-length encoding
            algorithm and the image-specific filters.

        PDF_CCITT_FAX_ENCODING :
            a lossless bi-level (black/white) filter based on the Group 3 or Group 4 CCITT (ITU-T) fax
            compression standard defined in ITU-T T.4 and T.6.

        PDF_JBIG2_ENCODING :
            a lossy or lossless bi-level (black/white) filter based on the JBIG2 standard, introduced in
            PDF 1.4.

        PDF_JPX_ENCODING :
            a lossy or lossless filter based on the JPEG 2000 standard, introduced in PDF 1.5.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetEncodingType($object_id, $object_data)
    {
        $status = preg_match(
            '# / (?P<encoding> (ASCIIHexDecode) | (AHx) | (ASCII85Decode) | (A85) | (FlateDecode) | (Fl) | (DCTDecode) | (DCT) | ' .
            '(LZWDecode) | (LZW) | (RunLengthDecode) | (RL) | (CCITTFaxDecode) | (CCF) | (JBIG2Decode) | (JPXDecode) ) \b #imsx',
            $object_data,
            $match
        );

        if (!$status) {
            return (self::PDF_TEXT_ENCODING);
        }

        switch (strtolower($match ['encoding'])) {
            case    'asciihexdecode'    :
            case    'ahx'            :
                return (self::PDF_ASCIIHEX_ENCODING);

            case    'ascii85decode'    :
            case    'a85'            :
                return (self::PDF_ASCII85_ENCODING);

            case    'flatedecode'        :
            case    'fl'            :
                return (self::PDF_FLATE_ENCODING);

            case    'dctdecode'        :
            case    'dct'            :
                return (self::PDF_DCT_ENCODING);

            case    'lzwdecode'        :
            case    'lzw'            :
                return (self::PDF_LZW_ENCODING);

            case    'ccittfaxdecode'    :
            case    'ccf'            :

            case    'runlengthdecode'    :
            case    'rl'            :

            case    'jbig2decode'        :

            case    'jpxdecode'        :
                if (PdfToText::$DEBUG > 1) {
                    warning("Encoding type \"{$match [ 'encoding' ]}\" not yet implemented for pdf object #$object_id.");
                }
                return (self::PDF_UNKNOWN_ENCODING);
            default                :
                return (self::PDF_UNKNOWN_ENCODING);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetObjectReferences - Gets object references from a specified construct.

        PROTOTYPE
            $status     =  $this -> GetObjectReferences ( $object_id, $object_data, $searched_string, &$object_ids ) ;

        DESCRIPTION
            Certain parameter specifications are followed by an object reference of the form :
            x 0 R
        but it can also be an array of references :
            [x1 0 R x2 0 R ... xn 0 r]
        Those kind of constructs can occur after parameters such as : /Pages, /Contents, /Kids...
        This method extracts the object references found in such a construct.

        PARAMETERS
            $object_id (integer) -
                    Id of the object to be analyzed.

        $object_data (string) -
            Object contents.

        $searched_string (string) -
            String to be searched, that must be followed by an object or an array of object references.
            This parameter can contain constructs used in regular expressions. Note however that the '#'
            character must be escaped, since it is used as a delimiter in the regex that is applied on
            object data.

        $object_ids (array of integers) -
            Returns on output the ids of the pdf object that have been found after the searched string.

        RETURN VALUE
            True if the searched string has been found and is followed by an object or array of object references,
        false otherwise.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetObjectReferences(/** @noinspection PhpUnusedParameterInspection */
        $object_id,
        $object_data,
        $searched_string,
        &$object_ids
    ) {
        $status = true;
        $object_ids = [];

        if (preg_match("#$searched_string \\s* \\[ (?P<objects> [^\\]]+ ) \\]#ix", $object_data, $match)) {
            $object_list = $match ['objects'];

            if (preg_match_all('/(?P<object> \d+) \s+ \d+ \s+ R/x', $object_list, $matches)) {
                foreach ($matches ['object'] as $id) {
                    $object_ids [] = ( integer )$id;
                }
            } else {
                $status = false;
            }
        } elseif (preg_match("#$searched_string \\s+ (?P<object> \\d+) \\s+ \\d+ \\s+ R#ix", $object_data, $match)) {
            $object_ids [] = ( integer )$match ['object'];
        } else {
            $status = false;
        }

        return ($status);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetStringParameter - Retrieve a string flag value.

        PROTOTYPE
            $result     =  $this -> GetStringParameter ( $parameter, $object_data ) ;

        DESCRIPTION
            Retrieves the value of a string parameter ; for example :

            /U (parameter value)

        or :

            /U <hexdigits>

        PARAMETERS
            $parameter (string) -
                    Parameter name.

        $object_data (string) -
            Object containing the parameter.

        RETURN VALUE
            The parameter value.

        NOTES
            description

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetStringParameter($parameter, $object_data)
    {
        if (preg_match('#' . $parameter . ' \s* \( \s* (?P<value> [^)]+) \)#ix', $object_data, $match)) {
            $result = $this->ProcessEscapedString($match ['value']);
        } elseif (preg_match('#' . $parameter . ' \s* \< \s* (?P<value> [^>]+) \>#ix', $object_data, $match)) {
            $hexdigits = $match ['value'];
            $result = '';

            for ($i = 0, $count = strlen($hexdigits); $i < $count; $i += 2) {
                $result .= chr(hexdec(substr($hexdigits, $i, 2)));
            }
        } else {
            $result = '';
        }

        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

        GetUTCDate -
            Reformats an Adobe UTC date to a format that can be understood by the strtotime() function.
        Dates are specified in the following format :
            D:20150521154000Z
            D:20160707182114+02
        with are both recognized by strtotime(). However, another format can be specified :
            D:20160707182114+02'00'
        which is not recognized by strtotime() so we have to get rid from the '00' part.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetUTCDate($date)
    {
        if ($date) {
            if (($date [0] == 'D' || $date [0] == 'd') && $date [1] == ':') {
                $date = substr($date, 2);
            }

            if (($index = strpos($date, "'")) !== false) {
                $date = substr($date, 0, $index);
            }
        }

        return ($date);
    }


    /*--------------------------------------------------------------------------------------------------------------

        IsCharacterMap -
            Checks if the specified text contents represent a character map definition or not.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function IsCharacterMap($decoded_data)
    {
        // preg_match is faster than calling strpos several times
        return (preg_match('#(begincmap)|(beginbfrange)|(beginbfchar)|(/Differences)#ix', $decoded_data));
    }


    /*--------------------------------------------------------------------------------------------------------------

        IsFont -
        Checks if the current object contents specify a font declaration.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function IsFont($object_data)
    {
        return
            (
                stripos($object_data, '/BaseFont') !== false ||
                (!preg_match('#/Type \s* /FontDescriptor#ix', $object_data) &&
                    preg_match('#/Type \s* /Font#ix', $object_data))
            );
    }


    /*--------------------------------------------------------------------------------------------------------------

        IsFormData -
        Checks if the current object contents specify references to font data.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function IsFormData($object_data)
    {
        return
            (
            preg_match('#\bR \s* \( \s* datasets \s* \)#imsx', $object_data)
            );
    }


    /*--------------------------------------------------------------------------------------------------------------

        IsFontMap -
        Checks if the code contains things like :
            <</F1 26 0 R/F2 22 0 R/F3 18 0 R>>
        which maps font 1 (when specified with the /Fx instruction) to object 26, 2 to object 22 and 3 to
        object 18, respectively, in the above example.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function IsFontMap($object_data)
    {
        $object_data = self::UnescapeHexCharacters($object_data);

        if (preg_match('#<< \s* ( ' . self::$FontSpecifiers . ' ) \s+ .* >>#imsx', $object_data)) {
            return (true);
        } else {
            return (false);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        IsImage -
        Checks if the code contains things like :
            /Subtype/Image

     *-------------------------------------------------------------------------------------------------------------*/
    protected function IsImage($object_data)
    {
        if (preg_match('#/Subtype \s* /Image#msx', $object_data)) {
            return (true);
        } else {
            return (false);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        IsObjectStream -
        Checks if the code contains an object stream (/Type/ObjStm)
            /Subtype/Image

     *-------------------------------------------------------------------------------------------------------------*/
    protected function IsObjectStream($object_data)
    {
        if (preg_match('#/Type \s* /ObjStm#isx', $object_data)) {
            return (true);
        } else {
            return (false);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            IsPageHeaderOrFooter - Check if the specified object contents denote a text stream.

        PROTOTYPE
            $status     =  $this -> IsPageHeaderOrFooter ( $stream_data ) ;

        DESCRIPTION
            Checks if the specified decoded stream contents denotes header or footer data.

        PARAMETERS
            $stream_data (string) -
                    Decoded stream contents.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function IsPageHeaderOrFooter($stream_data)
    {
        if (preg_match('#/Type \s* /Pagination \s* /Subtype \s*/((Header)|(Footer))#ix', $stream_data)) {
            return (true);
        } elseif (preg_match('#/Attached \s* \[ .*? /((Top)|(Bottom)) [^]]#ix', $stream_data)) {
            return (true);
        } else {
            return (false);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            IsText - Check if the specified object contents denote a text stream.

        PROTOTYPE
            $status     =  $this -> IsText ( $object_data, $decoded_stream_data ) ;

        DESCRIPTION
            Checks if the specified object contents denote a text stream.

        PARAMETERS
            $object_data (string) -
                    Object data, ie the contents located between the "obj" and "endobj" keywords.

            $decoded_stream_data (string) -
                The flags specified in the object data are not sufficient to be sure that we have a block of
                drawing instructions. We must also check for certain common instructions to be present.

        RETURN VALUE
            True if the specified contents MAY be text contents, false otherwise.

        NOTES
        I do not consider this method as bullet-proof. There may arise some cases where non-text blocks can be
        mistakenly considered as text blocks, so it is subject to evolve in the future.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function IsText($object_data, $decoded_stream_data)
    {
        if (preg_match('# / (Filter) | (Length) #ix', $object_data) &&
            !preg_match('# / (Type) | (Subtype) | (Length1) #ix', $object_data)
        ) {
            if (preg_match('/\\b(BT|Tf|Td|TJ|Tj|Tm|Do|cm)\\b/', $decoded_stream_data)) {
                return (true);
            }
        } elseif (preg_match('/\\b(BT|Tf|Td|TJ|Tj|Tm|Do|cm)\\b/', $decoded_stream_data)) {
            return (true);
        }

        return (false);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            PregStrReplace - Replace string(s) using regular expression(s)

        PROTOTYPE
            $result     =  PdfToText::PregStrReplace ( $pattern, $replacement, $subject, $limit = -1,
                        &$match_count = null )

        DESCRIPTION
            This function behaves like a mix of str_replace() and preg_replace() ; it allows to search for strings
        using regular expressions, but the replacements are plain-text strings and no reference to a capture
        specified in the regular expression will be interpreted.
        This is useful when processing templates, which can contain constructs such as "\00" or "$", which are
        interpreted by preg_replace() as references to captures.

        The function has the same parameters as preg_replace().

        RETURN VALUE
            Returns the substituted text.

     *-------------------------------------------------------------------------------------------------------------*/
    public static function PregStrReplace($pattern, $replacement, $subject, $limit = -1)
    {
        // Make sure that $pattern and $replacement become arrays of the same size
        if (is_array($pattern)) {
            if (is_array($replacement)) {
                if (count($pattern) !== count($replacement)) {
                    warning("The \$replacement parameter should have the same number of element as \$pattern.");
                    return ($subject);
                }
            } else {
                $replacement = array_fill($replacement, count($pattern), $replacement);
            }
        } else {
            if (is_array($replacement)) {
                warning("Expected string for the \$replacement parameter.");
                return ($subject);
            }

            $pattern = [$pattern];
            $replacement = [$replacement];
        }

        // Upper limit
        if ($limit < 1) {
            $limit = PHP_INT_MAX;
        }

        // Loop through each supplied pattern
        $current_subject = $subject;
        $count = 0;

        for ($i = 0, $pattern_count = count($pattern); $i < $pattern_count; $i++) {
            $regex = $pattern [$i];

            // Get all matches for this pattern
            if (preg_match_all($regex, $current_subject, $matches, PREG_OFFSET_CAPTURE)) {
                $result = '';        // Current output result
                $last_offset = 0;

                // Process each match
                foreach ($matches [0] as $match) {
                    $offset = ( integer )$match [1];

                    // Append data from the last seen offset up to the current one
                    if ($last_offset < $offset) {
                        $result .= substr($current_subject, $last_offset, $offset - $last_offset);
                    }

                    // Append the replacement string for this match
                    $result .= $replacement [$i];

                    // Compute next offset in $current_subject
                    $last_offset = $offset + strlen($match [0]);

                    // Limit checking
                    $count++;

                    if ($count > $limit) {
                        break 2;
                    }
                }

                // Append the last part of the subject that has not been matched by anything
                $result .= substr($current_subject, $last_offset);

                // The current subject becomes the string that has been built in the steps above
                $current_subject = $result;
            }
        }

        /// All done, return
        return ($current_subject);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            ProcessEscapedCharacter - Interprets a character after a backslash in a string.

        PROTOTYPE
            $ch     =  $this -> ProcessEscapedCharacter ( $ch ) ;

        DESCRIPTION
            Interprets a character after a backslash in a string and returns the interpreted value.

        PARAMETERS
            $ch (char) -
                    Character to be escaped.

        RETURN VALUE
            The escaped character.

        NOTES
        This method does not process octal sequences.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function ProcessEscapedCharacter($ch)
    {
        switch ($ch) {
            // Normally, only a few characters should be escaped...
            case    '('    :
                $newchar = "(";
                break;
            case    ')'    :
                $newchar = ")";
                break;
            case    '['    :
                $newchar = "[";
                break;
            case    ']'    :
                $newchar = "]";
                break;
            case    '\\'    :
                $newchar = "\\";
                break;
            case    'n'    :
                $newchar = "\n";
                break;
            case    'r'    :
                $newchar = "\r";
                break;
            case    'f'    :
                $newchar = "\f";
                break;
            case    't'    :
                $newchar = "\t";
                break;
            case    'b'    :
                $newchar = chr(8);
                break;
            case    'v'    :
                $newchar = chr(11);
                break;

            // ... but should we consider that it is a heresy to escape other characters ?
            // For the moment, no.
            default        :
                $newchar = $ch;
                break;
        }

        return ($newchar);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            ProcessEscapedString - Processes a string which can have escaped characters.

        PROTOTYPE
            $result     =  $this -> ProcessEscapedString ( $str, $process_octal_escapes = false ) ;

        DESCRIPTION
            Processes a string which may contain escape sequences.

        PARAMETERS
            $str (string) -
                    String to be processed.

        $process_octal_escapes (boolean) -
            When true, octal escape sequences such as \037 are processed.

        RETURN VALUE
            The processed input string.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function ProcessEscapedString($str, $process_octal_escapes = false)
    {
        $length = strlen($str);
        $offset = 0;
        $result = '';
        $ord0 = ord('0');

        while (($backslash_index = strpos($str, '\\', $offset)) !== false) {
            if ($backslash_index + 1 < $length) {
                $ch = $str [++$backslash_index];

                if (!$process_octal_escapes) {
                    $result .= substr($str, $offset, $backslash_index - $offset - 1) . $this->ProcessEscapedCharacter($ch);
                    $offset = $backslash_index + 1;
                } elseif ($ch < '0' || $ch > '7') {
                    $result .= substr($str, $offset, $backslash_index - $offset - 1) . $this->ProcessEscapedCharacter($ch);
                    $offset = $backslash_index + 1;
                } else {
                    $result .= substr($str, $offset, $backslash_index - $offset - 1);
                    $ord = ord($ch) - $ord0;
                    $count = 0;
                    $backslash_index++;

                    while ($backslash_index < $length && $count < 2 &&
                        $str [$backslash_index] >= '0' && $str [$backslash_index] <= '7') {
                        $ord = ($ord * 8) + (ord($str [$backslash_index++]) - $ord0);
                        $count++;
                    }

                    $result .= chr($ord);
                    $offset = $backslash_index;
                }
            } else {
                break;
            }
        }

        $result .= substr($str, $offset);

        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            Unescape - Processes escape sequences from the specified string.

        PROTOTYPE
            $value  =  $this -> Unescape ( $text ) ;

        DESCRIPTION
            Processes escape sequences within the specified text. The recognized escape sequences are like the
        C-language ones : \b (backspace), \f (form feed), \r (carriage return), \n (newline), \t (tab).
        All other characters prefixed by "\" are returned as is.

        PARAMETERS
            $text (string) -
                    Text to be unescaped.

        RETURN VALUE
            Returns the unescaped value of $text.

     *-------------------------------------------------------------------------------------------------------------*/
    public static function Unescape($text)
    {
        $length = strlen($text);
        $result = '';
        $ord0 = ord(0);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text [$i];

            if ($ch == '\\' && isset($text [$i + 1])) {
                $nch = $text [++$i];

                switch ($nch) {
                    case    'b'    :
                        $result .= "\b";
                        break;
                    case    't'    :
                        $result .= "\t";
                        break;
                    case    'f'    :
                        $result .= "\f";
                        break;
                    case    'r'    :
                        $result .= "\r";
                        break;
                    case    'n'    :
                        $result .= "\n";
                        break;
                    default    :
                        // Octal escape notation
                        if ($nch >= '0' && $nch <= '7') {
                            $ord = ord($nch) - $ord0;
                            $digits = 1;
                            $i++;

                            while ($i < $length && $digits < 3 && $text [$i] >= '0' && $text [$i] <= '7') {
                                $ord = ($ord * 8) + ord($text [$i]) - $ord0;
                                $i++;
                                $digits++;
                            }

                            $i--;        // Count one character less since $i will be incremented at the end of the for() loop

                            $result .= chr($ord);
                        } else {
                            $result .= $nch;
                        }
                }
            } else {
                $result .= $ch;
            }
        }

        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            UnescapeHexCharacters - Unescapes characters in the #xy notation.

        PROTOTYPE
            $result     =  $this -> UnescapeHexCharacters ( $data ) ;

        DESCRIPTION
        Some specifications contain hex characters specified as #xy. For the moment, I have met such a construct in
        font aliases such as :
            /C2#5F0 25 0 R
        where "#5F" stands for "_", giving :
            /C2_0 25 0 R
        Hope that such constructs do not happen in other places...

        PARAMETERS
            $data (string) -
                    String to be unescaped.

        RETURN VALUE
            The input string with all the hex character representations replaced with their ascii equivalent.

     *-------------------------------------------------------------------------------------------------------------*/
    public static function UnescapeHexCharacters($data)
    {
        if (strpos($data, 'stream') === false && preg_match('/(?P<hex> \# [0-9a-f] [0-9a-f])/ix', $data)) {
            preg_match_all('/(?P<hex> \# [0-9a-f] [0-9a-f])/ix', $data, $matches);

            $searches = [];
            $replacements = [];

            foreach ($matches ['hex'] as $hex) {
                if (!isset($searches [$hex])) {
                    $searches [$hex] = $hex;
                    $replacements [] = chr(hexdec(substr($hex, 1)));
                }

                $data = str_replace($searches, $replacements, $data);
            }
        }

        return ($data);
    }


    /*--------------------------------------------------------------------------------------------------------------

        ValidatePhpName -
        Checks that the specified name (declared in the XML template) is a valid PHP name.

     *-------------------------------------------------------------------------------------------------------------*/
    public static function ValidatePhpName($name)
    {
        $name = trim($name);

        if (!preg_match('/^ [a-z_][a-z0-9_]* $/ix', $name)) {
            error(new FormException("Invalid PHP name \"$name\"."));
        }

        return ($name);
    }
}
