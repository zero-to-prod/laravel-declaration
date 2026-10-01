<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory as ValidationFactory;

// Sections 2 and 3 of docs/declarative-validator-acceptance-test-plan.md — application-specified
// custom validation rules and implicit custom rules, sourced from
// docs/repos/laravel/docs/validation.md.

// AT-01 — validation.md — Custom Validation Rules.
it('verifies its attribute with an application-specified custom rule', function (): void {
    $file = $this->manifest(<<<'YAML'
        validator:
          extend:
            slug: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\SlugExtension@check'
        requests:
          - name: slug-rule
            rules:
              slug: [required, slug]
        routes:
          addRoute:
            - uri: slug-rule
              methods: POST
              action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
              metadata:
                request: slug-rule
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $this->postJson('/slug-rule', ['slug' => 'Not OK'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);       // the custom rule failed the invalid value

    $this->postJson('/slug-rule', ['slug' => 'ok-slug'])
        ->assertOk();                                 // and passed the valid one
});

// AT-02 — validation.md — Implicit Rules. The doc observes this behavior with
// `Validator::make($input, $rules)->passes(); // true` — global middleware
// (ConvertEmptyStringsToNull) rewrites a posted empty string to null before a
// FormRequest validates, so the empty-string branch is observed on the shared factory.
it('does not run a custom rule when its attribute is absent or contains an empty string', function (): void {
    $file = $this->manifest(<<<'YAML'
        validator:
          extend:
            slug: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\SlugExtension@check'
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $empty = app(ValidationFactory::class)->make(['slug' => ''], ['slug' => 'slug']);
    $absent = app(ValidationFactory::class)->make([], ['slug' => 'slug']);

    expect($empty->passes())->toBeTrue()              // the empty string would fail the rule — a pass proves the rule was skipped
        ->and($absent->passes())->toBeTrue();         // no slug field at all — the rule never ran
});

// AT-03 — validation.md — Implicit Rules. The rule fails every value it is run against,
// so a failure proves the rule ran where normal custom rules are skipped (AT-02).
it('runs an implicit custom rule even when the attribute is absent or empty', function (): void {
    $file = $this->manifest(<<<'YAML'
        validator:
          extendImplicit:
            phone: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\RejectsEverything'
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $absent = app(ValidationFactory::class)->make([], ['phone' => 'phone']);
    $empty = app(ValidationFactory::class)->make(['phone' => ''], ['phone' => 'phone']);

    expect($absent->fails())->toBeTrue()
        ->and($absent->errors()->keys())->toBe(['phone'])
        ->and($empty->fails())->toBeTrue()
        ->and($empty->errors()->keys())->toBe(['phone']);
});

// AT-04 — validation.md — Implicit Rules.
it('invalidates a missing or empty attribute only when the implicit rule chooses to', function (): void {
    $file = $this->manifest(<<<'YAML'
        validator:
          extendImplicit:
            optionalPhone: 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Validation\OptionalPhone'
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    $absent = app(ValidationFactory::class)->make([], ['phone' => ['optionalPhone']]);
    $empty = app(ValidationFactory::class)->make(['phone' => ''], ['phone' => ['optionalPhone']]);

    expect($absent->passes())->toBeTrue()             // absent — implying required did not invalidate it
        ->and($empty->passes())->toBeTrue();          // empty — the rule chose to pass it
});
