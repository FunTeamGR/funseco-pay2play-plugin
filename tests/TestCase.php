<?php

namespace Funseco\Pay2Play\Tests;

use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Pay2Play\Pay2PlayServiceProvider;

abstract class TestCase extends CoreTestCase
{
    /** Dependencies first, then this plugin (isolated runs load only these). */
    protected function plugins(): array
    {
        return [
            Pay2PlayServiceProvider::class,
        ];
    }
}
