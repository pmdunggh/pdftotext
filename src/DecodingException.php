<?php

namespace VanXuan\PdfToText;

// Thrown when unexpected data is encountered while analyzing PDF contents.
class DecodingException extends PTTException
{
    public function __construct($message, $object_id = false)
    {
        $text = "Pdf decoding error";

        if ($object_id !== false) {
            $text .= " (object #$object_id)";
        }

        $text .= " : $message";

        parent::__construct($text);
    }
}
