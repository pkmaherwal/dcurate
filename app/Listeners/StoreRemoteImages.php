<?php

namespace App\Listeners;

use App\Enums\SourceType;
use App\Events\ItemsIngested;
use App\Models\Item;
use App\Sources\Support\HttpClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Instagram image URLs expire within days, so keep a copy for the AI (posters) and the review screen.
 */
class StoreRemoteImages
{
    public function __construct(protected HttpClient $http)
    {
        //
    }

    public function handle(ItemsIngested $event): void
    {
        if ($event->source->type !== SourceType::Instagram) {
            return;
        }

        foreach (Item::query()->whereKey($event->itemIds)->get() as $item) {
            $url = $item->imageUrl();

            if ($url === null || data_get($item->media, 'image_path')) {
                continue;
            }

            try {
                $response = $this->http->request()->timeout(20)->get($url);
            } catch (Throwable $e) {
                Log::info('Could not store image', ['item_id' => $item->id, 'error' => $e->getMessage()]);

                continue;
            }

            if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                continue;
            }

            $extension = Str::after((string) $response->header('Content-Type'), 'image/') === 'png' ? 'png' : 'jpg';
            $path = "workspaces/{$item->workspace_id}/media/".Str::random(32).".{$extension}";

            Storage::disk('public')->put($path, $response->body());

            $item->forceFill(['media' => [
                ...($item->media ?? []),
                'image' => Storage::disk('public')->url($path),
                'image_path' => $path,
                'source_image' => $url,
            ]])->save();
        }
    }
}
