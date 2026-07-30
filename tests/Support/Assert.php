<?php

declare(strict_types=1);

namespace Tests\Support;

final class Assert
{
    public static function true(bool $condition, string $message = 'Expected true.'): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $prefix = $message !== '' ? $message . ' ' : '';
            throw new \RuntimeException(
                $prefix . 'Failed asserting that ' . self::export($actual) . ' is identical to ' . self::export($expected) . '.'
            );
        }
    }

    public static function notSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            $prefix = $message !== '' ? $message . ' ' : '';
            throw new \RuntimeException(
                $prefix . 'Failed asserting that ' . self::export($actual) . ' is not identical to ' . self::export($unexpected) . '.'
            );
        }
    }

    /**
     * @param callable(): mixed $callback
     * @param class-string<\Throwable> $class
     */
    public static function throws(string $class, callable $callback, string $message = ''): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new \RuntimeException(
                    ($message !== '' ? $message . ' ' : '') .
                    'Expected ' . $class . ' but got ' . $e::class . ': ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            return $e;
        }

        throw new \RuntimeException(
            ($message !== '' ? $message . ' ' : '') . 'Expected ' . $class . ' was not thrown.'
        );
    }

    /**
     * @param list<string> $haystack
     */
    public static function contains(string $needle, array $haystack, string $message = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            $prefix = $message !== '' ? $message . ' ' : '';
            throw new \RuntimeException(
                $prefix . 'Failed asserting that list contains ' . self::export($needle) . '.'
            );
        }
    }

    /**
     * @param list<string> $expectedNames
     * @param list<object> $events
     */
    public static function eventNames(array $expectedNames, array $events, string $message = ''): void
    {
        $actual = [];
        foreach ($events as $event) {
            if (!is_object($event) || !method_exists($event, 'eventName')) {
                throw new \RuntimeException('Event missing eventName().');
            }
            $actual[] = $event->eventName();
        }
        self::same($expectedNames, $actual, $message !== '' ? $message : 'Event names mismatch.');
    }

    private static function export(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return is_object($value) ? $value::class : gettype($value);
    }
}
