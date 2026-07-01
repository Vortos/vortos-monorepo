<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Rendering;

use Twig\Environment;
use Vortos\Http\Response;

class TwigRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function render(string $template, array $context = [], int $status = 200): Response
    {
        $html = $this->twig->render($template, $context);

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function renderFragment(string $template, array $context = []): Response
    {
        $html = $this->twig->render($template, $context);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
