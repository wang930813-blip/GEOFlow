<?php

use App\Providers\AppServiceProvider;
use App\Providers\GeoInclusionCheckServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    GeoInclusionCheckServiceProvider::class,
    HorizonServiceProvider::class,
];
