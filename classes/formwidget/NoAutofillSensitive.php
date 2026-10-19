<?php

namespace Logingrupa\Metapixel\Classes\FormWidget;

use Backend\FormWidgets\Sensitive;

/**
 * The sensitive token input that browser password managers leave alone.
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
        $this->addViewPath(base_path('modules/backend/formwidgets/sensitive/partials'), true);
    }

    /**
     * Load the core widget's css and js; WidgetBase calls this before init().
     */
    protected function loadAssets(): void
    {
        $this->assetPath = '/modules/backend/formwidgets/sensitive/assets';
        parent::loadAssets();
    }
}
