<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    PdfTexterUnicodeMap -
        A class for fonts having a character map specified with the /ToUnicode parameter.

  ==============================================================================================================*/

class PdfTexterUnicodeMap extends PdfTexterCharacterMap
{
    // Id of the character map (specified by the /Rx flag)
    public $Id;
    // Character substitution table, using the beginbfrange/endbfrange notation
    // Only constructs of the form :
    //  <low> <high> <start>
    // are stored in this table. Constructs of the form :
    //  <x> <y> [ <subst_x> <subst_x+1> ... <subst_y> ]
    // are stored in the $DirectMap array, because it is conceptually the same thing in the end as a character substitution being
    // defined with the beginbfchar/endbfchar construct.
    // Note that a dichotomic search in $RangeMap will be performed for each character reference not yet seen in the pdf flow.
    // Once the substitution character has been found, it will be added to the $DirectMap array for later faster access.
    // The reason for this optimization is that some pdf files can contain beginbfrange/endbfrange constructs that may seem useless,
    // except for validation purposes (ie, validating the fact that a character reference really belongs to the character map).
    // However, such constructs can lead to thousands of character substitutions ; consider the following example, that comes
    // from a sample I received :
    //  beginbfrange
    //  <1000> <1FFFF> <1000>
    //  <2000> <2FFFF> <2000>
    //  ...
    //  <A000> <AFFFF> <A0000>
    //  ...
    //  endbfrange
    // By naively storing a one-to-one character relationship in an associative array, such as :
    //  $array [ 0x1000 ] = 0x1000 ;
    //  $array [ 0x1001 ] = 0x1001 ;
    //  ..
    //  $array [ 0x1FFF ] = 0x1FFF ;
    //  etc.
    // you may arrive to a situation where the array becomes so big that it exhausts all of the available memory.
    // This is why the ranges are stored as is and a dichotomic search is performed to go faster.
    // Since it is useless to use this method to search the same character twice, when it has been found once, the
    // substitution pair will be put in the $DirectMap array for subsequent accesses (there is little probability that a PDF
    // file contains so much different characters, unless you are processing the whole Unicode table itself ! - but in this
    // case, you will simply have to adjust the value of the memory_limit setting in your php.ini file. Consider that I am
    // not a magician...).
    protected $RangeMap = [];
    private $RangeCount = 0;                // Avoid unnecessary calls to the count() function
    private $RangeMin = PHP_INT_MAX,            // Min and max values of the character ranges
        $RangeMax = -1;
    // Character substitution table for tables using the beginbfchar notation
    protected $DirectMap = [];


