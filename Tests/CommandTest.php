<?php

namespace Ikechukwukalu\Makeservice\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

class CommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fires_make_service_command(): void
    {
        $this->artisan('make:service SampleService')->assertSuccessful();

        $this->artisan('make:service SampleService -f')->assertSuccessful();

        $this->artisan('make:request SampleRequest')->assertSuccessful();

        $this->artisan('make:service SampleService --request=SampleRequest -f')->assertSuccessful();

        $this->artisan('make:service SampleService --request=SampleRequest -e')->assertSuccessful();

        $this->artisan('make:service SampleService --request=SampleRequest -e -f')->assertSuccessful();

        $this->artisan('make:trait SampleTrait')->assertSuccessful();

        $this->artisan('make:trait SampleTrait -f')->assertSuccessful();

        $this->artisan('make:enum Sample')->assertSuccessful();

        $this->artisan('make:enum Sample -f')->assertSuccessful();

        $this->artisan('make:interface SampleInterface')->assertSuccessful();

        $this->artisan('make:interface SampleInterface -f')->assertSuccessful();

        $this->artisan('make:interface UserRepositoryInterface --model=User')->assertSuccessful();

        $this->artisan('make:interface UserRepositoryInterface --model=User -f')->assertSuccessful();

        $this->artisan('make:repository UserRepository --interface=UserRepositoryInterface --model=User')->assertSuccessful();

        $this->artisan('make:repository UserRepository --interface=UserRepositoryInterface --model=User -f')->assertSuccessful();

        $this->artisan('make:repository UserRepository --model=User -c')->assertSuccessful();

        $this->artisan('make:repository UserRepository --model=User -c -f')->assertSuccessful();

        $this->artisan('make:facade Sample')->assertSuccessful();

        $this->artisan('make:facade Sample -f')->assertSuccessful();

        $this->artisan('make:facade User --contract=UserRepositoryInterface')->assertSuccessful();

        $this->artisan('make:facade User --contract=UserRepositoryInterface -f')->assertSuccessful();
    }
}
