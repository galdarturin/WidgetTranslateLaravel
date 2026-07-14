<?php

namespace Newtxt\Laravel\Tests;

use Newtxt\Laravel\NewtxtServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Load the package service provider for package tests.
     */
    protected function getPackageProviders($app): array
    {
        return [
            NewtxtServiceProvider::class,
        ];
    }
}
