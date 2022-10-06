<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class CaptureArea -
        A capture area describes a rectangle, either by its top, left, right and bottom coordinates, or by
    its top/left coordinates, and its width and height.

  ==============================================================================================================*/

class CaptureArea //extends  Object
{
    // List of authorzed keyword for defining the rectangle dimensions
    static private $Keys = ['left', 'top', 'right', 'bottom', 'width', 'height'];

    // Rectangle dimensions
    private $Left = false,
        $Top = false,
        $Right = false,
        $Bottom = false;

    // Area name (for internal purposes)
    public $Name;


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            Constructor

        PROTOTYPE
            $area   =  new CaptureArea ( $area, $default_area = null, $name = '' ) ;

        DESCRIPTION
            Initialize an area (a rectangle) using the supplied coordinates

        PARAMETERS
            $area (array) -
                    An associative array that may contain the following entries :

            - 'left' (double) :
                Left x-coordinate (mandatory).

            - 'top' (double) :
                Top y-coordinate (mandatory).

            - 'right (double) :
                Right x-coordinate.

            - 'bottom' (double) :
                Bottom y-coordinate.

            - 'width' (double) :
                Width of the rectangle, starting from 'left'.

            - 'height' (double) :
                Height of the rectangle, starting from 'top'.

            Either the 'right' or 'width' entries must be specified. This is the same for the 'bottom' and
            'height' entries.

        $default_area (array) -
            An array that can be used to supply default values when absent from $area.

        $name (string) -
            An optional name for this area. This information is not used by the class.

        NOTES
            Coordinate (0,0) is located at the left bottom of the page.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($area, $default_area = null, $name = '')
    {
        $left =
        $top =
        $right =
        $bottom =
        $width =
        $height = false;

        // Retrieve each entry that allows to specify a coordinate component, using $default_area if needed
        foreach (self::$Keys as $key) {
            if (isset($area [$key])) {
                if ($area [$key] === false) {
                    if (isset($default_area [$key])) {
                        $$key = $default_area [$key];
                    } else {
                        $$key = false;
                    }
                } else {
                    $$key = $area [$key];
                }
            } elseif (isset($default_area [$key])) {
                $$key = $default_area [$key];
            }
        }

        // Check for mandatory coordinates
        if ($left === false) {
            error(new CaptureException("Attribute \"left\" is mandatory."));
        } else {
            $left = ( double )$left;
        }

        if ($top === false) {
            error(new CaptureException("Attribute \"top\" is mandatory."));
        } else {
            $top = ( double )$top;
        }

        // Either the 'right' or 'width' entries are required
        if ($right === false) {
            if ($width === false) {
                error(new CaptureException("Either the \"right\" or the \"width\" attribute must be specified."));
            } else {
                $right = $left + ( double )$width - 1;
            }
        } else {
            $right = ( double )$right;
        }

        // Same for 'bottom' and 'height'
        if ($bottom === false) {
            if ($height === false) {
                error(new CaptureException("Either the \"bottom\" or the \"height\" attribute must be specified."));
            } else {
                $bottom = $top - ( double )$height + 1;
            }
        } else {
            $bottom = ( double )$bottom;
        }

        // All done, we have the coordinates we wanted
        $this->Left = $left;
        $this->Right = $right;
        $this->Top = $top;
        $this->Bottom = $bottom;

        $this->Name = $name;
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            __get, __set - Implement the Width and Height properties.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __get($member)
    {
        switch ($member) {
            case    'Left'        :
            case    'Top'        :
            case    'Right'        :
            case    'Bottom'    :
                return ($this->$member);

            case    'Width'        :
                return ($this->Right - $this->Left + 1);

            case    'Height'    :
                return ($this->Top - $this->Bottom + 1);

            default :
                trigger_error("Undefined property \"$member\".");
                return null;
        }
    }


    public function __set($member, $value)
    {
        $value = ( double )$value;

        switch ($member) {
            case    'Top'        :
            case    'Left'        :
            case    'Right'        :
            case    'Bottom'    :
                $this->$member = $value;
                break;

            case    'Width'        :
                $this->Right = $this->Left + $value - 1;
                break;

            case    'Height'    :
                $this->Bottom = $this->Top - $value + 1;
                break;

            default :
                trigger_error("Undefined property \"$member\".");
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            Contains - Check if this area contains the specified rectangle.

     *-------------------------------------------------------------------------------------------------------------*/
    public function Contains($left, $top, $right, $bottom)
    {
        if ($left >= $this->Left && $right <= $this->Right && $top <= $this->Top && $bottom >= $this->Bottom) {
            return (true);
        } else {
            return (false);
        }
    }
}
