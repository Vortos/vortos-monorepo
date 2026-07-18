<?php

declare(strict_types=1);

namespace Vortos\Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Search\Document\SearchDocument;

final class SearchDocumentTest extends TestCase
{
    public function testKeywordBlobFoldsKeywordsAndVisibleLines(): void
    {
        $doc = new SearchDocument(
            type: 'application',
            entityId: '42',
            tenantId: 'org-1',
            title: 'Sarah Johnson',
            subtitle: 'Pending',
            keywords: ['sarah.johnson@email.com'],
        );

        self::assertStringContainsString('sarah.johnson@email.com', $doc->keywordBlob());
        self::assertStringContainsString('Sarah Johnson', $doc->keywordBlob());
        self::assertStringContainsString('Pending', $doc->keywordBlob());
    }

    /**
     * @dataProvider blankIdentity
     */
    public function testRejectsEmptyIdentityFields(string $type, string $entityId, string $tenantId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchDocument(type: $type, entityId: $entityId, tenantId: $tenantId, title: 't');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function blankIdentity(): iterable
    {
        yield 'empty type'   => ['', '42', 'org-1'];
        yield 'empty entity' => ['application', '', 'org-1'];
        yield 'empty tenant' => ['application', '42', ''];
    }
}
