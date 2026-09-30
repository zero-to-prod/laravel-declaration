<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Request
{
    use DataModel;

    public const string name = 'name';

    #[Describe([Describe::required => true])]
    public string $name;

    public const string authorize = 'authorize';

    #[Describe([Describe::nullable => true])]
    public bool|string|null $authorize;

    public const string rules = 'rules';

    /** @var array<string, mixed>|string */
    #[Describe([Describe::default => []])]
    public array|string $rules;

    public const string messages = 'messages';

    /** @var array<string, string>|string */
    #[Describe([Describe::default => []])]
    public array|string $messages;

    public const string attributes = 'attributes';

    /** @var array<string, string>|string */
    #[Describe([Describe::default => []])]
    public array|string $attributes;

    public const string validationData = 'validationData';

    #[Describe([Describe::nullable => true])]
    public ?string $validationData;

    public const string prepareForValidation = 'prepareForValidation';

    #[Describe([Describe::nullable => true])]
    public ?string $prepareForValidation;

    public const string passedValidation = 'passedValidation';

    #[Describe([Describe::nullable => true])]
    public ?string $passedValidation;

    public const string withValidator = 'withValidator';

    #[Describe([Describe::nullable => true])]
    public ?string $withValidator;

    public const string after = 'after';

    /** @var list<string> */
    #[Describe([Describe::default => []])]
    public array $after;

    public const string validator = 'validator';

    #[Describe([Describe::nullable => true])]
    public ?string $validator;

    public const string failedValidation = 'failedValidation';

    #[Describe([Describe::nullable => true])]
    public ?string $failedValidation;

    public const string failedAuthorization = 'failedAuthorization';

    #[Describe([Describe::nullable => true])]
    public ?string $failedAuthorization;

    public const string redirect = 'redirect';

    #[Describe([Describe::nullable => true])]
    public ?string $redirect;

    public const string redirectRoute = 'redirectRoute';

    #[Describe([Describe::nullable => true])]
    public ?string $redirectRoute;

    public const string redirectAction = 'redirectAction';

    #[Describe([Describe::nullable => true])]
    public ?string $redirectAction;

    public const string errorBag = 'errorBag';

    #[Describe([Describe::default => 'default'])]
    public string $errorBag;

    public const string stopOnFirstFailure = 'stopOnFirstFailure';

    #[Describe([Describe::default => false])]
    public bool $stopOnFirstFailure;

    public const string shouldFailOnUnknownFields = 'shouldFailOnUnknownFields';

    #[Describe([Describe::nullable => true])]
    public ?bool $shouldFailOnUnknownFields;
}
