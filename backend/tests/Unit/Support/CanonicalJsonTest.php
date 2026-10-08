<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

/**
 * Postgres `jsonb` returns an object's keys in its own order; SQLite and PHP
 * keep insertion order. A version hash has to be the same either way, or it
 * passes every local test and mismatches in production. Order inside a list
 * is content, and must still count.
 */
class CanonicalJsonTest extends TestCase
{
    public function test_object_key_order_does_not_change_the_hash_at_any_depth(): void
    {
        $a = ['steps' => [['n' => 1, 'subject' => 'S', 'variants' => [['key' => 'a', 'body' => 'B']]]], 'kind' => 'draft_sequence'];
        $b = ['kind' => 'draft_sequence', 'steps' => [['variants' => [['body' => 'B', 'key' => 'a']], 'subject' => 'S', 'n' => 1]]];

        $this->assertSame(CanonicalJson::hash($a), CanonicalJson::hash($b));
    }

    public function test_list_order_is_content(): void
    {
        $this->assertNotSame(
            CanonicalJson::hash(['steps' => [['n' => 1], ['n' => 2]]]),
            CanonicalJson::hash(['steps' => [['n' => 2], ['n' => 1]]]),
        );
    }

    public function test_a_value_change_changes_the_hash_and_types_are_kept(): void
    {
        $this->assertNotSame(CanonicalJson::hash(['body' => 'x']), CanonicalJson::hash(['body' => 'y']));
        $this->assertNotSame(CanonicalJson::hash(['delay' => 1]), CanonicalJson::hash(['delay' => '1']));
        $this->assertNotSame(CanonicalJson::hash(['delay' => null]), CanonicalJson::hash(['delay' => 0]));
    }

    public function test_the_hash_names_its_algorithm(): void
    {
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', CanonicalJson::hash(['a' => 1]));
    }
}
