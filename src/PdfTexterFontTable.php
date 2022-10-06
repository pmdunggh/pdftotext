<?php
namespace VanXuan\PdfToText;

/**************************************************************************************************************
 ******                                      FONT TABLE MANAGEMENT                                       ******
 **************************************************************************************************************/
/*==============================================================================================================

    PdfTexterFontTable class -
        The PdfTexterFontTable class is not supposed to be used outside the context of the PdfToText class.
    Its purposes are to hold a list of font definitions taken from a pdf document, along with their
    associated character mapping tables, if any.
    This is why no provision has been made to design this class a a general purpose class ; its utility
    exists only in the scope of the PdfToText class.

  ==============================================================================================================*/

class PdfTexterFontTable extends PdfObjectBase
{
    // Font table
    public $Fonts = [];
    private $DefaultFont = false;
    // Font mapping between a font number and an object number
    private $FontMap = [];
    // A character map buffer is used to store results from previous calls to the MapCharacter() method of the
    // FontTable object. It dramatically reduces the number of calls needed, from one call for each character
    // defined in the pdf stream, to one call on each DISTINCT character defined in the PDF stream.
    // As an example, imagine a PDF file that contains 200K characters, but only 150 distinct ones. The
    // MapCharacter method will be called 150 times, instead of 200 000...
    private $CharacterMapBuffer = [];


    // Constructor -
    //  Well, does not do anything special
    public function __construct()
    {
        parent::__construct();
    }


    // Add -
    //  Adds the current font declaration to the font table. Handles special cases where font id is not
    //  given by the object id, but rather by <</Rx...>> constructs
    public function Add($object_id, $font_definition, $pdf_objects, $extra_mappings)
    {
        if (PdfToText::$DEBUG) {
            echo "\n----------------------------------- FONT #$object_id\n";
            echo $font_definition;
        }

        $font_type = PdfTexterFont::FONT_ENCODING_STANDARD;
        $cmap_id = 0;
        $secondary_cmap_id = 0;
        $font_variant = false;

        // Font resource id specification
        if (preg_match('#<< \s* (?P<rscdefs> /R\d+ .*) >>#ix', $font_definition, $match)) {
            $resource_definitions = $match ['rscdefs'];

            preg_match_all('#/R (?P<font_id> \d+) #ix', $resource_definitions, $id_matches);
            preg_match_all('#/ToUnicode \s* (?P<cmap_id> \d+)#ix', $resource_definitions, $cmap_matches);

            $count = count($id_matches ['font_id']);

            for ($i = 0; $i < $count; $i++) {
                $font_id = $id_matches   ['font_id'] [$i];
                $cmap_id = $cmap_matches ['cmap_id'] [$i];

                $this->Fonts [$font_id] = new  PdfTexterFont($font_id, $cmap_id, PdfTexterFont::FONT_ENCODING_UNICODE_MAP, $extra_mappings);
            }

            return;
        } elseif (preg_match('#/(Base)?Encoding \s* /Identity-H#ix', $font_definition)) {
            // Experimental implementation of CID fonts
            if (preg_match('#/BaseFont \s* /(?P<font> [^\s/]+)#ix', $font_definition, $match)) {
                $font_variant = $match ['font'];
            }

            $font_type = PdfTexterFont::FONT_ENCODING_CID_IDENTITY_H;
        } elseif (preg_match('#/ToUnicode \s* (?P<cmap> \d+)#ix', $font_definition, $match)) {
            // Font has an associated Unicode map (using the /ToUnicode keyword)
            $cmap_id = $match ['cmap'];
            $font_type = PdfTexterFont::FONT_ENCODING_UNICODE_MAP;

            if (preg_match('#/Encoding \s* (?P<cmap> \d+)#ix', $font_definition, $secondary_match)) {
                $secondary_cmap_id = $secondary_match ['cmap'];
            }
        } elseif (preg_match('#/Encoding \s* (?P<cmap> \d+) \s+ \d+ #ix', $font_definition, $match)) {
            // Font has an associated character map (using a cmap id)
            $cmap_id = $match ['cmap'];
            $font_type = PdfTexterFont::FONT_ENCODING_PDF_MAP;
        } elseif (preg_match('#/(Base)?Encoding \s* /WinAnsiEncoding#ix', $font_definition)) {
            // Font uses the Windows Ansi encoding
            $font_type = PdfTexterFont::FONT_ENCODING_WINANSI;

            if (preg_match('# /BaseFont \s* / [a-z0-9_]+ \+ [a-z0-9_]+? Cyr #imsx', $font_definition)) {
                $font_type |= PdfTexterFont::FONT_VARIANT_ISO8859_5;
            }
        } elseif (preg_match('#/(Base)?Encoding \s* /MacRomanEncoding#ix', $font_definition)) {
            // Font uses the Mac Roman encoding
            $font_type = PdfTexterFont::FONT_ENCODING_MAC_ROMAN;
        }

        $this->Fonts [$object_id] = new  PdfTexterFont($object_id, $cmap_id, $font_type, $secondary_cmap_id, $pdf_objects, $extra_mappings, $font_variant);

        // Arbitrarily set the default font to the first font encountered in the pdf file
        if ($this->DefaultFont === false) {
            reset($this->Fonts);
            $this->DefaultFont = key($this->Fonts);
        }
    }


