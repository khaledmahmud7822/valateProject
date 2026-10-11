<?php
/**
 * Metro Valet Parking — pure-PHP TOTP (RFC 6238, SHA-1, 30s step, 6 digits).
 *
 * Used for optional staff MFA (users.mfa_secret). No external libraries.
 * Secret entry is manual (typed code), so no QR generation is needed.
 * Defines functions only.
 */

/** Base32 alphabet (RFC 4648). */
function totp_b32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $bits .= sprintf('%08b', ord($data[$i]));
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    $pad = (8 - (strlen($out) % 8)) % 8;
    return $out . str_repeat('=', $pad);
}

/** Decode a base32 string to raw bytes. Throws on invalid input. */
function totp_b32_decode(string $b32): string {
    $b32 = strtoupper(rtrim(trim($b32), '='));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $v = strpos($alphabet, $b32[$i]);
        if ($v === false) {
            throw new InvalidArgumentException('Invalid base32 character in TOTP secret.');
        }
        $bits .= sprintf('%05b', $v);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) {
            break;
        }
        $out .= chr(bindec($byte));
    }
    return $out;
}

/** Generate a new random base32 secret (default 20 bytes = 160 bits). */
function totp_secret(int $len = 20): string {
    return totp_b32_encode(random_bytes($len));
}

/**
 * Compute the 6-digit TOTP code for $secret at $time (Unix timestamp).
 * Test vector (RFC 6238, SHA-1): secret base32 of ASCII "12345678901234567890",
 * time=59 → "287082"; time=1111111109 → "081804".
 */
function totp_code(string $secret, ?int $time = null): string {
    $key = totp_b32_decode($secret);
    $counter = (int) floor(($time ?? time()) / 30);
    // 8-byte big-endian counter.
    $msg = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3])
    ) % 1000000;
    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

/**
 * Verify a user-supplied 6-digit $code against $secret.
 * $window allows ±$window 30-second steps of clock drift (default 1).
 */
function totp_verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return false;
    }
    try {
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(totp_code($secret, $now + $i * 30), $code)) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}
