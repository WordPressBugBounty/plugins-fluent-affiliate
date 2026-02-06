<?php

namespace FluentAffiliate\App\Hooks\Handlers;
use FluentAffiliate\Framework\Foundation\Application;
use FluentAffiliate\Database\DBMigrator;

class ActivationHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle($network_wide = false)
    {
        DBMigrator::run($network_wide);
    }
}
