<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class FormFieldDefinition -
        Contains an XML template form field definition.

  ==============================================================================================================*/

class FormFieldDefinition // extends  Object
{
    // Supported field types
    const        TYPE_STRING = 1;            // String
    const        TYPE_CHOICE = 2;            // Choice (must have <constant> subtags)

    // Official name (as it will appear in the class based on the XML template)
    public $Name = false;
    // Field name, as specified in the input PDF file
    public $PdfName = false;
    // Field type
    public $Type = self::TYPE_STRING;
    // Available constant values for this field when the "type" attribute has the value "choice"
    public $Constants = [];


    /*--------------------------------------------------------------------------------------------------------------

        Constructor -
        Builds the field definition object.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct(\SimpleXMLElement $field_node)
    {
        // Loop through attributes
        foreach ($field_node->attributes() as $attribute_name => $attribute_value) {
            switch (strtolower($attribute_name)) {
                // "name" attribute :
                //  Specifies the field name as it will appear in the output class. Must be a valid PHP name.
                case    'name' :
                    //$this -> Name     =  PdfToTextFormDefinition::ValidatePhpName ( ( string ) $attribute_value ) ;
                    $this->Name = PdfObjectBase::ValidatePhpName(( string )$attribute_value);
                    break;

                // "form-field" attribute :
                //  Corresponding field name in the input PDF form.
                case    'form-field' :
                    $this->PdfName = ( string )$attribute_value;
                    break;

                // "type" :
                //  Field type. Can be either :
                //  - "string" :
                //      The field value can be any type of string.
                //  - "choice" :
                //      The field value has one of the values defined by the <case> or <default> subtags.
                case    'type' :
                    switch (strtolower(( string )$attribute_value)) {
                        case    'string' :
                            $this->Type = self::TYPE_STRING;
                            break;

                        case    'choice' :
                            $this->Type = self::TYPE_CHOICE;
                            break;

                        default :
                            error(new FormException("Invalid value \"$attribute_value\" for the \"$attribute_name\" attribute of the <field> tag."));
                    }
            }
        }

        // The "name" and "form-field" attributes are mandatory
        if (!$this->Name) {
            error(new FormException("The \"name\" attribute is mandatory for the <field> tag."));
        }

        if (!$this->PdfName) {
            error(new FormException("The \"form-field\" attribute is mandatory for the <field> tag."));
        }

        // For "type=choice" entries, we have to look for <case> or <default> subtags
        if ($this->Type === self::TYPE_CHOICE) {
            /** @var \SimpleXMLElement $child */
            foreach ($field_node->children() as $child) {
                $tag_name = $child->getName();
                $lcname = strtolower($tag_name);
                $is_default = false;

                switch ($lcname) {
                    // Default value to be used when no PDF field value matches the defined constants
                    /** @noinspection PhpMissingBreakStatementInspection */
                    case    'default' :
                        $is_default = true;

                    // "case" attribute :
                    //  Maps a value to  constant name that will be defined in the generated class.
                    case    'case' :
                        $constant_value = "";
                        $constant_name = false;

                        // Retrieve attributes
                        foreach ($child->attributes() as $attribute_name => $attribute_value) {
                            switch (strtolower($attribute_name)) {
                                // "value" attribute :
                                //  PDF form field value.
                                case    'value'    :
                                    $constant_value = ( string )$attribute_value;
                                    break;

                                // "constant" attribute :
                                //  Associated constant.
                                case    'constant' :
                                    //$constant_name        =  PdfToTextFormDefinition::ValidatePhpName ( ( string ) $attribute_value ) ;
                                    $constant_name = PdfObjectBase::ValidatePhpName(( string )$attribute_value);
                                    break;

                                // Bail out if any unrecognized attribute has been specified
                                default :
                                    error(new FormException("Invalid tag <$tag_name> in <field> definition."));
                            }
                        }

                        // Each <case> entry must have a "constant" attribute
                        if ($constant_value === false && !$is_default) {
                            error(new FormException("Missing constant value in <case> tag."));
                        }

                        if ($constant_name === false) {
                            error(new FormException("Attribute \"constant-name\" is required for <$tag_name> tag."));
                        }

                        // Add this to the list of existing constants
                        $this->Constants [] =
                        [
                            'name' => $constant_name,
                            'value' => $constant_value,
                            'default' => $is_default
                        ];

                        break;

                    // Check for unrecognized tags
                    default :
                        error(new FormException("Invalid tag <$tag_name> in <field> definition."));
                }
            }
        }
    }
}
