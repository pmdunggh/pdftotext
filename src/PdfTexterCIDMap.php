<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    PdfTexterCIDMap -
        A class for mapping (or trying to...) CID fonts.

  ==============================================================================================================*/

abstract class PdfTexterCIDMap extends PdfTexterCharacterMap
{
    // CID maps are associative arrays whose keys are the font CID (currently expressed as a numeric value) and
    // whose values are the corresponding UTF8 representation. The following special values can also be used to
    // initialize certain entries :
    // UNKNOWN_CID :
    //  Indicates that the corresponding CID has no known UTF8 counterpart. When the PdfToText::$DEBUG variable
    //  is true, every character in this case will be replaced with the string : "[UID: abcd]", where "abcd" is
    //  the hex representation of the CID. This way, new CID tables can be built using this information.
    const        UNKNOWN_CID = -1;
    // ALT_CID :
    //  Sorry, this will remain undocumented so far and will be highligh subject to change, since it is dating
    //  from my first interpretation of CID fonts, which is probably wrong.
    const        ALT_CID = -2;


    // CID font map file ; the file is a PHP script that must contain an array of the form :
    //  $map    =  array
    //     (
    //      'plain'     =>  array
    //         (
    //          $cid1   =>  $utf1,
    //          ...
    //          )
    //      ) ;
    protected $MapFile;
    // Map, loaded into memry
    protected $Map;
    // Map cache - the interest is to avoid unnecessary includes
    private static $CachedMaps = [];

    // Related to the first experimentatl implementation of CID fonts
    private $LastAltOffset = false;


    /*--------------------------------------------------------------------------------------------------------------

        Constructor -
        Loads the specified map.
        If the map files contains a definition such as :

            $map    =  'IDENTITY-H-GQJGLM.cid' ;

        then the specified map will be loaded instead (ony one ndirection is supported).

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($object_id, $map_name, $font_variant)
    {
        // Initialize parent objects
        parent::__construct($object_id);
        $this->HexCharWidth = 4;            // So far, CIDs are 2-bytes long

        // Since alternate characters can be apparently prefixed by 0x0000 or 0x0001, two calls to the array access operator
        // will be needed to retrieve the exact character in such cases
        // This is why we have to tell the upper layers not to cache the results
        $this->Cache = false;

        $map_index = "$map_name:$font_variant";

        // If this font has already been loaded somewhere, then reuse its information
        if (isset(self::$CachedMaps [$map_index])) {
            $map = self::$CachedMaps [$map_index] ['map'];
            $file = self::$CachedMaps [$map_index] ['file'];
        } else {
            // Otherwise,
            $file = $this->__get_cid_file($map_name, $font_variant);

            // No CID map found : CID numbers will be mapped as is
            if (!file_exists($file)) {
                if (PdfToText::$DEBUG) {
                    warning(new DecodingException("Could not find CID table \"$map_name\" in directory \"" . PdfToText::$CIDTablesDirectory . "\"."));
                }
            } else {
                // Otherwise, load the CID map
                /** @noinspection PhpIncludeInspection */
                include($file);

