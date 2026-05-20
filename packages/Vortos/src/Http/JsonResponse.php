<?php

declare(strict_types=1);

namespace Vortos\Http;

class JsonResponse extends Response
{
    protected mixed $data;
    protected ?string $callback = null;

    public const DEFAULT_ENCODING_OPTIONS = 15; // JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT

    protected int $encodingOptions = self::DEFAULT_ENCODING_OPTIONS;

    public function __construct(mixed $data = null, int $status = 200, array $headers = [], bool $json = false)
    {
        parent::__construct('', $status, $headers);

        if ($json && !\is_string($data) && !is_numeric($data) && !$data instanceof \Stringable) {
            throw new \TypeError(\sprintf('"%s": If $json is set to true, argument $data must be a string or object implementing __toString(), "%s" given.', __METHOD__, get_debug_type($data)));
        }

        $data ??= new \ArrayObject();

        $json ? $this->setJson($data) : $this->setData($data);
    }

    public static function fromJsonString(string $data, int $status = 200, array $headers = []): static
    {
        return new static($data, $status, $headers, true);
    }

    public function setCallback(?string $callback): static
    {
        if (null !== $callback) {
            $pattern = '/^[$_\p{L}][$_\p{L}\p{Mn}\p{Mc}\p{Nd}\p{Pc}\x{200C}\x{200D}]*(?:\[(?:"(?:\\\.|[^"\\\])*"|\'(?:\\\.|[^\'\\\])*\'|\d+)\])*?$/u';
            $reserved = [
                'break', 'do', 'instanceof', 'typeof', 'case', 'else', 'new', 'var', 'catch', 'finally', 'return', 'void', 'continue', 'for', 'switch', 'while',
                'debugger', 'function', 'this', 'with', 'default', 'if', 'throw', 'delete', 'in', 'try', 'class', 'enum', 'extends', 'super', 'const', 'export',
                'import', 'implements', 'let', 'private', 'public', 'yield', 'interface', 'package', 'protected', 'static', 'null', 'true', 'false',
            ];
            foreach (explode('.', $callback) as $part) {
                if (!preg_match($pattern, $part) || \in_array($part, $reserved, true)) {
                    throw new \InvalidArgumentException('The callback name is not valid.');
                }
            }
        }

        $this->callback = $callback;

        return $this->update();
    }

    public function setJson(string $json): static
    {
        $this->data = $json;

        return $this->update();
    }

    public function setData(mixed $data = []): static
    {
        try {
            $data = json_encode($data, $this->encodingOptions);
        } catch (\Exception $e) {
            if ('Exception' === $e::class && str_starts_with($e->getMessage(), 'Failed calling ')) {
                throw $e->getPrevious() ?: $e;
            }
            throw $e;
        }

        if (\JSON_THROW_ON_ERROR & $this->encodingOptions) {
            return $this->setJson($data);
        }

        if (\JSON_ERROR_NONE !== json_last_error()) {
            throw new \InvalidArgumentException(json_last_error_msg());
        }

        return $this->setJson($data);
    }

    public function getEncodingOptions(): int
    {
        return $this->encodingOptions;
    }

    public function setEncodingOptions(int $encodingOptions): static
    {
        $this->encodingOptions = $encodingOptions;

        return $this->setData(json_decode($this->data));
    }

    protected function update(): static
    {
        if (null !== $this->callback) {
            $this->headers->set('Content-Type', 'text/javascript');

            return $this->setContent(\sprintf('/**/%s(%s);', $this->callback, $this->data));
        }

        if (!$this->headers->has('Content-Type') || 'text/javascript' === $this->headers->get('Content-Type')) {
            $this->headers->set('Content-Type', 'application/json');
        }

        return $this->setContent($this->data);
    }
}
