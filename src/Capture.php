<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class Capture -
        Base class for all capture classes accessible to the caller.

  ==============================================================================================================*/

class Capture implements \ArrayAccess, \Countable, \IteratorAggregate
{
    protected $Captures;

    /*--------------------------------------------------------------------------------------------------------------
        Constructor -
        Instantiates a Capture object.
     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct($objects)
    {
        //parent::__construct ( ) ;

        $this->Captures = $objects;
    }

    /*--------------------------------------------------------------------------------------------------------------
        Interfaces implementations.
     *-------------------------------------------------------------------------------------------------------------*/
    public function count(): int
    {
        return ($this->Captures);
    }

    public function getIterator(): \Traversable
    {
        return (new \ArrayIterator($this->Captures));
    }

    public function offsetExists($offset): bool
    {
        return ($offset >= 0 && $offset < count($this->Captures));
    }

    public function offsetGet($offset): mixed
    {
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
