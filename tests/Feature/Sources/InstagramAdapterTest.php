<?php

namespace Tests\Feature\Sources;

use App\Enums\SourceType;
use App\Events\ItemsIngested;
use App\Http\Resources\ItemResource;
use App\Listeners\StoreRemoteImages;
use App\Models\Campaign;
use App\Models\Item;
use App\Models\Source;
use App\Sources\Adapters\InstagramAdapter;
use App\Sources\Instagram\InstagramUrl;
use App\Sources\SourceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstagramAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'curation.instagram.graph.ig_user_id' => '1784140000',
            'curation.instagram.graph.access_token' => 'graph-token',
            'curation.instagram.apify.token' => 'apify-token',
        ]);

        $campaign = Campaign::factory()->create();
        $this->enterWorkspace($campaign->workspace);
        $this->source = Source::factory()->for($campaign)->type(SourceType::Instagram, 'indianfootball')->create();
    }

    public function test_business_discovery_posts_become_items(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['business_discovery' => [
            'username' => 'indianfootball',
            'name' => 'Indian Football Team',
            'media' => ['data' => [
                [
                    'caption' => "#BlueTigers #IndianFootball\nIndia U17 squad for the Myanmar friendlies announced 🇮🇳\nMore on the-aiff.com",
                    'media_type' => 'CAROUSEL_ALBUM',
                    'permalink' => 'https://www.instagram.com/p/DAbc123xyz/',
                    'timestamp' => now()->subHours(5)->toIso8601String(),
                    'children' => ['data' => [['media_type' => 'VIDEO', 'media_url' => 'v.mp4'], ['media_type' => 'IMAGE', 'media_url' => 'https://cdn.ig/poster.jpg']]],
                ],
                [
                    'caption' => '#matchday',
                    'media_type' => 'VIDEO',
                    'thumbnail_url' => 'https://cdn.ig/thumb.jpg',
                    'permalink' => 'https://www.instagram.com/reel/DXyz987/',
                    'timestamp' => now()->subDay()->toIso8601String(),
                ],
            ]],
        ]])]);

        $items = app(InstagramAdapter::class)->fetch($this->source)->items;

        $this->assertCount(2, $items);
        $this->assertSame('India U17 squad for the Myanmar friendlies announced 🇮🇳', $items[0]->title, 'Hashtag-only lines are skipped.');
        $this->assertSame('https://cdn.ig/poster.jpg', $items[0]->media['image']);
        $this->assertSame('DAbc123xyz', $items[0]->media['shortcode']);
        $this->assertSame('instagram', $items[0]->media['embed']);
        $this->assertSame('Instagram post by @indianfootball', $items[1]->title);
        $this->assertSame('https://cdn.ig/thumb.jpg', $items[1]->media['image']);

        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'business_discovery.username(indianfootball)')
            && str_contains($request->url(), '/v25.0/1784140000'));
    }

    public function test_personal_accounts_get_a_clear_error(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid user id: not a business account']], 400)]);

        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('is not a Business or Creator account');

        app(InstagramAdapter::class)->fetch($this->source);
    }

    public function test_the_apify_driver(): void
    {
        $this->source->mergeConfig(['driver' => 'apify']);
        Http::fake(['api.apify.com/*' => Http::response([[
            'url' => 'https://www.instagram.com/p/C1apify/',
            'shortCode' => 'C1apify',
            'caption' => 'District league final this Sunday at Kochi',
            'timestamp' => now()->subHours(2)->toIso8601String(),
            'displayUrl' => 'https://cdn.ig/apify.jpg',
            'ownerFullName' => 'Kerala FA',
        ]])]);

        $item = app(InstagramAdapter::class)->fetch($this->source)->items[0];

        $this->assertSame('District league final this Sunday at Kochi', $item->title);
        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer apify-token']
            && $request['directUrls'] === ['https://www.instagram.com/indianfootball/']);
    }

    public function test_usernames_are_normalized(): void
    {
        $this->assertSame('indianfootball', InstagramUrl::username('@IndianFootball'));
        $this->assertSame('kerala.fa', InstagramUrl::username('https://www.instagram.com/kerala.fa/?hl=en'));

        $this->expectException(SourceException::class);
        InstagramUrl::username('https://www.instagram.com/p/DAbc123xyz/');
    }

    public function test_instagram_images_are_stored_and_the_api_uses_the_embed(): void
    {
        Storage::fake('public');
        Http::fake(['cdn.ig/*' => Http::response('fake-jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
        $item = Item::factory()->for($this->source)->create(['media' => ['image' => 'https://cdn.ig/poster.jpg', 'embed' => 'instagram', 'shortcode' => 'DAbc']]);

        app(StoreRemoteImages::class)->handle(new ItemsIngested($this->source, [$item->id]));

        $item->refresh();
        Storage::disk('public')->assertExists($item->media['image_path']);
        $this->assertSame('https://cdn.ig/poster.jpg', $item->media['source_image']);

        $json = (new ItemResource($item))->toArray(request());
        $this->assertNull($json['image']);
        $this->assertSame(['type' => 'instagram', 'id' => 'DAbc', 'url' => $item->url], $json['embed']);
    }
}
