<?php

namespace App\Services\Mcp;

/**
 * What a tool call sends back. Two representations of the same thing: readable
 * text, because that is what every client can display, and the structured
 * object next to it for whoever wants to work with the data.
 *
 * A failed call is not a protocol error. It comes back as a result with
 * `isError`, so the model reads what went wrong and can try something else —
 * a JSON-RPC error would only reach the client's plumbing.
 */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>|null  $structured
     */
    private function __construct(
        public string $text,
        public ?array $structured = null,
        public bool $isError = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $structured
     */
    public static function ok(string $text, ?array $structured = null): self
    {
        return new self($text, $structured);
    }

    public static function error(string $text): self
    {
        return new self($text, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'content' => [['type' => 'text', 'text' => $this->text]],
        ];

        if ($this->structured !== null) {
            $payload['structuredContent'] = $this->structured;
        }

        if ($this->isError) {
            $payload['isError'] = true;
        }

        return $payload;
    }
}
