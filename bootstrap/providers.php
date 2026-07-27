<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\MwDeployServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    MwDeployServiceProvider::class,
];
