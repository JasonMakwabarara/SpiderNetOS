<?php

declare(strict_types=1);

namespace Tests\Unit\Outreach;

use App\Services\Outreach\Affonso\AffonsoMcpClient;
use PHPUnit\Framework\TestCase;

/**
 * Framing and field-mapping for the Finder MCP client. The live contract is
 * unverified until `outreach:spike finder` runs, so these pin the two shapes
 * Streamable HTTP is allowed to use and the field spellings we accept.
 */
class AffonsoMcpClientTest extends TestCase
{
    private AffonsoMcpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new AffonsoMcpClient('sk_live_test');
    }

    public function test_reads_a_plain_json_body_and_a_batch(): void
    {
        $this->assertSame([['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]]],
            $this->client->frames('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}'));

        $this->assertCount(2, $this->client->frames('[{"id":1,"result":{}},{"id":2,"result":{}}]'));
        $this->assertSame([], $this->client->frames('   '));
        $this->assertSame([], $this->client->frames('not json at all'));
    }

    public function test_reads_sse_data_frames_and_ignores_other_stream_lines(): void
    {
        $body = "event: message\r\n"
            ."data: {\"jsonrpc\":\"2.0\",\"id\":7,\"result\":{\"tools\":[]}}\r\n"
            ."\r\n"
            .": keep-alive comment\n"
            ."id: 99\n"
            ."data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n";

        $frames = $this->client->frames($body);

        $this->assertCount(2, $frames);
        $this->assertSame(7, $frames[0]['id']);
        $this->assertSame('notifications/progress', $frames[1]['method']);
    }

    public function test_finds_items_in_structured_content_text_blocks_or_a_bare_list(): void
    {
        $structured = ['structuredContent' => ['items' => [['id' => 'a'], ['id' => 'b']]]];
        $this->assertCount(2, $this->client->itemsFrom($structured));

        $textBlock = ['content' => [['type' => 'text', 'text' => '{"shortlist":[{"id":"c"}]}']]];
        $this->assertSame('c', $this->client->itemsFrom($textBlock)[0]['id']);

        $bareList = ['content' => [['type' => 'text', 'text' => '[{"id":"d"},{"id":"e"}]']]];
        $this->assertCount(2, $this->client->itemsFrom($bareList));

        $this->assertSame([], $this->client->itemsFrom(['content' => [['type' => 'text', 'text' => 'nope']]]));
    }

    public function test_maps_finder_fields_onto_the_import_row_contract(): void
    {
        $row = $this->client->toRow([
            'id' => 'itm_1',
            'opportunityName' => 'Mike Futia',
            'domain' => 'https://www.tiktok.com/@mike_futia',
            'primaryUrl' => 'https://www.tiktok.com/@mike_futia/video/1',
            'allUrls' => ['https://www.tiktok.com/@mike_futia/video/1'],
            'emails' => ['mike@example.test'],
            'status' => 'New',
            'notes' => 'posts about ad creative',
        ]);

        $this->assertSame('Mike Futia', $row['name']);
        $this->assertSame('https://www.tiktok.com/@mike_futia', $row['profile_url']);
        $this->assertSame(['mike@example.test'], $row['emails']);
        $this->assertSame('itm_1', $row['external_id']);
        $this->assertSame('New', $row['external_status']);
        $this->assertSame('posts about ad creative', $row['notes']);
    }

    public function test_falls_back_through_alternative_spellings_and_reports_nothing_usable(): void
    {
        $row = $this->client->toRow(['profile_url' => ' https://facebook.com/smexaminer ', 'email' => 'a@b.test']);
        $this->assertSame('https://facebook.com/smexaminer', $row['profile_url']);
        $this->assertSame('a@b.test', $row['emails']);
        $this->assertNull($row['name']);

        $empty = $this->client->toRow(['title' => '   ']);
        $this->assertNull($empty['profile_url']);
        $this->assertNull($empty['name']);
    }
}
