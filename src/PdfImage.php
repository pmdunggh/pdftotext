<?php
namespace VanXuan\PdfToText;

/**************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************
 ******                                                                                                  ******
 ******                                                                                                  ******
 ******                                         IMAGE MANAGEMENT                                         ******
 ******                                                                                                  ******
 ******                                                                                                  ******
 **************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************/
/*==============================================================================================================

    class PdfImage -
        Holds image data coming from pdf.

  ==============================================================================================================*/

abstract class PdfImage extends PdfObjectBase
{
    // Image resource that can be used to process image data, using the php imagexxx() functions
    public $ImageResource = null;
    // Original image data
    protected $ImageData;
    // Tells if the image resource has been created - false when the autosave feature is on and the image is pure JPEG data
    protected $NoResourceCreated;


    /*--------------------------------------------------------------------------------------------------------------

        CONSTRUCTOR
            Creates a PdfImage object with a resource that can be used with imagexxx() php functions.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($image_data, $no_resource_created = false)
    {
        parent::__construct();
        $this->ImageData = $image_data;
        $this->NoResourceCreated = $no_resource_created;

        if (!$no_resource_created) {
            $this->ImageResource = $this->CreateImageResource($image_data);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        DESTRUCTOR
            Destroys the associated image resource.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __destruct()
    {
        $this->DestroyImageResource();
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            CreateImageResource - creates an image resource from the supplied image data.

        PROTOTYPE
            $resource   =  $this -> CreateImageResource ( $data ) ;

        DESCRIPTION
            Creates an image resource from the supplied image data.
        Whatever the input format, the internal format will be the one used by the gd library.

        PARAMETERS
            $data (string) -
                    Image data.

     *-------------------------------------------------------------------------------------------------------------*/
    abstract protected function CreateImageResource($image_data);


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            DestroyImageResource - Destroys the allocated image resource.

        PROTOTYPE
            $this -> DestroyImageResource ( ) ;

        DESCRIPTION
            Destroys the allocated image resource, using the libgd imagedestroy() function. This method can be
        overridden by derived class if the underlying image resource does not come from the gd lib.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function DestroyImageResource()
    {
        if ($this->ImageResource) {
            imagedestroy($this->ImageResource);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            SaveAs - Saves the current image to a file.

        PROTOTYPE
            $pdfimage -> SaveAs ( $output_file, $image_type = IMG_JPEG ) ;

        DESCRIPTION
            Saves the current image resource to the specified output file, in the specified format.

        PARAMETERS
            $output_file (string) -
                    Output filename.

        $image_type (integer) -
            Output format. Can be any of the predefined php constants IMG_*.

     *-------------------------------------------------------------------------------------------------------------*/
    public function SaveAs($output_file, $image_type = IMG_JPEG)
    {
        if (!$this->ImageResource) {
            if ($this->NoResourceCreated && $image_type == IMG_JPEG) {
                file_put_contents($output_file, $this->ImageData);
            } elseif (PdfToText::$DEBUG) {
                warning(new DecodingException("No image resource allocated."));
            }

            return;
        }

        $image_types = imagetypes();

        switch ($image_type) {
            case    IMG_JPEG :
            case    IMG_JPG :
                if (!($image_types & IMG_JPEG) && !($image_types & IMG_JPG)) {
                    error(new DecodingException("Your current PHP version does not support JPG images."));
                }

                imagejpeg($this->ImageResource, $output_file, 100);
                break;

            case    IMG_GIF :
                if (!($image_types & IMG_GIF)) {
                    error(new DecodingException("Your current PHP version does not support GIF images."));
                }

                imagegif($this->ImageResource, $output_file);
                break;

            case    IMG_PNG :
                if (!($image_types & IMG_PNG)) {
                    error(new DecodingException("Your current PHP version does not support PNG images."));
                }

                imagepng($this->ImageResource, $output_file, 0);
                break;

            case    IMG_WBMP :
                if (!($image_types & IMG_WBMP)) {
                    error(new DecodingException("Your current PHP version does not support WBMP images."));
                }

                imagewbmp($this->ImageResource, $output_file);
                break;

            case    IMG_XPM :
                if (!($image_types & IMG_XPM)) {
                    error(new DecodingException("Your current PHP version does not support XPM images."));
                }

                imagexbm($this->ImageResource, $output_file);
                break;

            default :
                error(new DecodingException("Unknown image type #$image_type."));
        }
    }


    public function Output()
    {
        $this->SaveAs(null);
    }
}
