<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Move saved workspace AI settings onto the remaining models and drop the pre-filter and fallback keys.
     */
    public function up(): void
    {
        DB::table('workspaces')->whereNotNull('settings')->orderBy('id')->each(function (object $workspace) {
            $settings = json_decode($workspace->settings, true) ?: [];
            $ai = $settings['ai'] ?? null;

            if (! is_array($ai)) {
                return;
            }

            unset($ai['prefilter_model'], $ai['fallback_provider'], $ai['fallback_model']);

            if (isset($ai['model'])) {
                $ai['model'] = ($ai['provider'] ?? 'openai') === 'gemini' ? 'gemini-3.1-flash-lite' : 'gpt-6-luna';
            }

            if (isset($ai['web_search_model'])) {
                $ai['web_search_model'] = 'gpt-6-luna';
            }

            $settings['ai'] = $ai;

            DB::table('workspaces')->where('id', $workspace->id)->update(['settings' => json_encode($settings)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
