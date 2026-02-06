<?php

namespace FluentAffiliate\App\Modules\Integrations\FluentBooking;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Modules\Integrations\BaseConnectorSettings;
use FluentBooking\App\Models\CalendarSlot;

class Connector extends BaseConnectorSettings
{
    protected $integration = 'fluent_booking';

    public function init()
    {
        $this->register();
    }

    public function isAvailable()
    {
        return defined('\FLUENT_BOOKING_PRO_VERSION');
    }

    public function getInfo()
    {
        return [
            'integration'    => $this->integration,
            'title'          => 'FluentBooking Pro',
            'description'    => 'Connect FluentAffiliate with FluentBooking to track booking sales and commissions',
            'type'           => 'other',
            'logo'           => Utility::asset('images/integrations/fluentbooking.svg'),
            'is_unavailable' => !$this->isAvailable(),
            'config'         => $this->config()
        ];
    }

    public function config()
    {
        $defaults = [
            'is_enabled'             => 'no',
            'custom_affiliate_rate'  => 'no',
            'custom_affiliate_rates' => [],
        ];

        if (!$this->willConnectorRun()) {
            return $defaults;
        }

        $settings = Utility::getOption('_' . $this->integration . '_connector_config', []);

        return wp_parse_args($settings, $defaults);
    }

    public function getProductCatOptions($options = [], $params = [])
    {
        $events = CalendarSlot::with(['calendar'])->get();

        $formattedEvents = [];
        foreach ($events as $event) {
            $formattedEvents[] = [
                'id'    => $event->id,
                'label' => $event->title . ' (' . $event->calendar->title . ')',
            ];
        }

        return $formattedEvents;
    }

    public function getConfigFields()
    {
        return [
            'custom_rate_component' => [
                'type'           => 'custom_rate_component',
                'has_categories' => false,
                'has_products'   => true,
                'product_label'  => __('Select Booking Events', 'fluent-affiliate'),
                'main_label'     => __('Enable custom rate for specific booking events', 'fluent-affiliate'),
            ]
        ];
    }
}
