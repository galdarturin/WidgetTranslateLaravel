<?php

namespace Newtxt\Laravel\Tests;

use Newtxt\Laravel\NewtxtServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Configure the package test application.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }

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
