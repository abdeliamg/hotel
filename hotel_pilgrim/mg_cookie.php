<?php
// Shared helper for the `master_group` cookie used by the /hotel_pilgrim/* subsystem.
// The cookie value is stored base64-encoded; readers must call mg_cookie_get() to
// obtain the plain master_group string. Existing legacy plain-text cookies are
// transparently accepted so users don't get logged out on deployment.

if (!function_exists('mg_cookie_set')) {
    /**
     * Set the master_group cookie (base64-encoded).
     * Returns true on success (mirrors setcookie's return value).
     */
    function mg_cookie_set(string $plain, int $expire = 0, string $path = '/'): bool
    {
        $encoded = base64_encode($plain);
        return setcookie('master_group', $encoded, $expire, $path);
    }

    /**
     * Read and decode the master_group cookie into its plain string form.
     * Accepts the new base64 format as well as legacy plain-text values.
     * Returns '' when the cookie is absent or empty.
     */
    function mg_cookie_get(): string
    {
        $raw = trim((string)($_COOKIE['master_group'] ?? ''));
        if ($raw === '') {
            return '';
        }
        // Try strict base64 decode first.
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && $decoded !== '' && base64_encode($decoded) === $raw) {
            return trim($decoded);
        }
        // Fallback for legacy plain-text cookies.
        return $raw;
    }

    /**
     * Clear the master_group cookie.
     */
    function mg_cookie_clear(string $path = '/'): void
    {
        setcookie('master_group', '', time() - 3600, $path);
    }
}
