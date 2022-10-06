<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class FormDefinition -
        Holds the description of a form inside a form XML template.

  ==============================================================================================================*/

class FormDefinition // extends  Object
{
    // Class of the object returned by GetFormData( )
    public $ClassName;

    // Form version
    public $Version;

    // Field definitions
    public $FieldDefinitions = [];

    // Field groups (ie, fields that are the results of the concatenation of several form fields)
    public $Groups = [];

    // Pdf field definitions
    public $PdfDefinitions;

    // Class definition in PHP, whose instance will be returned by GetFormData()
    private $ClassDefinition = false;

    // Direct access to field definitions either through their template name or PDF name
    private $FieldDefinitionsByName = [];
    private $FieldDefinitionsByPdfName = [];


    /*--------------------------------------------------------------------------------------------------------------

        Constructor -
        Analyze the contents of an XML template form definition.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($class_name, \SimpleXMLElement $form_definition, $pdf_definitions)
    {
        $this->ClassName = $class_name;
        $this->PdfDefinitions = $pdf_definitions;
        $field_count = 0;

        // Get <form> tag attributes
        foreach ($form_definition->attributes() as $attribute_name => $attribute_value) {
            switch (strtolower($attribute_name)) {
                case    'version' :
                    $this->Version = ( string )$attribute_value;
                    break;

                default :
                    error(new FormException("Invalid attribute \"$attribute_name\" in <form> tag."));
            }
        }

        // Loop through subtags
        /** @var \SimpleXMLElement $child */
        foreach ($form_definition->children() as $child) {
            $tag_name = $child->getName();

            // Check subtags
            switch (strtolower($tag_name)) {
                // <group> :
                //  A group is used to create a property that is the concatenation of several existing properties.
                case    'group' :
                    $fields = [];
                    $separator = '';
                    $name = false;

                    // Loop through attribute names
                    foreach ($child->attributes() as $attribute_name => $attribute_value) {
                        switch ($attribute_name) {
                            // "name" attribute" :
                            //  The name of the property, as it will appear in the output object.
                            case    'name' :
                                //$name     =  PdfToTextObjectBase::ValidatePhpName ( ( string ) $attribute_value ) ;
                                $name = PdfObjectBase::ValidatePhpName(( string )$attribute_value);
                                break;

                            // "separator" attribute :
                            //  Separator to be used when concatenating the underlying properties.
                            case    'separator' :
                                $separator = ( string )$attribute_value;
                                break;

                            // "fields" :
                            //  A list of comma-separated field names, whose values will be concatenated together
                            //  using the specified separator.
                            case    'fields' :
                                $items = explode(',', ( string )$attribute_value);

                                if (!count($items)) {
                                    error(new FormException("Empty \"fields\" attribute in <group> tag."));
                                }

                                foreach ($items as $item) {
                                    $fields [] = PdfObjectBase::ValidatePhpName($item);
                                }
                                //$fields []    =  PdfToTextObjectBase::ValidatePhpName ( $item ) ;

                                break;

                            // Other attribute names : not allowed
                            default :
                                error(new FormException("Invalid attribute \"$attribute_name\" in <group> tag."));
                        }
                    }

                    // Check that at least one field has been specified
                    if (!count($fields)) {
                        error(new FormException("Empty \"fields\" attribute in <group> tag."));
                    }

                    // Check that the mandatory property name has been specified
                    if (!$name) {
                        error(new FormException("The \"name\" attribute is mandatory in <group> tag."));
                    }

                    // Add this new grouped property to the list of existing groups
                    $this->Groups [] =
                    [
                        'name' => $name,
                        'separator' => $separator,
                        'fields' => $fields
                    ];

                    break;

                // <field> :
                //  Field definition.
                case    'field' :
                    $field_def = new FormFieldDefinition($child);
                    $this->FieldDefinitions [] = $field_def;
                    $this->FieldDefinitionsByName [$field_def->Name] =
                    $this->FieldDefinitionsByPdfName [$field_def->PdfName] = $field_count;
                    $field_count++;
                    break;

                // Don't allow other attribute names
                default :
                    error(new FormException("Invalid tag <$tag_name> in <form> definition."));
            }
        }

        // Check that everything is ok (ie, that there is no duplicate fields)
        $this->__paranoid_checks();
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetClassDefinition - Returns the class definition for the urrent form.

