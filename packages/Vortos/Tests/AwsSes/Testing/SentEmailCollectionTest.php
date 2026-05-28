<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Testing;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Testing\SentEmailCollection;
use Vortos\AwsSes\ValueObject\Email;

final class SentEmailCollectionTest extends TestCase
{
    private function email(string $to, string $subject = 'Test', string $body = '<p>Hi</p>'): Email
    {
        return Email::new()
            ->from('sender@example.com')
            ->to($to)
            ->subject($subject)
            ->htmlBody($body);
    }

    public function test_to_filter_matches_recipient(): void
    {
        $collection = new SentEmailCollection([
            $this->email('a@example.com'),
            $this->email('b@example.com'),
        ]);

        $this->assertSame(1, $collection->to('a@example.com')->count());
    }

    public function test_to_filter_is_case_insensitive(): void
    {
        $collection = new SentEmailCollection([$this->email('User@Example.com')]);
        $this->assertSame(1, $collection->to('user@example.com')->count());
    }

    public function test_from_filter(): void
    {
        $e1 = Email::new()->from('alice@example.com')->to('x@example.com')->subject('S')->htmlBody('H');
        $e2 = Email::new()->from('bob@example.com')->to('y@example.com')->subject('S')->htmlBody('H');

        $collection = new SentEmailCollection([$e1, $e2]);
        $this->assertSame(1, $collection->from('alice@example.com')->count());
        $this->assertSame(0, $collection->from('carol@example.com')->count());
    }

    public function test_with_subject_exact_match(): void
    {
        $collection = new SentEmailCollection([
            $this->email('a@example.com', 'Welcome'),
            $this->email('b@example.com', 'Reset Password'),
        ]);

        $this->assertSame(1, $collection->withSubject('Welcome')->count());
        $this->assertSame(0, $collection->withSubject('welcome')->count()); // case sensitive
    }

    public function test_with_subject_containing(): void
    {
        $collection = new SentEmailCollection([
            $this->email('a@example.com', 'Welcome to Vortos'),
            $this->email('b@example.com', 'Reset Password'),
        ]);

        $this->assertSame(1, $collection->withSubjectContaining('Vortos')->count());
    }

    public function test_with_body_containing(): void
    {
        $collection = new SentEmailCollection([
            $this->email('a@example.com', 'S', '<p>Hello World</p>'),
            $this->email('b@example.com', 'S', '<p>Goodbye</p>'),
        ]);

        $this->assertSame(1, $collection->withBodyContaining('Hello World')->count());
    }

    public function test_with_meta(): void
    {
        $e1 = $this->email('a@example.com')->withMeta('campaign', 'welcome');
        $e2 = $this->email('b@example.com')->withMeta('campaign', 'promo');

        $collection = new SentEmailCollection([$e1, $e2]);
        $this->assertSame(1, $collection->withMeta('campaign', 'welcome')->count());
    }

    public function test_first_returns_first_email(): void
    {
        $e1 = $this->email('first@example.com');
        $e2 = $this->email('second@example.com');

        $collection = new SentEmailCollection([$e1, $e2]);
        $this->assertSame('first@example.com', $collection->first()->getTo()[0]->address());
    }

    public function test_first_returns_null_when_empty(): void
    {
        $this->assertNull((new SentEmailCollection([]))->first());
    }

    public function test_all_returns_all_emails(): void
    {
        $emails     = [$this->email('a@example.com'), $this->email('b@example.com')];
        $collection = new SentEmailCollection($emails);
        $this->assertCount(2, $collection->all());
    }

    public function test_is_empty(): void
    {
        $this->assertTrue((new SentEmailCollection([]))->isEmpty());
        $this->assertFalse((new SentEmailCollection([$this->email('a@example.com')]))->isEmpty());
    }

    public function test_assert_count_passes(): void
    {
        $collection = new SentEmailCollection([$this->email('a@example.com')]);
        $collection->assertCount(1);
        $this->assertTrue(true);
    }

    public function test_assert_count_throws_on_mismatch(): void
    {
        $collection = new SentEmailCollection([]);
        $this->expectException(\AssertionError::class);
        $collection->assertCount(1);
    }

    public function test_assert_empty_passes_on_empty(): void
    {
        (new SentEmailCollection([]))->assertEmpty();
        $this->assertTrue(true);
    }

    public function test_assert_not_empty_throws_when_empty(): void
    {
        $this->expectException(\AssertionError::class);
        (new SentEmailCollection([]))->assertNotEmpty();
    }

    public function test_filters_are_chainable(): void
    {
        $collection = new SentEmailCollection([
            $this->email('target@example.com', 'Welcome'),
            $this->email('target@example.com', 'Reset'),
            $this->email('other@example.com',  'Welcome'),
        ]);

        $result = $collection
            ->to('target@example.com')
            ->withSubject('Welcome');

        $this->assertSame(1, $result->count());
    }
}
