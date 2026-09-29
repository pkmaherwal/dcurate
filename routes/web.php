<?php

use App\Http\Controllers\SwitchWorkspaceController;
use App\Livewire\Campaigns\Approved;
use App\Livewire\Campaigns\Index as CampaignsIndex;
use App\Livewire\Campaigns\Review;
use App\Livewire\Campaigns\Settings as CampaignSettings;
use App\Livewire\Dashboard;
use App\Livewire\Sources\Health;
use App\Livewire\Sources\Manager as SourcesManager;
use App\Livewire\Workspace\Settings as WorkspaceSettings;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('workspaces/{workspace:slug}/switch', SwitchWorkspaceController::class)
    ->middleware(['auth', 'verified'])
    ->name('workspace.switch');

Route::middleware(['auth', 'verified', 'workspace'])->group(function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');

    Route::livewire('campaigns', CampaignsIndex::class)->name('campaigns.index');
    Route::livewire('campaigns/{campaign}', Review::class)->name('campaigns.review');
    Route::livewire('campaigns/{campaign}/sources', SourcesManager::class)->name('campaigns.sources');
    Route::livewire('campaigns/{campaign}/approved', Approved::class)->name('campaigns.approved');
    Route::livewire('campaigns/{campaign}/settings', CampaignSettings::class)->name('campaigns.settings');

    Route::livewire('sources/health', Health::class)->name('sources.health');

    Route::livewire('workspace/settings', WorkspaceSettings::class)->name('workspace.settings');
});

require __DIR__.'/settings.php';