    // Constructor -
    //  Analyzes the text contents of a CMAP and extracts mappings from the beginbfchar/endbfchar and
    //  beginbfrange/endbfrange constructs.
    public function __construct($object_id, $definitions)
    {
        parent::__construct($object_id);

        if (PdfToText::$DEBUG) {
            echo "\n----------------------------------- UNICODE CMAP #$object_id\n";
            echo $definitions;
        }

        // Retrieve the cmap id, if any
        preg_match('# /CMapName \s* /R (?P<num> \d+) #ix', $definitions, $match);
        $this->Id = isset($match ['num']) ? $match ['num'] : -1;

        // Get the codespace range, which will give us the width of a character specified in hexadecimal notation
        preg_match('# begincodespacerange \s+ <\s* (?P<low> [0-9a-f]+) \s*> \s* <\s* (?P<high> [0-9a-f]+) \s*> \s*endcodespacerange #ix', $definitions, $match);

        if (isset($match ['low'])) {
            $this->HexCharWidth = max(strlen($match ['low']), strlen($match ['high']));
        } else {
            $this->HexCharWidth = 0;
        }

        $max_found_char_width = 0;

        // Process beginbfchar/endbfchar constructs
        if (preg_match_all('/ beginbfchar \s* (?P<chars> .*?) endbfchar /imsx', $definitions, $char_matches)) {
            foreach ($char_matches ['chars'] as $char_list) {
                // beginbfchar / endbfchar constructs can behave as a kind of beginfbfrange/endbfrange ; example :
                //  <21> <0009 0020 000d>
                // means :
                //  . Map character #21 to #0009
                //  . Map character #22 to #0020
                //  . Map character #23 to #000D
                // There is no clue in the Adobe PDF specification that a single character could be mapped to a range.
                // The normal constructs would be :
                //  <21> <0009>
                //  <22> <0020>
                //  <23> <0000D>
                preg_match_all('/< \s* (?P<item> .*?) \s* >/msx', $char_list, $item_matches);

                for ($i = 0, $item_count = count($item_matches ['item']); $i < $item_count; $i += 2) {
                    $char = hexdec($item_matches ['item'] [$i]);
                    $char_width = strlen($item_matches ['item'] [$i]);
                    $map = explode(' ', preg_replace('/\s+/', ' ', $item_matches ['item'] [$i + 1]));

                    if ($char_width > $max_found_char_width) {
                        $max_found_char_width = $char_width;
                    }

                    for ($j = 0, $map_count = count($map); $j < $map_count; $j++) {
                        $subst = hexdec($map [$j]);

                        // Check for this very special, not really document feature which maps CIDs to a non-existing Unicode character
                        // (but it still corresponds to something...)
                        if (isset(PdfTexterAdobeUndocumentedUnicodeMap::$UnicodeMap [$subst])) {
                            $subst = PdfTexterAdobeUndocumentedUnicodeMap::$UnicodeMap [$subst];
                        }

                        $this->DirectMap [$char + $j] = $subst;
                    }
                }
            }
        }

        // Process beginbfrange/endbfrange constructs
        if (preg_match_all('/ beginbfrange \s* (?P<ranges> .*?) endbfrange /imsx', $definitions, $range_matches)) {
            foreach ($range_matches ['ranges'] as $range_list) {
                $start_index = 0;

                // There are two forms of syntax in a beginbfrange..endbfrange construct
                // 1) "<x> <y> <z>", which maps character ids x through y to z through (z+y-x)
                // 2) "<x> <y> [<a1> <a2> ... <an>]", which maps character x to a1, x+1 to a2, up to y, which is mapped to an
                // All the values are hex digits.
                // We will loop through the range definitions by first identifying the <x> and <y>, and the character that follows
                // them, which is either a "<" for notation 1), or a "[" for notation 2).
                while (preg_match(
                    '#  < \s* (?P<from> [0-9a-f]+) \s* > \s* < \s* (?P<to> [0-9a-f]+) \s* > \s* (?P<nextchar> .) #imsx',
                    $range_list,
                    $range_match,
                    PREG_OFFSET_CAPTURE,
                    $start_index
                )) {
                    $from = hexdec($range_match ['from'] [0]);
                    $to = hexdec($range_match ['to'] [0]);
                    $next_char = $range_match ['nextchar'] [0];
                    $next_char_index = $range_match ['nextchar'] [1];
                    $char_width = strlen($range_match ['from'] [0]);

                    if ($char_width > $max_found_char_width) {
                        $max_found_char_width = $char_width;
                    }

                    // Form 1) : catch the third hex value after <x> and <y>
                    if ($next_char == '<') {
                        if (preg_match('/ \s* (?P<start> [0-9a-f]+) (?P<tail> \s* > \s*) /imsx', $range_list, $start_match, PREG_OFFSET_CAPTURE, $next_char_index + 1)) {
                            $subst = hexdec($start_match ['start'] [0]);

                            // Check for this very special, not really document feature which maps CIDs to a non-existing Unicode character
                            // (but it still corresponds to something...)
                            if (isset(PdfTexterAdobeUndocumentedUnicodeMap::$UnicodeMap [$subst])) {
                                $subst = PdfTexterAdobeUndocumentedUnicodeMap::$UnicodeMap [$subst];
                            }

                            // Don't create a range if <x> and <y> are the same
                            if ($from != $to) {
                                $this->RangeMap [] = [$from, $to, $subst];

                                // Adjust min and max values for the ranges stored in this character map - to avoid unnecessary testing
                                if ($from < $this->RangeMin) {
                                    $this->RangeMin = $from;
                                }

                                if ($to > $this->RangeMax) {
                                    $this->RangeMax = $to;
                                }
                            } else {
                                $this->DirectMap [$from] = $subst;
                            }

                            $start_index = $start_match ['tail'] [1] + 1;
                        } else {
                            error("Character range $from..$to not followed by an hexadecimal value in Unicode map #$object_id.");
                        }
                    } elseif ($next_char == '[') {
                        // Form 2) : catch all the hex values between square brackets after <x> and <y>
                        if (preg_match('/ (?P<values> [\s<>0-9a-f]+ ) (?P<tail> \] \s*)/imsx', $range_list, $array_match, PREG_OFFSET_CAPTURE, $next_char_index + 1)) {
                            preg_match_all('/ < \s* (?P<num> [0-9a-f]+) \s* > /imsx', $array_match ['values'] [0], $array_values);

                            for ($i = $from, $count = 0; $i <= $to; $i++, $count++) {
                                $this->DirectMap [$i] = hexdec($array_values ['num'] [$count]);
                            }

                            $start_index = $array_match ['tail'] [1] + 1;
                        } else {
                            error("Character range $from..$to not followed by an array of hexadecimal values in Unicode map #$object_id.");
                        }
                    } else {
                        error("Unexpected character '$next_char' in Unicode map #$object_id.");
                        $start_index = $range_match ['nextchar'] [1] + 1;
                    }
                }
            }

            // Sort the ranges by their starting offsets
            $this->RangeCount = count($this->RangeMap);

            if ($this->RangeCount > 1) {
                usort($this->RangeMap, [$this, '__rangemap_cmpfunc']);
            }
        }

        if ($max_found_char_width && $max_found_char_width != $this->HexCharWidth) {
            if (PdfToText::$DEBUG) {
                warning("Character map #$object_id : specified code width ({$this -> HexCharWidth}) differs from actual width ($max_found_char_width).");
            }

            $this->HexCharWidth = $max_found_char_width;
        }
    }


