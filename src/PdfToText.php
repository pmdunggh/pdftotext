<?php
namespace VanXuan\PdfToText;

/**
 * Custom error reporting functions.
 **/
if (!function_exists('warning')) {
    function warning($message)
    {
        trigger_error($message, E_USER_WARNING);
    }
}

if (!function_exists('error')) {
    function error($message)
    {
        if (is_string($message)) {
            trigger_error($message, E_USER_ERROR);
        } elseif (is_a($message, '\Exception')) {
            throw $message;
        }
    }
}

/**
 * Backward-compatibility issues.
 **/

// hex2bin - This function appeared only in version 5.4.0
if (!function_exists('hex2bin')) {
    function hex2bin($hexstring)
    {
        $length = strlen($hexstring);
        $binstring = '';
        $index = 0;

        while ($index < $length) {
            $byte = substr($hexstring, $index, 2);
            $ch = pack('H*', $byte);
            $binstring .= $ch;

            $index += 2;
        }

        return ($binstring);
    }
}


/*==============================================================================================================

    PdfToText class -
    A class for extracting text from Pdf files.

 ==============================================================================================================*/

class PdfToText extends PdfObjectBase
{
    // Current version of the class
    const        VERSION = "1.6.7";

    // Pdf processing options
    const        PDFOPT_NONE = 0x00000000;        // No extra option
    const        PDFOPT_REPEAT_SEPARATOR = 0x00000001;        // Repeats the Separator property if the offset between two text blocks (in array notation)
    // is greater than $this -> MinSpaceWidth
    const        PDFOPT_GET_IMAGE_DATA = 0x00000002;        // Retrieve raw image data in the $ths -> ImageData array
    const        PDFOPT_DECODE_IMAGE_DATA = 0x00000004;        // Creates a jpeg resource for each image
    const        PDFOPT_IGNORE_TEXT_LEADING = 0x00000008;        // Ignore text leading values
    const        PDFOPT_NO_HYPHENATED_WORDS = 0x00000010;        // Join hyphenated words that are split on two lines
    const        PDFOPT_AUTOSAVE_IMAGES = 0x00000020;        // Autosave images ; the ImageFileTemplate property will need to be defined
    const        PDFOPT_ENFORCE_EXECUTION_TIME = 0x00000040;        // Enforces the max_execution_time PHP setting when processing a file. A PdfTexterTimeoutException
    // will be thrown if processing of a single file reaches (time_limit - 1 second) by default
    // The MaxExecutionTime property can be set to modify this default value.
    const        PDFOPT_ENFORCE_GLOBAL_EXECUTION_TIME = 0x00000080;        // Same as PDFOPT_ENFORCE_EXECUTION_TIME, but for all calls to the Load() method of the PdfToText class
    // The MaxGlobalExecutionTime static property can be set to modify the default time limit
    const        PDFOPT_IGNORE_HEADERS_AND_FOOTERS = 0x00000300;        // Ignore headers and footers

    const        PDFOPT_RAW_LAYOUT = 0x00000000;        // Layout rendering : raw (default)
    const        PDFOPT_BASIC_LAYOUT = 0x00000400;        // Layout rendering : basic

    const        PDFOPT_LAYOUT_MASK = 0x00000C00;        // Mask to isolate the targeted layout

    const        PDFOPT_ENHANCED_STATISTICS = 0x00001000;        // Compute statistics on PDF language instructions
    const        PDFOPT_DEBUG_SHOW_COORDINATES = 0x00002000;        // Include text coordinates ; implies the PDFOPT_BASIC_LAYOUT option
    // This option can be useful if you want to use capture areas and get information about
    // their coordinates
    const        PDFOPT_CAPTURE = 0x00004000;        // Indicates that the caller wants to capture some text and use the SetCaptures() method
    // It currently enables the PDFOPT_BASIC_LAYOUT option
    const        PDFOPT_LOOSE_X_CAPTURE = 0x00008000;        // Includes in captures text fragments whose dimensions may exceed the captured area dimensions
    const        PDFOPT_LOOSE_Y_CAPTURE = 0x00010000;        // (currently not used)

    // When boolean true, outputs debug information about fonts, character maps and drawing contents.
    // When integer > 1, outputs additional information about other objects.
    public static $DEBUG = false;

    // Current filename
    public $Filename = false;
    public $PdfVersion;
    public $FomDataDefinitions;
    public $FormDataObjects;
    // Extracted text
    public $Text = '';
    // Document pages (array of strings)
    public $Pages = [];
    // Document images (array of PdfImage objects)
    /** @var PdfImage[] */
    public $Images = [];
    protected $ImageCount = 0;
    // Raw data for document images
    public $ImageData = [];
    // ImageAutoSaveFileTemplate :
    //  Template for the file names to be generated when extracting images, if the PDFOPT_AUTOSAVE_IMAGES has been specified.
    //  Can contain any path, plus the following printf()-like modifiers :
    //  . "%p" : Path of the original PDF file.
    //  . "%f" : Filename part of the original PDF file.
    //  . "%d" : A sequential number, starting from 1, used when generating filenames. The format can contains a width specifier,
    //       such as "%3d", which will generate 3-digits sequential numbers left-filled with zeroes.
    //  . "%s" : Image suffix, which will automatically based on the underlying image type.
    public $ImageAutoSaveFileTemplate = "%p/%f.%d.%s";
    // Auto-save image file format
    public $ImageAutoSaveFormat = IMG_JPEG;
    // Auto-saved image file names
    public $AutoSavedImageFiles = [];
    // Text chunk separator (used to separate blocks of text specified as an array notation)
    public $BlockSeparator = '';
    // Separator used to separate text groups where the offset value is less than -1000 thousands of character units
    // (eg : [(1)-1822(2)] will add a separator between the characters "1" and "2")
    // Note that such values are expressed in thousands of text units and subtracted from the current position. A
    // negative value means adding more space between the two text units it separates.
    public $Separator = ' ';
    // Separator to be used between pages in the $Text property
    public $PageSeparator = "\n";
    // Minimum value (in 1/1000 of text units) that separates two text chunks that can be considered as a real space
    public $MinSpaceWidth = 200;
    // Pdf options
    public $Options = self::PDFOPT_NONE;
    // Maximum number of pages to extract from the PDF. A zero value means "extract everything"
    // If this number is negative, then the pages to be extract start from the last page. For example, a value of -2
    // extracts the last two pages
    public $MaxSelectedPages = false;
    // Maximum number of images to be extracted. A value of zero means "extract everything". A non-zero value gives
    // the number of images to extract.
    public $MaxExtractedImages = false;
    // Location of the CID tables directory
    public static $CIDTablesDirectory;
    // Loacation of the Font metrics directory, for the Adobe standard 14 fonts
    public static $FontMetricsDirectory;
    // Standard Adobe font names, and their corresponding file in $FontMetricsDirectory
    public static $AdobeStandardFontMetrics =
    [
        'courier' => 'courier.fm',
        'courier-bold' => 'courierb.fm',
        'courier-oblique' => 'courieri.fm',
        'courier-boldoblique' => 'courierbi.fm',
        'helvetica' => 'helvetica.fm',
        'helvetica-bold' => 'helveticab.fm',
        'helvetica-oblique' => 'helveticai.fm',
        'helvetica-boldoblique' => 'helveticabi.fm',
        'symbol' => 'symbol.fm',
        'times-roman' => 'times.fm',
        'times-bold' => 'timesb.fm',
        'times-bolditalic' => 'timesbi.fm',
        'times-italic' => 'timesi.fm',
        'zapfdingbats' => 'zapfdingbats.fm'
    ];
    // Author information
    public $Author = '';
    public $CreatorApplication = '';
    public $ProducerApplication = '';
    public $CreationDate = '';
    public $ModificationDate = '';
    public $Title = '';
    public $Subject = '';
    public $Keywords = '';
    protected $GotAuthorInformation = false;
    // Unique and arbitrary file identifier, as specified in the PDF file
    // Well, in fact, there are two IDs, but the PDF specification does not mention the goal of the second one
    public $ID = '';
    public $ID2 = '';
    // End of line string
    public $EOL = PHP_EOL;
    // String to be used when no Unicode translation is possible
    public static $Utf8Placeholder = '';
    // Information about memory consumption implied by the file currently being loaded
    public $MemoryUsage,
        $MemoryPeakUsage;
    // Offset of the document start (%PDF-x.y)
    public $DocumentStartOffset;
    // Debug statistics
    public $Statistics = [];
    // Max execution time settings. A positive value means "don't exceed that number of seconds".
    // A negative value means "Don't exceed PHP setting max_execution_time - that number of seconds". If the result
    // is negative, then the default will be "max_execution_time - 1".
    // For those limits to be enforced, you need to specify either the PDFOPT_ENFORCE_EXECUTION_TIME or
    // PDFOPT_ENFORCE_GLOBAL_EXECUTION_TIME options, or both
    public $MaxExecutionTime = -1;
    public static $MaxGlobalExecutionTime = -1;
    // This property is expressed in percents ; it gives the extra percentage to add to the values computed by
    // the PdfTexterFont::GetStringWidth() method.
    // This is basically used when computing text positions and string lengths with the PDFOPT_BASIC_LAYOUT option :
    // the computed string length is shorter than its actual length (because of extra spacing determined by character
    // kerning in the font data). To determine whether two consecutive blocks of text should be separated by a space,
    // we empirically add this extra percentage to the computed string length. The default is -5%.
    public $ExtraTextWidth = -5;

    // Marker stuff. The unprocessed marker list is a sequential array of markers, which will later be dispatched into
    // indexed arrays during their first reference
    protected $UnprocessedMarkerList = ['font' => []];
    protected $TextWithFontMarkers = [];

    // Internal variables used when the PDFOPT_ENFORCE_* options are specified
    protected static $PhpMaxExecutionTime;
    protected static $GlobalExecutionStartTime;
    protected static $AllowedGlobalExecutionTime;
    protected $ExecutionStartTime;
    protected $AllowedExecutionTime;

    // Font mappings
    /** @var PdfTexterFontTable */
    protected $FontTable = false;
    // Extra Adobe standard font mappings (for character names of the form "/axxx" for example)
    protected $AdobeExtraMappings = [];
    // Page map object
    /** @var  PdfTexterPageMap */
    protected $PageMap;
    // Page locations (start and end offsets)
    protected $PageLocations;
    // Encryption data
    public $IsEncrypted = false;
    /** @var PdfEncryptionData */
    protected $EncryptionData = false;
    // A flag coming from the constructor options, telling if enhanced statistics are enabled
    protected $EnhancedStatistics;

    // Document text fragments, with their absolute (x,y) position, approximate width and height
    protected $DocumentFragments;

    // Form data
    protected $FormData;
    protected $FormDataObjectNumbers;
    protected $FormDataDefinitions;
    protected $FormaDataObjects;

    // Capture data
    /** @var  CaptureDefinitions */
    public $CaptureDefinitions;
    /** @var  Captures */
    protected $CaptureObject;

    // Indicates whether global static initializations have been made
    // This is mainly used for variables such as $Utf8PlaceHolder, which is initialized to a different value
    private static $StaticInitialized = false;

    // Drawing instructions that are to be ignored and removed from a text stream before processing, for performance
    // reasons (it is faster to call preg_replace() once to remove them than calling the __next_instruction() and
    // __next_token() methods to process an input stream containing such useless instructions)
    // This is an array of regular expressions where the following constructs are replaced at runtime during static
    // initialization :
    // %n - Will be replaced with a regex matching a decimal number.
    private static $IgnoredInstructionTemplatesLayout =
    [
        '%n{6} ( (c) ) \s+',
        '%n{4} ( (re) | (y) | (v) | (k) | (K) ) \s+',
        '%n{3} ( (scn) | (SCN) | (r) | (rg) | (RG) | (sc) | (SC) ) \s+',
        '%n{2} ( (m) | (l) ) \s+',
        '%n ( (w) | (M) | (g) | (G) | (J) | (j) | (d) | (i) | (sc) | (SC) | (Tc) | (Tw) | (scn) | (Tr) | (Tz) | (Ts) ) \s+',
        '\b ( (BDC) | (EMC) ) \s+',
        '\/( (Cs \d+) | (CS \d+) | (G[Ss] \d+) | (Fm \d+) | (Im \d+) | (PlacedGraphic) ) \s+ \w+ \s*',
        '\/( (Span) | (Artifact) | (Figure) | (P) ) \s* << .*? >> [ \t\r\n>]*',
        '\/ ( (PlacedGraphic) | (Artifact) ) \s+',
        '\d+ \s+ ( (scn) | (SCN) )',
        '\/MC \d+ \s+',
        '^ \s* [fhS] \r? \n',
        '^W \s+ n \r? \n',
        '(f | W) \* \s+',
        '^[fhnS] \s+',
        '-?0 (\. \d+)? \s+ T[cw]',
        '\bBI \s+ .*? \bID \s+ .*? \bEI',
        '\/ \w+ \s+ ( (cs) | (CS) | (ri) | (gs) )',
        // Hazardous replaces ?
        '( [Ww] \s+ ){3,}',
        ' \[\] \s+ [Shs] \s+'
    ];
    // Additional instructions to be stripped when no particular page layout has been requested
    private static $IgnoredInstructionTemplatesNoLayout =
    [
        '%n{6} ( (cm) ) \s+',
    //      '\b ( (BT) | (ET) ) \s+',
        '^ \s* [Qq] \r? \n',
        '^ \s* (\b [a-zA-Z] \s+)+',
        '\s* (\b [a-zA-Z] \s+)+$',
        '^[qQ] \s+',
        '^q \s+ [hfS] \n',
        '( [Qfhnq] \s+ ){2,}'
    ];
    // Replacement regular expressions for %something constructs specified in the $IgnoredInstructions array
    private static $ReplacementConstructs =
    [
        '%n' => '( [+\-]? ( ( [0-9]+ ( \. [0-9]* )? ) | ( \. [0-9]+ ) ) \s+ )'
    ];
    // The final regexes that are built during static initialization by the __build_ignored_instructions() method
    private static $IgnoredInstructionsNoLayout = [];
    private static $IgnoredInstructionsLayout = [];
    private $IgnoredInstructions = [];

    // Map id buffer - for avoiding unneccesary calls to GetFontByMapId
    private $MapIdBuffer = [];

    // Same for MapCharacter()
    private $CharacterMapBuffer = [];

    // Font objects buffer - used by __assemble_text_fragments()
    private $FontObjectsBuffer = [];

    // Regex used for removing hyphens - we have to take care of different line endings : "\n" for Unix, "\r\n"
    // for Windows, and "\r" for pure Mac files.
    // Note that we replace an hyphen followed by an end-of-line then by non-space characters with the non-space
    // characters, so the word gets joined on the same line. Spaces after the end of the word (on the next line)
    // are removed, in order for the next word to appear at the beginning of the second line.
    private static $RemoveHyphensRegex = '#
									(
										  -
										  [ \t]* ( (\r\n) | \n | \r )+ [ \t\r\n]*
									 )
									([^ \t\r\n]+)
									\s*
								    #msx';

    // A small list of Unicode character ranges that are related to languages written from right to left
    // For performance reasons, everythings is mapped to a range here, even if it includes codepoints that do not map to anything
    // (this class is not a Unicode codepoint validator, but a Pdf text extractor...)
    // The UTF-16 version is given as comments ; only the UTF-8 translation is used here
    // To be completed !
    private static $RtlCharacters =
    [
        // This range represents the following languages :
        // - Hebrew         (0590..05FF)
        // - Arabic         (0600..06FF)
        // - Syriac         (0700..074F)
        // - Supplement for Arabic  (0750..077F)
        // - Thaana         (0780..07BF)
        // - N'ko           (07C0..07FF)
        // - Samaritan          (0800..083F)
        // - Mandaic            (0840..085F)
        //  array ( 0x00590, 0x0085F ),
        // Hebrew supplement (I suppose ?) + other characters
        //  array ( 0x0FB1D, 0x0FEFC ),
        // Mende kikakui
        //  array ( 0x1E800, 0x1E8DF ),
        // Adlam
        //  array ( 0x1E900, 0x1E95F ),
        // Others
        //   array ( 0x10800, 0x10C48 ),
        //   array ( 0x1EE00, 0x1EEBB )
        "\xD6" => [["\x90", "\xBF"]],
        "\xD7" => [["\x80", "\xBF"]],
        "\xD8" => [["\x80", "\xBF"]],
        "\xD9" => [["\x80", "\xBF"]],
        "\xDA" => [["\x80", "\xBF"]],
        "\xDB" => [["\x80", "\xBF"]],
        "\xDC" => [["\x80", "\xBF"]],
        "\xDD" => [["\x80", "\xBF"]],
        "\xDE" => [["\x80", "\xBF"]],
        "\xDF" => [["\x80", "\xBF"]]
        /*
        "\xE0"      =>  array
           (
            array ( "\xA0\x80", "\xA0\xBF" ),
            array ( "\xA1\x80", "\xA1\x9F" )
            ),
        "\xEF"      =>  array
           (
            array ( "\xAC\x9D", "\xAC\xBF" ),
            array ( "\xAD\x80", "\xAD\xBF" ),
            array ( "\xAE\x80", "\xAE\xBF" ),
            array ( "\xAF\x80", "\xAF\xBF" ),
            array ( "\xB0\x80", "\xB0\xBF" ),
            array ( "\xB1\x80", "\xB1\xBF" ),
            array ( "\xB2\x80", "\xB2\xBF" ),
            array ( "\xB3\x80", "\xB3\xBF" ),
            array ( "\xB4\x80", "\xB4\xBF" ),
            array ( "\xB5\x80", "\xB5\xBF" ),
            array ( "\xB6\x80", "\xB6\xBF" ),
            array ( "\xB7\x80", "\xB7\xBF" ),
            array ( "\xB8\x80", "\xB8\xBF" ),
            array ( "\xB9\x80", "\xB9\xBF" ),
            array ( "\xBA\x80", "\xBA\xBF" ),
            array ( "\xBB\x80", "\xBB\xBC" )
            )
            */
    ];

    // UTF-8 prefixes for RTL characters as keys, and number of characters that must follow the prefix as values
    private static $RtlCharacterPrefixLengths =
    [
        "\xD6" => 1,
        "\xD7" => 1,
        "\xD8" => 1,
        "\xD9" => 1,
        "\xDA" => 1,
        "\xDB" => 1,
        "\xDC" => 1,
        "\xDE" => 1,
        "\xDF" => 1
        /*
        "\xE0"      =>  2,
        "\xEF"      =>  2
        */
    ];

    // A string that contains all the RTL character prefixes above
    private static $RtlCharacterPrefixes;

    // As usual, caching a little bit the results of the IsRtlCharacter() method is welcome. Each item will have the value true if the
    // character is RTL, or false if LTR.
    private $RtlCharacterBuffer = [];

    // A subset of a character classification array that avoids too many calls to the ctype_* functions or too many
    // character comparisons.
    // This array is used only for highly sollicited parts of code
    const    CTYPE_ALPHA = 0x01;        // Letter
    const    CTYPE_DIGIT = 0x02;        // Digit
    const    CTYPE_XDIGIT = 0x04;        // Hex digit
    const    CTYPE_ALNUM = 0x08;        // Letter or digit
    const    CTYPE_LOWER = 0x10;        // Lower- or upper-case letters
    const    CTYPE_UPPER = 0x20;

    private static $CharacterClasses = false;

    // Stuff specific to the current PHP version
    private static $HasMemoryGetUsage;
    private static $HasMemoryGetPeakUsage;


    /*--------------------------------------------------------------------------------------------------------------

        CONSTRUCTOR
            $pdf    =  new PdfToText ( $filename = null, $options = PDFOPT_NONE ) ;

        DESCRIPTION
            Builds a PdfToText object and optionally loads the specified file's contents.

        PARAMETERS
            $filename (string) -
                    Optional PDF filename whose text contents are to be extracted.

        $options (integer) -
            A combination of PDFOPT_* flags. This can be any of the following :

            - PDFOPT_REPEAT_SEPARATOR :
                Text constructs specified as an array are separated by an offset which is expressed as
                thousands of text units ; for example :

                    [(1)-2000(2)]

                will be rendered as the text "1  2" ("1" and "2" being separated by two spaces) if the
                "Separator" property is set to a space (the default) and this flag is specified.
                When not specified, the text will be rendered as "1 2".

            - PDFOPT_NONE :
                None of the above options will apply.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($filename = null, $options = self::PDFOPT_NONE, $user_password = false, $owner_password = false)
    {
        // We need the mbstring PHP extension here...
        if (!function_exists('mb_convert_encoding')) {
            error("You must enable the mbstring PHP extension to use this class.");
        }

        // Perform static initializations if needed
        if (!self::$StaticInitialized) {
            if (self::$DEBUG) {
                // In debug mode, initialize the utf8 placeholder only if it still set to its default value, the empty string
                if (self::$Utf8Placeholder == '') {
                    self::$Utf8Placeholder = '[Unknown character : 0x%08X]';
                }
            }

            // Build the list of regular expressions from the list of ignored instruction templates
            self::__build_ignored_instructions();

            // Check if some functions are supported or not
            self::$HasMemoryGetUsage = function_exists('memory_get_usage');
            self::$HasMemoryGetPeakUsage = function_exists('memory_get_peak_usage');

            // Location of the directory containing CID fonts
            self::$CIDTablesDirectory = implode(DIRECTORY_SEPARATOR, [dirname(__FILE__), '..', 'CIDTables']);
            self::$FontMetricsDirectory = implode(DIRECTORY_SEPARATOR, [dirname(__FILE__), '..', 'FontMetrics']);

            // The string that contains all the Rtl character prefixes in UTF-8 - An optimization used by the __rtl_process() method
            self::$RtlCharacterPrefixes = implode('', array_keys(self::$RtlCharacterPrefixLengths));

            // Build the character classes (used only for testing letters and digits)
            if (self::$CharacterClasses === false) {
                self::$CharacterClasses = [];
                for ($ord = 0; $ord < 256; $ord++) {
                    $ch = chr($ord);

                    if ($ch >= '0' && $ch <= '9') {
                        self::$CharacterClasses [$ch] = self::CTYPE_DIGIT | self::CTYPE_XDIGIT | self::CTYPE_ALNUM;
                    } elseif ($ch >= 'A' && $ch <= 'Z') {
                        self::$CharacterClasses [$ch] = self::CTYPE_ALPHA | self::CTYPE_UPPER | self::CTYPE_ALNUM;

                        if ($ch <= 'F') {
                            self::$CharacterClasses [$ch] |= self::CTYPE_XDIGIT;
                        }
                    } elseif ($ch >= 'a' && $ch <= 'z') {
                        self::$CharacterClasses [$ch] = self::CTYPE_ALPHA | self::CTYPE_LOWER | self::CTYPE_ALNUM;

                        if ($ch <= 'f') {
                            self::$CharacterClasses [$ch] |= self::CTYPE_XDIGIT;
                        }
                    } else {
                        self::$CharacterClasses [$ch] = 0;
                    }
                }
            }

            // Global execution time limit
            self::$PhpMaxExecutionTime = ( integer )ini_get('max_execution_time');

            if (!self::$PhpMaxExecutionTime) {                    // Paranoia : default max script execution time to 120 seconds
                self::$PhpMaxExecutionTime = 120;
            }

            self::$GlobalExecutionStartTime = microtime(true);        // Set the start of the first execution

            if (self::$MaxGlobalExecutionTime > 0) {
                self::$AllowedGlobalExecutionTime = self::$MaxGlobalExecutionTime;
            } else {
                self::$AllowedGlobalExecutionTime = self::$PhpMaxExecutionTime + self::$MaxGlobalExecutionTime;
            }

            // Adjust in case of inconsistent values
            if (self::$AllowedGlobalExecutionTime < 0 || self::$AllowedGlobalExecutionTime > self::$PhpMaxExecutionTime) {
                self::$AllowedGlobalExecutionTime = self::$PhpMaxExecutionTime - 1;
            }

            self::$StaticInitialized = true;
        }

        parent::__construct();

        $this->Options = $options;

        if ($filename) {
            $this->Load($filename, $user_password, $owner_password);
        }
    }


    public function __tostring()
    {
        return ($this->Text);
    }


    /**************************************************************************************************************
     **************************************************************************************************************
     **************************************************************************************************************
     ******                                                                                                  ******
     ******                                                                                                  ******
     ******                                          PUBLIC METHODS                                          ******
     ******                                                                                                  ******
     ******                                                                                                  ******
     **************************************************************************************************************
     **************************************************************************************************************
     **************************************************************************************************************/

