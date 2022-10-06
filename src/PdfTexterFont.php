<?php
namespace VanXuan\PdfToText;

/**************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************
 ******                                                                                                  ******
 ******                                                                                                  ******
 ******                                         FONT MANAGEMENT                                          ******
 ******                                                                                                  ******
 ******                                                                                                  ******
 **************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************/
/*==============================================================================================================

    PdfTexterFont class -
        The PdfTexterFont class is not supposed to be used outside the context of the PdfToText class.
    It holds an optional character mapping table associted with this font.
    No provision has been made to design this class a a general purpose class ; its utility exists only in
    the scope of the PdfToText class.

  ==============================================================================================================*/

class PdfTexterFont extends PdfObjectBase
{
    // Font encoding types, for fonts that are neither associated with a Unicode character map nor a PDF character map
    const    FONT_ENCODING_STANDARD = 0;            // No character map, use the standard character set
    const    FONT_ENCODING_WINANSI = 1;            // No character map, use the Windows Ansi character set
    const    FONT_ENCODING_MAC_ROMAN = 2;            // No character map, use the MAC OS Roman character set
    const    FONT_ENCODING_UNICODE_MAP = 3;            // Font has an associated unicode character map
    const    FONT_ENCODING_PDF_MAP = 4;            // Font has an associated PDF character map
    const    FONT_ENCODING_CID_IDENTITY_H = 5;            // CID font : IDENTITY-H

    // Font variants
    const   FONT_VARIANT_STANDARD = 0x0000;
    const    FONT_VARIANT_ISO8859_5 = 0x1000;        // Cyrillic

    const    FONT_VARIANT_MASK = 0xF000;
    const    FONT_VARIANT_SHIFT = 12;

    // Font resource id (may be an object id, overridden by <</Rx...>> constructs
    public $Id;
    // Font type and variant
    public $FontType;
    public $FontVariant;
    // Character map id, specified by the /ToUnicode flag
    public $CharacterMapId;
    // Secondary character map id, specified by the /Encoding flag and that can contain a /Differences flag
    public $SecondaryCharacterMapId;
    // Optional character map, that may be set by the PdfToText::Load method just before processing text drawing blocks
    public $CharacterMap = null;
    public $SecondaryCharacterMap = null;
    // Character widths
    public $CharacterWidths = [];
    // Default character width, if not present in the $CharacterWidths array
    public $DefaultWidth = 0;
    private $GotWidthInformation = false;
    // A buffer for remembering character widths
    protected $CharacterWidthsBuffer = [];


