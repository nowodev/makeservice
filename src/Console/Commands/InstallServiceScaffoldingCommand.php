<?php

namespace Nowodev\Makeservice\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'makeservice:install')]
class InstallServiceScaffoldingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'makeservice:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install service scaffolding (ServiceResponse, BaseService, ServiceException, exception handler) for new projects';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->warn('This command will create or overwrite files in your application:');
        $this->line('  - app/Support/ServiceResponse.php');
        $this->line('  - app/Services/BaseService.php');
        $this->line('  - app/Exceptions/ServiceException.php');
        $this->line('  - bootstrap/app.php (inject exception handling into withExceptions)');
        $this->line('  - config/logging.php (add api channel after stack)');
        $this->newLine();
        $this->warn('It is intended for new projects. Existing customizations may be overwritten.');

        if (! $this->confirm('Do you want to continue?', false)) {
            $this->info('Installation cancelled.');
            return self::SUCCESS;
        }

        $namespace = $this->laravel->getNamespace();
        $appNamespace = rtrim($namespace, '\\');

        $this->createServiceResponse($appNamespace);
        $this->createBaseService($appNamespace);
        $this->createServiceException($appNamespace);
        $this->modifyBootstrapApp($appNamespace);
        $this->addApiLogChannel();

        $this->info('Service scaffolding installed successfully.');

        return self::SUCCESS;
    }

    protected function createServiceResponse(string $namespace): void
    {
        $path = $this->laravel->basePath('app/Support/ServiceResponse.php');
        $this->ensureDirectoryExists(dirname($path));

        $content = <<<PHP
<?php

namespace {$namespace}\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ServiceResponse
{
    public function __construct(
        public bool \$success,
        public int \$statusCode,
        public string \$message,
        public ?string \$errorCode = null,
        public mixed \$data = null,
        public mixed \$errors = null
    ) {}

    public static function success(
        string \$message = 'Success',
        int \$statusCode = Response::HTTP_OK,
        mixed \$data = null,
    ): ServiceResponse {
        return new ServiceResponse(
            success: true,
            statusCode: \$statusCode,
            message: \$message,
            data: \$data,
        );
    }

    public static function failure(
        string \$message = 'Failed',
        int \$statusCode = Response::HTTP_BAD_REQUEST,
        mixed \$data = null,
        ?string \$errorCode = null,
        mixed \$errors = null,
    ): ServiceResponse {
        return new ServiceResponse(
            success: false,
            statusCode: \$statusCode,
            message: \$message,
            data: \$data,
            errorCode: \$errorCode,
            errors: \$errors
        );
    }

    public function toArray(): array
    {
        \$response = [
            'success' => \$this->success,
            'status_code' => \$this->statusCode,
            'error_code' => \$this->errorCode,
            'message' => \$this->message,
            'data' => \$this->data,
            'errors' => \$this->errors,
        ];

        // Remove null/empty EXCEPT for 'data'
        \$response = array_filter(
            \$response,
            fn (\$value, \$key) => \$key === 'data' || (\$value !== null && \$value !== [] && \$value !== ''),
            ARRAY_FILTER_USE_BOTH
        );

        return \$response;
    }

    public function toJsonResponse(): JsonResponse
    {
        return response()->json(\$this->toArray(), \$this->statusCode);
    }
}
PHP;

        file_put_contents($path, $content);
        $this->line("  Created app/Support/ServiceResponse.php");
    }

    protected function createBaseService(string $namespace): void
    {
        $path = $this->laravel->basePath('app/Services/BaseService.php');
        $this->ensureDirectoryExists(dirname($path));

        $content = <<<PHP
<?php

namespace {$namespace}\Services;

use {$namespace}\Exceptions\ServiceException;
use {$namespace}\Support\ServiceResponse;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseService
{
    /**
     * Execute a callback with automatic transaction handling
     */
    protected function executeWithTransaction(callable \$callback): ServiceResponse
    {
        try {
            \$result = DB::transaction(\$callback);

            // If callback returns ServiceResponse, return it directly
            if (\$result instanceof ServiceResponse) {
                return \$result;
            }

            // Otherwise wrap in a success result
            return ServiceResponse::success(message: 'Success', data: \$result);

        } catch (ServiceException \$e) {
            // Custom service exceptions - already have proper error codes
            \$this->logError(\$e);

            return \$e->toServiceResponse();

        } catch (ValidationException \$e) {
            // Laravel validation exceptions
            return ServiceResponse::failure(
                message: 'Validation failed',
                errorCode: 'VALIDATION_ERROR',
                statusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
                errors: \$e->errors()
            );

        } catch (ModelNotFoundException \$e) {
            // Eloquent model not found
            return ServiceResponse::failure(
                message: 'Resource not found',
                errorCode: 'RESOURCE_NOT_FOUND',
                statusCode: Response::HTTP_NOT_FOUND
            );

        } catch (QueryException \$e) {
            // Database errors
            \$this->logError(\$e);

            return \$this->handleDatabaseError(\$e);

        } catch (Exception \$e) {
            // Any other unexpected errors
            \$this->logError(\$e);

            return ServiceResponse::failure(
                message: app()->environment('production') ? 'An unexpected error occurred' : \$e->getMessage(),
                errorCode: 'INTERNAL_ERROR',
                statusCode: 500
            );
        }
    }

    /**
     * Execute a callback without transaction (for read operations)
     */
    protected function execute(callable \$callback): ServiceResponse
    {
        try {
            \$result = \$callback();

            if (\$result instanceof ServiceResponse) {
                return \$result;
            }

            return ServiceResponse::success(message: 'Success', data: \$result);

        } catch (ServiceException \$e) {
            \$this->logError(\$e);

            return \$e->toServiceResponse();

        } catch (Exception \$e) {
            \$this->logError(\$e);

            return ServiceResponse::failure(
                message: app()->environment('production') ? 'An error occurred' : \$e->getMessage() . ' in ' . \$e->getFile() . ' on line ' . \$e->getLine(),
                errorCode: 'INTERNAL_ERROR',
                statusCode: 500
            );
        }
    }

    /**
     * Validate required parameters
     */
    protected function validateRequired(array \$data, array \$required): void
    {
        \$missing = array_diff(\$required, array_keys(array_filter(\$data, fn (\$value) => \$value !== null)));

        if (! empty(\$missing)) {
            throw new InvalidArgumentException(
                'Missing required parameters: ' . implode(', ', \$missing)
            );
        }
    }

    /**
     * Handle database-specific errors
     */
    private function handleDatabaseError(QueryException \$e): ServiceResponse
    {
        // Check for specific database error codes
        return match (\$e->getCode()) {
            '23000' => ServiceResponse::failure(
                message: 'Data integrity violation',
                errorCode: 'INTEGRITY_CONSTRAINT_VIOLATION',
                statusCode: Response::HTTP_CONFLICT
            ),
            '42S02' => ServiceResponse::failure(
                message: 'Database configuration error',
                errorCode: 'DATABASE_CONFIGURATION_ERROR',
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            ),
            default => ServiceResponse::failure(
                message: 'Database operation failed',
                errorCode: 'DATABASE_ERROR',
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            ),
        };
    }

    /**
     * Log errors with context
     */
    private function logError(Exception \$e): void
    {
        \$context = [
            'service' => static::class,
            'exception' => get_class(\$e),
            'message' => \$e->getMessage(),
            'file' => \$e->getFile(),
            'line' => \$e->getLine(),
        ];

        // Add custom context if available
        if (method_exists(\$e, 'getContext')) {
            \$context['custom_context'] = \$e->getContext();
        }

        Log::channel('api')->error(\$e->getMessage(), \$context);
    }
}
PHP;

        file_put_contents($path, $content);
        $this->line("  Created app/Services/BaseService.php");
    }

    protected function createServiceException(string $namespace): void
    {
        $path = $this->laravel->basePath('app/Exceptions/ServiceException.php');
        $this->ensureDirectoryExists(dirname($path));

        $content = <<<PHP
<?php

namespace {$namespace}\Exceptions;

use {$namespace}\Support\ServiceResponse;
use Exception;
use Throwable;

class ServiceException extends Exception
{
    protected string \$errorCode = 'SERVICE_ERROR';

    protected int \$statusCode = 400;

    protected array \$context = [];

    public function __construct(string \$message = '', array \$context = [], ?string \$errorCode = null, ?int \$statusCode = null, ?Throwable \$previous = null)
    {
        \$this->context = \$context;
        if (\$errorCode !== null) {
            \$this->errorCode = \$errorCode;
        }
        if (\$statusCode !== null) {
            \$this->statusCode = \$statusCode;
        }
        parent::__construct(\$message, 0, \$previous);
    }

    public function getErrorCode(): string
    {
        return \$this->errorCode;
    }

    public function getStatusCode(): int
    {
        return \$this->statusCode;
    }

    public function getContext(): array
    {
        return \$this->context;
    }

    public function toServiceResponse(): ServiceResponse
    {
        return ServiceResponse::failure(
            message: \$this->getMessage(),
            errorCode: \$this->getErrorCode(),
            statusCode: \$this->getStatusCode(),
            errors: \$this->getContext()
        );
    }
}
PHP;

        file_put_contents($path, $content);
        $this->line("  Created app/Exceptions/ServiceException.php");
    }

    protected function modifyBootstrapApp(string $namespace): void
    {
        $path = $this->laravel->basePath('bootstrap/app.php');

        if (! file_exists($path)) {
            $this->warn('  bootstrap/app.php not found. Skipping. (Laravel 11+ uses this file.)');
            return;
        }

        $content = file_get_contents($path);

        $imports = [
            'use App\Exceptions\ServiceException;',
            'use App\Support\ServiceResponse;',
            'use Illuminate\Auth\Access\AuthorizationException;',
            'use Illuminate\Auth\AuthenticationException;',
            'use Illuminate\Database\QueryException;',
            'use Illuminate\Http\Exceptions\ThrottleRequestsException;',
            'use Illuminate\Http\Request;',
            'use Illuminate\Support\Facades\Log;',
            'use Illuminate\Validation\ValidationException;',
            'use Symfony\Component\HttpFoundation\Response;',
            'use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;',
            'use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;',
            'use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;',
        ];

        // Use actual app namespace for ServiceException and ServiceResponse
        $imports[0] = 'use ' . $namespace . '\Exceptions\ServiceException;';
        $imports[1] = 'use ' . $namespace . '\Support\ServiceResponse;';

        // Optional: Spatie Permission (comment out if not used)
        $spatieImport = 'use Spatie\Permission\Exceptions\UnauthorizedException;';

        foreach ($imports as $imp) {
            if (strpos($content, $imp) === false && strpos($content, trim($imp, ';')) === false) {
                $content = $this->addImportToBootstrap($content, $imp);
            }
        }
        if (strpos($content, 'Spatie\Permission\Exceptions\UnauthorizedException') === false) {
            $content = $this->addImportToBootstrap($content, $spatieImport);
        }

        $exceptionsBody = $this->getWithExceptionsBody($namespace);

        // Inject into existing ->withExceptions(function (Exceptions $exceptions): void { ... })
        $pattern = '/->withExceptions\s*\(\s*function\s*\(\s*Exceptions\s+\$exceptions\s*\)\s*:\s*void\s*\{\s*(?:\/\/?\s*)?\s*\}/s';
        $replacement = '->withExceptions(function (Exceptions $exceptions): void {' . "\n" . $exceptionsBody . "\n    }";

        $newContent = preg_replace($pattern, $replacement, $content, 1);

        if ($newContent !== null && $newContent !== $content) {
            file_put_contents($path, $newContent);
            $this->line('  Updated bootstrap/app.php');
        } elseif (strpos($content, '->withExceptions(') === false) {
            $this->warn('  Could not find withExceptions block in bootstrap/app.php. Add the render logic manually.');
        } else {
            $this->warn('  Could not inject into withExceptions (block may already be customized). Add the render logic manually if needed.');
        }
    }

    protected function addApiLogChannel(): void
    {
        $path = $this->laravel->basePath('config/logging.php');

        if (! file_exists($path)) {
            $this->warn('  config/logging.php not found. Skipping api channel.');
            return;
        }

        $content = file_get_contents($path);

        if (strpos($content, "'api' =>") !== false || strpos($content, '"api" =>') !== false) {
            $this->line('  config/logging.php already has an api channel.');
            return;
        }

        $apiChannel = <<<'CHAN'

        'api' => [
            'driver' => 'single',
            'path' => storage_path('logs/api_error.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],
CHAN;

        // Insert after first channel (usually 'stack'). Try 8-space then 4-space indent (Laravel style).
        // Match "        ]," or "    ]," followed by newline(s) and next channel key.
        $inserted = preg_replace(
            "/(        \],)(\s*\n+\s*)(        ')([a-z_]+)('\s*=>\s*\[)/m",
            '$1' . "\n\n" . trim($apiChannel) . "\n\n" . '$3$4$5',
            $content,
            1
        );

        if ($inserted === null || $inserted === $content) {
            $inserted = preg_replace(
                "/(    \],)(\s*\n+\s*)(    ')([a-z_]+)('\s*=>\s*\[)/m",
                '$1' . "\n\n" . trim($apiChannel) . "\n\n" . '$3$4$5',
                $content,
                1
            );
        }

        if ($inserted !== null && $inserted !== $content) {
            file_put_contents($path, $inserted);
            $this->line('  Added api channel to config/logging.php');
        } else {
            $this->warn('  Could not find insertion point in config/logging.php. Add the api channel manually after stack.');
        }
    }

    protected function addImportToBootstrap(string $content, string $import): string
    {
        $line = $import . "\n";
        if (preg_match('/^<\?php\s*\n/', $content)) {
            return preg_replace('/^<\?php\s*\n/', "<?php\n\n" . $line, $content, 1);
        }
        $pos = strpos($content, "\n");
        return substr($content, 0, $pos + 1) . $line . substr($content, $pos + 1);
    }

    protected function getWithExceptionsBody(string $namespace): string
    {
        $serviceException = $namespace . '\Exceptions\ServiceException';
        $serviceResponse = $namespace . '\Support\ServiceResponse';

        return <<<BODY
        \$exceptions->render(function (\\Throwable \$e, \\Illuminate\\Http\\Request \$request) {
            if (\$request->expectsJson() || \$request->is('api/*')) {
                if (\$e instanceof {$serviceException}) {
                    return \$e->toServiceResponse()->toJsonResponse();
                }

                \\Illuminate\\Support\\Facades\\Log::channel('api')->error('Unhandled exception', [
                    'Instance of ' => get_class(\$e),
                    'Message' => \$e->getMessage(),
                    'File' => \$e->getFile(),
                    'Line' => \$e->getLine(),
                    'Trace' => \$e->getTraceAsString(),
                ]);

                if (\$e instanceof \\Illuminate\\Validation\\ValidationException) {
                    return {$serviceResponse}::failure(
                        message: 'Validation failed',
                        errorCode: 'VALIDATION_ERROR',
                        statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_UNPROCESSABLE_ENTITY,
                        errors: \$e->errors()
                    )->toJsonResponse();
                }

                if (\$e instanceof \\Illuminate\\Database\\Eloquent\\ModelNotFoundException) {
                    return {$serviceResponse}::failure(
                        message: 'Resource not found',
                        errorCode: 'RESOURCE_NOT_FOUND',
                        statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_NOT_FOUND
                    )->toJsonResponse();
                }

                if (\$e instanceof \\Illuminate\\Auth\\AuthenticationException) {
                    return {$serviceResponse}::failure(
                        message: 'Unauthenticated',
                        errorCode: 'UNAUTHENTICATED',
                        statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_UNAUTHORIZED
                    )->toJsonResponse();
                }

                if (\$e instanceof \\Illuminate\\Auth\\Access\\AuthorizationException) {
                    return {$serviceResponse}::failure(
                        message: 'Unauthorized',
                        errorCode: 'UNAUTHORIZED',
                        statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_UNAUTHORIZED
                    )->toJsonResponse();
                }

                if (\$e instanceof \\Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException || (class_exists('\\Spatie\\Permission\\Exceptions\\UnauthorizedException') && \$e instanceof \\Spatie\\Permission\\Exceptions\\UnauthorizedException)) {
                    return {$serviceResponse}::failure(
                        message: \$e->getMessage(),
                        errorCode: 'ACCESS_DENIED',
                        statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_FORBIDDEN
                    )->toJsonResponse();
                }

                if (\$e instanceof \\Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException) {
                    return {$serviceResponse}::failure(
                        message: 'Route not found',
                        errorCode: 'ROUTE_NOT_FOUND',
                        statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_NOT_FOUND
                    )->toJsonResponse();
                }

                if (\$e instanceof \\Symfony\\Component\\HttpKernel\\Exception\\MethodNotAllowedHttpException) {
                    return {$serviceResponse}::failure(
                        message: 'Method not allowed',
                        errorCode: 'METHOD_NOT_ALLOWED',
                        statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_METHOD_NOT_ALLOWED
                    )->toJsonResponse();
                }

                if (\$e instanceof \\Illuminate\\Http\\Exceptions\\ThrottleRequestsException) {
                    return {$serviceResponse}::failure(
                        message: 'Too many requests',
                        errorCode: 'RATE_LIMIT_EXCEEDED',
                        statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_TOO_MANY_REQUESTS
                    )->toJsonResponse();
                }

                if (\$e instanceof \\Illuminate\\Database\\QueryException) {
                    \\Illuminate\\Support\\Facades\\Log::channel('api')->error('Database error', [
                        'message' => \$e->getMessage(),
                        'sql' => \$e->getSql(),
                        'bindings' => \$e->getBindings(),
                    ]);

                    return {$serviceResponse}::failure(
                        message: app()->environment('production')
                        ? 'Database operation failed'
                        : \$e->getMessage(),
                        errorCode: 'DATABASE_ERROR',
                        statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_INTERNAL_SERVER_ERROR
                    )->toJsonResponse();
                }

                return {$serviceResponse}::failure(
                    message: app()->environment('production')
                    ? 'An unexpected error occurred'
                    : \$e->getMessage(),
                    errorCode: 'INTERNAL_ERROR',
                    statusCode: \\Symfony\\Component\\HttpFoundation\\Response::HTTP_INTERNAL_SERVER_ERROR
                )->toJsonResponse();
            }
        });
BODY;
    }

    protected function ensureDirectoryExists(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
