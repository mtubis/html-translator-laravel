<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Config;

use BackedEnum;
use Closure;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;

/**
 * Typed, validating access to one section of the package configuration (ADR-0007).
 *
 * Every error is an InvalidConfigurationException whose message starts with the full key
 * (`html-translator.profiles.docs.term_policy: expected one of …, got "x"`).
 *
 * Rules:
 * - A missing key and `null` both mean "not set": the default is used, or the value is reported as required.
 * - Only the coercions needed for values that come from `env()` are applied: an integer may be given as a decimal
 *   string with an optional leading minus and no plus sign, spaces or leading zeros (`"30"`, `"-1"`); a boolean as
 *   `"true"`, `"false"`, `"1"` or `"0"`;
 *   an empty string means "not set" for nullable strings and secrets (`FOO=` in `.env`). Nothing else is coerced.
 * - Offending values are quoted on one line, truncated, with control characters escaped; secrets are never quoted,
 *   only their type is named.
 *
 * @internal used by the Config\* factories; not part of the package's public API
 */
final readonly class ConfigReader
{
    private const int MAX_QUOTED_LENGTH = 50;

    private const int MAX_SUGGESTION_DISTANCE = 2;

    // TODO(core): temporary copy of the core's @internal UntrustedText rules (ADR-0002); use the public helper once
    // "Make quoting of untrusted text public API for integrations" (docs/core-feedback/) is released.
    private const string UNSAFE = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200E}\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}]/u';

    private const array ESCAPES = ["\n" => '\n', "\r" => '\r', "\t" => '\t'];

    /**
     * @param array<mixed> $values
     * @param string $prefix full key of the section, e.g. "html-translator.options"
     */
    public function __construct(
        private array $values,
        private string $prefix,
    ) {}

    public function key(string $key): string
    {
        return $this->prefix . '.' . $key;
    }

    public function has(string $key): bool
    {
        return null !== ($this->values[$key] ?? null);
    }

    /**
     * @param list<string> $known
     */
    public function assertKnownKeys(array $known): void
    {
        foreach (array_keys($this->values) as $key) {
            $key = (string) $key;
            if (\in_array($key, $known, true)) {
                continue;
            }

            $suggestion = $this->suggest($key, $known);

            throw InvalidConfigurationException::forKey(
                $this->key($this->sanitize($key)),
                null === $suggestion
                    ? 'unknown key; expected one of ' . implode(', ', $known)
                    : \sprintf('unknown key; did you mean "%s"?', $suggestion),
            );
        }
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return $default ?? throw $this->required($key);
        }

        if (!\is_string($value) || '' === $value) {
            throw $this->invalid($key, 'a non-empty string', $value);
        }

        return $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->values[$key] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw $this->invalid($key, 'a string or null', $value);
        }

        return $value;
    }

    public function int(string $key, ?int $default = null): int
    {
        return $this->integer($key, $default, null);
    }

    public function nonNegativeInt(string $key, ?int $default = null): int
    {
        return $this->integer($key, $default, 0);
    }

    public function positiveInt(string $key, ?int $default = null): int
    {
        return $this->integer($key, $default, 1);
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return $default ?? throw $this->required($key);
        }

        return match ($value) {
            true, 'true', '1' => true,
            false, 'false', '0' => false,
            default => throw $this->invalid($key, 'a boolean', $value),
        };
    }

    /**
     * @return list<string> empty when not set
     */
    public function stringList(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return [];
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->invalid($key, 'a list of strings', $value);
        }

        $list = [];
        foreach ($value as $index => $item) {
            if (!\is_string($item) || '' === $item) {
                throw InvalidConfigurationException::forKey(
                    $this->key($key),
                    \sprintf('item %d must be a non-empty string, got %s', $index, $this->render($item)),
                );
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * A map whose keys are all consecutive integers from 0 (e.g. `['0' => 'x']`) is indistinguishable from a list in
     * PHP and is rejected as one.
     *
     * @return array<string, string> empty when not set
     */
    public function stringMap(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return [];
        }

        if (!\is_array($value)) {
            throw $this->invalid($key, 'a map of strings to strings', $value);
        }

        if ([] !== $value && array_is_list($value)) {
            throw InvalidConfigurationException::forKey($this->key($key), 'expected a map of strings to strings, got a list');
        }

        $map = [];
        foreach ($value as $mapKey => $item) {
            // PHP turns numeric string keys into integers; they were strings in the configuration.
            $mapKey = (string) $mapKey;
            if (!\is_string($item) || '' === $item) {
                throw InvalidConfigurationException::forKey(
                    $this->key($key),
                    \sprintf('the value for "%s" must be a non-empty string, got %s', $this->sanitize($mapKey), $this->render($item)),
                );
            }

            $map[$mapKey] = $item;
        }

        return $map;
    }

    /**
     * @template T of BackedEnum
     *
     * @param class-string<T> $class a string-backed enum
     * @param T|null $default
     *
     * @return T
     */
    public function enum(string $key, string $class, ?BackedEnum $default = null): BackedEnum
    {
        $cases = $class::cases();
        $value = $this->oneOf(
            $key,
            array_map(static fn(BackedEnum $case): string => (string) $case->value, $cases),
            $default instanceof BackedEnum ? (string) $default->value : null,
        );

        foreach ($cases as $case) {
            if ((string) $case->value === $value) {
                return $case;
            }
        }

        throw new LogicException(\sprintf('oneOf() returned "%s", which is not a case of %s.', $value, $class));
    }

    /**
     * @param list<string> $allowed
     */
    public function oneOf(string $key, array $allowed, ?string $default = null): string
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return $default ?? throw $this->required($key);
        }

        if (\is_string($value) && \in_array($value, $allowed, true)) {
            return $value;
        }

        $problem = \sprintf('expected one of %s, got %s', implode(', ', $allowed), $this->render($value));
        $suggestion = \is_string($value) ? $this->suggest($value, $allowed) : null;
        if (null !== $suggestion) {
            $problem .= \sprintf('; did you mean "%s"?', $suggestion);
        }

        throw InvalidConfigurationException::forKey($this->key($key), $problem);
    }

    /**
     * Like nullableString(), but an invalid value is described by its type only.
     */
    public function secret(string $key): ?string
    {
        $value = $this->values[$key] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw InvalidConfigurationException::forKey($this->key($key), 'expected a string or null, got ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * Runs a core call that applies the value of $key and reports the core's argument errors under that key. The core
     * message and the core exception (as previous) are kept: do not guard a call that receives a secret unless the
     * core is known to leave it out of its messages.
     *
     * @template T
     *
     * @param Closure(): T $build
     *
     * @return T
     */
    public function guard(string $key, Closure $build): mixed
    {
        try {
            return $build();
        } catch (InvalidArgumentException $exception) {
            throw InvalidConfigurationException::forKey($this->key($key), $exception->getMessage(), $exception);
        }
    }

    private function integer(string $key, ?int $default, ?int $min): int
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return $default ?? throw $this->required($key);
        }

        $int = $this->toInt($value);
        if (null === $int || (null !== $min && $int < $min)) {
            throw $this->invalid($key, null === $min ? 'an integer' : \sprintf('an integer >= %d', $min), $value);
        }

        return $int;
    }

    private function toInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (!\is_string($value) || 1 !== preg_match('/^(?:0|-?[1-9]\d*)\z/', $value)) {
            return null;
        }

        // Rejects values beyond PHP_INT_MAX instead of silently saturating them.
        $int = filter_var($value, \FILTER_VALIDATE_INT);

        return \is_int($int) ? $int : null;
    }

    private function required(string $key): InvalidConfigurationException
    {
        return InvalidConfigurationException::forKey($this->key($key), 'is required');
    }

    private function invalid(string $key, string $expected, mixed $value): InvalidConfigurationException
    {
        return InvalidConfigurationException::forKey($this->key($key), \sprintf('expected %s, got %s', $expected, $this->render($value)));
    }

    /**
     * @param list<string> $candidates
     */
    private function suggest(string $value, array $candidates): ?string
    {
        $best = null;
        $bestDistance = self::MAX_SUGGESTION_DISTANCE + 1;
        foreach ($candidates as $candidate) {
            $distance = levenshtein($value, $candidate);
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function render(mixed $value): string
    {
        return match (true) {
            \is_string($value) => '"' . $this->sanitize($value) . '"',
            \is_int($value), \is_float($value), \is_bool($value) => var_export($value, true),
            default => get_debug_type($value),
        };
    }

    private function sanitize(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            // U+FFFD for each invalid sequence; mb_scrub() would depend on the global mb_substitute_character().
            $decoded = json_decode(json_encode($text, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);
            $text = \is_string($decoded) ? $decoded : '';
        }

        // Cut first, so an escape sequence is never cut in half.
        if (mb_strlen($text) > self::MAX_QUOTED_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_QUOTED_LENGTH - 1) . '…';
        }

        return preg_replace_callback(
            self::UNSAFE,
            static fn(array $match): string => self::ESCAPES[$match[0]] ?? \sprintf('\u{%04X}', mb_ord($match[0])),
            $text,
        ) ?? $text;
    }
}
