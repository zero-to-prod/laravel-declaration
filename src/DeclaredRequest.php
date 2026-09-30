<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use LogicException;

class DeclaredRequest extends FormRequest
{
    public function validateResolved(): void
    {
        $this->configureDeclaration();

        parent::validateResolved();
    }

    protected function configureDeclaration(): void
    {
        $declaration = $this->declaration();

        if ($declaration->redirect !== null) {
            $this->redirect = $declaration->redirect;
        }

        if ($declaration->redirectRoute !== null) {
            $this->redirectRoute = $declaration->redirectRoute;
        }

        if ($declaration->redirectAction !== null) {
            $this->redirectAction = $declaration->redirectAction;
        }

        $this->errorBag = $declaration->errorBag;
        $this->stopOnFirstFailure = $declaration->stopOnFirstFailure;
    }

    public function authorize(): bool|Response
    {
        $authorize = $this->declaration()->authorize;

        /** @var bool|Response $authorize */
        $authorize = $authorize === null ? true : $this->resolve($authorize);

        return $authorize;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        /** @var array<string, string> $messages */
        $messages = $this->resolve($this->declaration()->messages);

        return $messages;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = $this->resolve($this->declaration()->attributes);

        return $attributes;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        /** @var array<string, mixed>|null $data */
        $data = $this->resolve($this->declaration()->validationData);

        return $data ?? parent::validationData();
    }

    protected function prepareForValidation(): void
    {
        $this->resolve($this->declaration()->prepareForValidation);
    }

    protected function passedValidation(): void
    {
        $this->resolve($this->declaration()->passedValidation);
    }

    public function withValidator(Validator $validator): void
    {
        $this->resolve($this->declaration()->withValidator, ['validator' => $validator]);
    }

    /** @return list<Closure(Validator): mixed> */
    public function after(?Validator $validator = null): array
    {
        return array_map(
            fn (string $hook): Closure => fn (Validator $Validator): mixed => $this->resolve($hook, ['validator' => $Validator]),
            $this->declaration()->after,
        );
    }

    public function validator(ValidationFactory $factory): Validator
    {
        /** @var Validator|null $validator */
        $validator = $this->resolve($this->declaration()->validator, ['factory' => $factory]);

        return $validator ?? $this->createDefaultValidator($factory);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var array<string, mixed> $rules */
        $rules = $this->resolve($this->declaration()->rules);

        return array_map(
            fn (mixed $field_rules): mixed => is_array($field_rules)
                ? array_map($this->rule(...), $field_rules)
                : $field_rules,
            $rules,
        );
    }

    protected function shouldFailOnUnknownFields(): bool
    {
        $shouldFail = $this->declaration()->shouldFailOnUnknownFields;

        return $shouldFail ?? parent::shouldFailOnUnknownFields();
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->resolve($this->declaration()->failedValidation, ['validator' => $validator]);

        parent::failedValidation($validator);
    }

    protected function failedAuthorization(): never
    {
        $this->resolve($this->declaration()->failedAuthorization);

        throw new AuthorizationException;
    }

    private function declaration(): Request
    {
        /** @var string|null $name */
        $name = $this->route()->getMetadata('request') ?? $this->route()->defaults['request'] ?? null;

        /** @var Request|null $Request */
        $Request = $this->container->make(Manifest::class)->requests->get($name);

        return $Request
            ?? throw new LogicException(
                "The route declares no `request` metadata or default, or [{$name}] is not declared under `requests`.",
            );
    }

    /** @param  array<string, mixed>  $parameters */
    private function resolve(mixed $value, array $parameters = []): mixed
    {
        return is_string($value)
            ? $this->container->call($value, ['request' => $this, ...$parameters])
            : $value;
    }

    private function rule(mixed $rule): mixed
    {
        if (! is_string($rule) || ! str_contains(Str::before($rule, ':'), '\\')) {
            return $rule;
        }

        return class_exists($rule) ? $this->container->make($rule) : $this->resolve($rule);
    }
}
