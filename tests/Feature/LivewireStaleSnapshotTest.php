<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

test('livewire update 404 is returned as 419 for stale tabs', function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);

    $this->postJson(EndpointResolver::updatePath(), [])
        ->assertStatus(419);
});

test('unrelated 404 responses are unchanged', function () {
    $this->getJson('/khong-ton-tai')
        ->assertNotFound();
});
