<?php
namespace VanXuan\PdfToText;

// Thrown when something unexpected is encountered while processing encrypted data.
class DecryptionException extends PTTException
{
    public function __construct($message, $object_id = false)
    {
        $text = "Pdf decryption error";

        if ($object_id !== false) {
            $text .= " (object #$object_id)";
        }

        $text .= " : $message";

        parent::__construct($text);
    }
}