        PROTOTYPE
            $def    =   $form_def -> GetClassDefinition ( ) ;

        DESCRIPTION
            Returns a string containing the PHP class definition that will contain the properties defined in the XML
        form template.

        RETURN VALUE
            Returns a string containing the PHP class definition for the current form.

     *-------------------------------------------------------------------------------------------------------------*/
    public function GetClassDefinition()
    {
        // Return the existing definition, if this method has been called more than once
        if ($this->ClassDefinition) {
            return ($this->ClassDefinition);
        }

        $class_def = "// Class " . $this->ClassName . " : " . $this->Version . PHP_EOL .
            "class {$this -> ClassName}\t\textends PdfToTextFormData" . PHP_EOL .
            "   {" . PHP_EOL;

        // Get the maximum width of constant and field names
        $max_width = 0;

        foreach ($this->FieldDefinitions as $def) {
            $length1 = strlen($def->Name);
            $length2 = strlen($def->PdfName);

            if ($length1 > $max_width || $length2 > $max_width) {
                $max_width = max($length1, $length2);
            }

            foreach ($def->Constants as $constant) {
                $length = strlen($constant ['name']);

                if ($length > $max_width) {
                    $max_width = $length;
                }
            }
        }

        // First, write out the constant definitions
        $all_constants = [];

        foreach ($this->FieldDefinitions as $def) {
            foreach ($def->Constants as $constant) {
                $name = $constant ['name'];
                $value = $constant ['value'];

                if (isset($all_constants [$name])) {
                    if ($all_constants [$name] != $value) {
                        error(new FormException("Constant \"$name\" is defined more than once with different values."));
                    }
                } else {
                    $all_constants [$name] = $value;

                    if (!is_numeric($value)) {
                        $value = '"' . addslashes($value) . '"';
                    }

                    $class_def .= "\tconst\t" . str_pad($name, $max_width, " ", STR_PAD_RIGHT) . "\t = $value ; " . PHP_EOL;
                }
            }
        }

        $class_def .= PHP_EOL . PHP_EOL;

        // Then write property definitions
        foreach ($this->FieldDefinitions as $def) {
            $class_def .= "\t/** @formdata */" . PHP_EOL .
                "\tprotected\t\t\${$def -> Name} ;" . PHP_EOL;
        }

        $class_def .= PHP_EOL . PHP_EOL;

        // And finally, grouped properties
        foreach ($this->Groups as $group) {
            $class_def .= "\t/**" . PHP_EOL .
                "\t\t@formdata" . PHP_EOL .
                "\t\t@group(" . implode(',', $group ['fields']) . ')' . PHP_EOL .
                "\t\t@separator(" . str_replace(')', '\)', $group ['separator']) . ')' . PHP_EOL .
                "\t */" . PHP_EOL .
                "\tprotected\t\t\${$group [ 'name' ]} ;" . PHP_EOL . PHP_EOL;
        }

        // Constructor
        $class_def .= PHP_EOL . PHP_EOL .
            "\t// Class constructor" . PHP_EOL .
            "\tpublic function __construct ( )" . PHP_EOL .
            "\t   {" . PHP_EOL .
            "\t\tparent::__construct ( ) ;" . PHP_EOL .
            "\t    }" . PHP_EOL;

        $class_def .= "    }" . PHP_EOL;

        // Save the definition, if a second call occurs
        $this->ClassDefinition = $class_def;

        // All done, return
        return ($class_def);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetFormData - Returns a form data object containing properties mapped to the form data.

        PROTOTYPE
            $object     =  $form_def -> GetFormData ( $fields ) ;

        DESCRIPTION
            Returns an object containing properties mapped to actual form data.

        PARAMETERS
            $fields (array) -
                    An associative array whoses keys are the PDF form field names, and values their values as stored
            in the PDF file.

        RETURN VALUE
            Returns an object of the class, as defined by the template specified to PdfToTextFormDefinitions
        class constructor.

     *-------------------------------------------------------------------------------------------------------------*/
    public function GetFormData($fields = [])
    {
        if (!class_exists($this->ClassName, false)) {
            $class_def = $this->GetClassDefinition();
            eval($class_def);
        }

        $class_name = $this->ClassName;
        $object = new  $class_name();

        foreach ($fields as $name => $value) {
            if (isset($this->FieldDefinitionsByPdfName [$name])) {
                $property = $this->FieldDefinitions [$this->FieldDefinitionsByPdfName [$name]]->Name;
                $object->$property = $this->__process_field_value($value);
            }
        }

        return ($object);
    }


    // __process_field_values -
    //  Translates html entities and removes carriage returns (which are apparently used for multiline field) to
    //  replace them with newlines.
    private function __process_field_value($value)
    {
        $value = html_entity_decode($value);
        $result = '';

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            if ($value [$i] !== "\r") {
                $result .= $value [$i];
            } else {
                if (isset($value [$i + 1])) {
                    if ($value [$i + 1] !== "\n") {
                        $result .= "\n";
                    }
                } else {
                    $result .= "\n";
                }
            }
        }

        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetformDataFromPdfObject - Same as GetFormData(), except that it operates on XML data.

        PROTOTYPE
            $object     =  $pdf -> GetFormDataFromPdfObject ( $pdf_data ) ;

        DESCRIPTION
            Behaves the same as GetFormData(), except that it takes as input the XML contents of a PDF object.

        PARAMETERS
            $pdf_data (string) -
                    XML data coming from the PDF file.

        RETURN VALUE
            Returns an object of the class, as defined by the template specified to PdfToTextFormDefinitions
        class constructor.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetFormDataFromPdfObject($pdf_data)
    {
        // simplexml_ functions do not like tags that contain a colon - replace them with a dash
        $pdf_data = preg_replace('/(<[^:]+?)(:)/', '$1-', $pdf_data);

        // Load the xml data
        $xml = simplexml_load_string($pdf_data);

        // Get the form field values
        $fields = [];

        $this->__get_pdfform_data($fields, $xml);

        // Return the object
        return ($this->GetFormData($fields));
    }


    // __getpdfform_data -
    //  Retrieve the form field values from the specified PDF object, specified as XML
    private function __get_pdfform_data(&$fields, \SimpleXMLElement $xml)
    {
        $tag_name = $xml->getName();

        if (isset($this->PdfDefinitions [$tag_name])) {
            $fields [$tag_name] = ( string )$xml;
        } else {
            foreach ($xml->children() as $child) {
                $this->__get_pdfform_data($fields, $child);
            }
        }
    }


    // __paranoid_checks -
    //  Checks for several kinds of inconsistencies in the supplied XML template.
    private function __paranoid_checks()
    {
        // Check that field names, PDF field names and constant names are unique
        $names = [];
        $pdf_names = [];
        $constant_names = [];

        foreach ($this->FieldDefinitions as $def) {
            if (!isset($this->PdfDefinitions [$def->PdfName])) {
                error(new FormException("Field \"{$def -> PdfName}\" is not defined in the PDF file."));
            }

            if (isset($names [$def->Name])) {
                error(new FormException("Field \"{$def -> Name}\" is defined more than once."));
            }

            $names [$def->Name] = true;

            if (isset($pdf_names [$def->PdfName])) {
                error(new FormException("PDF Field \"{$def -> PdfName}\" is referenced more than once."));
            }

            $pdf_names [$def->PdfName] = true;

            foreach ($def->Constants as $constant) {
                $constant_name = $constant ['name'];

                if (isset($constant_names [$constant_name]) && $constant_names [$constant_name] != $constant ['value']) {
                    error(new FormException("Constant \"$constant_name\" is defined more than once with different values."));
                }

                $constant_names [$constant_name] = $constant ['value'];
            }
        }

        // Check that group names are unique and that the fields they are referencing exist
        $group_names = [];

        foreach ($this->Groups as $group) {
            if (isset($group_names [$group ['name']])) {
                error(new FormException("Group \"{$group [ 'name' ]}\" is defined more than once."));
            }

            if (isset($names [$group ['name']])) {
                error(new FormException("Group \"{$group [ 'name' ]}\" has the same name as an existing field."));
            }

            foreach ($group ['fields'] as $field_name) {
                if (!isset($names [$field_name])) {
                    error(new FormException("Field \"$field_name\" of group \"{$group [ 'name' ]}\" does not exist."));
                }
            }
        }
    }
}
