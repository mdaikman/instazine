<?php

namespace App\Support;

class PrinterText
{
    /**
     * Remove control characters the thermal printer would treat as commands.
     *
     * The firmware sends ASCII bytes to the printer untouched, so a stray ESC
     * or GS in stored text would reset or reconfigure it mid-print. Line breaks
     * are kept (normalised to \n) and tabs become spaces.
     */
    public static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $text);

        return preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $text);
    }
}
