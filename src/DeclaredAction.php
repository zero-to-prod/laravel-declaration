<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use ZeroToProd\LaravelDeclaration\Attributes\FlashAction;
use ZeroToProd\LaravelDeclaration\Attributes\Mutation;
use ZeroToProd\LaravelDeclaration\Attributes\RedirectAction;

class DeclaredAction extends Controller
{
    /**
     * @param  string  $method
     * @param  array<string, mixed>  $parameters
     */
    public function callAction($method, $parameters): Response
    {
        return $this->{$method}(...$parameters);
    }

    public function __invoke(mixed ...$args): RedirectResponse
    {
        $request = request();
        $route = $request->route();

        $validated = $route->getMetadata('request') !== null
            ? (array) app(DeclaredRequest::class)->validated()
            : $request->all();

        $target = null;
        if (isset($args['model']) && is_string($args['model'])) {
            if (! is_subclass_of($args['model'], Model::class)) {
                throw new LogicException("Model class [{$args['model']}] must extend ".Model::class.'.');
            }

            $target = $args['model'];
        } elseif (isset($args['target']) && is_string($args['target'])) {
            $targetParam = $args['target'];
            $resolved = $args[$targetParam] ?? $route->parameter($targetParam);

            if (! $resolved instanceof Model) {
                throw new InvalidArgumentException(
                    "Target parameter [{$targetParam}] must resolve to an instance of ".Model::class.'.'
                );
            }

            $target = $resolved;
        } else {
            throw new LogicException("DeclaredAction requires either 'model' or 'target' to be specified in setDefaults.");
        }

        $method = isset($args['call']) && is_string($args['call'])
            ? $args['call']
            : (is_string($target) ? 'create' : 'update');
        $attributes = isset($args['args']) && is_array($args['args']) ? $args['args'] : $validated;
        $column = isset($args['column']) && is_string($args['column']) ? $args['column'] : null;

        (new Mutation)->apply($target, $method, $attributes, $column);

        /** @var Redirector $redirector */
        $redirector = app('redirect');
        $response = (new RedirectAction)->apply($redirector, $args);

        return (new FlashAction)->apply($response, $args);
    }
}
