<?php

declare(strict_types=1);

namespace Vortos\AwsSes\ValueObject;

final class RenderedEmail
{
    public function __construct(
        private readonly string $htmlBody,
        private readonly ?string $textBody = null,
    ) {}

    public function htmlBody(): string
    {
        return $this->htmlBody;
    }

    /**
     * Plain-text body. When null, the mailer will strip HTML tags as a fallback.
     */
    public function textBody(): ?string
    {
        return $this->textBody;
    }
}
