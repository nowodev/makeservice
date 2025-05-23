<?php

namespace Ikechukwukalu\Makeservice\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:service')]
class MakeServiceCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:service';

    /**
     * The name of the console command.
     *
     * This name is used to identify the command during lazy loading.
     *
     * @var string|null
     *
     * @deprecated
     */
    protected static $defaultName = 'make:service';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new service class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Service';

    /**
     * Build the class with the given name.
     *
     * @param  string  $name
     * @return string
     */
    protected function buildClass($name)
    {
        if (!$request = $this->option('request')) {
            return parent::buildClass($name);
        }

        if ($this->option('extra')) {
            $this->call('make:request', ['name' => $request]);
        }

        if ($this->option('facade')) {
            $this->call('make:facade', ['name' => $request]);
        }

        if (! Str::startsWith($request, [
            $this->laravel->getNamespace(),
            'Illuminate',
            '\\',
        ])) {
            $request = $this->laravel->getNamespace().'Http\\Requests\\'.str_replace('/', '\\', $request);
        }

        $stub = str_replace(
            ['DummyRequest', '{{ request }}'], class_basename($request), parent::buildClass($name)
        );

        return str_replace(
            ['DummyFullRequest', '{{ requestNamespace }}'], trim($request, '\\'), $stub
        );
    }

    /**
     * Determine if the class already exists.
     *
     * @param  string  $rawName
     * @return bool
     */
    protected function alreadyExists($rawName)
    {
        return class_exists($rawName) ||
               $this->files->exists($this->getPath($this->qualifyClass($rawName)));
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        if ($this->option('request')) {
            return __DIR__.'/stubs/service.stub';
        }

        return __DIR__.'/stubs/service-duck.stub';
    }

    /**
     * Resolve the fully-qualified path to the stub.
     *
     * @param  string  $stub
     * @return string
     */
    protected function resolveStubPath($stub)
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
                        ? $customPath
                        : __DIR__.$stub;
    }

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\Services';
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['extra', 'e', InputOption::VALUE_NONE, 'Create a form request class for this service'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the service already exists'],
            ['request', 'r', InputOption::VALUE_OPTIONAL, 'Create a form request namespace class for this service'],
            ['facade', '', InputOption::VALUE_NONE, 'Create a facade class for this service'],
        ];
    }
}
