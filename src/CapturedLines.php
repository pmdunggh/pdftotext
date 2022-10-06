<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

     class CapturedLines -
         Implements a set of lines.

   ==============================================================================================================*/

class CapturedLines implements \ArrayAccess, \Countable, \IteratorAggregate
{
    // Capture name, as specified by the "name" attribute of the <lines> tag
    public $Name;
    // Page number of the capture
    public $Page;
    // Captured lines
    public $Lines;
    // Content type (mimics a little bit the CapturedText class)
    public $Type = CaptureShapeDefinition::SHAPE_LINE;


    /*--------------------------------------------------------------------------------------------------------------

        Constructor -
        Instantiates a CapturedLines object.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($name, $page, $lines)
    {
        $this->Name = $name;
        $this->Page = $page;
        $this->Lines = $lines;
    }


    /*--------------------------------------------------------------------------------------------------------------

        Interfaces implementations.

     *-------------------------------------------------------------------------------------------------------------*/
    public function count(): int
    {
        return ($this->Lines);
    }


    public function getIterator(): \Traversable
    {
        return (new \ArrayIterator($this->Lines));
    }


    public function offsetExists($offset): bool
    {
        return ($offset >= 0 && $offset < count($this->Lines));
    }


    public function offsetGet($offset): mixed
    {
        /** @noinspection PhpUndefinedFieldInspection */
        return ($this->Captures [$offset]);
    }


    public function offsetSet($offset, $value): void
    {
        error(new CaptureException("Unsupported operation."));
    }


    public function offsetUnset($offset): void
    {
        error(new CaptureException("Unsupported operation."));
    }
}
