<?php

use App\Providers\AppServiceProvider;
use App\Providers\IntegrationServiceProvider;
use App\Providers\PluginServiceProvider;
use App\Providers\UpstreamServiceProvider;

return [
    AppServiceProvider::class,
    PluginServiceProvider::class,
    IntegrationServiceProvider::class,
    UpstreamServiceProvider::class,
];
