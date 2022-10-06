<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class CaptureApplicablePages -
        Holds a list of applicable pages given by the "number" attribute of <page> tags.

  ==============================================================================================================*/

class CaptureApplicablePages implements \ArrayAccess, \Countable, \Iterator
{
    // Ranges of pages, as given by the "number" attribute of the <page> tag. Since a page number expression
    // can refer to the last page ("$"), and the total number of pages in the document is not yet known at the
    // time of object instantiation, we have to store all the page ranges as is.
    protected $PageRanges = [];

    // Once the SetPageCount() method has been called (ie, once the total number of pages in the document is
    // known), then a PageMap is built ; each key is the page number, indicating whether the page applies or not.
    public $PageMap = [];

    // Extra data associated, this time, with each page in PageMap
    public $ExtraPageMapData = [];

    // Page count - set by the SetPageCount() method
    public $PageCount = false;

    /*--------------------------------------------------------------------------------------------------------------
        CONSTRUCTOR
            Initializes the object.
     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct()
    {
    }

    /*--------------------------------------------------------------------------------------------------------------
        NAME
            Add - Add a page number(s) definition.

        PROTOTYPE
            $applicable_pages -> Add ( $page_number ) ;

        DESCRIPTION
            Add the page number(s) specified by the "number" attribute of the <pages> tag to the list of applicable
        pages.

        PARAMETERS
            $page_number (string) -
                    A string defining which pages are applicable. This can be a single page number :

                <page number="1" .../>

            or a comma-separated list of pages :

                <page number="1, 2, 10" .../>

            or range(s) of pages :

                <page number="1..10, 12..20" .../>

            The special "$" character means "last page" ; thus the following example :

                <page number="1, $-9..$" .../>

            means : "applicable pages are 1, plus the last ten pages f the document".

     *-------------------------------------------------------------------------------------------------------------*/
    public function Add($page_number, $extra_data = false)
    {
        $this->__parse_page_numbers($page_number, $extra_data);
    }

    /*--------------------------------------------------------------------------------------------------------------
        NAME
            SetPageCount - Sets the total number of pages in the document.

        PROTOTYPE
            $applicable_pages -> SetPageCount ( $count ) ;

        DESCRIPTION
            Sets the total number of pages in the document and builds a map of which pages are applicable or not.

        PARAMETERS
            $count (integer) -
                    Total number of pages in the document.
     *-------------------------------------------------------------------------------------------------------------*/
    public function SetPageCount($count)
    {
        $this->PageCount = $count;
        $this->PageMap = [];

        // Loop through the page ranges - every single value in the ranges has been converted to an integer ;
        // the other ones, built as expressions (using "$" for example) are processed here to give the actual
        // page number
        foreach ($this->PageRanges as $range) {
            $low = $range [0];
            $high = $range [1];

            // Translate expression to an actual value for the low and high parts of the range, if not already integers
            if (!is_integer($low)) {
                $low = $this->__check_expression($low, $count);
            }

            if (!is_integer($high)) {
                $high = $this->__check_expression($high, $count);
            }

            // Expressions using "$" may lead to negative values - adjust them
            if ($low < 1) {
                if ($high < 1) {
                    $high = 1;
                }

                $low = 1;
            }

            // Check that the range is consistent
            if ($low > $high) {
                error(new CaptureException("Low value ($low) must be less or equal to high value ($high) " .
                    "in page range specification \"{$range [0]}..{$range [1]}\"."));
            }

            // Ignore ranges where the 'low' value is higher than the number of pages in the document
            if ($low > $count) {
                warning(new CaptureException("Low value ($low) is greater than page count ($count) " .
                    "in page range specification \"{$range [0]}..{$range [1]}\"."));
                continue;
            }

            // Normalize the 'high' value, so that it's not bigger than the number of pages in the document
            if ($high > $count) {
                $high = $count;
            }

            // Complement the page map using this range
            for ($i = $low; $i <= $high; $i++) {
                $this->PageMap [$i] = true;
                $this->ExtraPageMapData [$i] = $range [2];
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        Interfaces implementations.

     *-------------------------------------------------------------------------------------------------------------*/

    // Countable interface
    public function count(): int
    {
        return (count($this->PageMap));
    }


    // Array access interface
    public function offsetExists($offset): bool
    {
        return (isset($this->PageMap [$offset]));
    }


    public function offsetGet($offset): mixed
    {
        return ((isset($this->PageMap [$offset])) ? true : false);
    }


    public function offsetSet($offset, $value): void
    {
        error(new PTTException("Unsupported operation"));
    }


    public function offsetUnset($offset): void
    {
        error(new PTTException("Unsupported operation"));
    }


    // Iterator interface
    private $__iterator_value = 1;

    public function rewind(): void
    {
        $this->__iterator_value = 1;
    }


    public function valid(): bool
    {
        return ($this->__iterator_value >= 1 && $this->__iterator_value <= $this->PageCount);
    }


    public function key(): mixed
    {
        return ($this->__iterator_value);
    }


    public function next(): void
    {
        $this->__iterator_value++;
    }


    public function current(): mixed
    {
        return ((isset($this->PageMap [$this->__iterator_value])) ? true : false);
    }


    /*--------------------------------------------------------------------------------------------------------------

        Helper functions.

     *-------------------------------------------------------------------------------------------------------------*/

    // __parse_page_numbers -
    //  Performs a first pass on the value of the "number" attribute of the <page> tag. Transforms range expressions
    //  when possible to integers ; keep the expression string intact when either the low or high value of a range
    //  is itself an expression, probably using the "$" (page count) character.
    private function __parse_page_numbers($text, $extra_data)
    {
        $ranges = explode(',', $text);

        // Loop through comma-separated ranges
        foreach ($ranges as $range) {
            $items = explode('..', $range);
            $low = $high = null;

            // Check if current item is a range
            switch (count($items)) {
                // If not a range (ie, a single value) then make a range using that value
                // (low and high range values will be the same)
                case    1 :
                    if (is_numeric($items [0])) {
                        $low = $high = ( integer )$items [0];
                    } else {
                        $low = $high = trim($items [0]);
                    }

                    break;

                // If range, store the low and high values
                case    2 :
                    $low = (is_numeric($items [0])) ? ( integer )$items [0] : trim($items [0]);
                    $high = (is_numeric($items [1])) ? ( integer )$items [1] : trim($items [1]);
                    break;

                // Other cases : throw an exception
                default :
                    error(new CaptureException("Invalid page range specification \"$range\"."));
            }

            // If the low or high range value is an expression, check at this stage that it is correct
            if (is_string($low) && $this->__check_expression($low) === false) {
                error(new CaptureException("Invalid expression \"$low\" in page range specification \"$range\"."));
            }

            if (is_string($high) && $this->__check_expression($high) === false) {
                error(new CaptureException("Invalid expression \"$high\" in page range specification \"$range\"."));
            }

            // Add the page range and the extra data
            $this->PageRanges [] = [$low, $high, $extra_data];
        }
    }


    // __check_expression -
    //  Checks that a syntactically correct
    private function __check_expression($str, $count = 1)
    {
        $new_str = str_replace('$', $count, $str);
        $value = @eval("return ( $new_str ) ;");

        return ($value);
    }
}