    // Constructor -
    //  Builds a PdfTexterFont object, using its resource id and optional character map id.
    public function __construct($resource_id, $cmap_id, $font_type, $secondary_cmap_id = null, $pdf_objects = null, $extra_mappings = null, $font_variant = false)
    {

        parent::__construct();

        $this->Id = $resource_id;
        $this->CharacterMapId = $cmap_id;
        $this->SecondaryCharacterMapId = $secondary_cmap_id;
        $this->FontType = $font_type & ~self::FONT_VARIANT_MASK;
        $this->FontVariant = ($font_type >> self::FONT_VARIANT_SHIFT) & 0x0F;

        // Instantiate the appropriate character map for this font
        switch ($this->FontType) {
            case    self::FONT_ENCODING_WINANSI :
                $this->CharacterMap = new  PdfTexterAdobeWinAnsiMap($resource_id, $this->FontVariant);
                break;

            case    self::FONT_ENCODING_MAC_ROMAN :
                $this->CharacterMap = new  PdfTexterAdobeMacRomanMap($resource_id, $this->FontVariant);
                break;

            case    self::FONT_ENCODING_CID_IDENTITY_H :
                $this->CharacterMap = new PdfTexterIdentityHCIDMap($resource_id, $font_variant);
                break;

            case    self::FONT_ENCODING_PDF_MAP :
                $this->CharacterMap = new  PdfTexterEncodingMap($cmap_id, $pdf_objects [$cmap_id], $extra_mappings);
                break;

            case    self::FONT_ENCODING_UNICODE_MAP :
                break;

            case    self::FONT_ENCODING_STANDARD :
                break;

            default :
                if (PdfToText::$DEBUG) {
                    warning("Unknown font type #$font_type found for object #$resource_id, character map #$cmap_id.");
                }
        }

        // Get font data ; include font descriptor information if present
        $font_data = $pdf_objects [$resource_id];

        if (preg_match('/FontDescriptor \s+ (?P<id> \d+) \s+ \d+ \s+ R/imsx', $font_data, $match)) {
            $descriptor_id = $match ['id'];

            // Don't care about searching this in that object, or that in this object - simply catenate the font descriptor
            // with the font definition
            if (isset($pdf_objects [$descriptor_id])) {
                $font_data .= $pdf_objects [$descriptor_id];
            }
        }

        // Type1 fonts belong to the Adobe 14 standard fonts available. Information about the character widths is never embedded in the PDF
        // file, but must be taken from external data (in the FontMetrics directory).
        if (preg_match('#/SubType \s* /Type1#ix', $font_data)) {
            preg_match('#/BaseFont \s* / ([\w]+ \+)? (?P<font> [^\s\[</]+)#ix', $font_data, $match);
            $font_name = $match ['font'];
            $lc_font_name = strtolower($font_name);

            // Do that only if a font metrics file exists...
            if (isset(PdfToText::$AdobeStandardFontMetrics [$lc_font_name])) {
                $metrics_file = PdfToText::$FontMetricsDirectory . '/' . PdfToText::$AdobeStandardFontMetrics [$lc_font_name];

                if (file_exists($metrics_file)) {
                    /** @noinspection PhpIncludeInspection */
                    include($metrics_file);

                    if (isset($charwidths)) {
                        // Build the CharacterWidths table
                        foreach ($charwidths as $char => $width) {
                            $this->CharacterWidths [chr($char)] = ( double )$width;
                        }

                        $this->GotWidthInformation = true;
                    }
                }
            }
        }

        // Retrieve the character widths for this font. This means :
        // - Retrieving the /FirstChar, /LastChar and /Widths entries from the font definition. /Widths is an array of individual character
        //   widths, between the /FirstChar and /LastChar entries. A value of zero in this array means "Use the default width"...
        // - ... which is given by the /MissingWidth parameter, normally put in the font descriptor whose object id is given by the
        //   /FontDescriptor entry of the font definition
        // Well, to be considered, given the number of buggy PDFs around the world, we won't care about the /LastChar entry and we won't
        // check whether the /Widths array contains (LastChar - FirstChar + 1) integer values...
        // Get the entries
        $first_char = false;
        $widths = false;
        $missing_width = false;

        if (preg_match('#/FirstChar \s+ (?P<char> \d+)#imsx', $font_data, $match)) {
            $first_char = $match ['char'];
        }

        if (preg_match('#/Widths \s* \[ (?P<widths> [^\]]+) \]#imsx', $font_data, $match)) {
            $widths = $match ['widths'];
        }

        if (preg_match('#/MissingWidth \s+ (?P<missing> \d+)#imsx', $font_data, $match)) {
            $missing_width = $match ['missing'];
        }

        // It would not make sense if one of the two entries /FirstChar and /Widths was missing
        // So ensure they are all there (note that /MissingWidths can be absent)
        if ($first_char !== false && $widths) {
            if ($missing_width !== false) {
                $this->DefaultWidth = ( double )$missing_width;
            }

            // Here comes a really tricky part :
            // - The PDF file can contain CharProcs (example names : /a0, /a1, etc.) for which we have no
            //   Unicode equivalent
            // - The caller may have called the AddAdobeExtraMappings method, to providing a mapping between
            //   those char codes (/a0, /a1, etc.) and a Unicode equivalent
            // - Each "charproc" listed in the /Differences array as a specific code, such as :
            //  [0/a1/a2/a3...]
            //   which maps /a1 to code 0, /a2 to code 1, and so on
            // - However, the GetStringWidth() method provides real Unicode characters
            // Consequently, we have to map each CharProc character (/a1, /a2, etc.) to the Unicode value
            // that may have been specified using the AddAdobeExtraMappings() method.
            // The first step below collects the name list of CharProcs.
            $charprocs = false;

            if (isset($this->CharacterMap->Encodings) &&
                preg_match('# /CharProcs \s* << (?P<list> .*?) >>#imsx', $font_data, $match)
            ) {
                preg_match_all('#/ (?P<char> \w+) \s+ \d+ \s+ \d+ \s+ R#msx', $match ['list'], $char_matches);

                $charprocs = array_flip($char_matches ['char']);
            }

            // The /FontMatrix entry defines the scaling to be used for the character widths (among other things)
            if (preg_match('#/FontMatrix \s* \[ \s* (?P<multiplier> \d+)#imsx', $font_data, $match)) {
                $multiplier = 1000 * ( double )$match ['multiplier'];
            } else {
                $multiplier = 1;
            }

            $widths = trim(preg_replace('/\s+/', ' ', $widths));
            $widths = explode(' ', $widths);

            for ($i = 0, $count = count($widths); $i < $count; $i++) {
                $value = ( double )trim($widths [$i]);
                $chr_index = $first_char + $i;

                // Tricky thing part 2 :
                if ($charprocs) {
                    // If one of the CharProc characters is listed in the /Differences array then...
                    if (isset($this->CharacterMap->DifferencesByPosition [$chr_index])) {
                        $chname = $this->CharacterMap->DifferencesByPosition [$chr_index];

                        // ... if this CharProcs character is defined in the encoding table (possibly because
                        // it was complemeted through a call to the AddAdobeExtraMappings() method), then we
                        // will use its Unicode counterpart instead of the character ID coming from the
                        // /Differences array)
                        if (isset($charprocs [$chname]) && isset($this->CharacterMap->Encodings [$chname])) {
                            $chr_index = $this->CharacterMap->Encodings [$chname] [2];
                        }
                    }
                }

                $this->CharacterWidths [chr($chr_index)] = ($value) ? ($value * $multiplier) : $this->DefaultWidth;
            }

            $this->GotWidthInformation = true;
        }
    }


