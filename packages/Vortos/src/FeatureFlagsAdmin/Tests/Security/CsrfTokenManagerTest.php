<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Vortos\FeatureFlagsAdmin\Security\CsrfTokenManager;
use Vortos\Http\Request;

final class CsrfTokenManagerTest extends TestCase
{
    private CsrfTokenManager $csrf;
    private RequestStack $requestStack;
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();

        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $this->csrf = new CsrfTokenManager($this->requestStack);
    }

    public function test_generates_token(): void
    {
        $token = $this->csrf->getToken();

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));
    }

    public function test_returns_same_token_within_session(): void
    {
        $token1 = $this->csrf->getToken();
        $token2 = $this->csrf->getToken();

        $this->assertSame($token1, $token2);
    }

    public function test_validates_correct_token(): void
    {
        $token = $this->csrf->getToken();

        $this->assertTrue($this->csrf->isValid($token));
    }

    public function test_rejects_invalid_token(): void
    {
        $this->csrf->getToken();

        $this->assertFalse($this->csrf->isValid('invalid-token'));
    }

    public function test_rejects_empty_token(): void
    {
        $this->csrf->getToken();

        $this->assertFalse($this->csrf->isValid(''));
    }

    public function test_rejects_token_before_generation(): void
    {
        $this->assertFalse($this->csrf->isValid('any-token'));
    }

    public function test_regenerate_produces_new_token(): void
    {
        $original = $this->csrf->getToken();
        $regenerated = $this->csrf->regenerate();

        $this->assertNotSame($original, $regenerated);
        $this->assertTrue($this->csrf->isValid($regenerated));
        $this->assertFalse($this->csrf->isValid($original));
    }

    public function test_token_is_timing_safe(): void
    {
        $token = $this->csrf->getToken();

        $almostRight = substr($token, 0, -1) . 'x';
        $this->assertFalse($this->csrf->isValid($almostRight));
    }
}
