<?php

namespace Core;

/**
 * Request
 * Wraps the current HTTP request into a value object.
 * All controllers receive this instead of reading superglobals directly.
 */
class Request
{
    private string $method;
    private string $action;
    private array  $query;
    private array  $body;
    private array  $cookies;
    private array  $server;

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->query   = $_GET   ?? [];
        $this->cookies = $_COOKIE ?? [];
        $this->server  = $_SERVER ?? [];
        $this->action  = $this->query['action'] ?? '';

        // Parse JSON body once, fall back to empty array
        $raw = file_get_contents('php://input');
        $this->body = ($raw !== false && $raw !== '')
            ? (json_decode($raw, true) ?? [])
            : [];
    }

    // ── HTTP method ───────────────────────────────────────────────────────────

    public function getMethod(): string   { return $this->method; }
    public function isGet(): bool         { return $this->method === 'GET'; }
    public function isPost(): bool        { return $this->method === 'POST'; }
    public function isPut(): bool         { return $this->method === 'PUT'; }
    public function isDelete(): bool      { return $this->method === 'DELETE'; }
    public function isOptions(): bool     { return $this->method === 'OPTIONS'; }

    // ── Action ────────────────────────────────────────────────────────────────

    public function getAction(): string   { return $this->action; }

    // ── Query parameters ──────────────────────────────────────────────────────

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        return isset($this->query[$key]) ? (int)$this->query[$key] : $default;
    }

    // ── Body (JSON) ───────────────────────────────────────────────────────────

    public function body(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }

    public function bodyInt(string $key, int $default = 0): int
    {
        return isset($this->body[$key]) ? (int)$this->body[$key] : $default;
    }

    /** Returns the whole decoded body array */
    public function allBody(): array { return $this->body; }

    // ── Cookies ───────────────────────────────────────────────────────────────

    public function cookie(string $key, $default = null)
    {
        return $this->cookies[$key] ?? $default;
    }

    // ── Server / environment ──────────────────────────────────────────────────

    public function server(string $key, $default = null)
    {
        return $this->server[$key] ?? $default;
    }

    public function getIp(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function getUserAgent(): ?string
    {
        return $this->server['HTTP_USER_AGENT'] ?? null;
    }

    public function getOrigin(): string
    {
        return $this->server['HTTP_ORIGIN'] ?? '';
    }
}
