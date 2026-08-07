<?php

namespace Helpers;

/**
 * URL Helper
 * Provides URL manipulation and resolution functions
 */
class UrlHelper
{
    /**
     * Get site origin from configuration
     */
    public static function getSiteOrigin(): string
    {
        static $origin = null;
        if ($origin !== null) {
            return $origin;
        }

        if (defined('ALLOWED_ORIGINS') && is_array(ALLOWED_ORIGINS)) {
            foreach (ALLOWED_ORIGINS as $allowed) {
                if ($allowed !== '*' && $allowed !== '') {
                    $origin = rtrim($allowed, '/');
                    return $origin;
                }
            }
        }

        // Backward compatibility: same-host installs
        $parsed = parse_url(APP_URL);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $origin = $host !== '' ? ($scheme . '://' . $host) : '';
        return $origin;
    }

    /**
     * Resolve page URL to full URL with site origin
     */
    public static function resolvePageUrl(string $pageUrl): string
    {
        if ($pageUrl === '' || $pageUrl === null) {
            return $pageUrl;
        }

        if (preg_match('#^https?://#i', $pageUrl)) {
            // Rewrite full URLs that incorrectly use the comment app host
            $appHost = parse_url(APP_URL, PHP_URL_HOST);
            $pageHost = parse_url($pageUrl, PHP_URL_HOST);
            
            if ($appHost && $pageHost === $appHost) {
                $siteOrigin = self::getSiteOrigin();
                if ($siteOrigin !== '') {
                    $path = parse_url($pageUrl, PHP_URL_PATH) ?? '/';
                    $query = parse_url($pageUrl, PHP_URL_QUERY);
                    $fragment = parse_url($pageUrl, PHP_URL_FRAGMENT);
                    
                    if ($query !== null) {
                        $path .= '?' . $query;
                    }
                    if ($fragment !== null) {
                        $path .= '#' . $fragment;
                    }
                    
                    return $siteOrigin . $path;
                }
            }
            return $pageUrl;
        }

        $siteOrigin = self::getSiteOrigin();
        if ($siteOrigin === '') {
            return $pageUrl;
        }

        return $siteOrigin . ($pageUrl[0] === '/' ? $pageUrl : '/' . $pageUrl);
    }

    /**
     * Enrich rows with page_url_href field
     */
    public static function enrichPageUrlHref(array &$rows, string $field = 'page_url'): void
    {
        foreach ($rows as &$row) {
            if (isset($row[$field])) {
                $row[$field . '_href'] = self::resolvePageUrl($row[$field]);
            }
        }
        unset($row);
    }

    /**
     * Normalize export page URL (strip scheme+host, keep path+query+fragment)
     */
    public static function normalizeExportPageUrl(string $link): string
    {
        $parsed = parse_url($link);
        $path = $parsed['path'] ?? $link;
        
        if (isset($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment'])) {
            $path .= '#' . $parsed['fragment'];
        }
        
        return $path;
    }
}
