<?php

declare(strict_types=1);

use Illuminate\Contracts\Validation\Factory as ValidationFactory;

$manifest = __DIR__.'/../Fixtures/manifest/validator.yml';

it('registers the declared extensions on the shared validation factory', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $validator = app(ValidationFactory::class)->make(['slug' => 'Not OK'], ['slug' => 'slug']);

    $validator->passes();

    expect($validator->errors()->all())->toBe(['The slug must be a slug.']);   // the Factory::extend $message fallback
});

it('dispatches a Class@method extension through its declared replacer', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $response = $this->postJson('/profile', ['name' => 'john doe', 'slug' => 'ok', 'phone' => '+15551234567']);

    $response->assertStatus(422)->assertJsonValidationErrors(['name']);

    expect($response->json('errors.name.0'))->toBe('The name must be uppercase (min 8).');   // replacer filled :min
});

it('runs an implicit extension when the field is absent', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['name' => 'JOHN', 'slug' => 'ok'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);          // a plain extend() would skip the absent field
});

it('passes a dependent extension its rule parameters', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['secret' => 'abc', 'reveal' => 'y', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['secret']);         // `reveal` present -> min 8 applies

    $this->postJson('/profile', ['secret' => 'abc', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertOk();                                     // `reveal` absent -> the check passes
});

it('applies when-conditional rules with a condition reference', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['admin' => 1, 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);          // admin -> required applies

    $this->postJson('/profile', ['name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertOk();                                     // not admin -> nullable applies
});

it('keeps native lazy semantics for a condition reference returning a closure', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['tier' => 'pro', 'coupon' => 'basic', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['coupon']);         // the closure saw Fluent($data) with tier=pro

    $this->postJson('/profile', ['tier' => 'free', 'coupon' => 'basic', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertOk();                                     // the conditional rules never applied
});

it('applies unless-conditional rules when the condition is falsy', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['discount' => 'SAVE10', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['discount']);       // not admin -> prohibited applies

    $this->postJson('/profile', ['admin' => 1, 'discount' => 'SAVE10', 'email' => 'john@example.com', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertOk();
});

it('accepts a conditional map as the whole field value', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['notes' => 'anything', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertOk();                                     // condition false -> defaultRules [] -> no rules
});

it('maps nested rule references inside conditional rules', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->postJson('/profile', ['admin' => 1, 'handle' => 'BAD HANDLE', 'name' => 'JOHN', 'slug' => 'ok', 'phone' => '+15551234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['handle']);         // App\Requests\Rules\Slug container-made inside when.rules
});

it('throws when a rule entry declares both when and unless', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        requests:
          - name: both
            rules:
              email:
                - when:
                    condition: true
                    rules: [required]
                  unless:
                    condition: true
                    rules: [required]
        afterResolving:
          Illuminate\Routing\Router:
            addRoute:
              - uri: both
                methods: POST
                action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
                metadata:
                  request: both
        YAML)]);

    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => $this->postJson('/both'))
        ->toThrow(LogicException::class, 'A rule entry declares both `when` and `unless`; declare one.');
});

it('throws when a conditional rule declares no condition', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => $this->manifest(<<<'YAML'
        requests:
          - name: no-condition
            rules:
              email:
                - when:
                    rules: [required]
        afterResolving:
          Illuminate\Routing\Router:
            addRoute:
              - uri: no-condition
                methods: POST
                action: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\RequestController, store]
                metadata:
                  request: no-condition
        YAML)]);

    $this->withoutExceptionHandling();

    expect(fn (): TestResponse => $this->postJson('/no-condition'))
        ->toThrow(LogicException::class, 'The `when` rule entry declares no `condition`.');
});

it('applies nothing without a validator block', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

    expect(app(ValidationFactory::class)->make(['name' => 'x'], ['name' => 'required'])->passes())->toBeTrue();
});
