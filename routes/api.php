<?php

use App\Http\Controllers\Api\CampaignUpdatesController;
use Illuminate\Support\Facades\Route;

/*
| Public, read-only API for websites. Authenticate with a workspace API token:
| "Authorization: Bearer <token>" (or ?token=<token> for RSS readers).
*/

Route::prefix('v1')->middleware(['workspace.token', 'throttle:api'])->name('api.')->group(function () {
    Route::get('campaigns', [CampaignUpdatesController::class, 'campaigns'])->name('campaigns.index');
    Route::get('campaigns/{slug}/updates', [CampaignUpdatesController::class, 'index'])->name('campaigns.updates');
    Route::get('campaigns/{slug}/feed.rss', [CampaignUpdatesController::class, 'rss'])->name('campaigns.feed');
});
