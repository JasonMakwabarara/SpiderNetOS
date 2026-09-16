<?php

declare(strict_types=1);

namespace App\Services\Skills;

/**
 * A small JSON Schema (draft-07 subset) validator in plain PHP — there is no
 * schema library in vendor/ and none may be added. Covers what
 * packages/skills/_schema/skill-card.schema.json and the cards' own output
 * schemas use: type (incl. type lists), const, enum, required, properties,
 * additionalProperties (false or a schema), items, minItems/maxItems,
 * uniqueItems, minLength/maxLength, pattern, minimum/maximum, $ref (local
 * "#/definitions/..." pointers), allOf/anyOf/oneOf and if/then/else.
 *
 * Errors are "<json path>: <message>" strings so a command can print them
 * and a test can assert on them.
 */
final class SkillSchemaValidator
{
    /** @param  array<string, mixed>  $root */
    public function __construct(private readonly array $root) {}

    public static function fromFile(string $path): self
    {
        if (! is_readable($path)) {
            throw new \RuntimeException("Schema not readable: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("Schema is not valid JSON: {$path}");
        }

        return new self($decoded);
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return $this->root;
    }

    /**
     * @param  array<string, mixed>|null  $schema  defaults to the root schema
     * @return list<string> empty when valid
     */
    public function validate(mixed $data, ?array $schema = null, string $path = '$'): array
    {
        $schema ??= $this->root;
        $errors = [];

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $resolved = $this->resolveRef($schema['$ref']);
            $merged = $resolved + array_diff_key($schema, ['$ref' => true]);

            return $this->validate($data, $merged, $path);
        }

        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $errors[] = "{$path}: must equal ".json_encode($schema['const']);
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = "{$path}: must be one of ".implode(', ', array_map(fn ($v) => json_encode($v), $schema['enum']));
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $matched = false;
            foreach ($types as $type) {
                if ($this->isType($data, (string) $type)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $errors[] = "{$path}: expected type ".implode('|', $types).', got '.$this->describe($data);

                return $errors; // further keyword checks assume the type
            }
        }

        if (is_string($data)) {
            $len = mb_strlen($data);
            if (isset($schema['minLength']) && $len < (int) $schema['minLength']) {
                $errors[] = "{$path}: must be at least {$schema['minLength']} characters (is {$len})";
            }
            if (isset($schema['maxLength']) && $len > (int) $schema['maxLength']) {
                $errors[] = "{$path}: must be at most {$schema['maxLength']} characters (is {$len})";
            }
            if (isset($schema['pattern']) && is_string($schema['pattern'])) {
                $regex = '~'.str_replace('~', '\~', $schema['pattern']).'~u';
                if (@preg_match($regex, $data) !== 1) {
                    $errors[] = "{$path}: does not match pattern {$schema['pattern']}";
                }
            }
        }

        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = "{$path}: must be >= {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = "{$path}: must be <= {$schema['maximum']}";
            }
        }

        if (is_array($data) && $this->isList($data) && $this->wantsList($schema, $data)) {
            $count = count($data);
            if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
                $errors[] = "{$path}: must have at least {$schema['minItems']} items (has {$count})";
            }
            if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
                $errors[] = "{$path}: must have at most {$schema['maxItems']} items (has {$count})";
            }
            if (! empty($schema['uniqueItems'])) {
                $seen = [];
                foreach ($data as $i => $item) {
                    $key = json_encode($item);
                    if (isset($seen[$key])) {
                        $errors[] = "{$path}[{$i}]: duplicate item";
                    }
                    $seen[$key] = true;
                }
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($data as $i => $item) {
                    $errors = array_merge($errors, $this->validate($item, $schema['items'], "{$path}[{$i}]"));
                }
            }
        }

        if (is_array($data) && ! $this->wantsList($schema, $data)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $data)) {
                    $errors[] = "{$path}: missing required key \"{$required}\"";
                }
            }
            foreach ($properties as $name => $propertySchema) {
                if (array_key_exists($name, $data) && is_array($propertySchema)) {
                    $errors = array_merge($errors, $this->validate($data[$name], $propertySchema, "{$path}.{$name}"));
                }
            }
            $additional = $schema['additionalProperties'] ?? true;
            if ($additional !== true) {
                foreach ($data as $name => $value) {
                    if (array_key_exists($name, $properties)) {
                        continue;
                    }
                    if ($additional === false) {
                        $errors[] = "{$path}: unknown key \"{$name}\"";
                    } elseif (is_array($additional)) {
                        $errors = array_merge($errors, $this->validate($value, $additional, "{$path}.{$name}"));
                    }
                }
            }
        }

        foreach ((array) ($schema['allOf'] ?? []) as $sub) {
            if (is_array($sub)) {
                $errors = array_merge($errors, $this->validate($data, $sub, $path));
            }
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $passed = false;
            foreach ($schema['anyOf'] as $sub) {
                if (is_array($sub) && $this->validate($data, $sub, $path) === []) {
                    $passed = true;
                    break;
                }
            }
            if (! $passed) {
                $errors[] = "{$path}: matches none of the anyOf alternatives";
            }
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $passes = 0;
            foreach ($schema['oneOf'] as $sub) {
                if (is_array($sub) && $this->validate($data, $sub, $path) === []) {
                    $passes++;
                }
            }
            if ($passes !== 1) {
                $errors[] = "{$path}: must match exactly one oneOf alternative (matched {$passes})";
            }
        }

        if (isset($schema['if']) && is_array($schema['if'])) {
            $branch = $this->validate($data, $schema['if'], $path) === [] ? 'then' : 'else';
            if (isset($schema[$branch]) && is_array($schema[$branch])) {
                $errors = array_merge($errors, $this->validate($data, $schema[$branch], $path));
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    private function resolveRef(string $ref): array
    {
        if (! str_starts_with($ref, '#/')) {
            throw new \RuntimeException("Only local \$ref pointers are supported: {$ref}");
        }
        $node = $this->root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new \RuntimeException("Unresolvable \$ref: {$ref}");
            }
            $node = $node[$segment];
        }

        return is_array($node) ? $node : [];
    }

    private function isType(mixed $data, string $type): bool
    {
        return match ($type) {
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            'array' => is_array($data) && ($data === [] || $this->isList($data)),
            'object' => is_array($data) && ($data === [] || ! $this->isList($data)),
            default => false,
        };
    }

    /** True when the schema (or the value) says this array is a JSON array, not an object. */
    private function wantsList(array $schema, array $data): bool
    {
        $types = (array) ($schema['type'] ?? []);
        if (in_array('array', $types, true) && ! in_array('object', $types, true)) {
            return true;
        }
        if (in_array('object', $types, true) && ! in_array('array', $types, true)) {
            return false;
        }
        if (isset($schema['items']) || isset($schema['minItems']) || isset($schema['maxItems'])) {
            return true;
        }
        if (isset($schema['properties']) || isset($schema['required'])) {
            return false;
        }

        return $data !== [] && $this->isList($data);
    }

    private function isList(array $data): bool
    {
        return array_is_list($data);
    }

    private function describe(mixed $data): string
    {
        return match (true) {
            $data === null => 'null',
            is_bool($data) => 'boolean',
            is_int($data) => 'integer',
            is_float($data) => 'number',
            is_string($data) => 'string',
            is_array($data) => $this->isList($data) ? 'array' : 'object',
            default => gettype($data),
        };
    }
}
