<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class FormData -
        Base class for all Pdf form templates data.

  ==============================================================================================================*/

class FormData // extends  Object
{
    // Doc comments provide information about form data fields (mainly to handle grouped field values)
    // The $__Properties array gives information about the form data fields themselves
    private $__Properties = [];


    /*--------------------------------------------------------------------------------------------------------------

        Constructor -
        Retrieve information about the derived class properties, which are specified by the derived class
        generated on the fly.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct()
    {
        // Get class properties
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties();

        // Loop through class properties
        foreach ($properties as $property) {
            $propname = $property->getName();
            $doc_comment = $property->getDocComment();

            $fields = false;
            $separator = false;

            // A doc comment may indicate either :
            // - A form data field (@formdata)
            // - A grouped field ; in this case, we will have the following tags :
            //  . @formdata
            //  . @group(field_list) : list of fields grouped for this property
            //  . @separator(string) : a separator used when catenating grouped fields
            if ($doc_comment) {
                // The @formdata tag must be present
                if (strpos($doc_comment, '@formdata') === false) {
                    continue;
                }

                // @group(fields) pattern
                if (preg_match('/group \s* \( \s* (?P<fields> [^)]+) \)/imsx', $doc_comment, $match)) {
                    $items = explode(',', $match ['fields']);
                    $fields = [];

                    foreach ($items as $item) {
                        $fields    [] = $item;
                    }
                }

                // @separator(string) pattern
                if (preg_match('/separator \s* \( \s* (?P<separator> ( (\\\)) | (.) )+  \) /imsx', $doc_comment, $match)) {
                    $separator = stripslashes($match ['separator']);
                }
            } else {
                // Ignore non-formdata properties
                continue;
            }

            // Property belongs to the form - add it to the list of available properties
            $this->__Properties [$propname] =
            [
                'name' => $propname,
                'fields' => $fields,
                'separator' => $separator
            ];
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        __get -
        Returns the underlying property value for this PDF data field.
     *-------------------------------------------------------------------------------------------------------------*/
    public function __get($member)
    {
        if (!isset($this->__Properties [$member])) {
            warning(new FormException("Undefined property \"$member\"."));
        }

        return ($this->$member);
    }


    /*--------------------------------------------------------------------------------------------------------------

        __set -
        Sets the underlying property value for this PDF data field.
        When the property is a compound one, sets individual members as well.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __set($member, $value)
    {
        // Property exists : some special processing will be needed
        if (isset($this->__Properties [$member])) {
            $prop_entry = $this->__Properties [$member];

            // Non-compound property
            if (!$prop_entry ['fields']) {
                $this->$member = $value;

                // However, we have to check that this property belongs to a compound property and change
                // the compound property valu accordingly
                foreach ($this->__Properties as $name => $property) {
                    if ($property ['fields']) {
                        if (in_array($member, $property ['fields'])) {
                            $values = [];

                            foreach ($property ['fields'] as $value) {
                                $values    [] = $this->$value;
                            }

                            // Change compound property value accordingly, using the specified separator
                            $this->$name = implode($property ['separator'], $values);
                        }
                    }
                }
            } else {
                // Compound property : we will have to explode it in separate parts, using the compound property separator,
                // then set individual property values
                $values = explode($prop_entry ['separator'], $value);
                $value_count = count($values);
                $field_count = count($prop_entry ['fields']);

                if ($value_count < $field_count) {
                    error(new FormException("Not enough value parts specified for the \"$member\" property ($value)."));
                } elseif ($value_count > $field_count) {
                    error(new FormException("Too much value parts specified for the \"$member\" property ($value)."));
                }

                $this->$member = $value;

                for ($i = 0; $i < $value_count; $i++) {
                    $sub_member = $prop_entry ['fields'] [$i];
                    $this->$sub_member = $values [$i];
                }
            }
        } else {
            // Property does not exist : let PHP act as the default way
            $this->$member = $value;
        }
    }
}
