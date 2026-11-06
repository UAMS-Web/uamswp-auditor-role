<?php

/**
 * Recognizes and masks secret-bearing names and values for the exports.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class Secrets
{
    /** What a masked value is replaced with. */
    public const MASK = '[masked]';

    /** A name that suggests its value is a secret. */
    public const NAME_PATTERN = '/pass(word)?|pwd|secret|token|api_?key|apikey|licen[cs]e|salt|auth|private|credential|webhook|smtp|client_?id|access_?key|recaptcha|nonce|hash|signature|cookie|session|connection_info|(^|_)(keys?|sig)($|_)/i';

    /**
     * Whether a name looks secret-bearing.
     */
    public static function is_secret_name(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Whether a value looks like a key or token whatever its name: a long run
     * of letters, digits and token punctuation with no spaces, or a known prefix.
     */
    public static function looks_like_secret(string $value): bool
    {
        // A query string or a config blob that carries a secret-named parameter,
        // such as a saved migration form with its connection key.
        preg_match_all('/(?:^|[&?;\s])([a-z0-9_\-\[\]]+)=/i', $value, $names);

        foreach ($names[1] as $param) {
            if (self::is_secret_name($param)) {
                return true;
            }
        }

        return preg_match('/^(sk|pk|rk)_(live|test)_|^xox[abposr]-|^gh[pousr]_|^AKIA[0-9A-Z]{16}$/', $value) === 1
            || preg_match('/^[A-Za-z0-9_\-+\/=.]{32,}$/', $value) === 1 && preg_match('/\d/', $value) === 1 && preg_match('/[A-Za-z]/', $value) === 1;
    }

    /**
     * A value with every secret-bearing part masked, recursing into arrays and objects.
     */
    public static function mask(string $name, mixed $value): mixed
    {
        if (self::is_secret_name($name)) {
            return in_array($value, ['', null, false], true) ? $value : self::MASK;
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $masked = [];

            foreach ($value as $key => $item) {
                $masked[$key] = self::mask((string) $key, $item);
            }

            return $masked;
        }

        if (is_string($value)) {
            return self::looks_like_secret($value) ? self::MASK : $value;
        }

        return $value;
    }
}
