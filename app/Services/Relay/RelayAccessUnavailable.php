<?php

namespace App\Services\Relay;

use RuntimeException;

class RelayAccessUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sir Remote access is suspended or the device connection is paused.');
    }
}
