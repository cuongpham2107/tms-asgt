<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('can search users with Vietnamese diacritics case-insensitively in SQLite', function (): void {
    User::factory()->create([
        'name' => 'Nguyễn Văn Đông',
        'email' => 'dong@example.com',
    ]);

    // Search with uppercase "Đông"
    $resUpper = User::whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower('Đông', 'UTF-8').'%'])->get();
    expect($resUpper)->toHaveCount(1)
        ->and($resUpper->first()->name)->toBe('Nguyễn Văn Đông');

    // Search with lowercase "đông"
    $resLower = User::whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower('đông', 'UTF-8').'%'])->get();
    expect($resLower)->toHaveCount(1)
        ->and($resLower->first()->name)->toBe('Nguyễn Văn Đông');

    // Search with mixed case "đÔnG"
    $resMixed = User::whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower('đÔnG', 'UTF-8').'%'])->get();
    expect($resMixed)->toHaveCount(1);
});
