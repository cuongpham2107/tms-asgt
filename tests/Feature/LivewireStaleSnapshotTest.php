<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Log;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

test('livewire update 404 is returned as 419 for stale tabs', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);

    $this->postJson(EndpointResolver::updatePath(), [])
        ->assertStatus(419);
});

test('rejected livewire updates are logged with the rejection reason', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Log::spy();

    $this->withHeader('X-Livewire', 'true')
        ->postJson(EndpointResolver::updatePath(), [])
        ->assertStatus(419);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Livewire update rejected'
            && $context['reason'] === 'empty_components');
});

test('unrelated 404 responses are unchanged', function () {
    $this->getJson('/khong-ton-tai')
        ->assertNotFound();
});
