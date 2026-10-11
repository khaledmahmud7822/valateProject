<?php
/**
 * Metro Valet Parking — file-based rate limiting.
 *
 * Counters live in data/ratelimit/<key>.json (list of hit timestamps).
 * Usage: if (!rate_ok($key, 5, 600)) { /* blocked *\/ } else { rate_hit($key); ... }
 * Logins: 5 attempts / 600s per IP. Public forms: 20 / 3600s per IP.
 * Defines functions only.
 */

/** Directory holding the counter files (created on demand). */
function _rl_dir(): string {
    $d = DATA_DIR . '/ratelimit';
    if (!is_dir($d)) {
        mkdir($d, 0755, true);
    }
    return $d;
}

/** Safe filename for a rate-limit key. */
function _rl_file(string $key): string {
    return _rl_dir() . '/' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $key) . '.json';
}

/** Read the (pruned) hit timestamps for a key. */
function _rl_hits(string $key, int $window): array {
    $f = _rl_file($key);
    $now = time();
    $hits = [];
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $hits = $decoded;
        }
    }
    return array_values(array_filter($hits, fn($t) => is_numeric($t) && $t > $now - $window));
}

/**
 * Is $key still under $max hits within the last $window seconds?
 * Does NOT record a hit — call rate_hit() when the attempt actually happens.
 */
function rate_ok(string $key, int $max, int $window): bool {
    return count(_rl_hits($key, $window)) < $max;
}

/** Record one hit for $key. */
function rate_hit(string $key): void {
    $f = _rl_file($key);
    $hits = _rl_hits($key, 86400 * 30); // keep raw list bounded; pruned per-window on read
    $hits[] = time();
    @file_put_contents($f, json_encode(array_values($hits)), LOCK_EX);
}

/** Clear the counter for $key (e.g. after a successful login). */
function rate_clear(string $key): void {
    @unlink(_rl_file($key));
}
