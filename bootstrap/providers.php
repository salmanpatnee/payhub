<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

$providers = [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];

if (class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)) {
    $providers[] = App\Providers\TelescopeServiceProvider::class;
}

return $providers;
