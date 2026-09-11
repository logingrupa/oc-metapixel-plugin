<?php

use Logingrupa\Metapixel\Classes\Meta\FbcValue;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class FbcValueTest extends MetapixelTestCase
{
    private const NOW_MS = 1757548800000;

    private const DAY_MS = 86400000;

    public function test_fresh_value_passes_unchanged(): void
    {
        $sFbc = 'fb.1.'.(self::NOW_MS - self::DAY_MS).'.IwAR1validfbclid_123';

        $this->assertSame($sFbc, FbcValue::fresh($sFbc, self::NOW_MS));
    }

    public function test_value_with_appendix_passes_unchanged(): void
    {
        $sFbc = 'fb.1.'.(self::NOW_MS - self::DAY_MS).'.IwAR1validfbclid_123.AQ';

        $this->assertSame($sFbc, FbcValue::fresh($sFbc, self::NOW_MS));
    }

    public function test_value_exactly_ninety_days_old_passes(): void
    {
        $sFbc = 'fb.1.'.(self::NOW_MS - 90 * self::DAY_MS).'.IwAR1validfbclid_123';

        $this->assertSame($sFbc, FbcValue::fresh($sFbc, self::NOW_MS));
    }

    public function test_value_older_than_ninety_days_is_dropped(): void
    {
        $sFbc = 'fb.1.'.(self::NOW_MS - 91 * self::DAY_MS).'.IwAR1validfbclid_123';

        $this->assertNull(FbcValue::fresh($sFbc, self::NOW_MS));
    }

    public function test_value_created_in_the_future_is_dropped(): void
    {
        $sFbc = 'fb.1.'.(self::NOW_MS + self::DAY_MS).'.IwAR1validfbclid_123';

        $this->assertNull(FbcValue::fresh($sFbc, self::NOW_MS));
    }

    public function test_malformed_values_are_dropped(): void
    {
        $iFresh = self::NOW_MS - self::DAY_MS;

        $this->assertNull(FbcValue::fresh(null, self::NOW_MS));
        $this->assertNull(FbcValue::fresh('', self::NOW_MS));
        $this->assertNull(FbcValue::fresh('fb.1.x.fbclidvalue', self::NOW_MS));
        $this->assertNull(FbcValue::fresh('fb.1.'.$iFresh, self::NOW_MS));
        $this->assertNull(FbcValue::fresh('fb.1.'.$iFresh.'.bad fbclid', self::NOW_MS));
        $this->assertNull(FbcValue::fresh('fb.1.'.$iFresh.'.IwAR1.AQ.extra', self::NOW_MS));
        $this->assertNull(FbcValue::fresh('FB.1.'.$iFresh.'.IwAR1validfbclid_123', self::NOW_MS));
    }

    public function test_non_positive_clock_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FbcValue::fresh('fb.1.1757548800000.IwAR1validfbclid_123', 0);
    }
}
