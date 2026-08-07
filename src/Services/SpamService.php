<?php

namespace Services;

/**
 * Spam Service
 * Handles spam detection logic
 */
class SpamService
{
    private const SPAM_SCORE_THRESHOLD = 4;

    private const SPAM_KEYWORDS = [
        'viagra', 'cialis', 'pharmacy', 'poker', 'casino',
        'loan', 'mortgage', 'seo services', 'buy now',
    ];

    private const SUSPICIOUS_DOMAINS = [
        'example.com', 'test.com', 'tempmail', 'disposable',
    ];

    /**
     * Detect if the given comment data is likely spam
     */
    public function detectSpam(string $content, string $authorName, string $authorEmail, ?string $authorUrl): bool
    {
        $score = 0;

        // Excessive links
        $linkCount = preg_match_all('/(https?:\/\/|www\.)/i', $content);
        if ($linkCount > 3) {
            $score += 2;
        }

        // Spam keywords in content or author name
        foreach (self::SPAM_KEYWORDS as $keyword) {
            if (stripos($content, $keyword) !== false || stripos($authorName, $keyword) !== false) {
                $score += 3;
            }
        }

        // Excessive capitalization
        if (preg_match('/[A-Z]{10,}/', $content)) {
            $score += 1;
        }

        // Suspicious email domains
        foreach (self::SUSPICIOUS_DOMAINS as $domain) {
            if (stripos($authorEmail, $domain) !== false) {
                $score += 1;
            }
        }

        // Content length checks
        $contentLength = strlen($content);
        if ($contentLength < 10) {
            $score += 1;
        }
        if ($contentLength > 4000) {
            $score += 1;
        }

        return $score >= self::SPAM_SCORE_THRESHOLD;
    }

    /**
     * Check honeypot field
     */
    public function isHoneypotTriggered(?string $honeypotValue): bool
    {
        return !empty($honeypotValue);
    }
}
