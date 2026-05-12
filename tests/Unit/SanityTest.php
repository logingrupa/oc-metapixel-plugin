<?php

it('boots the october harness', function () {
    expect(app())->not->toBeNull();
    expect(\Schema::hasTable('system_settings'))->toBeTrue();
});
