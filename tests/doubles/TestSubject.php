<?php

namespace Logingrupa\Metapixel\Tests\Doubles;

/**
 * Plain DTO subject fixture. Used as the subject class registered against
 * TestSubjectAdapter in EventLogWriter + queue tests.
 */
class TestSubject
{
    public int $iId = 42;
}