    /*--------------------------------------------------------------------------------------------------------------

        NAME
            Load        - Loads text contents from a PDF file.
        LoadFromString  - Loads PDF contents from a string.

        PROTOTYPE
            $text   =  $pdf -> Load ( $filename, $user_password = false, $owner_password = false ) ;
            $text   =  $pdf -> LoadFromString ( $contents, $user_password = false, $owner_password = false ) ;

        DESCRIPTION
            The Load() method extracts text contents from the specified PDF file. Once processed, text contents will
        be available through the "Text" property.
        The LoadFromString() method performs the same operation on PDF contents already loaded into memory.

        PARAMETERS
            $filename (string) -
                    Optional PDF filename whose text contents are to be extracted.

        $contents (string) -
            String containing PDF contents.

        $user_password (string) -
            User password used for decrypting PDF contents.

        $owner_password (string) -
            Owner password.

     *-------------------------------------------------------------------------------------------------------------*/
    private $__memory_peak_usage_start,
        $__memory_usage_start;

    public function Load($filename, $user_password = false, $owner_password = false)
    {
        $this->__memory_usage_start = (self::$HasMemoryGetUsage) ? memory_get_usage(true) : 0;
        $this->__memory_peak_usage_start = (self::$HasMemoryGetPeakUsage) ? memory_get_peak_usage(true) : 0;

        // Check if the file exists, but only if the file is on a local filesystem
        if (!preg_match('#^ [^:]+ ://#ix', $filename) && !file_exists($filename)) {
            error(new  DecodingException("File \"$filename\" does not exist."));
        }

        // Load its contents
        $contents = @file_get_contents($filename, FILE_BINARY);

        if ($contents === false) {
            error(new  DecodingException("Unable to open \"$filename\"."));
        }

        return ($this->__load($filename, $contents, $user_password, $owner_password));
    }


    public function LoadFromString($contents, $user_password = false, $owner_password = false)
    {
        $this->__memory_usage_start = (self::$HasMemoryGetUsage) ? memory_get_usage(true) : 0;
        $this->__memory_peak_usage_start = (self::$HasMemoryGetPeakUsage) ? memory_get_peak_usage(true) : 0;

        return ($this->__load('', $contents, $user_password, $owner_password));
    }


    private function __load(
        $filename,
        $contents, /** @noinspection PhpUnusedParameterInspection */
        $user_password = false, /** @noinspection PhpUnusedParameterInspection */
        $owner_password = false
    ) {
        // Search for the start of the document ("%PDF-x.y")
        $start_offset = strpos($contents, '%PDF');

        if ($start_offset === false) {        // Not a pdf document !
            error(new DecodingException("File \"$filename\" is not a valid PDF file."));
        } else { // May be a PDF document
            $this->DocumentStartOffset = $start_offset;
        }

        // Check that this is a PDF file with a valid version number
        if (!preg_match('/ %PDF- (?P<version> \d+ (\. \d+)*) /ix', $contents, $match, 0, $start_offset)) {
            error(new DecodingException("File \"$filename\" is not a valid PDF file."));
        }

        $this->PdfVersion = $match ['version'];

        // Initializations
        $this->Text = '';
        $this->FontTable = new PdfTexterFontTable();
        $this->Filename = realpath($filename);
        $this->Pages = [];
        $this->Images = [];
        $this->ImageData = [];
        $this->ImageCount = 0;
        $this->AutoSavedImageFiles = [];
        $this->PageMap = new PdfTexterPageMap();
        $this->PageLocations = [];
        $this->Author = '';
        $this->CreatorApplication = '';
        $this->ProducerApplication = '';
        $this->CreationDate = '';
        $this->ModificationDate = '';
        $this->Title = '';
        $this->Subject = '';
        $this->Keywords = '';
        $this->GotAuthorInformation = false;
        $this->ID = '';
        $this->ID2 = '';
        $this->EncryptionData = false;
        $this->EnhancedStatistics = (($this->Options & self::PDFOPT_ENHANCED_STATISTICS) != 0);

        // Also reset cached information that may come from previous runs
        $this->MapIdBuffer = [];
        $this->RtlCharacterBuffer = [];
        $this->CharacterMapBuffer = [];
        $this->FontObjectsBuffer = [];
        $this->FormData = [];
        $this->FormDataObjectNumbers = false;
        $this->FomDataDefinitions = [];
        $this->FormDataObjects = [];
        $this->CaptureDefinitions = false;
        $this->CaptureObject = false;
        $this->DocumentFragments = [];

        // Enable the PDFOPT_BASIC_LAYOUT option if the PDFOPT_CAPTURE flag is specified
        if ($this->Options & self::PDFOPT_CAPTURE) {
            $this->Options |= self::PDFOPT_BASIC_LAYOUT;
        }

        // Enable the PDFOPT_BASIC_LAYOUT_OPTION is PDFOPT_DEBUG_SHOW_COORDINATES is specified
        if ($this->Options & self::PDFOPT_DEBUG_SHOW_COORDINATES) {
            $this->Options |= self::PDFOPT_BASIC_LAYOUT;
        }

        // Page layout options needs more instructions to be retained - select the appropriate list of useless instructions
        if ($this->Options & self::PDFOPT_BASIC_LAYOUT) {
            $this->IgnoredInstructions = self::$IgnoredInstructionsLayout;
        } else {
            $this->IgnoredInstructions = self::$IgnoredInstructionsNoLayout;
        }


        // Debug statistics
        $this->Statistics =
        [
            'TextSize' => 0,                // Total size of drawing instructions ("text" objects)
            'OptimizedTextSize' => 0,                // Optimized text size, with useless instructions removed
            'Distributions' =>             // Statistics about handled instructions distribution - Works only with the page layout option in debug mode
            [
                'operand' => 0,
                'Tm' => 0,
                'Td' => 0,
                'TD' => 0,
                "'" => 0,
                'TJ' => 0,
                'Tj' => 0,
                'Tf' => 0,
                'TL' => 0,
                'T*' => 0,
                '(' => 0,
                '<' => 0,
                '[' => 0,
                'cm' => 0,
                'BT' => 0,
                'template' => 0,
                'ignored' => 0,
                'space' => 0
            ]
        ];

        // Per-instance execution time limit
        $this->ExecutionStartTime = microtime(true);

        if ($this->MaxExecutionTime > 0) {
            $this->AllowedExecutionTime = $this->MaxExecutionTime;
        } else {
            $this->AllowedExecutionTime = self::$PhpMaxExecutionTime + $this->MaxExecutionTime;
        }

        // Adjust in case of inconsistent values
        if ($this->AllowedExecutionTime < 0 || $this->AllowedExecutionTime > self::$PhpMaxExecutionTime) {
            $this->AllowedExecutionTime = self::$PhpMaxExecutionTime - 1;
        }

        // Systematically set the DECODE_IMAGE_DATA flag if the AUTOSAVE_IMAGES flag has been specified
        if ($this->Options & self::PDFOPT_AUTOSAVE_IMAGES) {
            $this->Options |= self::PDFOPT_DECODE_IMAGE_DATA;
        }

        // Systematically set the GET_IMAGE_DATA flag if DECODE_IMAGE_DATA is specified (debug mode only)
        if (self::$DEBUG && $this->Options & self::PDFOPT_DECODE_IMAGE_DATA) {
            $this->Options |= self::PDFOPT_GET_IMAGE_DATA;
        }

        // Since page layout options take 2 bits, but not all of the 4 possible values are allowed, make sure that an invalid
        // value will default to PDFOPT_RAW_LAYOUT value
        $layout_option = $this->Options & self::PDFOPT_LAYOUT_MASK;

        if (!$layout_option === self::PDFOPT_RAW_LAYOUT && $layout_option !== self::PDFOPT_BASIC_LAYOUT) {
            $layout_option = self::PDFOPT_RAW_LAYOUT;
            $this->Options = ($this->Options & ~self::PDFOPT_LAYOUT_MASK) | self::PDFOPT_RAW_LAYOUT;
        }

        // Author information needs to be processed after, because it may reference objects that occur later in the PDF stream
        $author_information_object_id = false;

        // Extract pdf objects that are enclosed by the "obj" and "endobj" keywords
        $pdf_objects = [];
        $contents_offset = $this->DocumentStartOffset;
        $contents_length = strlen($contents);


        while ($contents_offset < $contents_length &&
            preg_match('/(?P<re> (?P<object_id> \d+) \s+ \d+ \s+ obj (?P<object> .*?) endobj )/imsx', $contents, $match, PREG_OFFSET_CAPTURE, $contents_offset)) {
            $object_number = $match ['object_id'] [0];
            $object_data = $match ['object'] [0];

            // Handle the special case of object streams (compound objects)
            // They are not added in the $pdf_objects array, because they could be mistakenly processed as relevant information,
            // such as font definitions, etc.
            // Instead, only the objects they are embedding are stored in this array.
            if ($this->IsObjectStream($object_data)) {
                // Ignore ill-formed object streams
                if (($object_stream_matches = $this->DecodeObjectStream($object_number, $object_data)) !== false) {
                    // Add this list of objects to the list of known objects
                    for ($j = 0, $object_stream_count = count($object_stream_matches ['object_id']); $j < $object_stream_count; $j++) {
                        $pdf_objects [$object_stream_matches ['object_id'] [$j]] = $object_stream_matches ['object'] [$j];
                    }
                }
            } else {
                // Normal (non-compound) object
                $pdf_objects [$object_number] = $object_data;
            }

            // Update current offset through PDF contents
            $contents_offset = $match ['re'] [1] + strlen($match ['re'] [0]);
        }

        // We put a particular attention in treating errors returned by preg_match_all() here, since we need to be really sure why stopped
        // to find further PDF objects in the supplied contents
        $preg_error = preg_last_error();

        switch ($preg_error) {
            case  PREG_NO_ERROR :
                break;

            case  PREG_INTERNAL_ERROR :
                error(new DecodingException("PDF object extraction : the preg_match_all() function encountered an internal error."));
                break;

            case  PREG_BACKTRACK_LIMIT_ERROR :
                error(new DecodingException("PDF object extraction : backtrack limit reached (you may have to modify the pcre.backtrack_limit " .
                    "setting of your PHP.ini file, which is currently set to " . ini_get('pcre.backtrack_limit') . ")."));
                break;

            case  PREG_JIT_STACKLIMIT_ERROR :
                error(new DecodingException("PDF object extraction : JIT stack limit reached (you may disable this feature by setting the pcre.jit " .
                    "setting of your PHP.ini file to 0)."));
                break;

            case  PREG_RECURSION_LIMIT_ERROR :
                error(new DecodingException("PDF object extraction : recursion limit reached (you may have to modify the pcre.recursion_limit " .
                    "setting of your PHP.ini file, which is currently set to " . ini_get('pcre.recursion_limit') . ")."));
                break;

            case  PREG_BAD_UTF8_ERROR :
                error(new DecodingException("PDF object extraction : bad UTF8 character encountered."));
                break;

            case PREG_BAD_UTF8_OFFSET_ERROR :
                error(new DecodingException("PDF object extraction : the specified offset does not start at the beginning of a valid UTF8 codepoint."));
                break;

            default :
                error(new DecodingException("PDF object extraction : unkown PREG error #$preg_error"));
        }


        // Extract trailer information, which may contain the ID of an object specifying encryption flags
        $this->GetTrailerInformation($contents, $pdf_objects);
        unset($contents);

        // Character maps encountered so far
        $cmaps = [];

        // An array that will store object ids as keys and text contents as values
        $text = [];

        // Loop through the objects
        foreach ($pdf_objects as $object_number => $object_data) {
            // Some additional objects may be uncovered after processing (in an object containing compacted objects for example)
            // so add them to the list if necessary
            if (!isset($pdf_objects [$object_number])) {
                $pdf_objects [$object_number] = $object_data;
            }

            // Try to catch information related to page mapping - but don't discard the object since it can contain additional information
            $this->PageMap->Peek($object_number, $object_data, $pdf_objects);

            // Check if the object contais authoring information - it can appear encoded or unencoded
            if (!$this->GotAuthorInformation) {
                $author_information_object_id = $this->PeekAuthorInformation($object_number, $object_data);
            }

            // Also catch the object encoding type
            $type = $this->GetEncodingType($object_number, $object_data);
            $stream_match = null;

            if (strpos($object_data, 'stream') === false ||
                !preg_match('#[^/] stream \s+ (?P<stream> .*?) endstream#imsx', $object_data, $stream_match)
            ) {
                // Some font definitions are in clear text in an object, some are encoded in a stream within the object
                // We process here the unencoded ones
                if ($this->IsFont($object_data)) {
                    $this->FontTable->Add($object_number, $object_data, $pdf_objects, $this->AdobeExtraMappings);
                    continue;
                } elseif ($this->IsCharacterMap($object_data)) {
                    // Some character maps may also be in clear text
                    $cmap = PdfTexterCharacterMap::CreateInstance($object_number, $object_data, $this->AdobeExtraMappings);

                    if ($cmap) {
                        $cmaps [] = $cmap;
                    }

                    continue;
                } elseif ($this->IsFontMap($object_data)) {
                    // Check if there is an association between font number and object number
                    $this->FontTable->AddFontMap($object_number, $object_data);
                } elseif ($this->IsFormData($object_data)) {
                    // Retrieve form data if present
                    $this->RetrieveFormData($object_number, $object_data, $pdf_objects);
                } else {
                    // Ignore other objects that do not contain an encoded stream
                    if (self::$DEBUG > 1) {
                        echo "\n----------------------------------- UNSTREAMED #$object_number\n$object_data";
                    }

                    continue;
                }
            } elseif ($this->IsImage($object_data)) {
                // Extract image data, if any
                $this->AddImage($object_number, $stream_match ['stream'], $type, $object_data);
                continue;
            } elseif ($this->IsFontMap($object_data)) {
                // Check if there is an association between font number and object number
                $this->FontTable->AddFontMap($object_number, $object_data);

                if (!$stream_match) {
                    continue;
                }
            }

            // Check if the stream contains data (yes, I have found a sample that had streams of length 0...)
            // In other words : ignore empty streams
            if (stripos($object_data, '/Length 0') !== false) {
                continue;
            }

            // Isolate stream data and try to find its encoding type
            if (isset($stream_match ['stream'])) {
                $stream_data = ltrim($stream_match ['stream'], "\r\n");
            } else {
                continue;
            }

            // Ignore this stream if the object does not contain an encoding type (/FLATEDECODE, /ASCIIHEX or /ASCII85)
            if ($type == self::PDF_UNKNOWN_ENCODING) {
                if (self::$DEBUG > 1) {
                    echo "\n----------------------------------- UNENCODED #$object_number :\n$object_data";
                }

                continue;
            }

            // Decode the encoded stream
            $decoded_stream_data = $this->DecodeData($object_number, $stream_data, $type, $object_data);

            // Second chance to peek author information, this time on a decoded stream data
            if (!$this->GotAuthorInformation) {
                $author_information_object_id = $this->PeekAuthorInformation($object_number, $decoded_stream_data);
            }

            // Check for character maps
            if ($this->IsCharacterMap($decoded_stream_data)) {
                $cmap = PdfTexterCharacterMap::CreateInstance($object_number, $decoded_stream_data, $this->AdobeExtraMappings);

                if ($cmap) {
                    $cmaps [] = $cmap;
                }
            } elseif ($this->IsFont($decoded_stream_data)) {
                // Font definitions
                $this->FontTable->Add($object_number, $decoded_stream_data, $pdf_objects, $this->AdobeExtraMappings);
            } elseif ($this->IsFormData($object_data)) {
                // Retrieve form data if present
                $this->RetrieveFormData($object_number, $decoded_stream_data, $pdf_objects);
            } elseif ($this->IsText($object_data, $decoded_stream_data)) {
                // Plain text (well, in fact PDF drawing instructions)
                $text_data = false;

                // Check if we need to ignore page headers and footers
                if ($this->Options & self::PDFOPT_IGNORE_HEADERS_AND_FOOTERS) {
                    if (!$this->IsPageHeaderOrFooter($decoded_stream_data)) {
                        $text [$object_number] =
                        $text_data = $decoded_stream_data;
                    } else {
                        // However, they may be mixed with actual text contents so we need to separate them...
                        $this->ExtractTextData($object_number, $decoded_stream_data, $remainder, $header, $footer);

                        // We still need to check again that the extracted text portion contains something useful
                        if ($this->IsText($object_data, $remainder)) {
                            $text [$object_number] =
                            $text_data = $remainder;
                        }
                    }
                } else {
                    $text [$object_number] =
                    $text_data = $decoded_stream_data;
                }


                // The current object may be a text object that have been defined as an XObject in some other object
                // In this case, we have to keep it since it may be referenced by a /TPLx construct from within
                // another text object
                if ($text_data) {
                    $this->PageMap->AddTemplateObject($object_number, $text_data);
                }
            } else {
                // This may be here the opportunity to look into the $FormData property and replace object ids with their corresponding data
                $found = false;

                foreach ($this->FormData as &$form_entry) {
                    if (is_integer($form_entry ['values']) && $object_number == $form_entry ['values']) {
                        $form_entry ['values'] = $decoded_stream_data;
                        $found = true;
                    } elseif (is_integer($form_entry ['form']) && $object_number == $form_entry ['form']) {
                        $form_entry ['form'] = $decoded_stream_data;
                        $found = true;
                    }
                }

                if (!$found && self::$DEBUG > 1) {
                    echo "\n----------------------------------- UNRECOGNIZED #$object_number :\n$decoded_stream_data\n";
                }
            }
        }

        // Form data object numbers
        $this->FormDataObjectNumbers = array_keys($this->FormData);

        // Associate character maps with declared fonts
        foreach ($cmaps as $cmap) {
            $this->FontTable->AddCharacterMap($cmap);
        }

        // Current font defaults to -1, which means : take the first available font as the current one.
        // Sometimes it may happen that text drawing instructions do not set a font at all (PdfPro for example)
        $current_font = -1;

        // Build the page catalog
        $this->Pages = [];
        $this->PageMap->MapObjects($text);

        // Add font mappings local to each page
        $mapped_fonts = $this->PageMap->GetMappedFonts();
        $this->FontTable->AddPageFontMap($mapped_fonts);

        // Extract text from the collected text elements
        foreach ($this->PageMap->Pages as $page_number => $page_objects) {
            // Checks if this page is selected
            if (!$this->IsPageSelected($page_number)) {
                continue;
            }

            $this->Pages [$page_number] = '';

            if ($layout_option === self::PDFOPT_RAW_LAYOUT) {
                foreach ($page_objects as $page_object) {
                    if (isset($text [$page_object])) {
                        $new_text = $this->PageMap->ProcessTemplateReferences($page_number, $text [$page_object]);
                        $object_text = $this->ExtractText($page_number, $page_object, $new_text, $current_font);
                        $this->Pages [$page_number] .= $object_text;
                    } elseif (self::$DEBUG > 1) {
                        echo "\n----------------------------------- MISSING OBJECT #$page_object for page #$page_number\n";
                    }
                }
            } elseif ($layout_option === self::PDFOPT_BASIC_LAYOUT) {
                // New style (basic) layout rendering
                $page_fragments = [];

                foreach ($page_objects as $page_object) {
                    if (isset($text [$page_object])) {
                        $new_text = $this->PageMap->ProcessTemplateReferences($page_number, $text [$page_object]);
                        $this->ExtractTextWithLayout($page_fragments, $page_number, $page_object, $new_text, $current_font);
                    } elseif (self::$DEBUG > 1) {
                        echo "\n----------------------------------- MISSING OBJECT #$page_object for page #$page_number\n";
                    }
                }

                $this->Pages [$page_number] = $this->__assemble_text_fragments($page_number, $page_fragments, $page_width, $page_height);

                $this->DocumentFragments [$page_number] =
                [
                    'fragments' => $page_fragments,
                    'page-width' => $page_width,
                    'page_height' => $page_height
                ];
            }
        }

        // Retrieve author information
        if ($this->GotAuthorInformation) {
            $this->RetrieveAuthorInformation($author_information_object_id, $pdf_objects);
        }

        // Build the page locations (ie, starting and ending offsets)
        $offset = 0;
        $page_separator = utf8_encode($this->PageSeparator);
        $page_separator_length = strlen($page_separator);

        foreach ($this->Pages as $page_number => &$page) {
            // If hyphenated words are unwanted, then remove them
            if ($this->Options & self::PDFOPT_NO_HYPHENATED_WORDS) {
                $page = preg_replace(self::$RemoveHyphensRegex, '$4$2', $page);
            }

            $length = strlen($page);
            $this->PageLocations [$page_number] = ['start' => $offset, 'end' => $offset + $length - 1];
            $offset += $length + $page_separator_length;
        }

        // And finally, the Text property
        $this->Text = implode($page_separator, $this->Pages);

        // Free memory
        $this->MapIdBuffer = [];
        $this->RtlCharacterBuffer = [];
        $this->CharacterMapBuffer = [];

        // Compute memory occupied for this file
        $memory_usage_end = (self::$HasMemoryGetUsage) ? memory_get_usage(true) : 0;
        $memory_peak_usage_end = (self::$HasMemoryGetPeakUsage) ? memory_get_peak_usage(true) : 0;

        $this->MemoryUsage = $memory_usage_end - $this->__memory_usage_start;
        $this->MemoryPeakUsage = $memory_peak_usage_end - $this->__memory_peak_usage_start;

        // Adjust the "Distributions" statistics
        if ($this->Options & self::PDFOPT_ENHANCED_STATISTICS) {
            $instruction_count = 0;
            $statistics = [];

            // Count the total number of instructions
            foreach ($this->Statistics ['Distributions'] as $count) {
                $instruction_count += $count;
            }

            // Now transform the Distributions entries into an associative array containing the instruction counts
            // ('count') and their relative percentage
            foreach ($this->Statistics ['Distributions'] as $name => $count) {
                if ($instruction_count) {
                    $percent = round((100.0 / $instruction_count) * $count, 2);
                } else {
                    $percent = 0;
                }

                $statistics [$name] =
                [
                    'instruction' => $name,
                    'count' => $count,
                    'percent' => $percent
                ];
            }

            // Set the new 'Distributions' array and sort it by instruction count in reverse order
            $this->Statistics ['Distributions'] = $statistics;
            uksort($this->Statistics ['Distributions'], [$this, '__sort_distributions']);
        }

        // All done, return
        return ($this->Text);
    }


