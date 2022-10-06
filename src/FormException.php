<?php
namespace VanXuan\PdfToText;

// Thrown if the xml template passed to the GetFormData() method contains an error.
class FormException extends PTTException
{
    public function __construct($message)
    {
        $text = "Pdf form template error";

        $text .= " : $message";

        parent::__construct($text);
    }
}
