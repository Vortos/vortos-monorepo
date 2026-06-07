<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Hasher\ArgonPasswordHasher;

final class ArgonPasswordHasherTest extends TestCase
{
    private ArgonPasswordHasher $hasher;

    protected function setUp(): void
    {
        // Use lower cost for tests — faster execution
        $this->hasher = new ArgonPasswordHasher(memoryCost: 1024, timeCost: 1);
    }

    public function test_hash_returns_argon2id_hash(): void
    {
        $hash = $this->hasher->hash('mysecretpassword');

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertNotEquals('mysecretpassword', $hash);
    }

    public function test_verify_returns_true_for_correct_password(): void
    {
        $hash = $this->hasher->hash('mysecretpassword');

        $this->assertTrue($this->hasher->verify('mysecretpassword', $hash));
    }

    public function test_verify_returns_false_for_wrong_password(): void
    {
        $hash = $this->hasher->hash('mysecretpassword');

        $this->assertFalse($this->hasher->verify('wrongpassword', $hash));
    }

    public function test_verify_returns_false_for_empty_string(): void
    {
        $hash = $this->hasher->hash('mysecretpassword');

        $this->assertFalse($this->hasher->verify('', $hash));
    }

    public function test_same_password_produces_different_hashes(): void
    {
        // PHP generates a new salt each time
        $hash1 = $this->hasher->hash('mysecretpassword');
        $hash2 = $this->hasher->hash('mysecretpassword');

        $this->assertNotEquals($hash1, $hash2);
        $this->assertTrue($this->hasher->verify('mysecretpassword', $hash1));
        $this->assertTrue($this->hasher->verify('mysecretpassword', $hash2));
    }

    public function test_needs_rehash_returns_false_for_current_cost(): void
    {
        $hash = $this->hasher->hash('mysecretpassword');

        $this->assertFalse($this->hasher->needsRehash($hash));
    }

    public function test_needs_rehash_returns_true_when_cost_changes(): void
    {
        // Hash with low cost
        $lowCostHasher = new ArgonPasswordHasher(memoryCost: 1024, timeCost: 1);
        $hash = $lowCostHasher->hash('mysecretpassword');

        // Higher cost hasher says it needs rehash
        $highCostHasher = new ArgonPasswordHasher(memoryCost: 65536, timeCost: 4);
        $this->assertTrue($highCostHasher->needsRehash($hash));
    }
}
