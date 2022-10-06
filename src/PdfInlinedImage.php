<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class PdfInlinedImage -
        Decodes raw image data in objects having the /FlateDecode flag.

  ==============================================================================================================*/

class PdfInlinedImage extends PdfImage
{
    // Supported color schemes
    const        COLOR_SCHEME_RGB = 1;
    const        COLOR_SCHEME_CMYK = 2;
    const        COLOR_SCHEME_GRAY = 3;

    // Color scheme names, for debugging only
    private static $DecoderNames =
    [
        self::COLOR_SCHEME_RGB => 'RGB',
        self::COLOR_SCHEME_CMYK => 'CMYK',
        self::COLOR_SCHEME_GRAY => 'Gray'
    ];

    // Currently implemented image decoders
    private static $Decoders =
    [
        self::COLOR_SCHEME_RGB =>
        [
            8 => '__decode_rgb8'
        ],
        self::COLOR_SCHEME_GRAY =>
        [
            8 => '__decode_gray8'
        ],
        self::COLOR_SCHEME_CMYK =>
        [
            8 => '__decode_cmyk8'
        ],
    ];

    // Image width and height
    public $Width,
        $Height;
    // Color scheme
    public $ColorScheme;
    // Number of bits per color component
    public $BitsPerComponent;
    // Decoding function, varying upon the supplied image type
    public $DecodingFunction = false;


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            Constructor - Builds an image from the supplied data.

        PROTOTYPE
            $image  =  new  PdfInlinedImage ( $image_data, $width, $height, $bits_per_component, $color_scheme ) ;

        DESCRIPTION
            Builds an image from the supplied data. Checks that the image flags are supported.

        PARAMETERS
            $image_data (string) -
                    Uncompressed image data.

        $width (integer) -
            Image width, in pixels.

        $height (integer) -
            Image height, in pixels.

        $bits_per_components (integer) -
            Number of bits per color component.

        $color_scheme (integer) -
            One of the COLOR_SCHEME_* constants, specifying the initial data format.

