<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Schema;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models\Post;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Routing\User;

$manifest = __DIR__.'/../Fixtures/manifest/end-to-end.yml';

beforeEach(function () use ($manifest): void {
    $this->withConfig([
        'app.key' => 'base64:uAfOGL85H6IA6qwCFx3xTNH5jNOWMUDrVaz/N0TNnhU=',
        'laravel-declaration.manifest' => $manifest,
    ]);

    Schema::dropIfExists('posts');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->default('Test User');
        $table->timestamps();
    });

    Schema::create('posts', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id');
        $table->foreignId('author_id');
        $table->string('title');
        $table->timestamps();
    });
});

it('executes the entire unified pipeline within a single HTTP request lifecycle exactly as presented in roadmap §4', function (): void {
    $user = User::create(['id' => 7, 'name' => 'Ada Lovelace']);
    $author = User::create(['id' => 8, 'name' => 'Charles Babbage']);

    for ($i = 1; $i <= 20; $i++) {
        Post::create([
            'user_id' => $user->id,
            'author_id' => $author->id,
            'title' => "Post {$i}",
            'created_at' => now()->addMinutes($i),
        ]);
    }

    $response = $this->get('/users/7/posts?sort=title&page=2');

    $response->assertOk()
        ->assertViewIs('users.posts')
        ->assertViewHas('brand', 'Tenant Console')
        ->assertViewHas('title', 'Posts')
        ->assertViewHas('stats', 70)
        ->assertViewHas('tenant', 'acme')
        ->assertViewHas('user', fn (User $boundUser): bool => $boundUser->getKey() === 7)
        ->assertViewHas('posts', fn (mixed $paginator): bool => $paginator instanceof LengthAwarePaginator
            && $paginator->total() === 20
            && $paginator->currentPage() === 2
            && $paginator->count() === 5
            && $paginator->first()->relationLoaded('author'))
        ->assertSeeText('Tenant Console|Posts|20:2|70|7|acme');

    expect(app(KernelContract::class)->getMiddlewarePriority())->toContain(SubstituteBindings::class);
});

it('aborts with 422 before queries execute when request validation fails', function (): void {
    $user = User::create(['id' => 7, 'name' => 'Ada Lovelace']);

    $this->getJson('/users/7/posts?sort=invalid')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sort');
});
