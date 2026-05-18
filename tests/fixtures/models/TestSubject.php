<?php

namespace Logingrupa\Metapixel\Tests\Fixtures\Models;

use October\Rain\Database\Model;

/**
 * Hermetic Eloquent fixture for EventPixelTest — TestSubject row id is the
 * subject_id the EventPixel component looks up via subject_class + slug.
 * Non-anonymous so TestSubject::class resolves to a stable FQN.
 */
final class TestSubject extends Model
{
    /** @var string */
    public $table = 'test_subjects';

    /** @var list<string> */
    protected $fillable = ['id', 'secret_key'];

    /** @var bool */
    public $timestamps = true;
}
