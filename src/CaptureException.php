<?php
namespace VanXuan\PdfToText;

// Thrown if the xml template passed to the SetCaptures() method contains an error.
class CaptureException extends PTTException
{
    public function __construct($message)
    {
        $text = "Pdf capture template error";

        $text .= " : $message";

        parent::__construct($text);
    }
}