    // AddFontMap -
    //  Process things like :
    //      <</F1 26 0 R/F2 22 0 R/F3 18 0 R>>
    //  which maps font 1 (when specified with the /Fx instruction) to object 26,
    //  2 to object 22 and 3 to object 18, respectively, in the above example.
    //  Found also a strange way of specifying a font mapping :
    //      <</f-0-0 5 0 R etc.
    //  And yet another one :
    //      <</C0_0 5 0 R
    public function AddFontMap(/** @noinspection PhpUnusedParameterInspection */
        $object_id,
        $object_data
    ) {
        $object_data = self::UnescapeHexCharacters($object_data);

        // The same object can hold different notations for font associations
        /** @noinspection HtmlDeprecatedTag */
        if (preg_match_all('# (?P<font> ' . self::$FontSpecifiers . ' ) \s+ (?P<object> \d+) #imsx', $object_data, $matches)) {
            for ($i = 0, $count = count($matches ['font']); $i < $count; $i++) {
                $font = $matches ['font'] [$i];
                $object = $matches ['object'] [$i];

                $this->FontMap [$font] = $object;
            }
        }
    }


    // AddPageFontMap -
    //  Adds font aliases to the current font map, in the form : "page:xobject:font".
    //  The associated value is the font object itself.
    public function AddPageFontMap($map)
    {
        foreach ($map as $map_entry) {
            $this->FontMap [$map_entry ['page'] . ':' . $map_entry ['xobject-name'] . ':' . $map_entry ['font-name']] = $map_entry ['object'];
        }
    }


    // AddCharacterMap -
    //  Associates a character map to a font declaration that referenced it.
    public function AddCharacterMap($cmap)
    {
        $status = false;

        // We loop through all fonts, since the same character map can be referenced by several font definitions
        foreach ($this->Fonts as $font) {
            if ($font->CharacterMapId == $cmap->ObjectId) {
                $font->CharacterMap = $cmap;
                $status = true;
            } elseif ($font->SecondaryCharacterMapId == $cmap->ObjectId) {
                $cmap->Secondary = true;
                $font->SecondaryCharacterMap = $cmap;
                $status = true;
            }
        }

        return ($status);
    }


    // GetFontAttributes -
    //  Gets the specified font width in hex digits and whether the font has a character map or not.
    public function GetFontAttributes(/** @noinspection PhpUnusedParameterInspection */
        $page_number,
        $template,
        $font,
        &$font_map_width,
        &$font_mapped
    ) {
        // Font considered as global to the document
        if (isset($this->Fonts [$font])) {
            $key = $font;
        } else {
            // Font not found : try to use the first one declared in the document
            reset($this->Fonts);
            $key = key($this->Fonts);
        }

        // Font has an associated character map
        if ($key && $this->Fonts [$key]->CharacterMap) {
            $font_map_width = $this->Fonts [$key]->CharacterMap->HexCharWidth;
            $font_mapped = true;

            return (true);
        } else {
            // No character map : characters are specified as two hex digits
            $font_map_width = 2;
            $font_mapped = false;

            return (false);
        }
    }


    // GetFontByMapId -
    //  Returns the font id (object id) associated with the specified mapped id.
    public function GetFontByMapId($page_number, $template, $id)
    {
        if (isset($this->FontMap ["$page_number:$template:$id"])) {
            $font_object = $this->FontMap ["$page_number:$template:$id"];
        } elseif (isset($this->FontMap [$id])) {
            $font_object = $this->FontMap [$id];
        } else {
            $font_object = -1;
        }

        return ($font_object);
    }


    // GetFontObject -
    //  Returns the PdfTexterFont object for the given page, template and font id (in the form of "/something")
    public function GetFontObject($page_number, $template, $id)
    {
        if (isset($this->FontMap ["$page_number:$template:$id"])) {
            $font_object = $this->FontMap ["$page_number:$template:$id"];
        } elseif (isset($this->FontMap [$id])) {
            $font_object = $this->FontMap [$id];
        } else {
            return (false);
        }

        if (isset($this->Fonts [$font_object])) {
            return ($this->Fonts [$font_object]);
        } else {
            return (false);
        }
    }


    // MapCharacter -
    //  Returns the character associated to the specified one.
    public function MapCharacter($font, $ch, $return_false_on_failure = false)
    {
        if (isset($this->CharacterMapBuffer [$font] [$ch])) {
            return ($this->CharacterMapBuffer [$font] [$ch]);
        }

        // Use the first declared font as the default font, if none defined
        if ($font == -1) {
            $font = $this->DefaultFont;
        }

        $cache = true;

        if (isset($this->Fonts [$font])) {
            /** @var PdfTexterFont $font_object */
            $font_object = $this->Fonts [$font];

            $code = $font_object->MapCharacter($ch, $return_false_on_failure);

            if ($font_object->CharacterMap) {
                $cache = $font_object->CharacterMap->Cache;
            }
        } else {
            $code = $this->CodePointToUtf8($ch);
        }

        if ($cache) {
            $this->CharacterMapBuffer [$font] [$ch] = $code;
        }

        return ($code);
    }
}
