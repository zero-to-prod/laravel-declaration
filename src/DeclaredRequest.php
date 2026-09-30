<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

class DeclaredRequest extends FormRequest
{
    private const string when = 'when';

    private const string unless = 'unless';

    private const string arguments = 'arguments';

    private const string condition = 'condition';

    private const string defaultRules = 'defaultRules';

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

        /** @var bool|Response $result */
        $result = match (true) {
            $authorize === null => true,
            is_array($authorize) => $this->gateCall($authorize),
            default => $this->resolve($authorize),
        };

        return $result;
    }

    /** @param  array<string, array<string, mixed>>  $authorize */
    private function gateCall(array $authorize): bool|Response
    {
        if (count($authorize) !== 1) {
            throw new LogicException('The `authorize` map declares '.count($authorize).' Gate methods; declare one.');
        }

        $method = array_key_first($authorize);

        $parameters = $authorize[$method];

        if (array_key_exists(self::arguments, $parameters)) {
            $arguments = $parameters[self::arguments];

            $parameters[self::arguments] = is_array($arguments)
                ? array_map($this->gateArgument(...), $arguments)
                : $this->gateArgument($arguments);
        }

        /** @var GateContract $Gate */
        $Gate = $this->container->make(GateContract::class)->forUser($this->user());

        $result = $this->container->call([$Gate, $method], $parameters);   // @phpstan-ignore argument.type (the method name is manifest-declared; unknown names fail with Laravel's own exceptions)

        /** @var bool|Response $result */
        return $result;
    }

    private function gateArgument(mixed $argument): mixed
    {
        if (! is_string($argument) || str_contains($argument, '\\')) {
            return $argument;
        }

        return $this->route($argument)
            ?? (preg_match("/^['\"](.*)['\"]$/", $argument, $matches) ? $matches[1] : null);
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

    public function withValidator(Validator $Validator): void
    {
        $this->resolve($this->declaration()->withValidator, ['validator' => $Validator]);
    }

    /** @return list<Closure(Validator): mixed> */
    public function after(?Validator $Validator = null): array
    {
        return array_map(
            fn (string $hook): Closure => fn (Validator $Validator): mixed => $this->resolve($hook, ['validator' => $Validator]),
            $this->declaration()->after,
        );
    }

    public function validator(ValidationFactory $ValidationFactory): Validator
    {
        /** @var Validator|null $validator */
        $validator = $this->resolve($this->declaration()->validator, ['factory' => $ValidationFactory]);

        return $validator ?? $this->createDefaultValidator($ValidationFactory);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var array<string, mixed> $rules */
        $rules = $this->resolve($this->declaration()->rules);

        return array_map(
            fn (mixed $field_rules): mixed => is_array($field_rules) && array_is_list($field_rules)
                ? array_map($this->rule(...), $field_rules)   // list of rule entries — one call per entry
                : $this->rule($field_rules),                  // a conditional map IS the field value (Rule::when)
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
        if (is_array($rule) && (isset($rule[self::when]) || isset($rule[self::unless]))) {
            return $this->conditionalRule($rule);
        }

        if (! is_string($rule) || ! str_contains(Str::before($rule, ':'), '\\')) {
            return $rule;
        }

        return class_exists($rule) ? $this->container->make($rule) : $this->resolve($rule);
    }

    /** @param  array<mixed>  $rule */
    private function conditionalRule(array $rule): mixed
    {
        if (isset($rule[self::when], $rule[self::unless])) {
            throw new LogicException('A rule entry declares both `when` and `unless`; declare one.');
        }

        $method = isset($rule[self::when]) ? self::when : self::unless;
        $declaration = $rule[$method];

        if (! is_array($declaration) || ! array_key_exists(self::condition, $declaration)) {
            throw new LogicException("The `$method` rule entry declares no `condition`.");
        }

        /** @var bool|callable $condition — the native `callable|bool` argument: a bool passes through, a reference returns bool or a Closure */
        $condition = $this->resolve($declaration[self::condition]);

        return Rule::{$method}(
            $condition,
            $this->conditionalRules($declaration['rules'] ?? []),
            $this->conditionalRules($declaration[self::defaultRules] ?? []),
        );
    }

    /** @return string|array<int, mixed> */
    private function conditionalRules(mixed $rules): mixed
    {
        return is_string($rules) ? $rules : array_map($this->rule(...), (array) $rules);
    }
}
