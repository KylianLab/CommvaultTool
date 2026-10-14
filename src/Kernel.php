<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Dates are stored in UTC (DATETIME columns carry no time zone) and
        // displayed in Europe/Brussels by the views, whatever the server setting.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
