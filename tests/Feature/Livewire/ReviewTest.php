<?php

namespace Tests\Feature\Livewire;

use App\Enums\ItemStatus;
use App\Enums\WorkspaceRole;
use App\Livewire\Campaigns\Review;
use App\Models\Campaign;
use App\Models\Item;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;

    protected User $reviewer;

    protected Campaign $campaign;

    protected Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->reviewer] = $this->workspaceWithUser(WorkspaceRole::Reviewer);
        $this->campaign = Campaign::factory()->for($this->workspace)->create();
        $this->source = Source::factory()->for($this->campaign)->create();
        $this->actingInWorkspace($this->reviewer, $this->workspace);
    }

    public function test_reviewers_approve_and_reject_items(): void
    {
        [$first, $second] = Item::factory()->for($this->source)->count(2)->create();

        Livewire::test(Review::class, ['campaign' => $this->campaign])
            ->assertSee($first->title)
            ->call('approve', $first->id)
            ->call('reject', $second->id, 'Not relevant')
            ->assertDontSee($first->title);

        $this->assertSame(ItemStatus::Approved, $first->fresh()->status);
        $this->assertSame($this->reviewer->id, $first->fresh()->reviewed_by);
        $this->assertSame(ItemStatus::Rejected, $second->fresh()->status);
        $this->assertSame('Not relevant', $second->fresh()->reject_reason);
    }

    public function test_edit_then_approve_keeps_the_reviewers_wording(): void
    {
        $item = Item::factory()->for($this->source)->enriched()->create();

        Livewire::test(Review::class, ['campaign' => $this->campaign])
            ->call('startEdit', $item->id)
            ->set('editTitle', 'Better headline')
            ->set('editSummary', 'Short summary.')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame(ItemStatus::Approved, $item->status);
        $this->assertSame('Better headline', $item->displayTitle());
        $this->assertSame('Short summary.', $item->displaySummary());
    }

    public function test_low_relevance_items_have_their_own_tab(): void
    {
        $relevant = Item::factory()->for($this->source)->enriched(85)->create();
        $noise = Item::factory()->for($this->source)->enriched(10)->create();

        Livewire::test(Review::class, ['campaign' => $this->campaign])
            ->assertSee($relevant->ai_title)
            ->assertDontSee($noise->ai_title)
            ->set('tab', 'low')
            ->assertSee($noise->ai_title)
            ->assertDontSee($relevant->ai_title);
    }

    public function test_reviewing_a_story_settles_its_duplicates_and_undo_restores_them(): void
    {
        $lead = Item::factory()->for($this->source)->create();
        $lead->update(['cluster_id' => $lead->id]);
        $copy = Item::factory()->for($this->source)->create(['cluster_id' => $lead->id]);

        $component = Livewire::test(Review::class, ['campaign' => $this->campaign])
            ->assertDontSee($copy->title, false);

        $component->call('approve', $lead->id);
        $this->assertSame(ItemStatus::Rejected, $copy->fresh()->status);
        $this->assertSame('Duplicate of #'.$lead->id, $copy->fresh()->reject_reason);

        $lead->fresh()->resetReview();
        $this->assertSame(ItemStatus::Pending, $copy->fresh()->status);
    }

    public function test_bulk_actions(): void
    {
        $items = Item::factory()->for($this->source)->count(3)->create();

        Livewire::test(Review::class, ['campaign' => $this->campaign])
            ->call('toggleSelectPage')
            ->assertCount('selected', 3)
            ->call('bulk', 'approve');

        $this->assertSame(3, Item::where('status', ItemStatus::Approved->value)->count());
        $this->assertTrue($items->first()->fresh()->status === ItemStatus::Approved);
    }

    public function test_items_of_other_campaigns_cannot_be_reviewed(): void
    {
        $foreign = Item::factory()->create();

        Livewire::test(Review::class, ['campaign' => $this->campaign])
            ->call('approve', $foreign->id)
            ->assertNotFound();
    }
}
