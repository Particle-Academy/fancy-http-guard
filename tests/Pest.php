<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\Facade;

// Every test boots its own application; drop it afterwards so no container,
// facade or error handler leaks into the next test.
uses()->afterEach(function (): void {
    Facade::clearResolvedInstances();
    HandleExceptions::flushState($this);
    Container::setInstance(null);
})->in(__DIR__);
