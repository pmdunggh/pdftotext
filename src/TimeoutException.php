<?php
namespace VanXuan\PdfToText;

// Thrown when the PDFOPT_ENFORCE_EXECUTION_TIME or PDFOPT_ENFORCE_GLOBAL_EXECUTION_TIME option is set, and
//  the script took longer than the allowed execution time limit.
class TimeoutException extends PTTException
{
    // Set to true if the reason why the max execution time was reached because of too many invocations of the Load() method
    // Set to false if the max execution time was reached by simply processing one PDF file
    public $GlobalTimeout;

    /**
     * TimeoutException constructor.
     *
     * @param string $message
     * @param int $global
     * @param int $php_setting
     * @param int $class_setting
     */
    public function __construct($message, $global, $php_setting, $class_setting)
    {
        $text = "PdfToText max execution time reached ";

        if (!$global) {
            $text .= "for one single file ";
        }

        $text .= "(php limit = {$php_setting}s, class limit = {$class_setting}s) : $message";

        $this->GlobalTimeout = $global;

        parent::__construct($text);
    }
}
