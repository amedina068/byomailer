<?php

namespace App\Support\OpenApi;

use App\Http\Middleware\EnforceAccessLevel;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Str;

/**
 * Adds the error responses that Scramble cannot see in the controller code.
 *
 * EnforceAccessLevel answers 403 on each API route. A path parameter that
 * names no record answers 404. A `throttle` middleware answers 429.
 */
class DocumentsErrorResponses extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $middleware = collect($routeInfo->route->gatherMiddleware())->filter(fn ($name) => is_string($name));

        if ($middleware->contains(fn (string $name) => Str::startsWith($name, EnforceAccessLevel::class))) {
            $this->addUnlessDocumented($operation, 403, AuthorizationException::class);
        }

        if (collect($operation->parameters)->contains(fn ($parameter) => $parameter instanceof Parameter && $parameter->in === 'path')) {
            $this->addUnlessDocumented($operation, 404, ModelNotFoundException::class);
        }

        if ($middleware->contains(fn (string $name) => Str::startsWith($name, 'throttle:'))) {
            $this->addUnlessDocumented($operation, 429, ThrottleRequestsException::class);

            $this->response($operation, 429)?->addHeader('Retry-After', new Header(
                description: 'The number of seconds to wait before you send the request again.',
                schema: Schema::fromType(new IntegerType),
            ));
        }
    }

    /**
     * @param  class-string  $exception
     */
    private function addUnlessDocumented(Operation $operation, int $code, string $exception): void
    {
        if ($this->response($operation, $code) === null) {
            $operation->addResponse($this->openApiTransformer->toResponse(new ObjectType($exception)));
        }
    }

    private function response(Operation $operation, int $code): ?Response
    {
        return collect($operation->responses)
            ->map(fn ($response) => $response instanceof Reference ? $response->resolve() : $response)
            ->first(fn ($response) => $response instanceof Response && (int) $response->code === $code);
    }
}
