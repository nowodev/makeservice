<?php

namespace Nowodev\Makeservice;

use Illuminate\Support\ServiceProvider;
use Nowodev\Makeservice\Console\Commands\MakeActionCommand;
use Nowodev\Makeservice\Console\Commands\MakeEnumCommand;
use Nowodev\Makeservice\Console\Commands\MakeFacadeCommand;
use Nowodev\Makeservice\Console\Commands\MakeInterfaceCommand;
use Nowodev\Makeservice\Console\Commands\MakeRepositoryCommand;
use Nowodev\Makeservice\Console\Commands\MakeServiceCommand;
use Nowodev\Makeservice\Console\Commands\MakeTraitCommand;

class MakeServiceServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeActionCommand::class,
                MakeEnumCommand::class,
                MakeFacadeCommand::class,
                MakeInterfaceCommand::class,
                MakeRepositoryCommand::class,
                MakeServiceCommand::class,
                MakeTraitCommand::class
            ]);
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