                if (isset($map)) {
                    // We authorize one CID map to contain the name of another CID map file, instead of the map itself
                    if (is_string($map)) {
                        $file = PdfToText::$CIDTablesDirectory . "/$map";
                        /** @noinspection PhpIncludeInspection */
                        include($file);
                    }

                    if (isset($map)) {
                        self::$CachedMaps [$map_index] = ['file' => $file, 'map' => $map];
                    }
                } elseif (PdfToText::$DEBUG) {
                    warning(new DecodingException("CID \"$file\" does not contain any definition."));
                }
            }
        }

        // Save map info for this CID font
        $this->MapFile = $file;
        $this->Map = (isset($map)) ? $map : [];
    }


    /*--------------------------------------------------------------------------------------------------------------

        __get_cid_file -
        Searches in the CIDTables directory for the CID map that best matches the specified map name (usually,
        IDENTITY-H) and the optional font variant.

        If a font variant has been specified, like "ABCD+Italic-Arial", then the CID tables directory will be
        searched for the following files, in the following order :
        - IDENTITY-H-ABCD+Italic-Arial.cid
        - IDENTITY-H-ABCD+Italic.cid
        - IDENTITY-H-ABCD.cid
        - If none found, then IDENTITY-H-empty.cid will be used and a warning will be issued in debug mode.

     *-------------------------------------------------------------------------------------------------------------*/
    private function __get_cid_file($map_name, $font_variant)
    {
        $files = [];

        // Search for font variants, if any
        if ($font_variant) {
            if (preg_match('/^ (?P<name> [a-z_][a-z_0-9]*) (?P<rest> [\-+] .*) $/imsx', $font_variant, $match)) {
                $basename = '-' . $match ['name'];

                if (preg_match_all('/ (?P<sep> [\-+]) (?P<name> [^\-+]+) /ix', $match ['rest'], $other_matches)) {
                    for ($i = count($other_matches ['name']) - 1; $i >= 0; $i--) {
                        $new_file = $basename;

                        for ($j = 0; $j < $i; $j++) {
                            $new_file .= $other_matches ['sep'] [$i] . $other_matches ['name'] [$i];
                        }

                        $files [] = [PdfToText::$CIDTablesDirectory . "/$map_name$new_file.cid", 'standard'];
                    }
                }
            }

            // Last one will be the empty CID font
            $files [] = [PdfToText::$CIDTablesDirectory . "/IDENTITY-H-empty.cid", 'empty'];
        }

        // Add the specified map file
        $files [] = [PdfToText::$CIDTablesDirectory . "/$map_name.cid", 'default'];

        // The first existing file in the list should be the appropriate one
        foreach ($files as $file) {
            if (file_exists($file [0])) {
                if (PdfToText::$DEBUG) {
                    if ($file [1] === 'empty') {
                        warning(new DecodingException("Using empty IDENTITY-H definition for map \"$map_name\", variant \"$font_variant\"."));
                    } elseif ($file [1] === 'default') {
                        warning(new DecodingException("Using default IDENTITY-H definition for map \"$map_name\"."));
                    }
                }

                return ($file [0]);
            }
        }

        // No CID font found
        return (false);
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
        return (isset($this->Map ['plain'] [$offset]));
    }


    public function offsetGet($offset)
    {
        if (isset($this->Map ['plain'] [$offset])) {
            $ch = $this->Map ['plain'] [$offset];

            switch ($ch) {
                case    self::UNKNOWN_CID :
                    if (PdfToText::$DEBUG) {
                        echo('[UID:' . sprintf('%04x', $offset) . "]");
                    }

                    $this->LastAltOffset = false;

                    if (!PdfToText::$DEBUG) {
                        return ('');
                    } else {
                        return ('[UID:' . sprintf('%04x', $offset) . "]");
                    }

                case    self::ALT_CID :
                    $this->LastAltOffset = ( integer )$offset;

                    return ('');

                default :
                    if ($this->LastAltOffset === false) {
                        return ($ch);
                    }

                    if (isset($this->Map ['alt'] [$this->LastAltOffset] [$offset])) {
                        $ch2 = $this->Map ['alt'] [$this->LastAltOffset] [$offset];

                        if ($ch2 == self::UNKNOWN_CID) {
                            if (PdfToText::$DEBUG) {
                                echo("[CID{$this -> LastAltOffset}:" . sprintf('%04x', $offset) . "]");

                                $ch2 = "[CID{$this -> LastAltOffset}: $offset]";
                            }
                        }
                    } else {
                        $ch2 = '';
                    }

                    $this->LastAltOffset = false;

                    return ($ch2);
            }
        } else {
            $this->LastAltOffset = false;

            return ('');
        }
    }
}
