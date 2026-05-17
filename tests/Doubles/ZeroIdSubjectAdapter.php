<?php

namespace Logingrupa\Metapixel\Tests\Doubles;

/**
 * TestSubjectAdapter variant whose getSubjectId returns 0 — used to
 * exercise EventLogWriter's `<= 0` reject branch in downstream plans.
 */
final class ZeroIdSubjectAdapter extends TestSubjectAdapter
{
    public function getSubjectId(object $obSubject): int
    {
        return 0;
    }
}
