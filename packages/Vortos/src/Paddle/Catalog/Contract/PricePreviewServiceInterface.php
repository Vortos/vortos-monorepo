<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog\Contract;

use Vortos\Paddle\Catalog\Operation\PricePreviewRequest;
use Vortos\Paddle\Catalog\PricePreviewResult;

interface PricePreviewServiceInterface
{
    public function preview(PricePreviewRequest $request): PricePreviewResult;
}
