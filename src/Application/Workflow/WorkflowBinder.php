<?php

declare(strict_types=1);

namespace Aep\Application\Workflow;

/**
 * Binds workflow parameters and interpolates ${params.*} references.
 */
final class WorkflowBinder
{
    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function bindParameters(WorkflowDefinition $definition, array $attributes): array
    {
        $bound = [];
        foreach ($definition->parameters() as $parameter) {
            if (array_key_exists($parameter->name(), $attributes)) {
                $bound[$parameter->name()] = $attributes[$parameter->name()];
                continue;
            }
            if ($parameter->default() !== null) {
                $bound[$parameter->name()] = $parameter->default();
                continue;
            }
            if ($parameter->required()) {
                throw new \InvalidArgumentException('Missing required workflow parameter: ' . $parameter->name());
            }
        }

        return $bound;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function resolveMap(array $value, array $params): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->resolveValue($item, $params);
        }

        return $out;
    }

    public function resolveValue(mixed $value, array $params): mixed
    {
        if (is_string($value)) {
            return $this->interpolate($value, $params);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->resolveValue($v, $params);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function interpolate(string $value, array $params): mixed
    {
        if (preg_match('/^\$\{params\.([A-Za-z0-9_]+)\}$/', $value, $m) === 1) {
            $name = $m[1];
            if (!array_key_exists($name, $params)) {
                throw new \InvalidArgumentException('Unknown parameter reference: ' . $value);
            }

            return $params[$name];
        }

        return preg_replace_callback(
            '/\$\{params\.([A-Za-z0-9_]+)\}/',
            static function (array $m) use ($params): string {
                $name = $m[1];
                if (!array_key_exists($name, $params)) {
                    throw new \InvalidArgumentException('Unknown parameter reference: ${params.' . $name . '}');
                }
                $v = $params[$name];
                if (is_bool($v)) {
                    return $v ? 'true' : 'false';
                }
                if (is_scalar($v)) {
                    return (string) $v;
                }
                throw new \InvalidArgumentException('Parameter ${params.' . $name . '} cannot interpolate into string.');
            },
            $value
        ) ?? $value;
    }

    /**
     * @param array<string, mixed> $when
     * @param array<string, mixed> $params
     */
    public function evaluateWhen(array $when, array $params): bool
    {
        if (isset($when['eq']) && is_array($when['eq']) && count($when['eq']) === 2) {
            $left = $this->resolveValue($when['eq'][0], $params);
            $right = $this->resolveValue($when['eq'][1], $params);

            return $left === $right;
        }
        if (isset($when['neq']) && is_array($when['neq']) && count($when['neq']) === 2) {
            $left = $this->resolveValue($when['neq'][0], $params);
            $right = $this->resolveValue($when['neq'][1], $params);

            return $left !== $right;
        }
        if (isset($when['truthy'])) {
            return (bool) $this->resolveValue($when['truthy'], $params);
        }
        if (isset($when['falsy'])) {
            return !(bool) $this->resolveValue($when['falsy'], $params);
        }

        throw new \InvalidArgumentException('Unsupported when expression.');
    }
}