        NOTES
            Processed images are always converted to JPEG format.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($image_data, $width, $height, $bits_per_component, $color_scheme)
    {
        $this->Width = $width;
        $this->Height = $height;
        $this->BitsPerComponent = $bits_per_component;
        $this->ColorScheme = $color_scheme;

        // Check that we have a decoding function for the supplied parameters
        if (isset(self::$Decoders [$color_scheme])) {
            if (isset(self::$Decoders [$color_scheme] [$bits_per_component])) {
                $this->DecodingFunction = self::$Decoders [$color_scheme] [$bits_per_component];
            } else {
                error(new DecodingException("No decoding function has been implemented for image objects having the " .
                    self::$DecoderNames [$color_scheme] . " color scheme with $bits_per_component bits per color component."));
            }
        } else {
            error(new DecodingException("Unknown color scheme $color_scheme."));
        }

        parent::__construct($image_data);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            CreateInstance - Creates an appropriate instance of a PdfImage class.

        PROTOTYPE
            $image  =  PdfInlinedImage ( $stream_data, $object_data ) ;

        DESCRIPTION
            Creates an instance of either :
        - A PdfJpegImage class, if the image specifications in $object_data indicate that the compressed stream
          contents are only JPEG data
        - A PdfInlinedImage class, if the image specifications state that the compressed stream data contain
          only color values.

        The class currently supports (in $stream_data) :
        - Pure JPEG contents
        - RGB values
        - CMYK values
        - Gray scale values (in the current version, the resulting image does not correctly reproduce the
          initial colors, if interpolation is to be used).

        PARAMETERS
            $stream_data (string) -
                    Compressed image data.

        $object_data (string) -
            Object containing the stream data.

        RETURN VALUE
            Returns :
        - A PdfJpegImage object, if the stream data contains only pure JPEG contents
        - A PdfInlinedImage object, in other cases.
        - False if the supplied image data is not currently supported.

     *-------------------------------------------------------------------------------------------------------------*/
    public static function CreateInstance($stream_data, $object_data, $autosave)
    {
        // Remove stream data from the supplied object data, to speed up the searches below
        $index = strpos($object_data, 'stream');

        if ($index !== false) {
            $object_data = substr($object_data, 0, $index);
        }

        // Uncompress stream data
        $image_data = gzuncompress($stream_data);

        // The /DCTDecode flag indicates JPEG contents - returns a PdfJpegImage object
        if (stripos($object_data, '/DCTDecode')) {
            return (new PdfJpegImage($image_data, $autosave));
        }

        // Get the image width & height
        $match = null;
        preg_match('#/Width \s+ (?P<value> \d+)#ix', $object_data, $match);
        $width = ( integer )$match ['value'];

        $match = null;
        preg_match('#/Height \s+ (?P<value> \d+)#ix', $object_data, $match);
        $height = ( integer )$match ['value'];

        // Get the number of bits per color component
        $match = null;
        preg_match('#/BitsPerComponent \s+ (?P<value> \d+)#ix', $object_data, $match);
        $bits_per_component = ( integer )$match ['value'];

        // Get the target color space
        // Sometimes, this refers to an object in the PDF file, which can also be embedded in a compound object
        // We don't handle such cases for now
        $match = null;
        preg_match('#/ColorSpace \s* / (?P<value> \w+)#ix', $object_data, $match);

        if (!isset($match ['value'])) {
            return (false);
        }

        $color_space_name = $match ['value'];

        // Check that we are able to handle the specified color space
        switch (strtolower($color_space_name)) {
            case    'devicergb' :
                $color_space = self::COLOR_SCHEME_RGB;
                break;

            case    'devicegray' :
                $color_space = self::COLOR_SCHEME_GRAY;
                break;

            case    'devicecmyk' :
                $color_space = self::COLOR_SCHEME_CMYK;
                break;

            default :
                if (PdfToText::$DEBUG) {
                    warning(new DecodingException("Unsupported color space \"$color_space_name\"."));
                }

                return (false);
        }

        // Also check that we can handle the specified number of bits per component
        switch ($bits_per_component) {
            case    8 :
                break;

            default :
                if (PdfToText::$DEBUG) {
                    warning(new DecodingException("Unsupported bits per component : $bits_per_component."));
                }

                return (false);
        }

        // All done, return a PdfInlinedImage object
        return (new PdfInlinedImage($image_data, $width, $height, $bits_per_component, $color_space));
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            CreateImageResource - Creates the image resource.

        PROTOTYPE
            $resource   =  $image -> CreateImageResource ( $image_data ) ;

        DESCRIPTION
            Creates a GD image according to the supplied image data, and the parameters supplied to the class
        constructor.

        PARAMETERS
            $image_data (string) -
                    Image to be decoded.

        RETURN VALUE
            Returns a GD graphics resource in true color, or false if there is currently no implemented decoding
        function for this kind of images.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function CreateImageResource($image_data)
    {
        $decoder = $this->DecodingFunction;

        if ($decoder) {
            return ($this->$decoder($image_data));
        } else {
            return (false);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        Decoding functions.

     *-------------------------------------------------------------------------------------------------------------*/

    // __decode_rgb8 -
    //  Decodes image data consisting of 8-bits RGB values (one byte for each color component).
    /** @noinspection PhpUnusedPrivateMethodInspection
     * @param $data
     *
     * @return resource
     */
    private function __decode_rgb8($data)
    {
        $data_length = strlen($data);
        $colors = [];
        $width = $this->Width;
        $height = $this->Height;
        $image = imagecreatetruecolor($width, $height);

        for ($i = 0, $pixel_x = 0, $pixel_y = 0; $i + 3 <= $data_length; $i += 3, $pixel_x++) {
            $red = ord($data [$i]);
            $green = ord($data [$i + 1]);
            $blue = ord($data [$i + 2]);

            $color = ($red << 16) | ($green << 8) | ($blue);

            if (isset($colors [$color])) {
                $pixel_color = $colors [$color];
            } else {
                $pixel_color = imagecolorallocate($image, $red, $green, $blue);
                $colors [$color] = $pixel_color;
            }

            if ($pixel_x >= $width) {
                $pixel_x = 0;
                $pixel_y++;
            }

            imagesetpixel($image, $pixel_x, $pixel_y, $pixel_color);
        }

        return ($image);
    }


    // __decode_cmyk8 -
    //  Decodes image data consisting of 8-bits CMYK values (one byte for each color component).
    /** @noinspection PhpUnusedPrivateMethodInspection
     * @param $data
     *
     * @return resource
     */
    private function __decode_cmyk8($data)
    {
        $data_length = strlen($data);
        $colors = [];
        $width = $this->Width;
        $height = $this->Height;
        $image = imagecreatetruecolor($width, $height);

        for ($i = 0, $pixel_x = 0, $pixel_y = 0; $i + 4 <= $data_length; $i += 4, $pixel_x++) {
            $cyan = ord($data [$i]);
            $magenta = ord($data [$i + 1]);
            $yellow = ord($data [$i + 2]);
            $black = ord($data [$i + 3]);

            $color = ($cyan << 24) | ($magenta << 16) | ($yellow << 8) | ($black);

            if (isset($colors [$color])) {
                $pixel_color = $colors [$color];
            } else {
                $rgb = $this->__convert_cmyk_to_rgb($cyan, $magenta, $yellow, $black);
                $pixel_color = imagecolorallocate($image, $rgb [0], $rgb [1], $rgb [2]);
                $colors [$color] = $pixel_color;
            }

            if ($pixel_x >= $width) {
                $pixel_x = 0;
                $pixel_y++;
            }

            imagesetpixel($image, $pixel_x, $pixel_y, $pixel_color);
        }

        return ($image);
    }


    // __decode_gray8 -
    //  Decodes image data consisting of 8-bits gray values.
    /** @noinspection PhpUnusedPrivateMethodInspection
     * @param $data
     *
     * @return resource
     */
    private function __decode_gray8($data)
    {
        $data_length = strlen($data);
        $colors = [];
        $width = $this->Width;
        $height = $this->Height;
        $image = imagecreatetruecolor($width, $height);

        for ($i = 0, $pixel_x = 0, $pixel_y = 0; $i < $data_length; $i++, $pixel_x++) {
            $color = ord($data [$i]);

            if (isset($colors [$color])) {
                $pixel_color = $colors [$color];
            } else {
                $pixel_color = imagecolorallocate($image, $color, $color, $color);
                $colors [$color] = $pixel_color;
            }

            if ($pixel_x >= $width) {
                $pixel_x = 0;
                $pixel_y++;
            }

            imagesetpixel($image, $pixel_x, $pixel_y, $pixel_color);
        }

        return ($image);
    }


    /*--------------------------------------------------------------------------------------------------------------

        Support functions.

     *-------------------------------------------------------------------------------------------------------------*/

    // __convert_cmyk_to_rgb -
    //  Converts CMYK color value to RGB.
    private function __convert_cmyk_to_rgb($C, $M, $Y, $K)
    {
        if ($C > 1 || $M > 1 || $Y > 1 || $K > 1) {
            $C /= 100.0;
            $M /= 100.0;
            $Y /= 100.0;
            $K /= 100.0;
        }

        $R = (1 - $C * (1 - $K) - $K) * 256;
        $G = (1 - $M * (1 - $K) - $K) * 256;
        $B = (1 - $Y * (1 - $K) - $K) * 256;

        $result = [round($R), round($G), round($B)];

        return ($result);
    }
}
