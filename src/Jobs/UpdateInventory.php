<?php

namespace RecursiveTree\Seat\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RecursiveTree\Seat\Inventory\Models\InventorySource;
use RecursiveTree\Seat\Inventory\Models\Workspace;

class UpdateInventory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function tags()
    {
        return ["seat-inventory", "sources"];
    }

    public function handle()
    {
        $workspaces = Workspace::all();
        foreach ($workspaces as $workspace){
            $this->handleWorkspace($workspace);
        }
    }

    public function handleWorkspace(Workspace $workspace): void
    {
        UpdateCorporationAssets::dispatch($workspace);
        UpdateContracts::dispatch($workspace);

        foreach ($workspace->markets as $market) {
            UpdateStructureOrders::dispatch($market->character->refresh_token, $market->location,$workspace);
        }
        $allowed = $workspace->markets->pluck('location_id');
        $sources = InventorySource::where('workspace_id', $workspace->id)
            ->whereNotIn('location_id',$allowed)
            ->where('source_type','market')
            ->get();
        foreach ($sources as $source){
            $source->items()->delete();
            $source->delete();
        }
    }
}