    public function __rangemap_cmpfunc($a, $b)
    {
        return ($a [0] - $b [0]);
    }


    /*--------------------------------------------------------------------------------------------------------------

            Interface implementations.

     *-------------------------------------------------------------------------------------------------------------*/
    public function count(): int
    {
        return (count($this->DirectMap));
    }


    public function offsetExists($offset): bool
    {
        return ($this->offsetGetSafe($offset) !== false);
    }


    public function offsetGetSafe($offset, $translate = true)
    {
        // Return value
        $code = false;

        // Character already has an entry (character reference => subtituted character)
        if (isset($this->DirectMap [$offset])) {
            $code = ($translate) ? $this->CodePointToUtf8($this->DirectMap [$offset]) : $this->DirectMap [$offset];
        } elseif ($this->RangeCount && $offset >= $this->RangeMin && $offset <= $this->RangeMax) {
            // Character does not has a direct entry ; have a look in the character ranges defined for this map
            $low = 0;
            $high = count($this->RangeMap) - 1;
            $result = false;

            // Use a dichotomic search through character ranges
            while ($low <= $high) {
                $middle = ($low + $high) >> 1;

                if ($offset < $this->RangeMap [$middle] [0]) {
                    $high = $middle - 1;
                } elseif ($offset > $this->RangeMap [$middle] [1]) {
                    $low = $middle + 1;
                } else {
                    $result = $this->RangeMap [$middle] [2] + $offset - $this->RangeMap [$middle] [0];
                    break;
                }
            }

            // Once a character has been found in the ranges defined by this character map, store it in the DirectMap property
            // so that it will be directly retrieved during subsequent accesses
            if ($result !== false) {
                $code = ($translate) ? $this->CodePointToUtf8($result) : $result;
                $this->DirectMap [$offset] = $result;
            }
        }

        // All done, return
        return ($code);
    }


    public function offsetGet($offset): mixed
    {
        $code = $this->offsetGetSafe($offset);

        if ($code === false) {
            $code = $this->CodePointToUtf8($offset);
        }

        return ($code);
    }
}