    public function __sort_distributions($a, $b)
    {
        return ($this->Statistics ['Distributions'] [$b] ['count'] - $this->Statistics ['Distributions'] [$a] ['count']);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            AddAdobeExtraMappings - Adds extra mappings for standard Adobe fonts.

        PROTOTYPE
            $pdf -> AddAdobeExtraMappings ( $mappings ) ;

        DESCRIPTION
            Adobe supports 4 predefined fonts : standard, Mac, WinAnsi and PDF). All the characters in these fonts
        are identified by a character time, a little bit like HTML entities ; for example, 'one' will be the
        character '1', 'acircumflex' will be '�', etc.
        There are thousands of character names defined by Adobe (see https://mupdf.com/docs/browse/source/pdf/pdf-glyphlist.h.html).
        Some of them are not in this list ; this is the case for example of the 'ax' character names, where 'x'
        is a decimal number. When such a character is specified in a /Differences array, then there is somewhere
        a CharProc[] array giving an object id for each of those characters.
        The referenced object(s) in turn contain drawing instructions to draw the glyph. At no point you could
        guess what is the corresponding Unicode character for this glyph, since the information is not contained
        in the PDF file.
        The AddAdobeExtraMappings() method allows you to specify such correspondences. Specify an array as the
        $mappings parameter, whose keys are the Adobe character name (for example, "a127") and values the
        corresponding Unicode values (see the description of the $mappings parameter for more information).

        PARAMETERS
            $mappings (associative array) -
                    Associative array whose keys are Adobe character names. The array values can take several forms :
            - A character
            - An integer value
            - An array of up to four character or integer values.
            Internally, every specified value is converted to an array of four integer values, one for
            each of the standard Adobe character sets (Standard, Mac, WinAnsi and PDF). The following
            rules apply :
            - If the input value is a single character, the output array corrsponding the Adobe character
              name will be a set of 4 elements corresponding to the ordinal value of the supplied
              character.
            - If the input value is an integer, the output array will be a set of 4 identical values
            - If the input value is an array :
              . Arrays with less that 4 elements will be padded, using the last array item for padding
              . Arrays with more than 4 elements will be silently truncated
              . Each array value can either be a character or a numeric value.

        NOTES
            In this current implementation, the method applies the mappings to ALL Adobe default fonts. That is,
        you cannot have one mapping for one Adobe font referenced in the PDF file, then a second mapping for
        a second Adobe font, etc.

     *-------------------------------------------------------------------------------------------------------------*/
    public function AddAdobeExtraMappings($mappings)
    {
        $items = [];
        // Loop through each mapping
        foreach ($mappings as $key => $value) {
            // Character value : we retain its ordinal value as the 4 values of the output array
            if (is_string($value)) {
                $ord = ord($value);
                $items = [$ord, $ord, $ord, $ord];
            } elseif (is_numeric($value)) {
                // Numeric value : the output array will contain 4 times the supplied value
                $value = ( integer )$value;
                $items = [$value, $value, $value, $value];
            } elseif (is_array($value)) {
                // Array value : make sure we will have an output array of 4 values
                $items = [];

                // Collect the supplied values, converting characters to their ordinal values if necessary
                for ($i = 0, $count = count($value); $i < $count && $i < 4; $i++) {
                    $code = $value [$i];

                    if (is_string($code)) {
                        $items [] = ord($code);
                    } else {
                        $items [] = ( integer )$code;
                    }
                }

                // Ensure that we have 4 values ; fill the missing ones with the last seen value if necessary
                $count = count($items);

                if (!$count) {
                    error(new PTTException("Adobe extra mapping \"$key\" has no values."));
                }

                $last_value = $items [$count - 1];

                for ($i = $count; $i < 4; $i++) {
                    $items [] = $last_value;
                }
            } else {
                error(new PTTException("Invalid value \"$value\" for Adobe extra mapping \"$key\"."));
            }

            // Add this current mapping to the Adobe extra mappings array
            $this->AdobeExtraMappings [$key] = $items;
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetPageFromOffset - Returns a page number from a text offset.

        PROTOTYPE
            $offset     =  $pdf -> GetPageFromOffset ( $offset ) ;

        DESCRIPTION
            Given a byte offset in the Text property, returns its page number in the pdf document.

        PARAMETERS
            $offset (integer) -
                    Offset, in the Text property, whose page number is to be retrieved.

        RETURN VALUE
            Returns a page number in the pdf document, or false if the specified offset does not exist.

     *-------------------------------------------------------------------------------------------------------------*/
    public function GetPageFromOffset($offset)
    {
        if ($offset === false) {
            return (false);
        }

        foreach ($this->PageLocations as $page => $location) {
            if ($offset >= $location ['start'] && $offset <= $location ['end']) {
                return ($page);
            }
        }

        return (false);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            text_strpos, text_stripos - Search for an occurrence of a string.

        PROTOTYPE
            $result     =  $pdf -> text_strpos  ( $search, $start = 0 ) ;
            $result     =  $pdf -> text_stripos ( $search, $start = 0 ) ;

        DESCRIPTION
            These methods behave as the strpos/stripos PHP functions, except that :
        - They operate on the text contents of the pdf file (Text property)
        - They return an array containing the page number and text offset. $result [0] will be set to the page
          number of the searched text, and $result [1] to its offset in the Text property

        PARAMETERS
            $search (string) -
                    String to be searched.

        $start (integer) -
            Start offset in the pdf text contents.

        RETURN VALUE
            Returns an array of two values containing the page number and text offset if the searched string has
        been found, or false otherwise.

     *-------------------------------------------------------------------------------------------------------------*/
    public function text_strpos($search, $start = 0)
    {
        $offset = mb_strpos($this->Text, $search, $start, 'UTF-8');

        if ($offset !== false) {
            return ([$this->GetPageFromOffset($offset), $offset]);
        }

        return (false);
    }


    public function text_stripos($search, $start = 0)
    {
        $offset = mb_stripos($this->Text, $search, $start, 'UTF-8');

        if ($offset !== false) {
            return ([$this->GetPageFromOffset($offset), $offset]);
        }

        return (false);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            document_strpos, document_stripos - Search for all occurrences of a string.

        PROTOTYPE
            $result     =  $pdf -> document_strpos  ( $search, $group_by_page = false ) ;
            $result     =  $pdf -> document_stripos ( $search, $group_by_page = false ) ;

        DESCRIPTION
        Searches for ALL occurrences of a given string in the pdf document. The value of the $group_by_page
        parameter determines how the results are returned :
        - When true, the returned value will be an associative array whose keys will be page numbers and values
          arrays of offset of the found string within the page
        - When false, the returned value will be an array of arrays containing two entries : the page number
          and the text offset.

        For example, if a pdf document contains the string "here" at character offset 100 and 200 in page 1, and
        position 157 in page 3, the returned value will be :
        - When $group_by_page is false :
            [ [ 1, 100 ], [ 1, 200 ], [ 3, 157 ] ]
        - When $group_by_page is true :
            [ 1 => [ 100, 200 ], 3 => [ 157 ] ]

        PARAMETERS
            $search (string) -
                    String to be searched.

        $group_by_page (boolean) -
            Indicates whether the found offsets should be grouped by page number or not.

        RETURN VALUE
            Returns an array of page numbers/character offsets (see Description above) or false if the specified
        string does not appear in the document.

     *-------------------------------------------------------------------------------------------------------------*/
    public function document_strpos($text, $group_by_page = false)
    {
        $length = strlen($text);

        if (!$length) {
            return (false);
        }

        $result = [];
        $index = 0;

        while (($index = mb_strpos($this->Text, $text, $index, 'UTF-8')) !== false) {
            $page = $this->GetPageFromOffset($index);

            if ($group_by_page) {
                $result [$page] [] = $index;
            } else {
                $result [] = [$page, $index];
            }

            $index += $length;
        }

        return ($result);
    }


    public function document_stripos($text, $group_by_page = false)
    {
        $length = strlen($text);

        if (!$length) {
            return (false);
        }

        $result = [];
        $index = 0;

        while (($index = mb_stripos($this->Text, $text, $index, 'UTF-8')) !== false) {
            $page = $this->GetPageFromOffset($index);

            if ($group_by_page) {
                $result [$page] [] = $index;
            } else {
                $result [] = [$page, $index];
            }

            $index += $length;
        }

        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            text_match, document_match - Search string using regular expressions.

        PROTOTYPE
            $status     =  $pdf -> text_match ( $pattern, &$match = null, $flags = 0, $offset = 0 ) ;
            $status     =  $pdf -> document_match ( $pattern, &$match = null, $flags = 0, $offset = 0 ) ;

        DESCRIPTION
            text_match() calls the preg_match() PHP function on the pdf text contents, to locate the first occurrence
        of text that matches the specified regular expression.
        document_match() calls the preg_match_all() function to locate all occurrences that match the specified
        regular expression.
        Note that both methods add the PREG_OFFSET_CAPTURE flag when calling preg_match/preg_match_all so you
        should be aware that all captured results are an array containing the following entries :
        - Item [0] is the captured string
        - Item [1] is its text offset
        - The text_match() and document_match() methods add an extra array item (index 2), which contains the
          page number where the matched text resides

        PARAMETERS
            $pattern (string) -
                    Regular expression to be searched.

        $match (any) -
            Output captures. See preg_match/preg_match_all.

        $flags (integer) -
            PCRE flags. See preg_match/preg_match_all.

        $offset (integer) -
            Start offset. See preg_match/preg_match_all.

        RETURN VALUE
            Returns the number of matched occurrences, or false if the specified regular expression is invalid.

     *-------------------------------------------------------------------------------------------------------------*/
    public function text_match($pattern, &$match = null, $flags = 0, $offset = 0)
    {
        $local_match = null;
        $status = preg_match($pattern, $this->Text, $local_match, $flags | PREG_OFFSET_CAPTURE, $offset);

        if ($status) {
            foreach ($local_match as &$entry) {
                $entry [2] = $this->GetPageFromOffset($entry [1]);
            }

            $match = $local_match;
        }

        return ($status);
    }


    public function document_match($pattern, &$matches = null, $flags = 0, $offset = 0)
    {
        $local_matches = null;
        $status = preg_match_all($pattern, $this->Text, $local_matches, $flags | PREG_OFFSET_CAPTURE, $offset);

        if ($status) {
            foreach ($local_matches as &$entry) {
                foreach ($entry as &$subentry) {
                    $subentry [2] = $this->GetPageFromOffset($subentry [1]);
                }
            }

            $matches = $local_matches;
        }

        return ($status);
    }


    /*--------------------------------------------------------------------------------------------------------------

        HasFormData -
        Returns true if the PDF file contains form data or not.

     *-------------------------------------------------------------------------------------------------------------*/
    public function HasFormData()
    {
        return (count($this->FormData) > 0);
    }


    /*--------------------------------------------------------------------------------------------------------------

        GetFormCount -
        Returns the number of top-level forms contained in the PDF file.

     *-------------------------------------------------------------------------------------------------------------*/
    public function GetFormCount()
    {
        return (count($this->FormData));
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetFormData - Returns form data, if any

        PROTOTYPE
            $object     =  $pdf -> GetFormData ( $template = null, $form_index = 0 ) ;

        DESCRIPTION
            Retrieves form data if present.

        PARAMETERS
            $template (string) -
                    An XML file describing form data using human-readable names for field values.
            If not specified, the inline form definitions will be used, together with the field names
            specified in the PDF file.

        $form_index (integer) -
            Form index in the PDF file. So far, I really don't know if a PDF file can have multiple forms.

        RETURN VALUE
            An object derived from the PdfToTextFormData class.

     *-------------------------------------------------------------------------------------------------------------*/
    public function GetFormData($template = null, $form_index = 0)
    {
        if (isset($this->FormDataObjects [$form_index])) {
            return ($this->FormDataObjects [$form_index]);
        }

        if ($form_index > count($this->FormDataObjectNumbers)) {
            error(new FormException("Invalid form index #$form_index."));
        }

        $form_data = $this->FormData [$this->FormDataObjectNumbers [$form_index]];

        if ($template) {
            if (!file_exists($template)) {
                error(new FormException("Form data template file \"$template\" not found."));
            }

            $xml_data = file_get_contents($template);
            $definitions = new FormDefinitions($xml_data, $form_data ['form']);
            ;
        } else {
            $definitions = new FormDefinitions(null, $form_data ['form']);
        }

        /** @noinspection PhpUndefinedMethodInspection */
        $object = $definitions[$form_index]->GetFormDataFromPdfObject($form_data ['values']);

        $this->FormDataDefinitions [] = $definitions;
        $this->FormDataObjects [] = $object;

        return ($object);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            MarkTextLike - Marks output text.

        PROTOTYPE
            $pdf -> MarkTextLike ( $regex, $marker_start, $marker_end ) ;

        DESCRIPTION
            Sometimes it may be convenient, when you want to extract only a portion of text, to say : "I want to
        extract text between this title and this title". The MarkTextLike() method provides some support for
        such a task. Imagine you have documents that have the same structure, all starting with an "Introduction"
        title :

            Introduction
                ...
                some text
                ...
            Some other title
                ...

        By calling the MarkTextLike() method such as in the example below :

            $pdf -> MarkTextLike ( '/\bIntroduction\b/', '<M>', '</M' ) ;

        then you will get as output :

            <M>Introduction</M>
                ...
                some text
                ...
            <M>Some other title</M>

        Adding such markers in the output will allow you to easily extract the text between the chapters
        "Introduction" and "Some other title", using a regular expression.

        The font name used for the first string matched by the specified regular expression will be searched
        later to add markers around all the text portions using this font.


        PARAMETERS
            $regex (string) -
                    A regular expression to match the text to be matched. Subsequent portions of text using the
            same font will be surrounded by the marker start/end strings.

        $marker_start, $marker_end (string) -
            Markers to surround the string when a match is found.

     *-------------------------------------------------------------------------------------------------------------*/
    public function MarkTextLike($regex, $marker_start, $marker_end)
    {
        $this->UnprocessedMarkerList ['font'] [] =
        [
            'regex' => $regex,
            'start' => $marker_start,
            'end' => $marker_end
        ];
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            SetCaptures, SetCapturesFromString - Defines document parts to be captured.

        PROTOTYPE
            $pdf -> SetCaptures ( $xml_file ) ;
        $pdf -> SetCapturesFromString ( $xml_data ) ;

        DESCRIPTION
            Defines document parts to be captured.
        SetCaptures() takes the definitions for the areas to be captured from an XML file, while
        SetCapturesFromString() takes them from a string representing xml capture definitions.

        NOTES
            - See file README.md for an explanation on the format of the XML capture definition file.
        - The SetCaptures() methods must be called before the Load() method.

     *-------------------------------------------------------------------------------------------------------------*/
    public function SetCaptures($xml_file)
    {
        if (!file_exists($xml_file)) {
            error(new PTTException("File \"$xml_file\" does not exist."));
        }

        $xml_data = file_get_contents($xml_file);

        $this->SetCapturesFromString($xml_data);
    }


    public function SetCapturesFromString($xml_data)
    {
        // Setting capture areas implies having the PDFOPT_BASIC_LAYOUT option
        $this->Options |= self::PDFOPT_BASIC_LAYOUT;

        $this->CaptureDefinitions = new CaptureDefinitions($xml_data);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetCaptures - Returns captured data.

        PROTOTYPE
            $object     =  $pdf -> GetCaptures ( $full = false ) ;

        PARAMETERS
        $full (boolean) -
            When true, the whole captures, togethers with their definitions, are returned. When false,
            only a basic object containing the capture names and their values is returned.

        DESCRIPTION
            Returns the object that contains captured data.

        RETURN VALUE
            An object of type Captures, or false if an error occurred.

     *-------------------------------------------------------------------------------------------------------------*/
    public function GetCaptures($full = false)
    {
        if (!$this->CaptureObject) {
            $this->CaptureDefinitions->SetPageCount(count($this->Pages));
            $this->CaptureObject = $this->CaptureDefinitions->GetCapturedObject($this->DocumentFragments);
        }

        if ($full) {
            return ($this->CaptureObject);
        } else {
            return ($this->CaptureObject->ToCaptures());
        }
    }


    /**************************************************************************************************************
     **************************************************************************************************************
     **************************************************************************************************************
     ******                                                                                                  ******
     ******                                                                                                  ******
     ******                                         INTERNAL METHODS                                         ******
     ******                                                                                                  ******
     ******                                                                                                  ******
     **************************************************************************************************************
     **************************************************************************************************************
     *************************************************************************************************************
     *
     * @param $object_id
     * @param $stream_data
     * @param $type
     * @param $object_data
     */

    /*--------------------------------------------------------------------------------------------------------------

        NAME
            AddImage - Adds an image from the PDF stream to the current object.

        PROTOTYPE
            $this -> AddImage ( $object_id, $stream_data, $type, $object_data ) ;

        DESCRIPTION
            Adds an image from the PDF stream to the current object.
        If the PDFOPT_GET_IMAGE_DATA flag is enabled, image data will be added to the ImageData property.
        If the PDFOPT_DECODE_IMAGE_DATA flag is enabled, a jpeg resource will be created and added into the
        Images array property.

        PARAMETERS
            $object_id (integer) -
                    Pdf object id.

        $stream_data (string) -
            Contents of the unprocessed stream data containing the image.

        $type (integer) -
            One of the PdfToText::PDF_*_ENCODING constants.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function AddImage($object_id, $stream_data, $type, $object_data)
    {

        if (self::$DEBUG && $this->Options & self::PDFOPT_GET_IMAGE_DATA) {
            switch ($type) {
                case    self::PDF_DCT_ENCODING :
                    $this->ImageData = ['type' => 'jpeg', 'data' => $stream_data];
                    break;
            }
        }


        if ($this->Options & self::PDFOPT_DECODE_IMAGE_DATA &&
            (!$this->MaxExtractedImages || $this->ImageCount < $this->MaxExtractedImages)
        ) {
            $image = $this->DecodeImage($object_id, $stream_data, $type, $object_data, $this->Options & self::PDFOPT_AUTOSAVE_IMAGES);

            if ($image !== false) {
                $this->ImageCount++;

                // When the PDFOPT_AUTOSAVE_IMAGES flag is set, we simply use a template filename to generate a real output filename
                // then save the image to that file. The memory is freed after that.
                if ($this->Options & self::PDFOPT_AUTOSAVE_IMAGES) {
                    $output_filename = $this->__get_output_image_filename();

                    $image->SaveAs($output_filename, $this->ImageAutoSaveFormat);
                    unset($image);

                    $this->AutoSavedImageFiles [] = $output_filename;
                } else {
                    // Otherwise, simply store the image data into memory
                    $this->Images [] = $image;
                }
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            DecodeData - Decodes stream data.

        PROTOTYPE
            $data   =  $this -> DecodeData ( $object_id, $stream_data, $type ) ;

        DESCRIPTION
            Decodes stream data (binary data located between the "stream" and "enstream" directives) according to the
        specified encoding type, given in the surrounding object parameters.

        PARAMETERS
        $object_id (integer) -
            Id of the object containing the data.

            $stream_data (string) -
                    Contents of the binary stream.

        $type (integer) -
            One of the PDF_*_ENCODING constants, as returned by the GetEncodingType() method.

        RETURN VALUE
            Returns the decoded stream data.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function DecodeData(
        $object_id,
        $stream_data,
        $type, /** @noinspection PhpUnusedParameterInspection */
        $object_data
    ) {
        $decoded_stream_data = '';

        switch ($type) {
            case    self::PDF_FLATE_ENCODING :
                // Objects in password-protected Pdf files SHOULD be encrypted ; however, it happens that we may encounter normal,
                // unencrypted ones. This is why we always try to gzuncompress them first then, if failed, try to decrypt them
                $decoded_stream_data = @gzuncompress($stream_data);

                if ($decoded_stream_data === false) {
                    if ($this->IsEncrypted) {
                        $decoded_stream_data = $this->EncryptionData->Decrypt($object_id, $stream_data);

                        if ($decoded_stream_data === false) {
                            if (self::$DEBUG > 1) {
                                warning(new DecodingException("Unable to decrypt object contents.", $object_id));
                            }
                        }
                    } elseif (self::$DEBUG > 1) {
                        warning(new DecodingException("Invalid gzip data.", $object_id));
                    }
                }

                break;

            case    self::PDF_LZW_ENCODING :
                $decoded_stream_data = $this->__decode_lzw($stream_data);
                break;

            case    self::PDF_ASCIIHEX_ENCODING :
                $decoded_stream_data = $this->__decode_ascii_hex($stream_data);
                break;

            case    self::PDF_ASCII85_ENCODING :
                $decoded_stream_data = $this->__decode_ascii_85($stream_data);

                // Dumbly check if this could not be gzipped data after decoding (normally, the object flags should also specify
                // the /FlateDecode flag)
                if ($decoded_stream_data !== false && ($result = @gzuncompress($decoded_stream_data)) !== false) {
                    $decoded_stream_data = $result;
                }

                break;

            case    self::PDF_TEXT_ENCODING :
                $decoded_stream_data = $stream_data;
                break;
        }

        return ($decoded_stream_data);
    }


    // __decode_lzw -
    //  Decoding function for LZW encrypted data. This function is largely inspired by the TCPDF one but has been rewritten
    //  for a performance gain of 30-35%.
    private function __decode_lzw($data)
    {
        // The initial dictionary contains 256 entries where each index is equal to its character representation
        static $InitialDictionary =
        [
            "\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07", "\x08", "\x09", "\x0A", "\x0B", "\x0C", "\x0D", "\x0E", "\x0F",
            "\x10", "\x11", "\x12", "\x13", "\x14", "\x15", "\x16", "\x17", "\x18", "\x19", "\x1A", "\x1B", "\x1C", "\x1D", "\x1E", "\x1F",
            "\x20", "\x21", "\x22", "\x23", "\x24", "\x25", "\x26", "\x27", "\x28", "\x29", "\x2A", "\x2B", "\x2C", "\x2D", "\x2E", "\x2F",
            "\x30", "\x31", "\x32", "\x33", "\x34", "\x35", "\x36", "\x37", "\x38", "\x39", "\x3A", "\x3B", "\x3C", "\x3D", "\x3E", "\x3F",
            "\x40", "\x41", "\x42", "\x43", "\x44", "\x45", "\x46", "\x47", "\x48", "\x49", "\x4A", "\x4B", "\x4C", "\x4D", "\x4E", "\x4F",
            "\x50", "\x51", "\x52", "\x53", "\x54", "\x55", "\x56", "\x57", "\x58", "\x59", "\x5A", "\x5B", "\x5C", "\x5D", "\x5E", "\x5F",
            "\x60", "\x61", "\x62", "\x63", "\x64", "\x65", "\x66", "\x67", "\x68", "\x69", "\x6A", "\x6B", "\x6C", "\x6D", "\x6E", "\x6F",
            "\x70", "\x71", "\x72", "\x73", "\x74", "\x75", "\x76", "\x77", "\x78", "\x79", "\x7A", "\x7B", "\x7C", "\x7D", "\x7E", "\x7F",
            "\x80", "\x81", "\x82", "\x83", "\x84", "\x85", "\x86", "\x87", "\x88", "\x89", "\x8A", "\x8B", "\x8C", "\x8D", "\x8E", "\x8F",
            "\x90", "\x91", "\x92", "\x93", "\x94", "\x95", "\x96", "\x97", "\x98", "\x99", "\x9A", "\x9B", "\x9C", "\x9D", "\x9E", "\x9F",
            "\xA0", "\xA1", "\xA2", "\xA3", "\xA4", "\xA5", "\xA6", "\xA7", "\xA8", "\xA9", "\xAA", "\xAB", "\xAC", "\xAD", "\xAE", "\xAF",
            "\xB0", "\xB1", "\xB2", "\xB3", "\xB4", "\xB5", "\xB6", "\xB7", "\xB8", "\xB9", "\xBA", "\xBB", "\xBC", "\xBD", "\xBE", "\xBF",
            "\xC0", "\xC1", "\xC2", "\xC3", "\xC4", "\xC5", "\xC6", "\xC7", "\xC8", "\xC9", "\xCA", "\xCB", "\xCC", "\xCD", "\xCE", "\xCF",
            "\xD0", "\xD1", "\xD2", "\xD3", "\xD4", "\xD5", "\xD6", "\xD7", "\xD8", "\xD9", "\xDA", "\xDB", "\xDC", "\xDD", "\xDE", "\xDF",
            "\xE0", "\xE1", "\xE2", "\xE3", "\xE4", "\xE5", "\xE6", "\xE7", "\xE8", "\xE9", "\xEA", "\xEB", "\xEC", "\xED", "\xEE", "\xEF",
            "\xF0", "\xF1", "\xF2", "\xF3", "\xF4", "\xF5", "\xF6", "\xF7", "\xF8", "\xF9", "\xFA", "\xFB", "\xFC", "\xFD", "\xFE", "\xFF"
        ];

        // Dictionary lengths - when we reach one of the values specified as the key, we have to set the bit length to the corresponding value
        static $DictionaryLengths =
        [
            511 => 10,
            1023 => 11,
            2047 => 12
        ];

        // Decoded string to be returned
        $result = '';

        // Convert string to binary string
        $bit_string = '';
        $data_length = strlen($data);

        for ($i = 0; $i < $data_length; $i++) {
            $bit_string .= sprintf('%08b', ord($data[$i]));
        }

        $data_length *= 8;

        // Initialize dictionary
        $bit_length = 9;
        $dictionary_index = 258;
        $dictionary = $InitialDictionary;

        // Previous value
        $previous_index = 0;

        // Start index in bit string
        $start_index = 0;

        // Until we encounter the EOD marker (257), read $bit_length bits
        while (($start_index < $data_length) && (($index = bindec(substr($bit_string, $start_index, $bit_length))) !== 257)) {
            // Move to next bit position
            $start_index += $bit_length;

            if ($index !== 256 && $previous_index !== 256) {
                // Check if index exists in the dictionary and remember it
                if ($index < $dictionary_index) {
                    $result .= $dictionary [$index];
                    $dictionary_value = $dictionary [$previous_index] . $dictionary [$index] [0];
                    $previous_index = $index;
                } else {
                    // Index does not exist - add it to the dictionary
                    $dictionary_value = $dictionary [$previous_index] . $dictionary [$previous_index] [0];
                    $result .= $dictionary_value;
                }

                // Update dictionary
                $dictionary [$dictionary_index++] = $dictionary_value;

                // Change bit length whenever we reach an index limit
                if (isset($DictionaryLengths [$dictionary_index])) {
                    $bit_length = $DictionaryLengths [$dictionary_index];
                }
            } elseif ($index === 256) {
                // Clear table marker
                // Reset dictionary and bit length
                // Reset dictionary and bit length
                $bit_length = 9;
                $dictionary_index = 258;
                $previous_index = 256;
                $dictionary = $InitialDictionary;
            } else {
                // $previous_index  === 256
                // First entry
                // first entry
                $result .= $dictionary [$index];
                $previous_index = $index;
            }
        }

        // All done, return
        return ($result);
    }


    // __decode_ascii_hex -
    //  Decoder for /AsciiHexDecode streams.
    private function __decode_ascii_hex($input)
    {
        $output = "";
        $is_odd = true;
        $is_comment = false;

        for ($i = 0, $codeHigh = -1; $i < strlen($input) && $input [$i] != '>'; $i++) {
            $c = $input [$i];

            if ($is_comment) {
                if ($c == '\r' || $c == '\n') {
                    $is_comment = false;
                }

                continue;
            }

            switch ($c) {
                case  '\0' :
                case  '\t' :
                case  '\r' :
                case  '\f' :
                case  '\n' :
                case  ' '  :
                    break;

                case '%' :
                    $is_comment = true;
                    break;

                default :
                    $code = hexdec($c);

                    if ($code === 0 && $c != '0') {
                        return ('');
                    }

                    if ($is_odd) {
                        $codeHigh = $code;
                    } else {
                        $output .= chr(($codeHigh << 4) | $code);
                    }

                    $is_odd = !$is_odd;
                    break;
            }
        }

        if ($input [$i] != '>') {
            return ('');
        }

        if ($is_odd) {
            $output .= chr($codeHigh << 4);
        }

        return ($output);
    }


    // __decode_ascii_85 -
    //  Decoder for /Ascii85Decode streams.
    private function __decode_ascii_85($data)
    {
        // Ordinal value of the first character used in Ascii85 encoding
        static $first_ord = 33;
        // "A 'z' in the input data means "sequence of 4 nuls"
        static $z_exception = "\0\0\0\0";
        // Powers of 85, from 4 to 0
        static $exp85 = [52200625, 614125, 7225, 85, 1];

        // Ignore empty data
        if ($data === '') {
            return (false);
        }

        $data_length = strlen($data);
        $ords = [];
        $ord_count = 0;
        $result = '';

        // Paranoia : Ascii85 data may start with '<~' (but it always end with '~>'). Anyway, we must start past this construct if present
        if ($data [0] == '<' && $data [1] == '~') {
            $start = 2;
        } else {
            $start = 0;
        }

        // Loop through nput characters
        for ($i = $start; $i < $data_length && $data [$i] != '~'; $i++) {
            $ch = $data [$i];

            // Most common case : current character is in the range of the Ascii85 encoding ('!'..'u')
            if ($ch >= '!' && $ch <= 'u') {
                $ords [$ord_count++] = ord($ch) - $first_ord;
            } elseif ($ch == 'z' && !$ord_count) {
                // 'z' is replaced with a sequence of null bytes
                $result .= $z_exception;
            } elseif ($ch !== "\0" && $ch !== "\t" && $ch !== ' ' && $ch !== "\r" && $ch !== "\n" && $ch !== "\f") {
                // Spaces are ignored
                continue;
            } else {
                // Other characters : corrupted data...
                return (false);
            }

            // We have collected 5 characters in base 85 : convert their 32-bits value to base 2 (3 characters)
            if ($ord_count == 5) {
                $ord_count = 0;

                for ($sum = 0, $j = 0; $j < 5; $j++) {
                    $sum = ($sum * 85) + $ords [$j];
                }

                for ($j = 3; $j >= 0; $j--) {
                    $result .= chr($sum >> ($j * 8));
                }
            }
        }

        // A last processing for the potential remaining bytes
        // Notes : this situation has never been tested
        if ($ord_count) {
            for ($i = 0, $sum = 0; $i < $ord_count; $i++) {
                $sum += ($ords [$i] + ($i == $ord_count - 1)) * $exp85 [$i];
            }

            for ($i = 0; $i < $ord_count - 1; $i++) {
                $result .= chr($sum >> ((3 - $i) * 8));
            }
        }

        // All done, return
        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            DecodeImage - Returns decoded image contents.

        PROTOTYPE
            TBC

        DESCRIPTION
            description

        PARAMETERS
            $object_id (integer) -
                    Pdf object number.

        $stream_data (string) -
            Object data.

        $type (integer) -
            One of the PdfToText::PDF_*_ENCODING constants.

        $autosave (boolean) -
            When autosave is selected, images will not be decoded into memory unless they have a format
            different from JPEG. This is intended to save memory.

        RETURN VALUE
            Returns an object of type PdfIMage, or false if the image encoding type is not currently supported.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function DecodeImage(/** @noinspection PhpUnusedParameterInspection */
        $object_id,
        $stream_data,
        $type,
        $object_data,
        $autosave
    ) {
        switch ($type) {
            // Normal JPEG image
            case    self::PDF_DCT_ENCODING :
                return (new PdfJpegImage($stream_data, $autosave));

            // CCITT fax image
            case    self::PDF_CCITT_FAX_ENCODING :
                return (new PdfFaxImage($stream_data));

            // For now, I have not found enough information to be able to decode image data in an inflated stream...
            // In some cases, however, this is JPEG data
            case    self::PDF_FLATE_ENCODING :
                $image = PdfInlinedImage::CreateInstance($stream_data, $object_data, $autosave);

                if ($image) {
                    return ($image);
                }

                break;

            default :
                return (false);
        }

        return (false);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            DecodeObjectStream - Decodes an object stream.

        PROTOTYPE
            $array  =  $this -> DecodeObjectStream ( $object_id, $object_data ) ;

        DESCRIPTION
            Decodes an object stream. An object stream is yet another PDF object type that contains itself several
        objects not defined using the "x y obj ... endobj" syntax.
        As far as I understood, object streams data is contained within stream/endstream delimiters, and is
        gzipped.
        Object streams start with a set of object id/offset pairs separated by a space ; catenated object data
        immediately follows the last space ; for example :

            1167 0 1168 114 <</DA(/Helv 0 Tf 0 g )/DR<</Encoding<</PDFDocEncoding 1096 0 R>>/Font<</Helv 1094 0 R/ZaDb 1095 0 R>>>>/Fields[]>>[/ICCBased 1156 0 R]

        The above example specifies two objects :
            . Object #1167, which starts at offset 0 and ends before the second object, at offset #113 in
              the data. The contents are :
                <</DA(/Helv 0 Tf 0 g )/DR<</Encoding<</PDFDocEncoding 1096 0 R>>/Font<</Helv 1094 0 R/ZaDb 1095 0 R>>>>/Fields[]>>
            . Object #1168, which starts at offset #114 and continues until the end of the object stream.
              It contains the following data :
                [/ICCBased 1156 0 R]

        PARAMETERS
            $object_id (integer) -
                    Pdf object number.

        $object_data (string) -
            Object data.

        RETURN VALUE
            Returns false if any error occurred (mainly for syntax reasons).
        Otherwise, returns an associative array containing the following elements :
        - object_id :
            Array of all the object ids contained in the object stream.
        - object :
            Array of corresponding object data.

        The reason for this format is that it is identical to the array returned by the preg_match() function
        used in the Load() method for finding objects in a PDF file (ie, a regex that matches "x y oj/endobj"
        constructs).

     *-------------------------------------------------------------------------------------------------------------*/
    protected function DecodeObjectStream($object_id, $object_data)
    {
        // Extract gzipped data for this object
        if (preg_match('#[^/] stream ( (\r? \n) | \r ) (?P<stream> .*?) endstream#imsx', $object_data, $stream_match)) {
            $stream_data = $stream_match ['stream'];
            $type = $this->GetEncodingType($object_id, $object_data);
            $decoded_data = $this->DecodeData($object_id, $stream_data, $type, $object_data);

            if (self::$DEBUG > 1) {
                echo "\n----------------------------------- OBJSTREAM #$object_id\n$decoded_data";
            }
        } else {
            // Stay prepared to find one day a sample declared as an object stream but not having gzipped data delimited by stream/endstream tags
            if (self::$DEBUG > 1) {
                error(new DecodingException("Found object stream without gzipped data", $object_id));
            }

            return (false);
        }

        // Object streams data start with a series of object id/offset pairs. The offset is absolute to the first character
        // after the last space of these series.
        // Note : on Windows platforms, the default stack size is 1Mb. The following regular expression will make Apache crash in most cases,
        // so you have to enable the following lines in your http.ini file to set a stack size of 8Mb, as for Unix systems :
        //  Include conf/extra/httpd-mpm.conf
        //  ThreadStackSize 8388608
        if (!preg_match('/^ \s* (?P<series> (\d+ \s* )+ )/x', $decoded_data, $series_match)) {
            if (self::$DEBUG > 1) {
                error(new DecodingException("Object stream does not start with integer object id/offset pairs.", $object_id));
            }

            return (false);
        }

        // Extract the series of object id/offset pairs and the stream object data
        $series = explode(' ', rtrim(preg_replace('/\s+/', ' ', $series_match ['series'])));
        $data = substr($decoded_data, strlen($series_match ['series']));

        // $series should contain an even number of values
        if (count($series) % 2) {
            if (self::$DEBUG) {
                warning(new DecodingException("Object stream should start with an even number of integer values.", $object_id));
            }

            array_pop($series);
        }

        // Extract every individual object
        $objects = ['object_id' => [], 'object' => []];

        for ($i = 0, $count = count($series); $i < $count; $i += 2) {
            $object_id = ( integer )$series [$i];
            $offset = ( integer )$series [$i + 1];

            // If there is a "next" object, extract only a substring within the object stream contents
            if (isset($series [$i + 3])) {
                $object_contents = substr($data, $offset, $series [$i + 3] - $offset);
            } else {
                // Otherwise, extract everything until the end
                $object_contents = substr($data, $offset);
            }

            $objects ['object_id'] [] = $object_id;
            $objects ['object'] [] = $object_contents;
        }

        return ($objects);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            ExtractTextData - Extracts text, header & footer information from a text object.

        PROTOTYPE
            $this -> ExtractTextData ( $object_id, $stream_contents, &$text, &$header, &$footer ) ;

        DESCRIPTION
            Extracts text, header & footer information from a text object. The extracted text contents will be
        stripped from any header/footer information.

        PARAMETERS
            $text (string) -
                    Variable that will receive text contents.

        $header, $footer (string) -
            Variables that will receive header and footer information.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function ExtractTextData(/** @noinspection PhpUnusedParameterInspection */
        $object_id,
        $stream_contents,
        &$text,
        &$header,
        &$footer
    ) {
        // Normally, a header or footer is introduced with a construct like :
        //  << /Type /Pagination ... [/Bottom] ... >> (or [/Top]
        // The initial regular expression was :
        //  << .*? \[ \s* / (?P<location> (Bottom) | (Top) ) \s* \] .*? >> \s* BDC .*? EMC
        // (the data contained between the BDC and EMC instructions are text-drawing instructions).
        // However, this expression revealed to be too greedy and captured too much data ; in the following example :
        //  <</MCID 0>> ...(several kb of drawing instructions)... << ... [/Bottom] ... >> BDC (other drawing instructions for the page footer) EMC
        // everything was captured, from the initial "<<M/MCID 0>>" to the final "EMC", which caused regular page contents to be interpreted as page bottom
        // contents.
        // The ".*?" in the regex has been replaced with "[^>]*?", which works better. However, it will fail to recognize header/footer contents if
        // the header/footer declaration contains a nested construct , such as :
        //  << /Type /Pagination ... [/Bottom] ... << (some nested contents) >> ... >> (or [/Top]
        // Let's wait for the case to happen one day...
        static $header_or_footer_re = '#
								(?P<contents>
									<< [^>]*? \[ \s* / (?P<location> (Bottom) | (Top) ) \s* \] [^>]*? >> \s*
									BDC .*? EMC
								 )
							    #imsx';

        $header =
        $footer =
        $text = '';

        if (preg_match_all($header_or_footer_re, $stream_contents, $matches, PREG_OFFSET_CAPTURE)) {
            for ($i = 0, $count = count($matches ['contents']); $i < $count; $i++) {
                if (!strcasecmp($matches ['location'] [$i] [0], 'Bottom')) {
                    $footer = $matches ['contents'] [$i] [0];
                } else {
                    $header = $matches ['contents'] [$i] [0];
                }
            }

            $text = preg_replace($header_or_footer_re, '', $stream_contents);
        } else {
            $text = $stream_contents;
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
        ExtractText - extracts text from a pdf stream.

        PROTOTYPE
        $text   =  $this -> ExtractText ( $page_number, $object_id, $data, &$current_font ) ;

        DESCRIPTION
            Extracts text from decoded stream contents.

        PARAMETERS
        $page_number (integer) -
            �Page number that contains the text to be extracted.

            $object_id (integer) -
                Object id of this text block.

            $data (string) -
                Stream contents.

        $current_font (integer) -
            Id of the current font, which should be found in the $this->FontTable property, if anything
            went ok.
            This parameter is required, since text blocks may not specify a new font resource id and reuse
            the one that waas set before.

        RETURN VALUE
        Returns the decoded text.

        NOTES
        The PDF language can be seen as a stack-driven language  ; for example, the instruction defining a text
        matrix ( "Tm" ) expects 6 floating-point values from the stack :

            0 0 0 0 x y Tm

        It can also specify specific operators, such as /Rx, which sets font number "x" to be the current font,
        or even "<< >>" constructs that we can ignore during our process of extracting textual data.
        Actually, we only want to handle a very small subset of the Adobe drawing language ; These are :
        - "Tm" instructions, that specify, among others, the x and y coordinates of the next text to be output
        - "/R" instructions, that specify which font is to be used for the next text output. This is useful
          only if the font has an associated character map.
        - "/F", same as "/R", but use a font map id instead of a direct object id.
        - Text, specified either using a single notation ( "(sometext)" ) or the array notation
          ( "[(...)d1(...)d2...(...)]" ), which allows for specifying inter-character spacing.
         - "Tf" instructions, that specifies the font size. This is to be able to compute approximately the
           number of empty lines between two successive Y coordinates in "Tm" instructions
         - "TL" instructions, that define the text leading to be used by "T*"

        This is why I choosed to decompose the process of text extraction into three steps :
        - The first one, the lowest-level step, is a tokenizer that extracts individual elements, such as "Tm",
          "TJ", "/Rx" or "510.77". This is handled by the __next_token() method.
        - The second one, __next_instruction(), collects tokens. It pushes every floating-point value onto the
          stack, until an instruction is met.
        - The third one, ExtractText(), processes data returned by __next_instruction(), and actually performs
          the (restricted) parsing of text drawing instructions.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function ExtractText($page_number, $object_id, $data, &$current_font)
    {
        $new_data = $this->__strip_useless_instructions($data);

        if (self::$DEBUG) {
            echo "\n----------------------------------- TEXT #$object_id (size = " . strlen($data) . " bytes, new size = " . strlen($new_data) . " bytes)\n";
            echo $data;
            echo "\n----------------------------------- OPTIMIZED TEXT #$object_id (size = " . strlen($data) . " bytes, new size = " . strlen($new_data) . " bytes)\n";
            echo $new_data;
        }

        $data = $new_data;

        // Index into the specified block of text-drawing instructions
        $data_index = 0;

        $data_length = strlen($data);        // Data length
        $result = '';                // Resulting string

        // Y-coordinate of the last seen "Tm" instruction
        $last_goto_y = 0;
        //$last_goto_x          =  0 ;

        // Y-coordinate of the last seen "Td" or "TD" relative positioning instruction
        $last_relative_goto_y = 0;

        // When true, the current text should be output on the same line as the preceding one
        $use_same_line = false;

        // Instruction preceding the current one
        //$last_instruction         =  true ;

        // Current font size
        $current_font_size = 0;

        // Active template
        $current_template = '';

        // Various pre-computed variables
        $separator_length = strlen($this->Separator);

        // Current font map width, in bytes, plus a flag saying whether the current font is mapped or not
        $this->FontTable->GetFontAttributes($page_number, $current_template, $current_font, $current_font_map_width, $current_font_mapped);

        // Extra newlines to add before the current text
        $extra_newlines = 0;

        // Text leading used by T*
        $text_leading = 0;

        // Set to true if a separator needs to be inserted
        //$needs_separator      =  false ;

        // A flag to tell if we should "forget" the last instruction
        $discard_last_instruction = false;

        // A flag that tells whether the Separator and BlockSeparator properties are identical
        $same_separators = ($this->Separator == $this->BlockSeparator);

        // Instruction count (used for handling execution timeouts)
        $instruction_count = 0;

        // Unprocessed markers
        $unprocessed_marker_count = count($this->UnprocessedMarkerList ['font']);

        // Loop through instructions
        while (($instruction = $this->__next_instruction($page_number, $data, $data_length, $data_index, $current_template)) !== false) {
            $fragment = '';

            $instruction_count++;

            // Timeout handling - don't test for every instruction processed
            if (!($instruction_count % 100)) {
                // Global timeout handling
                if ($this->Options & self::PDFOPT_ENFORCE_GLOBAL_EXECUTION_TIME) {
                    $now = microtime(true);

                    if ($now - self::$GlobalExecutionStartTime > self::$MaxGlobalExecutionTime) {
                        error(new TimeoutException("file {$this -> Filename}", true, self::$PhpMaxExecutionTime, self::$MaxGlobalExecutionTime));
                    }
                }

                // Per-instance timeout handling
                if ($this->Options & self::PDFOPT_ENFORCE_EXECUTION_TIME) {
                    $now = microtime(true);

                    if ($now - $this->ExecutionStartTime > $this->MaxExecutionTime) {
                        error(new TimeoutException("file {$this -> Filename}", false, self::$PhpMaxExecutionTime, $this->MaxExecutionTime));
                    }
                }
            }

            // Character position after the current instruction
            $data_index = $instruction ['next'];

            // Process current instruction
            switch ($instruction ['instruction']) {
                // Raw text (enclosed by parentheses) or array text (enclosed within square brackets)
                // is returned as a single instruction
                case    'text' :
                    // Empty arrays of text may be encountered - ignore them
                    if (!count($instruction ['values'])) {
                        break;
                    }

                    // Check if we have to insert a newline
                    if (!$use_same_line) {
                        $fragment .= $this->EOL;
                        $needs_separator = false;
                    } elseif ($extra_newlines > 0) {
                        // Roughly simulate spacing between lines by inserting newline characters
                        $fragment .= str_repeat($this->EOL, $extra_newlines);
                        $extra_newlines = 0;
                        $needs_separator = false;
                    } else {
                        $needs_separator = true;
                    }

                    // Add a separator if necessary
                    if ($needs_separator) {
                        // If the Separator and BlockSeparator properties are the same (and not empty), only add a block separator if
                        // the current result does not end with it
                        if ($same_separators) {
                            if ($this->Separator != '' && substr($fragment, -$separator_length) != $this->BlockSeparator) {
                                $fragment .= $this->BlockSeparator;
                            }
                        } else {
                            $fragment .= $this->BlockSeparator;
                        }
                    }

                    //$needs_separator  =  true ;
                    $value_index = 0;

                    // Fonts having character maps will require some special processing
                    if ($current_font_mapped) {
                        // Loop through each text value
                        foreach ($instruction ['values'] as $text) {
                            $is_hex = ($text [0] == '<');
                            $length = strlen($text) - 1;
                            $handled = false;

                            // Characters are encoded within angle brackets ( "<>" ).
                            // Note that several characters can be specified within the same angle brackets, so we have to take
                            // into account the width we detected in the begincodespancerange construct
                            if ($is_hex) {
                                for ($i = 1; $i < $length; $i += $current_font_map_width) {
                                    $value = substr($text, $i, $current_font_map_width);
                                    $ch = hexdec($value);

                                    if (isset($this->CharacterMapBuffer [$current_font] [$ch])) {
                                        $newchar = $this->CharacterMapBuffer [$current_font] [$ch];
                                    } elseif ($current_font == -1) {
                                        $newchar = chr($ch);
                                    } else {
                                        $newchar = $this->FontTable->MapCharacter($current_font, $ch);
                                        $this->CharacterMapBuffer [$current_font] [$ch] = $newchar;
                                    }

                                    $fragment .= $newchar;
                                }

                                $handled = true;
                            } elseif ($current_font_map_width == 4) {
                                // Yes ! double-byte codes can also be specified as plain text within parentheses !
                                // However, we have to be really careful here ; the sequence :
                                //  (Be)
                                // can mean the string "Be" or the Unicode character 0x4265 ('B' = 0x42, 'e' = 0x65)
                                // We first look if the character map contains an entry for Unicode codepoint 0x4265 ;
                                // if not, then we have to consider that it is regular text to be taken one character by
                                // one character. In this case, we fall back to the "if ( ! $handled )" condition
                                $temp_result = '';

                                for ($i = 1; $i < $length; $i++) {
                                    // Each character in the pair may be a backslash, which escapes the next character so we must skip it
                                    // This code needs to be reviewed ; the same code is duplicated to handle escaped characters in octal notation
                                    if ($text [$i] != '\\') {
                                        $ch1 = $text [$i];
                                    } else {
                                        $i++;

                                        if ($text [$i] < '0' || $text [$i] > '7') {
                                            $ch1 = $this->ProcessEscapedCharacter($text [$i]);
                                        } else {
                                            $oct = '';
                                            $digit_count = 0;

                                            while ($i < $length && $text [$i] >= '0' && $text [$i] <= '7' && $digit_count < 3) {
                                                $oct .= $text [$i++];
                                                $digit_count++;
                                            }

                                            $ch1 = chr(octdec($oct));
                                            $i--;
                                        }
                                    }

                                    $i++;

                                    if ($text [$i] != '\\') {
                                        $ch2 = $text [$i];
                                    } else {
                                        $i++;

                                        if ($text [$i] < '0' || $text [$i] > '7') {
                                            $ch2 = $this->ProcessEscapedCharacter($text [$i]);
                                        } else {
                                            $oct = '';
                                            $digit_count = 0;

                                            while ($i < $length && $text [$i] >= '0' && $text [$i] <= '7' && $digit_count < 3) {
                                                $oct .= $text [$i++];
                                                $digit_count++;
                                            }

                                            $ch2 = chr(octdec($oct));
                                            $i--;
                                        }
                                    }

                                    // Build the 2-bytes character code
                                    $ch = (ord($ch1) << 8) | ord($ch2);

                                    if (isset($this->CharacterMapBuffer [$current_font] [$ch])) {
                                        $newchar = $this->CharacterMapBuffer [$current_font] [$ch];
                                    } else {
                                        $newchar = $this->FontTable->MapCharacter($current_font, $ch, true);
                                        $this->CharacterMapBuffer [$current_font] [$ch] = $newchar;
                                    }

                                    // Yes !!! for characters encoded with two bytes, we can find the following construct :
                                    //  0x00 "\" "(" 0x00 "C" 0x00 "a" 0x00 "r" 0x00 "\" ")"
                                    // which must be expanded as : (Car)
                                    // We have here the escape sequences "\(" and "\)", but the backslash is encoded on two bytes
                                    // (although the MSB is nul), while the escaped character is encoded on 1 byte. waiting
                                    // for the next quirk to happen...
                                    if ($newchar == '\\' && isset($text [$i + 2])) {
                                        $newchar = $this->ProcessEscapedCharacter($text [$i + 2]);
                                        $i++;        // this time we processed 3 bytes, not 2
                                    }

                                    $temp_result .= $newchar;
                                }

                                // Happens only if we were unable to translate a character using the current character map
                                $fragment .= $temp_result;
                                $handled = true;
                            }

                            // Character strings within parentheses.
                            // For every text value, use the character map table for substitutions
                            if (!$handled) {
                                for ($i = 1; $i < $length; $i++) {
                                    $ch = $text [$i];

                                    // Set to true to optimize calls to MapCharacters
                                    // Currently does not work with pobox@dizy.sk/infoma.pdf (a few characters differ)
                                    $use_map_buffer = false;

                                    // ... but don't forget to handle escape sequences "\n" and "\r" for characters
                                    // 10 and 13
                                    if ($ch == '\\') {
                                        $ch = $text [++$i];

                                        // Escaped character
                                        if ($ch < '0' || $ch > '7') {
                                            $ch = $this->ProcessEscapedCharacter($ch);
                                        } else {
                                            // However, an octal form can also be specified ; in this case we have to take into account
                                            // the character width for the current font (if the character width is 4 hex digits, then we
                                            // will encounter constructs such as "\000\077").
                                            // The method used here is dirty : we build a regex to match octal character representations on a substring
                                            // of the text
                                            $width = $current_font_map_width / 2;    // Convert to byte count
                                            $subtext = substr($text, $i - 1);
                                            $regex = "#^ (\\\\ [0-7]{3}){1,$width} #imsx";

                                            $status = preg_match($regex, $subtext, $octal_matches);

                                            if ($status) {
                                                $octal_values = explode('\\', substr($octal_matches [0], 1));
                                                $ord = 0;

                                                foreach ($octal_values as $octal_value) {
                                                    $ord = ($ord << 8) + octdec($octal_value);
                                                }

                                                $ch = chr($ord);
                                                $i += strlen($octal_matches [0]) - 2;
                                            }
                                        }

                                        $use_map_buffer = false;
                                    }

                                    // Add substituted character to the output result
                                    $ord = ord($ch);

                                    if (!$use_map_buffer) {
                                        $newchar = $this->FontTable->MapCharacter($current_font, $ord);
                                    } else {
                                        if (isset($this->CharacterMapBuffer [$current_font] [$ord])) {
                                            $newchar = $this->CharacterMapBuffer [$current_font] [$ord];
                                        } else {
                                            $newchar = $this->FontTable->MapCharacter($current_font, $ord);
                                            $this->CharacterMapBuffer [$current_font] [$ord] = $newchar;
                                        }
                                    }

                                    $fragment .= $newchar;
                                }
                            }

                            // Handle offsets between blocks of characters
                            if (isset($instruction ['offsets'] [$value_index]) &&
                                -($instruction ['offsets'] [$value_index]) > $this->MinSpaceWidth
                            ) {
                                $fragment .= $this->__get_character_padding($instruction ['offsets'] [$value_index]);
                            }

                            $value_index++;
                        }
                    } else {
                        // For fonts having no associated character map, we simply encode the string in UTF8
                        // after the C-like escape sequences have been processed
                        // Note that <xxxx> constructs can be encountered here, so we have to process them as well
                        foreach ($instruction ['values'] as $text) {
                            $is_hex = ($text [0] == '<');
                            $length = strlen($text) - 1;

                            // Some text within parentheses may have a backslash followed by a newline, to indicate some continuation line.
                            // Example :
                            //  (this is a sentence \
                            //   continued on the next line)
                            // Funny isn't it ? so remove such constructs because we don't care
                            $text = str_replace(["\\\r\n", "\\\r", "\\\n"], '', $text);

                            // Characters are encoded within angle brackets ( "<>" )
                            if ($is_hex) {
                                for ($i = 1; $i < $length; $i += 2) {
                                    $ch = hexdec(substr($text, $i, 2));

                                    $fragment .= $this->CodePointToUtf8($ch);
                                }
                            } else {
                                // Characters are plain text
                                $text = self::Unescape($text);

                                for ($i = 1, $length = strlen($text) - 1; $i < $length; $i++) {
                                    $ch = $text [$i];
                                    $ord = ord($ch);

                                    if ($ord < 127) {
                                        $newchar = $ch;
                                    } else {
                                        if (isset($this->CharacterMapBuffer [$current_font] [$ord])) {
                                            $newchar = $this->CharacterMapBuffer [$current_font] [$ord];
                                        } else {
                                            $newchar = $this->FontTable->MapCharacter($current_font, $ord);
                                            $this->CharacterMapBuffer [$current_font] [$ord] = $newchar;
                                        }
                                    }

                                    $fragment .= $newchar;
                                }
                            }

                            // Handle offsets between blocks of characters
                            if (isset($instruction ['offsets'] [$value_index]) &&
                                abs($instruction ['offsets'] [$value_index]) > $this->MinSpaceWidth
                            ) {
                                $fragment .= $this->__get_character_padding($instruction ['offsets'] [$value_index]);
                            }

                            $value_index++;
                        }
                    }

                    // Process the markers which do not have an associated font yet - this will be done by matching
                    // the current text fragment against one of the regular expressions defined.
                    // If a match occurs, then all the subsequent text fragment using the same font will be put markers
                    for ($j = 0; $j < $unprocessed_marker_count; $j++) {
                        $marker = $this->UnprocessedMarkerList ['font'] [$j];

                        if (preg_match($marker ['regex'], trim($fragment))) {
                            $this->TextWithFontMarkers [$current_font] =
                            [
                                'font' => $current_font,
                                'height' => $current_font_size,
                                'regex' => $marker   ['regex'],
                                'start' => $marker   ['start'],
                                'end' => $marker   ['end']
                            ];

                            $unprocessed_marker_count--;
                            unset($this->UnprocessedMarkerList ['font'] [$j]);

                            break;
                        }
                    }

                    // Check if we need to add markers around this text fragment
                    if (isset($this->TextWithFontMarkers [$current_font]) &&
                        $this->TextWithFontMarkers [$current_font] ['height'] == $current_font_size
                    ) {
                        $fragment = $this->TextWithFontMarkers [$current_font] ['start'] .
                            $fragment .
                            $this->TextWithFontMarkers [$current_font] ['end'];
                    }

                    $result .= $fragment;

                    break;

                // An "nl" instruction means TJ, Tj, T* or "'"
                case    'nl' :
                    if (!$instruction ['conditional']) {
                        if ($instruction ['leading'] && $text_leading && $current_font_size) {
                            $count = ( integer )(($text_leading - $current_font_size) / $current_font_size);

                            if (!$count) {
                                $count = 1;
                            }
                        } else {
                            $count = 1;
                        }

                        $extra = str_repeat(PHP_EOL, $count);
                        $result .= $extra;
                        //$needs_separator   =  false ;
                        $last_goto_y -= ($count * $text_leading);    // Approximation on y-coord change
                        $last_relative_goto_y = 0;
                    }

                    break;

                // "Tm", "Td" or "TD" : Output text on the same line, if the "y" coordinates are equal
                case    'goto' :
                    // Some text is positioned using 'Tm' instructions ; however they can be immediatley followed by 'Td' instructions
                    // which give a relative positioning ; so consider that the last instruction wins
                    if ($instruction ['relative']) {
                        // Try to put a separator if the x coordinate is non-zero
                        //if  ( $instruction [ 'x' ] - $last_goto_x  >=  $current_font_size )
                        //  $result     .=  $this -> Separator ;

                        $discard_last_instruction = true;
                        $extra_newlines = 0;
                        $use_same_line = (($last_relative_goto_y - abs($instruction  ['y'])) <= $current_font_size);
                        $last_relative_goto_y = abs($instruction ['y']);
                        //$last_goto_x          =  $instruction [ 'x' ] ;

                        if (-$instruction ['y'] > $current_font_size) {
                            $use_same_line = false;

                            if ($last_relative_goto_y) {
                                $extra_newlines = ( integer )($current_font_size / $last_relative_goto_y);
                            } else {
                                $extra_newlines = 0;
                            }
                        } elseif (!$instruction ['y']) {
                            $use_same_line = true;
                            $extra_newlines = 0;
                        }

                        break;
                    } else {
                        $last_relative_goto_y = 0;
                    }

                    $y = $last_goto_y + $last_relative_goto_y;

                    if ($instruction ['y'] == $y || abs($instruction ['y'] - $y) < $current_font_size) {
                        $use_same_line = true;
                        $extra_newlines = 0;
                    } else {
                        // Compute the number of newlines we have to insert between the current and the next lines
                        if ($current_font_size) {
                            $extra_newlines = ( integer )(($y - $instruction ['y'] - $current_font_size) / $current_font_size);
                        }

                        $use_same_line = ($last_goto_y == 0);
                    }

                    $last_goto_y = $instruction ['y'];
                    break;

                // Set font size
                case    'fontsize' :
                    $current_font_size = $instruction ['size'];
                    break;

                // "/Rx" : sets the current font
                case    'resource' :
                    $current_font = $instruction ['resource'];

                    $this->FontTable->GetFontAttributes($page_number, $current_template, $current_font, $current_font_map_width, $current_font_mapped);
                    break;

                // "/TPLx" : references a template, which can contain additional font aliases
                case    'template' :
                    if ($this->PageMap->IsValidXObjectName($instruction ['token'])) {
                        $current_template = $instruction ['token'];
                    }

                    break;

                // 'TL' : text leading to be used for the next "T*" in the flow
                case    'leading' :
                    if (!($this->Options & self::PDFOPT_IGNORE_TEXT_LEADING)) {
                        $text_leading = $instruction ['size'];
                    }

                    break;


                // 'ET' : we have to reset a few things here
                case    'ET' :
                    $current_font = -1;
                    $current_font_map_width = 2;
                    break;
            }

            // Remember last instruction - this will help us into determining whether we should put the next text
            // on the current or following line
            if (!$discard_last_instruction) {
                /** @noinspection PhpUnusedLocalVariableInspection */
                $last_instruction = $instruction;
            }

            $discard_last_instruction = false;
        }

        return ($this->__rtl_process($result));
    }



    // __next_instruction -
    //  Retrieves the next instruction from the drawing text block.
    private function __next_instruction($page_number, $data, $data_length, $index, $current_template)
    {
        static $last_instruction = false;

        //$ch   =  '' ;

        // Constructs such as
        if ($last_instruction) {
            $result = $last_instruction;
            $last_instruction = false;

            return ($result);
        }

        // Whether we should compute enhanced statistics
        $enhanced_statistics = $this->EnhancedStatistics;

        // Holds the floating-point values encountered so far
        $number_stack = [];

        // Loop through the stream of tokens
        while (($part = $this->__next_token($page_number, $data, $data_length, $index)) !== false) {
            $token = $part [0];
            $next_index = $part [1];

            // Floating-point number : push it onto the stack
            if (($token [0] >= '0' && $token [0] <= '9') || $token [0] == '-' || $token [0] == '+' || $token [0] == '.') {
                $number_stack [] = $token;
                $enhanced_statistics && $this->Statistics ['Distributions'] ['operand']++;
            } elseif ($token == 'Tm') {
                // 'Tm' instruction : return a "goto" instruction with the x and y coordinates
                $x = $number_stack [4];
                $y = $number_stack [5];

                $enhanced_statistics && $this->Statistics ['Distributions'] ['Tm']++;

                return (['instruction' => 'goto', 'next' => $next_index, 'x' => $x, 'y' => $y, 'relative' => false, 'token' => $token]);
            } elseif ($token == 'Td' || $token == 'TD') {
                // 'Td' or 'TD' instructions : return a goto instruction with the x and y coordinates (1st and 2nd args)
                $x = $number_stack [0];
                $y = $number_stack [1];

                $enhanced_statistics && $this->Statistics ['Distributions'] [$token]++;

                return (['instruction' => 'goto', 'next' => $next_index, 'x' => $x, 'y' => $y, 'relative' => true, 'token' => $token]);
            } elseif ($token [0] == "'") {
                // Output text "'" instruction, with conditional newline
                $enhanced_statistics && $this->Statistics ['Distributions'] ["'"]++;

                return (['instruction' => 'nl', 'next' => $next_index, 'conditional' => true, 'leading' => false, 'token' => $token]);
            } elseif ($token == 'TJ' || $token == 'Tj') {
                // Same as above
                $enhanced_statistics && $this->Statistics ['Distributions'] [$token]++;

                return (['instruction' => 'nl', 'next' => $next_index, 'conditional' => true, 'leading' => false, 'token' => $token]);
            } elseif ($token == 'Tf') {
                // Set font size
                $enhanced_statistics && $this->Statistics ['Distributions'] ['Tf']++;

                return (['instruction' => 'fontsize', 'next' => $next_index, 'size' => $number_stack [0], 'token' => $token]);
            } elseif ($token == 'TL') {
                // Text leading (spacing used by T*)
                $enhanced_statistics && $this->Statistics ['Distributions'] ['TL']++;

                return (['instruction' => 'leading', 'next' => $next_index, 'size' => $number_stack [0], 'token' => $token]);
            } elseif ($token == 'T*') {
                // Position to next line
                $enhanced_statistics && $this->Statistics ['Distributions'] ['T*']++;

                return (['instruction' => 'nl', 'next' => $next_index, 'conditional' => true, 'leading' => true]);
            } elseif ($token == 'Do') {
                // Draw object ("Do"). To prevent different text shapes to appear on the same line, we return a "newline" instruction
                // here. Note that the shape position is not taken into account here, and shapes will be processed in the order they
                // appear in the pdf file (which is likely to be different from their position on a graphic screen).
                $enhanced_statistics && $this->Statistics ['Distributions'] ['ignored']++;

                return (['instruction' => 'nl', 'next' => $next_index, 'conditional' => false, 'leading' => false, 'token' => $token]);
            } elseif ($token [0] == '(') {
                // Raw text output
                $next_part = $this->__next_token($page_number, $data, $data_length, $next_index, $enhanced_statistics);
                $instruction = ['instruction' => 'text', 'next' => $next_index, 'values' => [$token], 'token' => $token];
                $enhanced_statistics && $this->Statistics ['Distributions'] ['(']++;

                if ($next_part [0] == "'") {
                    $last_instruction = $instruction;
                    return (['instruction' => 'nl', 'next' => $next_index, 'conditional' => false, 'leading' => true, 'token' => $token]);
                } else {
                    return ($instruction);
                }
            } elseif ($token [0] == '<') {
                // Hex digits within angle brackets
                $ch = $token [1];
                $enhanced_statistics && $this->Statistics ['Distributions'] ['<']++;
                //$instruction  =  array ( 'instruction' => 'text', 'next' => $next_index, 'values' => array ( $token ), 'token' => $token ) ;

                if (self::$CharacterClasses [$ch] & self::CTYPE_ALNUM) {
                    $next_part = $this->__next_token($page_number, $data, $data_length, $next_index);
                    $instruction = ['instruction' => 'text', 'next' => $next_index, 'values' => [$token], 'token' => $token];

                    if ($next_part [0] == "'") {
                        $last_instruction = $instruction;
                        return (['instruction' => 'nl', 'next' => $next_index, 'conditional' => false, 'leading' => true, 'token' => $token]);
                    } else {
                        return ($instruction);
                    }
                }
            } elseif ($token [0] == '[') {
                // Text specified as an array of individual raw text elements, and individual interspaces between characters
                $values = $this->__extract_chars_from_array($token);
                $enhanced_statistics && $this->Statistics ['Distributions'] ['[']++;
                $instruction = ['instruction' => 'text', 'next' => $next_index, 'values' => $values [0], 'offsets' => $values [1], 'token' => $token];

                return ($instruction);
            } elseif (preg_match('#^ ( ' . self::$FontSpecifiers . ' ) #ix', $token)) {
                // Token starts with a slash : maybe a font specification
                $key = "$page_number:$current_template:$token";
                $enhanced_statistics && $this->Statistics ['Distributions'] ['operand']++;

                if (isset($this->MapIdBuffer [$key])) {
                    $id = $this->MapIdBuffer [$key];
                } else {
                    $id = $this->FontTable->GetFontByMapId($page_number, $current_template, $token);

                    $this->MapIdBuffer [$key] = $id;
                }

                return (['instruction' => 'resource', 'next' => $next_index, 'resource' => $id, 'token' => $token]);
            } elseif (preg_match('/ !PDFTOTEXT_TEMPLATE_ (?P<template> \w+) /ix', $token, $match)) {
                // Template reference, such as /TPL1. Each reference has initially been replaced by !PDFTOTEXT_TEMPLATE_TPLx during substitution
                // by ProcessTemplateReferences(), because templates not only specify text to be replaced, but also font aliases
                // -and this is the place where we catch font aliases in this case
                $current_template = '/' . $match ['template'];
                $enhanced_statistics && $this->Statistics ['Distributions'] ['template']++;

                return (['instruction' => 'template', 'next' => $next_index, 'token' => $current_template]);
            } elseif ($token === 'cm') {
                // Others, only counted for statistics
                $enhanced_statistics && $this->Statistics ['Distributions'] ['cm']++;
            } elseif ($token === 'BT') {
                $enhanced_statistics && $this->Statistics ['Distributions'] ['BT']++;

                return (['instruction' => 'BT', 'next' => $next_index, 'token' => $token]);
            } elseif ($token == 'ET') {    // Nothing special to count here
                return (['instruction' => 'ET', 'next' => $next_index, 'token' => $token]);
            } else {
                // Other instructions : we're not that much interested in them, so clear the number stack and consider
                // that the current parameters, floating-point values, have been processed
                $number_stack = [];
                $enhanced_statistics && $this->Statistics ['Distributions'] ['ignored']++;
            }

            $index = $next_index;
        }

        // End of input
        return (false);
    }


    // __next_token :
    //  Retrieves the next token from the drawing instructions stream.
    private function __next_token(/** @noinspection PhpUnusedParameterInspection */
        $page_number,
        $data,
        $data_length,
        $index,
        $enhanced_statistics = null
    ) {
        // Skip spaces
        $count = 0;

        while ($index < $data_length && ($data [$index] == ' ' || $data [$index] == "\t" || $data [$index] == "\r" || $data [$index] == "\n")) {
            $index++;
            $count++;
        }

        $enhanced_statistics = $this->EnhancedStatistics;
        //$enhanced_statistics  &&  $this -> Statistics [ 'Distributions' ] [ 'space' ] +=  $count ;
        if ($enhanced_statistics) {
            $this->Statistics ['Distributions'] ['space'] += $count;
        }

        // End of input
        if ($index >= $data_length) {
            return (false);
        }

        // The current character will tell us what to do
        $ch = $data [$index];
        //$ch2  =  '' ;

        switch ($ch) {
            // Opening square bracket : we have to find the closing one, taking care of escape sequences
            // that can also specify a square bracket, such as "\]"
            case    "[" :
                $pos = $index + 1;
                $parent = 0;
                $angle = 0;
                $result = $ch;

                while ($pos < $data_length) {
                    $nch = $data [$pos++];

                    switch ($nch) {
                        case    '(' :
                            $parent++;
                            $result .= $nch;
                            break;

                        case    ')' :
                            $parent--;
                            $result .= $nch;
                            break;

                        case    '<' :
                            // Although the array notation can contain hex digits between angle brackets, we have to
                            // take care that we do not have an angle bracket between two parentheses such as :
                            // [ (<) ... ]
                            if (!$parent) {
                                $angle++;
                            }

                            $result .= $nch;
                            break;

                        case    '>' :
                            if (!$parent) {
                                $angle--;
                            }

                            $result .= $nch;
                            break;

                        case    '\\' :
                            $result .= $nch . $data [$pos++];
                            break;

                        case    ']' :
                            $result .= ']';

                            if (!$parent) {
                                break  2;
                            } else {
                                break;
                            }

                        case    "\n" :
                        case    "\r" :
                            break;

                        default :
                            $result .= $nch;
                    }
                }

                return ([$result, $pos]);

            // Parenthesis : Again, we have to find the closing parenthesis, taking care of escape sequences
            // such as "\)"
            case    "(" :
                $pos = $index + 1;
                $result = $ch;

                while ($pos < $data_length) {
                    $nch = $data [$pos++];

                    if ($nch == '\\') {
                        $after = $data [$pos];

                        // Character references specified as \xyz, where "xyz" are octal digits
                        if ($after >= '0' && $after <= '7') {
                            $result .= $nch;

                            while ($data [$pos] >= '0' && $data [$pos] <= '7') {
                                $result .= $data [$pos++];
                            }
                        } else {
                            // Regular character escapes
                            $result .= $nch . $data [$pos++];
                        }
                    } elseif ($nch == ')') {
                        $result .= ')';
                        break;
                    } else {
                        $result .= $nch;
                    }
                }

                return ([$result, $pos]);

            // A construction of the form : "<< something >>", or a unicode character
            case    '<' :
                if (!isset($data [$index + 1])) {
                    return (false);
                }

                if ($data [$index + 1] == '<') {
                    $pos = strpos($data, '>>', $index + 2);

                    if ($pos === false) {
                        return (false);
                    }

                    return ([substr($data, $index, $pos - $index + 2), $pos + 2]);
                } else {
                    $pos = strpos($data, '>', $index + 2);

                    if ($pos === false) {
                        return (false);
                    }

                    // There can be spaces and newlines inside a series of hex digits, so remove them...
                    $result = preg_replace('/\s+/', '', substr($data, $index, $pos - $index + 1));

                    return ([$result, $pos + 1]);
                }

            // Tick character : consider it as a keyword, in the same way as the "TJ" or "Tj" keywords
            case    "'" :
                return (["'", $index + 1]);

            // Other cases : this may be either a floating-point number or a keyword
            default :
                $index++;
                $value = $ch;

                if (isset($data [$index])) {
                    if ((self::$CharacterClasses [$ch] & self::CTYPE_DIGIT) ||
                        $ch == '-' || $ch == '+' || $ch == '.'
                    ) {
                        while ($index < $data_length &&
                            ((self::$CharacterClasses [$data [$index]] & self::CTYPE_DIGIT) ||
                                $data [$index] == '.')) {
                            $value .= $data [$index++];
                        }
                    } elseif ((self::$CharacterClasses [$ch] & self::CTYPE_ALPHA) ||
                        $ch == '/' || $ch == '!'
                    ) {
                        $ch = $data [$index];

                        while ($index < $data_length &&
                            ((self::$CharacterClasses [$ch] & self::CTYPE_ALNUM) ||
                                $ch == '*' || $ch == '-' || $ch == '_' || $ch == '.' || $ch == '+')) {
                            $value .= $ch;
                            $index++;

                            if (isset($data [$index])) {
                                $ch = $data [$index];
                            }
                        }
                    }
                }

                return ([$value, $index]);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            ExtractTextWithLayout - Extracts text, trying to render the page layout.

        $text   =  $this -> ExtractTextWithLayout ( $page_number, $object_id, $data, &$current_font ) ;

        DESCRIPTION
            Extracts text from decoded stream contents, trying to render the layout.

        PARAMETERS
        $page_number (integer) -
            �Page number that contains the text to be extracted.

            $object_id (integer) -
                Object id of this text block.

            $data (string) -
                Stream contents.

        $current_font (integer) -
            Id of the current font, which should be found in the $this->FontTable property, if anything
            went ok.
            This parameter is required, since text blocks may not specify a new font resource id and reuse
            the one that waas set before.

        RETURN VALUE
        Returns the decoded text.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function ExtractTextWithLayout(&$page_fragments, $page_number, $object_id, $data, &$current_font)
    {
        // Characters that can start a numeric operand
        static $numeric_starts =
        [
            '+' => true, '-' => true, '.' => true, '0' => true, '1' => true, '2' => true, '3' => true, '4' => true,
            '5' => true, '6' => true, '7' => true, '8' => true, '9' => true
        ];
        // Initial (default) transformation matrix. To reflect the PDF specifications, we will keep it as a 6 elements array :
        //  [ sx tx ty sy x y ]
        // (although tx and ty are not useful here, since they affect the graphic orientation of the text)
        // sx and sy are scaling parameters, actually a multiplier for the x and y parameters. We only keep
        static $IdentityMatrix = [1, 0, 0, 1, 0, 0];

        // Remove useless instructions
        $new_data = $this->__strip_useless_instructions($data);

        if (self::$DEBUG) {
            echo "\n----------------------------------- TEXT #$object_id (size = " . strlen($data) . " bytes, new size = " . strlen($new_data) . " bytes)\n";
            echo $data;
            echo "\n----------------------------------- OPTIMIZED TEXT #$object_id (size = " . strlen($data) . " bytes, new size = " . strlen($new_data) . " bytes)\n";
            echo $new_data;
        }

        $data = $new_data;
        $data_length = strlen($data);        // Data length

        $page_fragment_count = count($page_fragments);

        // Index into the specified block of text-drawing instructions
        $data_index = 0;

        // Text matrices
        $CTM =
        $Tm = $IdentityMatrix;

        // Nesting level of BT..ET instructions (Begin text/End text) - they are not nestable but be prepared to meet buggy PDFs
        $BT_nesting_level = 0;

        // Current font data
        $current_font_height = 0;

        // Current font map width, in bytes, plus a flag saying whether the current font is mapped or not
        $current_template = '';
        $current_font_name = '';
        $this->FontTable->GetFontAttributes($page_number, $current_template, $current_font, $current_font_map_width, $current_font_mapped);

        // Operand stack
        $operand_stack = [];

        // Number of tokens processed so far
        $token_count = 0;

        // Page attributes
        $page_attributes = $this->PageMap->PageAttributes [$page_number];

        // Graphics context stack - well, we only store here the current transformation matrix
        $graphic_stack = [];
        $graphic_stack_size = 0;

        // Global/local execution time measurements
        $tokens_between_timechecks = 1000;
        $enforce_global_execution_time = $this->Options & self::PDFOPT_ENFORCE_GLOBAL_EXECUTION_TIME;
        $enforce_local_execution_time = $this->Options & self::PDFOPT_ENFORCE_EXECUTION_TIME;
        $enforce_execution_time = $enforce_global_execution_time | $enforce_local_execution_time;

        // Whether we should compute enhanced statistics
        $enhanced_statistics = $this->EnhancedStatistics;

        // Whether we should show debug coordinates
        //$show_debug_coordinates       =  ( $this -> Options & self::PDFOPT_DEBUG_SHOW_COORDINATES ) ;

        // Text leading value set by the TL instruction
        $text_leading = 0.0;

        // Loop through the stream of tokens
        while ($this->__next_token_ex($page_number, $data, $data_length, $data_index, $token, $next_index) !== false) {
            $token_start = $token [0];
            $token_count++;
            $length = $next_index - $data_index - 1;

            // Check if we need to enforce execution time checking, to prevent PHP from terminating our script without any hope
            // of catching the error
            if ($enforce_execution_time && !($token_count % $tokens_between_timechecks)) {
                if ($enforce_global_execution_time) {
                    $now = microtime(true);

                    if ($now - self::$GlobalExecutionStartTime > self::$MaxGlobalExecutionTime) {
                        error(new TimeoutException("file {$this -> Filename}", true, self::$PhpMaxExecutionTime, self::$MaxGlobalExecutionTime));
                    }
                }

                // Per-instance timeout handling
                if ($enforce_local_execution_time) {
                    $now = microtime(true);

                    if ($now - $this->ExecutionStartTime > $this->MaxExecutionTime) {
                        error(new TimeoutException("file {$this -> Filename}", false, self::$PhpMaxExecutionTime, $this->MaxExecutionTime));
                    }
                }
            }

            /****************************************************************************************************************
             *
             * The order of the testings is important for maximum performance : put the most common cases first.
             * A study on over 1000 PDF files has shown the following :
             *
             * - Instruction operands appear 24.5 million times
             * - Tx instructions (including Tf, Tm, ', ", etc.) : 24M
             * - (), <> and [] constructs for drawing text : 17M
             * - Other : peanuts...
             * - Ignored instructions : 0.5M (these are the instructions without interest for text extraction and that
             * could not be removed by the __strip_useless_instructions() method).
             *
             * Of course, white spaces appear more than 100M times between instructions. However, it gets hard to remove
             * most of them without compromising the result of __strip_useless_instructions.
             ***************************************************************************************************************/
            // Numeric or flag for an instruction
            if ($token_start == '/' || isset($numeric_starts [$token_start])) {
                $operand_stack [] = $token;

                $enhanced_statistics && $this->Statistics ['Distributions'] ['operand']++;
            } elseif (($length === 2 && $token_start === 'T') || ($length === 1 && ($token_start === "'" || $token_start === '"'))) {
                // A 2-characters "Tx" or a 1-character quote/doublequote instruction
                switch (($length === 1) ? $token [0] : $token [1]) {
                    // Tj instruction
                    case    'j' :
                        $enhanced_statistics && $this->Statistics ['Distributions'] ['Tj']++;
                        break;

                    // Tm instruction
                    case    'm' :
                        $Tm [0] = ( double )$operand_stack [0];
                        $Tm [1] = ( double )$operand_stack [1];
                        $Tm [2] = ( double )$operand_stack [2];
                        $Tm [3] = ( double )$operand_stack [3];
                        $Tm [4] = ( double )$operand_stack [4];
                        $Tm [5] = ( double )$operand_stack [5];

                        $enhanced_statistics && $this->Statistics ['Distributions'] ['Tm']++;
                        break;

                    // Tf instruction
                    case    'f' :
                        $current_font_name = $operand_stack [0];
                        $key = "$page_number:$current_template:$current_font_name";

                        // We have to map a font specifier (such /TT0, C0-1, etc.) into an object id.
                        // Check first if we already met this font
                        if (isset($this->MapIdBuffer [$key])) {
                            $current_font = $this->MapIdBuffer [$key];
                        } else {
                            // Otherwise retrieve its corresponding object number and put it in our font cache
                            $current_font = $this->FontTable->GetFontByMapId($page_number, $current_template, $current_font_name);

                            $this->MapIdBuffer [$key] = $current_font;
                        }

                        $current_font_height = ( double )$operand_stack [1];
                        $this->FontTable->GetFontAttributes($page_number, $current_template, $current_font, $current_font_map_width, $current_font_mapped);
                        $enhanced_statistics && $this->Statistics ['Distributions'] ['Tf']++;
                        break;

                    // Td instruction
                    case    'd' :
                        $Tm [4] += ( double )$operand_stack [0] * abs($Tm [0]);
                        $Tm [5] += ( double )$operand_stack [1] * abs($Tm [3]);

                        $enhanced_statistics && $this->Statistics ['Distributions'] ['Td']++;
                        break;

                    // TJ instruction
                    case    'J' :
                        $enhanced_statistics && $this->Statistics ['Distributions'] ['TJ']++;
                        break;

                    // TD instruction
                    case    'D' :
                        $Tm [4] += ( double )$operand_stack [0] * $Tm [0];
                        $Tm [5] += ( double )$operand_stack [1] * $Tm [3];
                        $text_leading -= $Tm [5];

                        $enhanced_statistics && $this->Statistics ['Distributions'] ['TD']++;
                        break;

                    // T* instruction
                    case    '*' :
                        $Tm [4] = 0.0;
                        $Tm [5] -= $text_leading; //$current_font_height ;

                        $enhanced_statistics && $this->Statistics ['Distributions'] ['T*']++;
                        break;

                    // TL instruction - Set text leading. Currently not used.
                    case    'L' :
                        $text_leading = ( double )$operand_stack [0];
                        $enhanced_statistics && $this->Statistics ['Distributions'] ['TL']++;
                        break;

                    // ' instruction : go to next line and display text
                    case    "'" :
                        // Update the coordinates of the last text block found so far
                        $page_fragments [$page_fragment_count - 1] ['x'] += $text_leading;
                        $offset = $current_font_height * abs($Tm [3]);
                        $page_fragments [$page_fragment_count - 1] ['y'] -= $offset;

                        // And don't forget to update the y coordinate of the current transformation matrix
                        $Tm [5] -= $offset;

                        $enhanced_statistics && $this->Statistics ['Distributions'] ["'"]++;
                        break;

                    // "'" instruction
                    case    '"' :
                        if (self::$DEBUG) {
                            warning("Instruction $token not yet implemented.");
                        }

                        $enhanced_statistics && $this->Statistics ['Distributions'] ['"']++;
                        break;

                    // Other : ignore them
                    default :
                        $enhanced_statistics && $this->Statistics ['Distributions'] ['ignored']++;
                }

                $operand_stack = [];
            } elseif ($token == 'cm') {
                // cm instruction
                $a = ( double )$operand_stack [0];
                $b = ( double )$operand_stack [1];
                $c = ( double )$operand_stack [2];
                $d = ( double )$operand_stack [3];
                $e = ( double )$operand_stack [4];
                $f = ( double )$operand_stack [5];

                $CTM = [$a, $b, $c, $d, $e, $f];
                $operand_stack = [];

                $enhanced_statistics && $this->Statistics ['Distributions'] ['cm']++;
            } elseif ($token === 'q') {
                // q/Q instructions (save/restore graphic context)
                $graphic_stack [$graphic_stack_size++] = [$CTM, $Tm];
                $operand_stack = [];
            } elseif ($token === 'Q') {
                if ($graphic_stack_size) {
                    list ($CTM, $Tm) = $graphic_stack [--$graphic_stack_size];
                } elseif (self::$DEBUG) {
                    warning("Tried to restore graphics context from an empty stack.");
                }

                $operand_stack = [];
            } elseif ($token_start === '[') {
                // Text array in the [...] notation. Well, in fact, even non-array constructs are returned as an array by the
                // __next_token() function, for the sake of simplicity
                $text = $this->__decode_text($token, $current_font, $current_font_mapped, $current_font_map_width);

                if ($text !== '') {
                    $r = $this->__matrix_multiply($Tm, $CTM, $page_attributes ['width'], $page_attributes ['height']);
                    $fragment =
                    [
                        'x' => ($r [4] < 0) ? 0.0 : $r [4],
                        'y' => ($r [5] < 0) ? 0.0 : $r [5],
                        'page' => $page_number,
                        'template' => $current_template,
                        'font' => $current_font_name,
                        'font-height' => abs($current_font_height * $Tm [3]),
                        'text' => $text,
                    ];

                    // Add debug information when needed
                    if (self::$DEBUG) {
                        $fragment = array_merge(
                            $fragment,
                            [
                                'CTM' => $CTM,
                                'Tm' => $Tm,
                                'New Tm' => $r,
                                'Real font height' => $current_font_height,
                                'Page width' => $page_attributes ['width'],
                                'Page height' => $page_attributes ['height']
                            ]
                        );
                    }

                    // Add this text fragment to the list
                    $page_fragments [] = $fragment;
                    $page_fragment_count++;

                    $operand_stack = [];
                }
            } elseif ($token == 'BT') {
                // BT instruction
                $BT_nesting_level++;
                $operand_stack = [];
                $graphic_stack [$graphic_stack_size++] = [$CTM, $Tm];

                $enhanced_statistics && $this->Statistics ['Distributions'] ['BT']++;
            } elseif ($token == 'ET') {
                // ET instruction
                if ($BT_nesting_level) {
                    $BT_nesting_level--;

                    if (!$BT_nesting_level && $graphic_stack_size) {
                        list ($CTM, $Tm) = $graphic_stack [--$graphic_stack_size];
                    }
                }

                $operand_stack = [];
            } elseif ($token_start === '!') {
                // Template (substituted in __next_token)
                if (preg_match('/ !PDFTOTEXT_TEMPLATE_ (?P<template> \w+) /ix', $token, $match)) {
                    $name = '/' . $match ['template'];
                    $enhanced_statistics && $this->Statistics ['Distributions'] ['template']++;

                    if ($this->PageMap->IsValidXObjectName($name)) {
                        $current_template = $name;
                    }
                } else {
                    $enhanced_statistics && $this->Statistics ['Distributions'] ['ignored']++;
                }

                $operand_stack = [];
            } else {
                // Other instructions
                $operand_stack = [];
                $enhanced_statistics && $this->Statistics ['Distributions'] ['ignored']++;
            }

            // Update current index in instruction stream
            $data_index = $next_index;
        }
    }


    // __matrix_multiply -
    //  Multiplies matrix $ma by $mb.
    //  PDF transformation matrices are 3x3 matrices containing the following values :
    //
    //      | sx rx 0 |
    //      | ry sy 0 |
    //      | tx ty 1 |
    //
    //  However, we do not care about the 3rd column, which is always hardcoded. Transformation
    //  matrices here are implemented 6-elements arrays :
    //
    //      [ sx, rx, ry, tx, ty ]
    private function __matrix_multiply(
        $ma,
        $mb, /** @noinspection PhpUnusedParameterInspection */
        $page_width, /** @noinspection PhpUnusedParameterInspection */
        $page_height
    ) {
        // Scaling text is only appropriate for rendering graphics ; in our case, we just have to render
        // basic text without any consideration about its width or height ; so adjust the sx/sy parameters
        // accordingly
        $scale_1x = ($ma [0] > 0) ? 1 : -1;
        $scale_1y = ($ma [3] > 0) ? 1 : -1;
        $scale_2x = ($mb [0] > 0) ? 1 : -1;
        $scale_2y = ($mb [3] > 0) ? 1 : -1;

        // Perform the matrix multiplication
        $r = [];
        $r [0] = ($scale_1x * $scale_2x) + ($ma [1] * $mb [2]);
        $r [1] = ($scale_1x * $mb [1]) + ($ma [1] * $scale_2y);
        $r [2] = ($scale_1y * $scale_2x) + ($scale_1y * $mb [2]);
        $r [3] = ($scale_1y * $mb [1]) + ($scale_1y * $scale_2y);
        $r [4] = ($ma [4] * $scale_2x) + ($ma [5] * $mb [2]) + $mb [4];
        $r [5] = ($ma [4] * $mb [1]) + ($ma [5] * $scale_2y) + $mb [5];

        // Negative x/y values are expressed relative to the page width/height (???)
        if ($r [0] < 0) {
            $r [4] = abs($r [4]);//$page_width - $r [4] ;
        }

        if ($r [3] < 0) {
            $r [5] = abs($r [5]); //$page_height - $r [5] ;
        }

        return ($r);
    }


    // __next_token_ex :
    //  Reviewed version of __next_token, adapted to ExtractTextWithLayout.
    //  Both functions will be unified when this one will be stabilized.
    private function __next_token_ex(/** @noinspection PhpUnusedParameterInspection */
        $page_number,
        $data,
        $data_length,
        $index,
        &$token,
        &$next_index
    ) {
        // Skip spaces
        $count = 0;

        while ($index < $data_length && ($data [$index] == ' ' || $data [$index] == "\t" || $data [$index] == "\r" || $data [$index] == "\n")) {
            $index++;
            $count++;
        }

        $enhanced_statistics = $this->EnhancedStatistics;
        //$enhanced_statistics  &&  $this -> Statistics [ 'Distributions' ] [ 'space' ] +=  $count ;
        if ($enhanced_statistics) {
            $this->Statistics ['Distributions'] ['space'] += $count;
        }

        // End of input
        if ($index >= $data_length) {
            return (false);
        }

        // The current character will tell us what to do
        $ch = $data [$index];

        switch ($ch) {
            // Opening square bracket : we have to find the closing one, taking care of escape sequences
            // that can also specify a square bracket, such as "\]"
            case    "[" :
                $next_index = $index + 1;
                $parent = 0;
                $angle = 0;
                $token = '[';

                while ($next_index < $data_length) {
                    $nch = $data [$next_index++];

                    switch ($nch) {
                        case    '(' :
                            $parent++;
                            $token .= $nch;
                            break;

                        case    ')' :
                            $parent--;
                            $token .= $nch;
                            break;

                        case    '<' :
                            // Although the array notation can contain hex digits between angle brackets, we have to
                            // take care that we do not have an angle bracket between two parentheses such as :
                            // [ (<) ... ]
                            if (!$parent) {
                                $angle++;
                            }

                            $token .= $nch;
                            break;

                        case    '>' :
                            if (!$parent) {
                                $angle--;
                            }

                            $token .= $nch;
                            break;

                        case    '\\' :
                            $token .= $nch . $data [$next_index++];
                            break;

                        case    ']' :
                            $token .= ']';

                            if (!$parent) {
                                break  2;
                            } else {
                                break;
                            }

                        case    "\n" :
                        case    "\r" :
                            break;

                        default :
                            $token .= $nch;
                    }
                }

                $enhanced_statistics && $this->Statistics ['Distributions'] ['[']++;

                return (true);

            // Parenthesis : Again, we have to find the closing parenthesis, taking care of escape sequences
            // such as "\)"
            case    "(" :
                $next_index = $index + 1;
                $token = '[' . $ch;

                while ($next_index < $data_length) {
                    $nch = $data [$next_index++];

                    if ($nch === '\\') {
                        $after = $data [$next_index];

                        // Character references specified as \xyz, where "xyz" are octal digits
                        if ($after >= '0' && $after <= '7') {
                            $token .= $nch;

                            while ($data [$next_index] >= '0' && $data [$next_index] <= '7') {
                                $token .= $data [$next_index++];
                            }
                        } else {
                            // Regular character escapes
                            $token .= $nch . $data [$next_index++];
                        }
                    } elseif ($nch === ')') {
                        $token .= ')';
                        break;
                    } else {
                        $token .= $nch;
                    }
                }

                $enhanced_statistics && $this->Statistics ['Distributions'] ['(']++;
                $token .= ']';

                return (true);

            // A construction of the form : "<< something >>", or a unicode character
            case    '<' :
                if (isset($data [$index + 1])) {
                    if ($data [$index + 1] === '<') {
                        $next_index = strpos($data, '>>', $index + 2);

                        if ($next_index === false) {
                            return (false);
                        }

                        $token = substr($data, $index, $next_index - $index + 2);
                        $next_index += 2;

                        return (true);
                    } else {
                        $next_index = strpos($data, '>', $index + 2);

                        if ($next_index === false) {
                            return (false);
                        }

                        $enhanced_statistics && $this->Statistics ['Distributions'] ['<']++;

                        // There can be spaces and newlines inside a series of hex digits, so remove them...
                        $result = preg_replace('/\s+/', '', substr($data, $index, $next_index - $index + 1));

                        $token = "[$result]";
                        $next_index++;

                        return (true);
                    }
                } else {
                    return (false);
                }

            // Tick character : consider it as a keyword, in the same way as the "TJ" or "Tj" keywords
            case    "'" :
            case    '"' :
                $token = $ch;
                $next_index += 2;

                return (true);

            // Other cases : this may be either a floating-point number or a keyword
            default :
                $next_index = ++$index;
                $token = $ch;

                if (isset($data [$next_index])) {
                    if (($ch >= '0' && $ch <= '9') || $ch == '-' || $ch == '+' || $ch == '.') {
                        while ($next_index < $data_length &&
                            (($data [$next_index] >= '0' && $data [$next_index] <= '9') ||
                                $data [$next_index] === '-' || $data [$next_index] === '+' || $data [$next_index] === '.')) {
                            $token .= $data [$next_index++];
                        }
                    } elseif ((self::$CharacterClasses [$ch] & self::CTYPE_ALPHA) ||
                        $ch == '/' || $ch == '!'
                    ) {
                        $ch = $data [$next_index];

                        while ($next_index < $data_length &&
                            ((self::$CharacterClasses [$ch] & self::CTYPE_ALNUM) ||
                                $ch == '*' || $ch == '-' || $ch == '_' || $ch == '.' || $ch == '+')) {
                            $token .= $ch;
                            $next_index++;

                            if (isset($data [$next_index])) {
                                $ch = $data [$next_index];
                            }
                        }
                    }
                }

                return (true);
        }
    }


    // __decode_text -
    //  Text decoding function when the PDFOPT_BASIC_LAYOUT flag is specified.
    private function __decode_text($data, $current_font, $current_font_mapped, $current_font_map_width)
    {
        list ($text_values, $offsets) = $this->__extract_chars_from_array($data);
        $value_index = 0;
        $result = '';

        // Fonts having character maps will require some special processing
        if ($current_font_mapped) {
            // Loop through each text value
            foreach ($text_values as $text) {
                $is_hex = ($text [0] == '<');
                $length = strlen($text) - 1;
                $handled = false;

                // Characters are encoded within angle brackets ( "<>" ).
                // Note that several characters can be specified within the same angle brackets, so we have to take
                // into account the width we detected in the begincodespancerange construct
                if ($is_hex) {
                    for ($i = 1; $i < $length; $i += $current_font_map_width) {
                        $value = substr($text, $i, $current_font_map_width);
                        $ch = hexdec($value);

                        if (isset($this->CharacterMapBuffer [$current_font] [$ch])) {
                            $newchar = $this->CharacterMapBuffer [$current_font] [$ch];
                        } else {
                            $newchar = $this->FontTable->MapCharacter($current_font, $ch);
                            $this->CharacterMapBuffer [$current_font] [$ch] = $newchar;
                        }

                        $result .= $newchar;
                    }

                    $handled = true;
                } elseif ($current_font_map_width == 4) {
                    // Yes ! double-byte codes can also be specified as plain text within parentheses !
                    // However, we have to be really careful here ; the sequence :
                    //  (Be)
                    // can mean the string "Be" or the Unicode character 0x4265 ('B' = 0x42, 'e' = 0x65)
                    // We first look if the character map contains an entry for Unicode codepoint 0x4265 ;
                    // if not, then we have to consider that it is regular text to be taken one character by
                    // one character. In this case, we fall back to the "if ( ! $handled )" condition
                    $temp_result = '';

                    for ($i = 1; $i < $length; $i++) {
                        // Each character in the pair may be a backslash, which escapes the next character so we must skip it
                        // This code needs to be reviewed ; the same code is duplicated to handle escaped characters in octal notation
                        if ($text [$i] != '\\') {
                            $ch1 = $text [$i];
                        } else {
                            $i++;

                            if ($text [$i] < '0' || $text [$i] > '7') {
                                $ch1 = $this->ProcessEscapedCharacter($text [$i]);
                            } else {
                                $oct = '';
                                $digit_count = 0;

                                while ($i < $length && $text [$i] >= '0' && $text [$i] <= '7' && $digit_count < 3) {
                                    $oct .= $text [$i++];
                                    $digit_count++;
                                }

                                $ch1 = chr(octdec($oct));
                                $i--;
                            }
                        }

                        $i++;

                        if ($text [$i] != '\\') {
                            $ch2 = $text [$i];
                        } else {
                            $i++;

                            if ($text [$i] < '0' || $text [$i] > '7') {
                                $ch2 = $this->ProcessEscapedCharacter($text [$i]);
                            } else {
                                $oct = '';
                                $digit_count = 0;

                                while ($i < $length && $text [$i] >= '0' && $text [$i] <= '7' && $digit_count < 3) {
                                    $oct .= $text [$i++];
                                    $digit_count++;
                                }

                                $ch2 = chr(octdec($oct));
                                $i--;
                            }
                        }

                        // Build the 2-bytes character code
                        $ch = (ord($ch1) << 8) | ord($ch2);

                        if (isset($this->CharacterMapBuffer [$current_font] [$ch])) {
                            $newchar = $this->CharacterMapBuffer [$current_font] [$ch];
                        } else {
                            $newchar = $this->FontTable->MapCharacter($current_font, $ch, true);
                            $this->CharacterMapBuffer [$current_font] [$ch] = $newchar;
                        }

                        // Yes !!! for characters encoded with two bytes, we can find the following construct :
                        //  0x00 "\" "(" 0x00 "C" 0x00 "a" 0x00 "r" 0x00 "\" ")"
                        // which must be expanded as : (Car)
                        // We have here the escape sequences "\(" and "\)", but the backslash is encoded on two bytes
                        // (although the MSB is nul), while the escaped character is encoded on 1 byte. waiting
                        // for the next quirk to happen...
                        if ($newchar == '\\') {
                            $newchar = $this->ProcessEscapedCharacter($text [$i + 2]);
                            $i++;        // this time we processed 3 bytes, not 2
                        }

                        $temp_result .= $newchar;
                    }

                    // Happens only if we were unable to translate a character using the current character map
                    $result .= $temp_result;
                    $handled = true;
                }

                // Character strings within parentheses.
                // For every text value, use the character map table for substitutions
                if (!$handled) {
                    for ($i = 1; $i < $length; $i++) {
                        $ch = $text [$i];

                        // Set to true to optimize calls to MapCharacters
                        // Currently does not work with pobox@dizy.sk/infoma.pdf (a few characters differ)
                        $use_map_buffer = false;

                        // ... but don't forget to handle escape sequences "\n" and "\r" for characters
                        // 10 and 13
                        if ($ch == '\\') {
                            $ch = $text [++$i];

                            // Escaped character
                            if ($ch < '0' || $ch > '7') {
                                $ch = $this->ProcessEscapedCharacter($ch);
                            } else {
                                // However, an octal form can also be specified ; in this case we have to take into account
                                // the character width for the current font (if the character width is 4 hex digits, then we
                                // will encounter constructs such as "\000\077").
                                // The method used here is dirty : we build a regex to match octal character representations on a substring
                                // of the text
                                $width = $current_font_map_width / 2;    // Convert to byte count
                                $subtext = substr($text, $i - 1);
                                $regex = "#^ (\\\\ [0-7]{3}){1,$width} #imsx";

                                $status = preg_match($regex, $subtext, $octal_matches);

                                if ($status) {
                                    $octal_values = explode('\\', substr($octal_matches [0], 1));
                                    $ord = 0;

                                    foreach ($octal_values as $octal_value) {
                                        $ord = ($ord << 8) + octdec($octal_value);
                                    }

                                    $ch = chr($ord);
                                    $i += strlen($octal_matches [0]) - 2;
                                }
                            }

                            $use_map_buffer = false;
                        }

                        // Add substituted character to the output result
                        $ord = ord($ch);

                        if (!$use_map_buffer) {
                            $newchar = $this->FontTable->MapCharacter($current_font, $ord);
                        } else {
                            if (isset($this->CharacterMapBuffer [$current_font] [$ord])) {
                                $newchar = $this->CharacterMapBuffer [$current_font] [$ord];
                            } else {
                                $newchar = $this->FontTable->MapCharacter($current_font, $ord);
                                $this->CharacterMapBuffer [$current_font] [$ord] = $newchar;
                            }
                        }

                        $result .= $newchar;
                    }
                }

                // Handle offsets between blocks of characters
                if (isset($offsets [$value_index]) &&
                    -($offsets [$value_index]) > $this->MinSpaceWidth
                ) {
                    $result .= $this->__get_character_padding($offsets [$value_index]);
                }

                $value_index++;
            }
        } else {
            // For fonts having no associated character map, we simply encode the string in UTF8
            // after the C-like escape sequences have been processed
            // Note that <xxxx> constructs can be encountered here, so we have to process them as well
            foreach ($text_values as $text) {
                $is_hex = ($text [0] == '<');
                $length = strlen($text) - 1;

                // Some text within parentheses may have a backslash followed by a newline, to indicate some continuation line.
                // Example :
                //  (this is a sentence \
                //   continued on the next line)
                // Funny isn't it ? so remove such constructs because we don't care
                $text = str_replace(["\\\r\n", "\\\r", "\\\n"], '', $text);

                // Characters are encoded within angle brackets ( "<>" )
                if ($is_hex) {
                    for ($i = 1; $i < $length; $i += 2) {
                        $ch = hexdec(substr($text, $i, 2));

                        $result .= $this->CodePointToUtf8($ch);
                    }
                } else {
                    // Characters are plain text
                    $text = self::Unescape($text);

                    for ($i = 1, $length = strlen($text) - 1; $i < $length; $i++) {
                        $ch = $text [$i];
                        $ord = ord($ch);

                        if ($ord < 127) {
                            $newchar = $ch;
                        } else {
                            if (isset($this->CharacterMapBuffer [$current_font] [$ord])) {
                                $newchar = $this->CharacterMapBuffer [$current_font] [$ord];
                            } else {
                                $newchar = $this->FontTable->MapCharacter($current_font, $ord);
                                $this->CharacterMapBuffer [$current_font] [$ord] = $newchar;
                            }
                        }

                        $result .= $newchar;
                    }
                }

                // Handle offsets between blocks of characters
                if (isset($offsets [$value_index]) &&
                    abs($offsets [$value_index]) > $this->MinSpaceWidth
                ) {
                    $result .= $this->__get_character_padding($offsets [$value_index]);
                }

                $value_index++;
            }
        }

        // All done, return
        return ($result);
    }


    // __assemble_text_fragments -
    //  Assembles text fragments collected by the ExtractTextWithLayout function.
    private function __assemble_text_fragments($page_number, &$fragments, &$page_width, &$page_height)
    {
        $fragment_count = count($fragments);

        // No fragment no cry...
        if (!$fragment_count) {
            return ('');
        }

        // Compute the width of each fragment
        foreach ($fragments as &$fragment) {
            $this->__compute_fragment_width($fragment);
        }

        // Sort the fragments and group them by line
        usort($fragments, [$this, '__sort_page_fragments']);
        $line_fragments = $this->__group_line_fragments($fragments);

        // Retrieve the page attributes
        $page_attributes = $this->PageMap->PageAttributes [$page_number];

        // Some buggy PDF do not specify page width or page height so, during the processing of text fragments,
        // page width & height will be set to the largest x/y coordinate
        if (isset($page_attributes ['width']) && $page_attributes ['width']) {
            $page_width = $page_attributes ['width'];
        } else {
            $page_width = 0;

            foreach ($fragments as $fragment) {
                $end_x = $fragment ['x'] + $fragment ['width'];

                if ($end_x > $page_width) {
                    $page_width = $end_x;
                }
            }
        }

        if (isset($page_attributes ['height']) && $page_attributes ['height']) {
            $page_height = $page_attributes ['height'];
        } else {
            $page_height = $fragments [0] ['y'];
        }

        // Block separator
        $separator = ($this->BlockSeparator) ? $this->BlockSeparator : ' ';

        // Unprocessed marker count
        $unprocessed_marker_count = count($this->UnprocessedMarkerList ['font']);

        // Add page information if the PDFOPT_DEBUG_SHOW_COORDINATES option has been specified
        if ($this->Options & self::PDFOPT_DEBUG_SHOW_COORDINATES) {
            $result = "[Page : $page_number, width = $page_width, height = $page_height]" . $this->EOL;
        } else {
            $result = '';
        }

        // Loop through each line of fragments
        for ($i = 0, $line_count = count($line_fragments); $i < $line_count; $i++) {
            $current_x = 0;

            // Loop through each fragment of the current line
            for ($j = 0, $fragment_count = count($line_fragments [$i]); $j < $fragment_count; $j++) {
                $fragment = $line_fragments [$i] [$j];

                // Process the markers which do not have an associated font yet - this will be done by matching
                // the current text fragment against one of the regular expressions defined.
                // If a match occurs, then all the subsequent text fragment using the same font will be put markers
                for ($k = 0; $k < $unprocessed_marker_count; $k++) {
                    $marker = $this->UnprocessedMarkerList ['font'] [$k];

                    if (preg_match($marker ['regex'], $fragment ['text'])) {
                        $this->TextWithFontMarkers [$fragment ['font']] =
                        [
                            'font' => $fragment ['font'],
                            'height' => $fragment ['font-height'],
                            'regex' => $marker   ['regex'],
                            'start' => $marker   ['start'],
                            'end' => $marker   ['end']
                        ];

                        $unprocessed_marker_count--;
                        unset($this->UnprocessedMarkerList ['font'] [$k]);

                        break;
                    }
                }

                // Add debug info if needed
                if ($this->Options & self::PDFOPT_DEBUG_SHOW_COORDINATES) {
                    $result .= $this->__debug_get_coordinates($fragment);
                }

                // Add a separator between two fragments, if needed
                if ($j) {
                    if ($current_x < floor($fragment ['x'])) {    // Accept small rounding errors
                        $result .= $separator;
                    }
                }

                // Check if we need to add markers around this text fragment
                if (isset($this->TextWithFontMarkers [$fragment ['font']]) &&
                    $this->TextWithFontMarkers [$fragment ['font']] ['height'] == $fragment ['font-height']
                ) {
                    $fragment_text = $this->TextWithFontMarkers [$fragment ['font']] ['start'] .
                        $fragment ['text'] .
                        $this->TextWithFontMarkers [$fragment ['font']] ['end'];
                } else {
                    $fragment_text = $fragment ['text'];
                }

                // Add the current fragment to the result
                $result .= $fragment_text;

                // Update current x-position
                $current_x = $fragment ['x'] + $fragment ['width'];
            }

            // Add a line break between each line
            $result .= $this->EOL;
        }

        // All done, return
        return ($result);
    }


    // __sort_page_fragments -
    //  Sorts page fragments by their (y,x) coordinates.
    public function __sort_page_fragments($a, $b)
    {
        $xa = $a ['x'];
        $ya = $a ['y'];
        $xb = $b ['x'];
        $yb = $b ['y'];

        if ($ya !== $yb) {
            return ($yb - $ya);
        } else {
            return ($xa - $xb);
        }
    }


    // __sort_line_fragments -
    //  Sorts fragments per line.
    public function __sort_line_fragments($a, $b)
    {
        return ($a ['x'] - $b ['x']);
    }


    // __group_line_fragments -
    //  Groups page fragments per line, allowing a certain variation in the y-position.
    private function __group_line_fragments($fragments)
    {
        $result = [];
        $fragment_count = count($fragments);
        $last_y_coordinate = $fragments [0] ['y'];
        $current_fragments = [$fragments [0]];

        for ($i = 1; $i < $fragment_count; $i++) {
            $fragment = $fragments [$i];

            if ($fragment ['y'] + $fragment ['font-height'] >= $last_y_coordinate) {
                $current_fragments [] = $fragment;
            } else {
                $last_y_coordinate = $fragment ['y'];
                usort($current_fragments, [$this, '__sort_line_fragments']);
                $result    [] = $current_fragments;
                $current_fragments = [$fragment];
            }
        }

        if (count($current_fragments)) {
            usort($current_fragments, [$this, '__sort_line_fragments']);
            $result [] = $current_fragments;
        }

        return ($result);
    }


    // __compute_fragment_width -
    //  Compute the width of the specified text fragment and add the width entry accordingly.
    //  Returns the font object associated with this fragment
    private function __compute_fragment_width(&$fragment)
    {
        /** @var PdfTexterFont $font_object */
        // To avoid repeated calls to the PdfTexterFontTable::GetFontObject() method, we are buffering them in the FontObjectsBuffer property.
        $object_reference = $fragment ['page'] . ':' . $fragment ['template'] . ':' . $fragment ['font'];

        if (isset($this->FontObjectsBuffer [$object_reference])) {
            $font_object = $this->FontObjectsBuffer [$object_reference];
        } else {
            $font_object = $this->FontTable->GetFontObject($fragment ['page'], $fragment ['template'], $fragment ['font']);
            $this->FontObjectsBuffer [$object_reference] = $font_object;
        }

        // The width of the previous text fragment will be computed only if its associated font contains character widths information
        $fragment ['width'] = ($font_object) ? $font_object->GetStringWidth($fragment ['text'], $this->ExtraTextWidth) : 0;

        // Return the font object
        return ($font_object);
    }


    // __debug_get_coordinates -
    //  Returns the coordinates of the specified text fragment, in debug mode.
    private function __debug_get_coordinates($fragment)
    {
        return ("\n[x:" . round($fragment ['x'], 3) . ', y:' . round($fragment ['y'], 3) .
            ", w: " . round($fragment ['width'], 3) . ", h:" . round($fragment ['font-height'], 3) . ", font:" . $fragment ['font'] . "]");
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetTrailerInformation - Retrieves trailer information.

        PROTOTYPE
            $this -> GetTrailerInformation ( $contents ) ;

        DESCRIPTION
            Retrieves trailer information :
        - Unique file ID
        - Id of the object containing encryption data, if the PDF file is encrypted
        - Encryption data

        PARAMETERS
            $contents (string) -
                    PDF file contents.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetTrailerInformation($contents, $pdf_objects)
    {
        // Be paranoid : check if there is trailer information
        if (!preg_match('/trailer \s* << (?P<trailer> .+?) >>/imsx', $contents, $trailer_match)) {
            return;
        }

        $trailer_data = $trailer_match ['trailer'];

        // Get the unique file id from the trailer data
        static $id_regex = '#
							/ID \s* \[ \s*
							< (?P<id1> [^>]+) >
							\s*
							< (?P<id2> [^>]+) >
							\s* \]
						    #imsx';

        if (preg_match($id_regex, $trailer_data, $id_match)) {
            $this->ID = $id_match ['id1'];
            $this->ID2 = $id_match ['id2'];
        }

        // If there is an object describing encryption data, get its number (/Encrypt flag)
        if (!preg_match('#/Encrypt \s+ (?P<object> \d+)#ix', $trailer_data, $encrypt_match)) {
            return;
        }

        $encrypt_object_id = $encrypt_match ['object'];

        if (!isset($pdf_objects [$encrypt_object_id])) {
            if (self::$DEBUG) {
                error(new DecodingException("Object #$encrypt_object_id, which should contain encryption data, is missing."));
            }

            return;
        }

        // Parse encryption information
        $this->EncryptionData = PdfEncryptionData::GetInstance($this->ID, $encrypt_object_id, $pdf_objects [$encrypt_object_id]);
        $this->IsEncrypted = ($this->EncryptionData !== false);
    }


    // __build_ignored_instructions :
    //  Takes the template regular expressions from the self::$IgnoredInstructionsTemplates, replace each string with the contents
    //  of the self::$ReplacementConstructs array, and sets the self::$IgnoredInstructions to a regular expression that is able to
    //  match the Postscript instructions to be removed from any text stream.
    private function __build_ignored_instructions()
    {
        $searches = array_keys(self::$ReplacementConstructs);
        $replacements = array_values(self::$ReplacementConstructs);

        foreach (self::$IgnoredInstructionTemplatesLayout as $template) {
            $template = '/' . str_replace($searches, $replacements, $template) . '/msx';

            self::$IgnoredInstructionsLayout [] = $template;
            self::$IgnoredInstructionsNoLayout [] = $template;
        }

        foreach (self::$IgnoredInstructionTemplatesNoLayout as $template) {
            $template = '/' . str_replace($searches, $replacements, $template) . '/msx';

            self::$IgnoredInstructionsNoLayout [] = $template;
        }
    }


    // __convert_utf16 :
    //  Some strings found in a pdf file can be encoded in UTF16 (author information, for example).
    //  When this is the case, the string is converted to UTF8.
    private function __convert_utf16($text)
    {
        if (isset($text [0]) && isset($text [1])) {
            $b1 = ord($text [0]);
            $b2 = ord($text [1]);

            if (($b1 == 0xFE && $b2 == 0xFF) || ($b1 == 0xFF && $b2 == 0xFE)) {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-16');
            }
        }

        return ($text);
    }


    // __extract_chars_from_array -
    //  Extracts characters enclosed either within parentheses (character codes) or angle brackets (hex value)
    //  from an array.
    //  Example :
    //
    //      [<0D>-40<02>-36<03>-39<0E>-36<0F>-36<0B>-37<10>-37<10>-35(abc)]
    //
    //  will return an array having the following entries :
    //
    //      <0D>, <02>, <03>, <0E>, <0F>, <0B>, <10>, <10>, (abc)
    private function __extract_chars_from_array($array)
    {
        $length = strlen($array) - 1;
        $result = [];
        $offsets = [];

        for ($i = 1; $i < $length; $i++) {    // Start with character right after the opening bracket
            $ch = $array [$i];

            if ($ch == '(') {
                $endch = ')';
            } elseif ($ch == '<') {
                $endch = '>';
            } else {
                $value = '';

                while ($i < $length && (($array [$i] >= '0' && $array [$i] <= '9') ||
                        $array [$i] == '-' || $array [$i] == '+' || $array [$i] == '.')) {
                    $value .= $array [$i++];
                }

                $offsets [] = ( double )$value;

                if ($value !== '') {
                    $i--;
                }

                continue;
            }

            $char = $ch;
            $i++;

            while ($i < $length && $array [$i] != $endch) {
                if ($array [$i] == '\\') {
                    $char .= '\\' . $array [++$i];
                } else {
                    $char .= $array [$i];

                    if ($array [$i] == $endch) {
                        break;
                    }
                }

                $i++;
            }

            $result [] = $char . $endch;
        }

        return ([$result, $offsets]);
    }


    // __extract_chars_from_block -
    //  Extracts characters from a text block (enclosed in parentheses).
    //  Returns an array of character ordinals if the $as_array parameter is true, or a string if false.
    private function __extract_chars_from_block($text, $start_index = false, $length = false, $as_array = false)
    {
        if ($as_array) {
            $result = [];
        } else {
            $result = '';
        }

        if ($start_index === false) {
            $start_index = 0;
        }

        if ($length === false) {
            $length = strlen($text);
        }

        $ord0 = ord('0');

        for ($i = $start_index; $i < $length; $i++) {
            $ch = $text [$i];

            if ($ch == '\\') {
                if (isset($text [$i + 1])) {
                    $ch2 = $text [++$i];

                    switch ($ch2) {
                        case  'n' :
                            $ch = "\n";
                            break;
                        case  'r' :
                            $ch = "\r";
                            break;
                        case  't' :
                            $ch = "\t";
                            break;
                        case  'f' :
                            $ch = "\f";
                            break;
                        case  'v' :
                            $ch = "\v";
                            break;

                        default :
                            if ($ch2 >= '0' && $ch2 <= '7') {
                                $ord = $ch2 - $ord0;
                                $i++;

                                while (isset($text [$i]) && $text [$i] >= '0' && $text [$i] <= '7') {
                                    $ord = ($ord * 8) + ord($text [$i]) - $ord0;
                                    $i++;
                                }

                                $ch = chr($ord);
                                $i--;
                            } else {
                                $ch = $ch2;
                            }
                    }
                }
            }

            if ($as_array) {
                $result [] = ord($ch);
            } else {
                $result .= $ch;
            }
        }

        return ($result);
    }


    // __get_character_padding :
    //  If the offset specified between two character groups in an array notation for displaying text is less
    //  than -MinSpaceWidth thousands of text units,
    private function __get_character_padding($char_offset)
    {
        if ($char_offset <= -$this->MinSpaceWidth) {
            if ($this->Options & self::PDFOPT_REPEAT_SEPARATOR) {
                // If the MinSpaceWidth property is less than 1000 (text units), consider it has the value 1000
                // so that an exuberant number of spaces will not be repeated
                $space_width = ($this->MinSpaceWidth < 1000) ? 1000 : $this->MinSpaceWidth;

                $repeat_count = abs(round($char_offset / $space_width, 0));

                if ($repeat_count) {
                    $padding = str_repeat($this->Separator, $repeat_count);
                } else {
                    $padding = $this->Separator;
                }
            } else {
                $padding = $this->Separator;
            }

            return (utf8_encode(self::Unescape($padding)));
        } else {
            return ('');
        }
    }


    // __get_output_image_filename -
    //  Returns a real filename based on a template supplied by the AutoSaveImageFileTemplate property.
    private function __get_output_image_filename()
    {
        static $suffixes =
        [
            IMG_JPEG => 'jpg',
            IMG_JPG => 'jpg',
            IMG_GIF => 'gif',
            IMG_PNG => 'png',
            IMG_WBMP => 'wbmp',
            IMG_XPM => 'xpm'
        ];

        $template = $this->ImageAutoSaveFileTemplate;
        $length = strlen($template);
        $parts = pathinfo($this->Filename);

        if (!isset($parts ['filename'])) {    // for PHP versions < 5.2
            $index = strpos($parts ['basename'], '.');

            if ($index === false) {
                $parts ['filename'] = $parts ['basename'];
            } else {
                $parts ['filename'] = substr($parts ['basename'], $index);
            }
        }

        $searches = [];
        $replacements = [];

        // Search for each construct starting with '%'
        for ($i = 0; $i < $length; $i++) {
            if ($template [$i] != '%' || $i + 1 >= $length) {
                continue;
            }

            $ch = $template [++$i];

            // Percent sign found : check the character after
            switch ($ch) {
                // "%%" : Replace it with a single percent
                case    '%' :
                    $searches [] = '%%';
                    $replacements [] = '%';
                    break;

                // "%p" : Path of the original PDF file
                case    'p' :
                    $searches [] = '%p';
                    $replacements [] = $parts ['dirname'];
                    break;

                // "%f" : Filename part of the original PDF file, without its suffix
                case    'f' :
                    $searches [] = '%f';
                    $replacements [] = $parts ['filename'];
                    break;

                // "%s" : Output image file suffix, determined by the ImageAutoSaveFormat property
                case    's' :
                    if (isset($suffixes [$this->ImageAutoSaveFormat])) {
                        $searches [] = '%s';
                        $replacements [] = $suffixes [$this->ImageAutoSaveFormat];
                    } else {
                        $searches [] = '%s';
                        $replacements [] = 'unknown';
                    }

                    break;

                // Other : may be either "%d", or "%xd", where "x" are digits expression the width of the final sequential index
                default :
                    $width = 0;
                    $chars = '';

                    if (ctype_digit($ch)) {
                        do {
                            $width = ($width * 10) + ord($ch) - ord('0');
                            $chars .= $ch;
                            $i++;
                        } while ($i < $length && ctype_digit($ch = $template [$i]));

                        if ($template [$i] == 'd') {
                            $searches [] = '%' . $chars . 'd';
                            $replacements [] = sprintf("%0{$width}d", $this->ImageCount);
                        }
                    } else {
                        $searches [] = '%d';
                        $replacements [] = $this->ImageCount;
                    }
            }
        }

        // Perform the replacements
        if (count($searches)) {
            $result = str_replace($searches, $replacements, $template);
        } else {
            $result = $template;
        }

        // All done, return
        return ($result);
    }


    // __rtl_process -
    //  Processes the contents of a page when it contains characters belonging to an RTL language.
    private function __rtl_process($text)
    {
        $length = strlen($text);
        $pos = strcspn($text, self::$RtlCharacterPrefixes);

        // The text does not contain any of the UTF-8 prefixes that may introduce RTL contents :
        // simply return it as is
        if ($pos == $length || $text [$pos] === "\x00") {
            return ($text);
        }

        // Extract each individual line, and get rid of carriage returns if any
        $lines = explode("\n", str_replace("\r", '', $text));
        $new_lines = [];

        // Loop through lines
        foreach ($lines as $line) {
            // Check if the current line contains potential RTL characters
            $pos = strcspn($line, self::$RtlCharacterPrefixes);
            $length = strlen($line);

            // If not, simply store it as is
            if ($pos == $length) {
                $new_lines [] = $line;
                continue;
            }

            // Otherwise, it gets a little bit more complicated ; we have :
            // - To process each series of RTL characters and put them in reverse order
            // - Mark spaces and punctuation as "RTL separators", without reversing them (ie, a string like " ." remains " .", not ". ")
            // - Other sequences of non-RTL characters must be preserved as is and are not subject to reordering
            // The reordering sequence will be described later. For the moment, the $words array is used to store arrays of two elements :
            // - The first one is a boolean indicating whether it concerns RTL characters (true) or not (false)
            // - The second one is the string itself
            $words = [];

            // Start of the string is not an RTL sequence ; we can add it to our $words array
            if ($pos) {
                $word = substr($line, 0, $pos);
                $words [] = [$this->__is_rtl_separator($word), $word];
            }

            $in_rtl = true;

            // Loop through remaining characters of the current line
            while ($pos < $length) {
                // Character at the current position may be RTL character
                if ($in_rtl) {
                    $rtl_text = '';
                    $rtl_char = '';
                    $rtl_char_length = 0;
                    $found_rtl = false;

                    // Collect all the consecutive RTL characters, which represent a word, and put the letters in reverse order
                    while ($pos < $length && $this->__is_rtl_character($line, $pos, $rtl_char, $rtl_char_length)) {
                        $rtl_text = $rtl_char . $rtl_text;
                        $pos += $rtl_char_length;
                        $found_rtl = true;
                    }

                    // ... but make sure that we found a valid RTL sequence
                    if ($found_rtl) {
                        $words [] = [true, $rtl_text];
                    } else {
                        $words [] = [false, $line [$pos++]];
                    }

                    // For now, we are no more in a series of RTL characters
                    $in_rtl = false;
                } else {
                    // Non-RTL characters : collect them until either the end of the current line or the next RTL character
                    $next_pos = $pos + strcspn($line, self::$RtlCharacterPrefixes, $pos);

                    if ($next_pos >= $length) {
                        //$word = substr($line, $pos);
                        break;
                    } else {
                        $word = substr($line, $pos, $next_pos - $pos);
                        $pos = $next_pos;
                        $in_rtl = true;
                    }

                    // Don't forget to make the distinction between a sequence of spaces and punctuations, and a real
                    // piece of text. Space/punctuation strings surrounded by RTL words will be interverted
                    $words [] = [$this->__is_rtl_separator($word), $word];
                }
            }

            // Now we have an array, $words, whose first entry of each element indicates whether the second entry is an RTL string
            // or not (this includes strings that contain only spaces and punctuation).
            // We have to gather all the consecutive array items whose first entry is true, then invert their order.
            // Non-RTL strings are not affected by this process.
            $stacked_rtl_words = [];
            $new_words = [];

            foreach ($words as $word) {
                // RTL word : put it onto the stack
                if ($word [0]) {
                    $stacked_rtl_words [] = $word [1];
                } else {
                    // Non-RTL word : add it as is to the output array, $new_words
                    // But if RTL words were stacked before, invert them and add them to the output array
                    if (count($stacked_rtl_words)) {
                        $new_words = array_merge($new_words, array_reverse($stacked_rtl_words));
                        $stacked_rtl_words = [];
                    }

                    $new_words [] = $word [1];
                }
            }

            // Process any remaining RTL words that may have been stacked and not yet processed
            if (count($stacked_rtl_words)) {
                $new_words = array_merge($new_words, array_reverse($stacked_rtl_words));
            }

            // That's ok, we have processed one more line
            $new_lines [] = implode('', $new_words);
        }

        // All done, return a catenation of all the lines processed so far
        $result = implode("\n", $new_lines);

        return ($result);
    }


    // __is_rtl_character -
    //  Checks if the sequence starting at $pos in string $text is a character belonging to an RTL language.
    //  If yes, returns true and sets $rtl_char to the UTF8 string sequence for that character, and $rtl_char_length
    //  to the length of this string.
    //  If no, returns false.
    private function __is_rtl_character($text, $pos, &$rtl_char, &$rtl_char_length)
    {
        $ch = $text [$pos];

        // Check that the current character is the start of a potential UTF8 RTL sequence
        if (isset(self::$RtlCharacterPrefixLengths [$ch])) {
            // Get the number of characters that are expected after the sequence
            $length_after = self::$RtlCharacterPrefixLengths [$ch];

            // Get the sequence after the UTF8 prefix
            $codes_after = substr($text, $pos + 1, $length_after);

            // Search through $RtlCharacters, which contains arrays of ranges related to the UTF8 character prefix
            foreach (self::$RtlCharacters [$ch] as $range) {
                if (strcmp($range [0], $codes_after) <= 0 &&
                    strcmp($range [1], $codes_after) >= 0
                ) {
                    $rtl_char = $ch . $codes_after;
                    $rtl_char_length = $length_after + 1;

                    return (true);
                }
            }

            return (false);
        } else {
            return (false);
        }
    }


    // __is_rtl_separator -
    //  RTL words are separated by spaces and punctuation signs that are specified as LTR characters.
    //  However, such sequences, which are separators between words, must be considered as being part
    //  of an RTL sequence of words and therefore be reversed with them.
    //  This function helps to determine if the supplied string is simply a sequence of spaces and
    //  punctuation (a word separator) or plain text, that must keep its position in the line.
    private function __is_rtl_separator($text)
    {
        static $known_separators = [];
        static $separators = " \t,.;:/!-_=+";

        if (isset($known_separators [$text])) {
            return (true);
        }

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            if (strpos($separators, $text [$i]) === false) {
                return (false);
            }
        }

        $known_separators [$text] = true;

        return (true);
    }


    // __strip_useless_instructions :
    //  Removes from a text stream all the Postscript instructions that are not meaningful for text extraction
    //  (these are mainly shape drawing instructions).
    private function __strip_useless_instructions($data)
    {
        $result = preg_replace($this->IgnoredInstructions, ' ', $data);

        $this->Statistics ['TextSize'] += strlen($data);
        $this->Statistics ['OptimizedTextSize'] += strlen($result);

        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            IsPageSelected - Checks if a page is selected for output.

        PROTOTYPE
            $status     =  $this -> IsPageSelected ( $page ) ;

        DESCRIPTION
            Checks if the specified page is to be selected for output.

        PARAMETERS
            $page (integer) -
                    Page to be checked.

        RETURN VALUE
            True if the page is to be selected for output, false otherwise.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function IsPageSelected($page)
    {
        if (!$this->MaxSelectedPages) {
            return (true);
        }

        if ($this->MaxSelectedPages > 0) {
            return ($page <= $this->MaxSelectedPages);
        }

        // MaxSelectedPages  <  0
        return ($page > count($this->PageMap->Pages) + $this->MaxSelectedPages);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            PeekAuthorInformation - Gets author information from the specified object data.

        PROTOTYPE
            $this -> PeekAuthorInformation ( $object_id, $object_data ) ;

        DESCRIPTION
            Try to check if the specified object data contains author information (ie, the /Author, /Creator,
        /Producer, /ModDate, /CreationDate keywords) and sets the corresponding properties accordingly.

        PARAMETERS
            $object_id (integer) -
                Object id of this text block.

            $object_data (string) -
                Stream contents.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function PeekAuthorInformation($object_id, $object_data)
    {
        if ((strpos($object_data, '/Author') !== false || strpos($object_data, '/CreationDate') !== false)) {
            $this->GotAuthorInformation = true;
            return ($object_id);
        } else {
            return (false);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            RetrieveAuthorInformation - Extracts author information

        PROTOTYPE
            $this -> RetriveAuthorInformation ( $object_id, $pdf_objects ) ;

        DESCRIPTION
            Extracts the author information. Handles the case where flag values refer to existing objects.

        PARAMETERS
            $object_id (integer) -
                    Id of the object containing the author information.

        $pdf_objects (array) -
            Array whose keys are the PDF object ids, and values their corresponding contents.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function RetrieveAuthorInformation($object_id, $pdf_objects)
    {
        static $re = '#
							(?P<info>
								/
								(?P<keyword> (Author) | (Creator) | (Producer) | (Title) | (CreationDate) | (ModDate) | (Keywords) | (Subject) )
								\s*
								(?P<opening> [(<])
							)
						    #imsx';
        static $object_re = '#
							(?P<info>
								/
								(?P<keyword> (Author) | (Creator) | (Producer) | (Title) | (CreationDate) | (ModDate) | (Keywords) | (Subject) )
								\s*
								(?P<object_ref>
									(?P<object> \d+)
									\s+
									\d+
									\s+
									R
								 )
							)
						    #imsx';

        // Retrieve the object data corresponding to the specified object id
        $object_data = $pdf_objects [$object_id];

        // Pre-process flags whose values refer to existing objects
        if (preg_match_all($object_re, $object_data, $object_matches)) {
            $searches = [];
            $replacements = [];

            for ($i = 0, $count = count($object_matches ['keyword']); $i < $count; $i++) {
                $searches [] = $object_matches ['object_ref'] [$i];

                // Some buggy PDF may reference author information objects that do not exist
                $replacements [] = isset($pdf_objects [$object_matches ['object'] [$i]]) ?
                    trim($pdf_objects [$object_matches ['object'] [$i]]) : '';
            }

            $object_data = str_replace($searches, $replacements, $object_data);
        }


        // To execute faster, run the regular expression only if the object data contains a /Author keyword
        if (preg_match_all($re, $object_data, $matches, PREG_OFFSET_CAPTURE)) {
            for ($i = 0, $count = count($matches ['keyword']); $i < $count; $i++) {
                $keyword = $matches ['keyword'] [$i] [0];
                $opening = $matches ['opening'] [$i] [0];
                $start_index = $matches ['info'] [$i] [1] + strlen($matches ['info'] [$i] [0]);

                // Text between parentheses : the text is written as is
                if ($opening == '(') {
                    $parent_level = 1;

                    // Since the parameter value can contain any character, including "\" or "(", we will have to find the real closing
                    // parenthesis
                    $value = '';

                    for ($j = $start_index, $object_length = strlen($object_data); $j < $object_length; $j++) {
                        if ($object_data [$j] == '\\') {
                            $value .= '\\' . $object_data [++$j];
                        } elseif ($object_data [$j] == '(') {
                            $value .= '(';
                            $parent_level++;
                        } elseif ($object_data [$j] == ')') {
                            $parent_level--;

                            if (!$parent_level) {
                                break;
                            } else {
                                $value .= ')';
                            }
                        } else {
                            $value .= $object_data [$j];
                        }
                    }
                } else {
                    // Text within angle brackets, written as hex digits
                    $end_index = strpos($object_data, '>', $start_index);
                    $hexdigits = substr($object_data, $start_index, $end_index - $start_index);
                    $value = hex2bin(str_replace(["\n", "\r", "\t"], '', $hexdigits));
                }

                $value = $this->__convert_utf16($this->__extract_chars_from_block($value));

                switch (strtolower($keyword)) {
                    case  'author'        :
                        $this->Author = $value;
                        break;
                    case  'creator'        :
                        $this->CreatorApplication = $value;
                        break;
                    case  'producer'    :
                        $this->ProducerApplication = $value;
                        break;
                    case  'title'        :
                        $this->Title = $value;
                        break;
                    case  'keywords'    :
                        $this->Keywords = $value;
                        break;
                    case  'subject'        :
                        $this->Subject = $value;
                        break;
                    case  'creationdate'    :
                        $this->CreationDate = $this->GetUTCDate($value);
                        break;
                    case  'moddate'        :
                        $this->ModificationDate = $this->GetUTCDate($value);
                        break;
                }
            }

            if (self::$DEBUG) {
                echo "\n----------------------------------- AUTHOR INFORMATION\n";
                echo("Author               : " . $this->Author . "\n");
                echo("Creator application  : " . $this->CreatorApplication . "\n");
                echo("Producer application : " . $this->ProducerApplication . "\n");
                echo("Title                : " . $this->Title . "\n");
                echo("Subject              : " . $this->Subject . "\n");
                echo("Keywords             : " . $this->Keywords . "\n");
                echo("Creation date        : " . $this->CreationDate . "\n");
                echo("Modification date    : " . $this->ModificationDate . "\n");
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            RetrieveFormData - Retrieves raw form data

        PROTOTYPE
            $this -> RetrieveFormData ( $object_id, $object_data ) ;

        DESCRIPTION
            Retrieves raw form data (form definition and field values definition).

        PARAMETERS
            $object_id (integer) -
                    Id of the object containing the author information.

        $object_data (string) -
            Object contents.

        $pdf_objects (array) -
            Array whose keys are the PDF object ids, and values their corresponding contents.

        NOTES
            This function only memorizes the contents of form data definitions. The actual data will be processed
        only if the GetFormData() function is called.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function RetrieveFormData($object_id, $object_data, $pdf_objects)
    {
        // Retrieve the object that contains the field values
        preg_match('#\b R \s* \( \s* datasets \s* \) \s* (?P<object> \d+) \s+ \d+ \s+ R#imsx', $object_data, $field_match);
        $field_object = $field_match ['object'];

        if (!isset($pdf_objects [$field_object])) {
            if (self::$DEBUG) {
                warning("Field definitions object #$field_object not found in object #$object_id.");
            }

            return;
        }

        // Retrieve the object that contains the form definition
        preg_match('#\b R \s* \( \s* form \s* \) \s* (?P<object> \d+) \s+ \d+ \s+ R#imsx', $object_data, $form_match);
        $form_object = $form_match ['object'];

        if (!isset($pdf_objects [$form_object])) {
            if (self::$DEBUG) {
                warning("Form definitions object #$form_object not found in object #$object_id.");
            }

            return;
        }
        // Add this entry to form data information
        $this->FormData [$object_id] =
        [
            'values' => ( integer )$field_object,
            'form' => ( integer )$form_object
        ];
    }
}
