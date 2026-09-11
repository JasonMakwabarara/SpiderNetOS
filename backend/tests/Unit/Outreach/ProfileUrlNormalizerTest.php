<?php

declare(strict_types=1);

namespace Tests\Unit\Outreach;

use App\Services\Outreach\Import\ProfileUrlNormalizer;
use PHPUnit\Framework\TestCase;

class ProfileUrlNormalizerTest extends TestCase
{
    private ProfileUrlNormalizer $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->n = new ProfileUrlNormalizer;
    }

    public function test_tiktok_variants_collapse_to_one_hash(): void
    {
        $a = $this->n->normalize('https://www.tiktok.com/@Mike_Futia');
        $b = $this->n->normalize('http://m.tiktok.com/@mike_futia/?lang=en#top');
        $c = $this->n->normalize('tiktok.com/@mike_futia/');

        $this->assertSame('https://tiktok.com/@mike_futia', $a['url']);
        $this->assertSame($a['hash'], $b['hash']);
        $this->assertSame($a['hash'], $c['hash']);
        $this->assertSame('tiktok', $a['platform']);
        $this->assertSame('mike_futia', $a['handle']);
    }

    public function test_facebook_pages_groups_and_profiles(): void
    {
        $page = $this->n->normalize('https://www.facebook.com/smexaminer/');
        $group = $this->n->normalize('https://www.facebook.com/groups/1883545099044151/');
        $profile = $this->n->normalize('https://www.facebook.com/profile.php?id=123');

        $this->assertSame('facebook', $page['platform']);
        $this->assertSame('smexaminer', $page['handle']);
        $this->assertSame('1883545099044151', $group['handle']);
        $this->assertTrue($this->n->isFacebookGroup($group['url']));
        $this->assertFalse($this->n->isFacebookGroup($page['url']));
        // profile.php?id= has no stable handle in the path.
        $this->assertNull($profile['handle']);
    }

    public function test_other_hosts_and_invalid_input(): void
    {
        $this->assertSame('web', $this->n->normalize('https://example.com/about')['platform']);
        $this->assertSame('youtube', $this->n->normalize('https://youtube.com/@somechannel')['platform']);
        $this->assertSame('somechannel', $this->n->normalize('https://youtube.com/@somechannel')['handle']);
        $this->assertNull($this->n->normalize(''));
        $this->assertNull($this->n->normalize('   '));
        $this->assertNull($this->n->normalize('not a url at all'));
    }

    public function test_hash_is_sha256_of_canonical_url(): void
    {
        $r = $this->n->normalize('https://www.tiktok.com/@neilpatel');
        $this->assertSame(hash('sha256', 'https://tiktok.com/@neilpatel'), $r['hash']);
    }
}
