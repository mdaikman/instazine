<?php

namespace Tests\Unit;

use App\Support\PrinterText;
use PHPUnit\Framework\TestCase;

class PrinterTextTest extends TestCase
{
    public function test_it_strips_printer_control_characters(): void
    {
        $this->assertSame('Hello@ world', PrinterText::clean("Hello\x1b@ world\x1d\x7f"));
    }

    public function test_it_keeps_line_breaks_and_normalises_them(): void
    {
        $this->assertSame("One\nTwo\nThree", PrinterText::clean("One\r\nTwo\rThree"));
    }

    public function test_it_turns_tabs_into_spaces_and_keeps_unicode(): void
    {
        $this->assertSame('Café — “quoted”', PrinterText::clean("Café\t—\t“quoted”"));
    }

    public function test_it_passes_null_through(): void
    {
        $this->assertNull(PrinterText::clean(null));
    }
}