    // MapCharacter -
    //  Returns the substitution string value for the specified character, if the current font has an
    //  associated character map, or the original character encoded in utf8, if not.
    public function MapCharacter($ch, $return_false_on_failure = false)
    {
        if ($this->CharacterMap) {
            // Character is defined in the character map ; check if it has been overridden by a /Differences array in
            // a secondary character map
            if (isset($this->CharacterMap [$ch])) {
                // Since a /ToUnicode map can have an associated /Encoding map with a /Differences list, this is the right place
                // to perform the translation (ie, the final Unicode codepoint is impacted by the /Differences list)
                if (!$this->SecondaryCharacterMap) {        // Most common case first !
                    $code = $this->CharacterMap [$ch];
                } else {
                    if (isset($this->SecondaryCharacterMap [$ch])) {
                        $code = $this->SecondaryCharacterMap [$ch];
                    } else {
                        $code = $this->CharacterMap [$ch];
                    }
                }

                return ($code);
            } elseif ($this->SecondaryCharacterMap && isset($this->SecondaryCharacterMap [$ch])) {
                // On the contrary, the character may not be defined in the main character map but may exist in the secondary cmap
                $code = $this->SecondaryCharacterMap [$ch];

                return ($code);
            }
        }

        if ($return_false_on_failure) {
            return (false);
        }

        return ($this->CodePointToUtf8($ch));
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetStringWidth - Returns the length of a string, in 1/100 of points

        PROTOTYPE
            $width      =  $font -> GetStringWidth ( $text, $extra_percent ) ;

        DESCRIPTION
            Returns the length of a string, in 1/100 of points.

        PARAMETERS
            $text (string) -
                    String whose length is to be measured.

        $extra_percent (double) -
            Extra percentage to be added to the computed width.

        RETURN VALUE
            Returns the length of the specified string in 1/1000 of text points, or 0 if the font does not
        contain any character width information.

     *-------------------------------------------------------------------------------------------------------------*/
    public function GetStringWidth($text, $extra_percent)
    {
        // No width information
        if (!$this->GotWidthInformation) {
            return (false);
        }

        $width = 0;

        // Compute the width of each individual character - use a character width buffer to avoid
        // repeating the same tests again and again for characters whose width has already been processed
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $ch = $text [$i];

            // Character already in the Widths buffer - Simply retrieve its value
            if (isset($this->CharacterWidthsBuffer [$ch])) {
                $width += $this->CharacterWidthsBuffer [$ch];
            } else {
                // New character - The width comes either from the CharacterWidths array if an entry is defined
                // for this character, or from the default width property.
                if (isset($this->CharacterWidths [$ch])) {
                    $width += $this->CharacterWidths [$ch];
                    $this->CharacterWidthsBuffer [$ch] = $this->CharacterWidths [$ch];
                } else {
                    $width += $this->DefaultWidth;
                    $this->CharacterWidthsBuffer [$ch] = $this->DefaultWidth;
                }
            }
        }

        // The computed width is actually longer/smaller than its actual width. Adjust by the percentage specified
        // by the ExtraTextWidth property
        $divisor = 100 - $extra_percent;

        if ($divisor < 50) {            // Arbitrarily fix a limit
            $divisor = 50;
        }

        // All done, return
        return ($width / $divisor);
    }
}
