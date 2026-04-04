<?php

namespace justinholtweb\wink\enums;

enum GoalType: string
{
    case Pageview = 'pageview';
    case Click = 'click';
    case FormSubmit = 'form_submit';
    case CustomEvent = 'custom_event';

    public function label(): string
    {
        return match ($this) {
            self::Pageview => 'Page View',
            self::Click => 'Click',
            self::FormSubmit => 'Form Submit',
            self::CustomEvent => 'Custom Event',
        };
    }

    public function targetLabel(): string
    {
        return match ($this) {
            self::Pageview => 'URL Pattern',
            self::Click => 'CSS Selector',
            self::FormSubmit => 'CSS Selector',
            self::CustomEvent => 'Event Name',
        };
    }

    public function targetPlaceholder(): string
    {
        return match ($this) {
            self::Pageview => '/thank-you*',
            self::Click => '.cta-button',
            self::FormSubmit => '#signup-form',
            self::CustomEvent => 'purchase_complete',
        };
    }
}
