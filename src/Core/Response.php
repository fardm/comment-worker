<?php

namespace Core;

use Helpers\SecurityHelper;

/**
 * Response
 * Encapsulates an HTTP response.  Controllers return a Response;
 * the Router sends it.  Nothing writes to stdout except send().
 */
class Response
{
    private int    $statusCode;
    private array  $data;
    private array  $headers = [];
    private bool   $isRaw   = false;   // true when send() must not encode JSON
    private string $rawBody = '';

    public function __construct(array $data, int $statusCode = 200)
    {
        $this->data       = $data;
        $this->statusCode = $statusCode;
    }

    // ── Factory helpers ───────────────────────────────────────────────────────

    public static function json(array $data, int $status = 200): self
    {
        return new self($data, $status);
    }

    public static function success(array $extra = [], int $status = 200): self
    {
        return new self(array_merge(['success' => true], $extra), $status);
    }

    public static function error(string $message, int $status = 400): self
    {
        return new self(['error' => $message], $status);
    }

    public static function created(array $data): self
    {
        return new self($data, 201);
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(['error' => $message], 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(['error' => $message], 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(['error' => $message], 403);
    }

    public static function tooManyRequests(string $reason = 'Too many requests'): self
    {
        return new self(['error' => $reason], 429);
    }

    // ── Header management ─────────────────────────────────────────────────────

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    // ── Send ──────────────────────────────────────────────────────────────────

    /**
     * Write all headers, status code, and body to the SAPI.
     * Called exactly once by the Router after routing completes.
     */
    public function send(): void
    {
        // Flush any stray output from the buffer opened in the entry point
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        if ($this->isRaw) {
            echo $this->rawBody;
            return;
        }

        $data = SecurityHelper::ensureUtf8($this->data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            error_log('json_encode failed: ' . json_last_error_msg());
            $json = json_encode(['error' => 'Internal server error', 'code' => json_last_error()]);
        }

        echo $json;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    public function getStatusCode(): int  { return $this->statusCode; }
    public function getData(): array      { return $this->data; }
}
