<?php
namespace VanXuan\PdfToText;

/**************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************
 ******                                                                                                  ******
 ******                                                                                                  ******
 ******                               CAPTURE INTERFACE FOR THE DEVELOPER                                ******
 ******         (none of the classes listed here are meant to be instantiated outside this file)         ******
 ******                                                                                                  ******
 ******                                                                                                  ******
 **************************************************************************************************************
 **************************************************************************************************************
 **************************************************************************************************************/
/*==============================================================================================================

    class Captures -
        Represents all the areas in a PDF file captured by the supplied XML definitions.

  ==============================================================================================================*/

class Captures //extends  Object
{
    // Captured objects - May not exactly reflect the Capture*Shape classes
    private $CapturedObjects;
    // Allows faster access by capture name
    private $ObjectsByName = [];


    /*--------------------------------------------------------------------------------------------------------------

        Constructor -
        Instantiates a Captures object.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($captures)
    {
        $this->CapturedObjects = $captures;

        // Build an array of objects indexed by their names
        foreach ($captures as $page => $shapes) {
            foreach ($shapes as $shape) {
                $this->ObjectsByName [$shape->Name] [] = $shape;
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        ToCaptures -
        Returns a simplified view of captured objects, with only name/value pairs.

     *-------------------------------------------------------------------------------------------------------------*/
    public function ToCaptures()
    {
        $result = new \stdClass();

        foreach ($this->CapturedObjects as $page => $captures) {
            foreach ($captures as $capture) {
                switch ($capture->Type) {
                    case    CaptureShapeDefinition::SHAPE_RECTANGLE :
                        $name = $capture->Name;
                        $value = $capture->Text;
                        $result->{$name} [$page] = $value;
                        break;

                    case    CaptureShapeDefinition::SHAPE_LINE :
                        $name = $capture->Name;

                        if (!isset($result->{$name})) {
                            $result->{$name} = [];
                        }

                        foreach ($capture as $line) {
                            $columns = new  \stdClass;

                            foreach ($line as $column) {
                                $column_name = $column->Name;
                                $column_value = $column->Text;
                                $columns->{$column_name} = $column_value;
                            }

                            $result->{$name} [] = $columns;
                        }
                }
            }
        }

        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

        __get -
        Retrieves the captured objects by their name, as specified in the XML definition.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __get($member)
    {
        $fieldname = "__capture_{$member}__";

        if (!isset($this->$fieldname)) {
            if (!isset($this->ObjectsByName [$member])) {
                error(new PTTException("Undefined property \"$member\"."));
            }

            $this->$fieldname = $this->GetCaptureInstance($member);
        }

        return ($this->$fieldname);
    }


    /*--------------------------------------------------------------------------------------------------------------

        GetCapturedObjectsByName -
        Returns an associative array of the captured shapes, indexed by their name.

     *-------------------------------------------------------------------------------------------------------------*/
    public function GetCapturedObjectsByName()
    {
        return ($this->ObjectsByName);
    }


    /*--------------------------------------------------------------------------------------------------------------

        GetCaptureInstance -
        Returns an object inheriting from the Capture class, that wraps the capture results.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetCaptureInstance($fieldname)
    {
        switch ($this->ObjectsByName [$fieldname] [0]->Type) {
            case    CaptureShapeDefinition::SHAPE_RECTANGLE :
                return (new RectangleCapture($this->ObjectsByName [$fieldname]));

            case    CaptureShapeDefinition::SHAPE_LINE :
                return (new LinesCapture($this->ObjectsByName [$fieldname]));

            default :
                error(new CaptureException("Unhandled shape type " . $this->ObjectsByName [$fieldname] [0]->Type . "."));
                return null;
        }
    }
}
