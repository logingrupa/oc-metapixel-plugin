<?php

namespace Logingrupa\Metapixel\Classes\FormWidget;

use Backend\FormWidgets\Sensitive;

/**
 * The sensitive token input that browsers do not fill with a saved password.
 */
final class NoAutofillSensitive extends Sensitive
{
    /** @var string */
    protected $defaultAlias = 'metapixelsensitive';

    /**
     * Partials missing here (_sensitive, _sensitive_textarea) come from the core widget.
     */
    public function init(): void
    {
        parent::init();
        $this->addViewPath($this->guessViewPathFrom(Sensitive::class, '/partials'), true);
    }

    /**
     * Load the core widget's css and js; WidgetBase calls this before init().
     */
    protected function loadAssets(): void
    {
        $this->assetPath = $this->guessViewPathFrom(Sensitive::class, '/assets', true);
        parent::loadAssets();
    }
}
