<?php

namespace RecursiveTree\Seat\Inventory\Jobs;

use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RecursiveTree\Seat\Inventory\Helpers\ContractHelper;
use RecursiveTree\Seat\Inventory\Helpers\ItemHelper;
use RecursiveTree\Seat\Inventory\Models\InventoryItem;
use RecursiveTree\Seat\Inventory\Models\InventorySource;
use RecursiveTree\Seat\Inventory\Models\Location;
use RecursiveTree\Seat\Inventory\Models\Stock;
use RecursiveTree\Seat\Inventory\Models\TrackedAlliance;
use RecursiveTree\Seat\Inventory\Models\TrackedCorporation;
use RecursiveTree\Seat\Inventory\Models\Workspace;
use Seat\Eveapi\Models\Assets\CorporationAsset;
use Seat\Eveapi\Models\Contracts\ContractDetail;
use Seat\Eveapi\Models\Universe\UniverseStation;
use Seat\Eveapi\Models\Universe\UniverseStructure;

class UpdateContracts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(15);
    }

    private static array $ITEM_BLACKLIST = [
        27 //Corporation Hangar Office
    ];

    protected Workspace $workspace;

    /**
     * @param Workspace $workspace
     */
    public function __construct(Workspace $workspace)
    {
        $this->workspace = $workspace;
    }

    public function tags(): array
    {
        return ["seat-inventory", "contracts", "sources"];
    }

    public function middleware(): array
    {
        return array_merge(
            [
               (new WithoutOverlapping($this->workspace->id))->releaseAfter(60),
            ]
        );
    }


    public function handle()
    {
        $corporations = TrackedCorporation::where("workspace_id",$this->workspace->id)->get();
        $alliances = TrackedAlliance::where("workspace_id",$this->workspace->id)->get();

        DB::transaction(function () use ($alliances, $corporations) {
            $this->handleContracts($corporations, $alliances, $this->workspace->id);
        });

        $ids = Stock::pluck("location_id")->unique();
        foreach ($ids as $id) {
            UpdateStockLevels::dispatch($id, $this->workspace->id)->onQueue('default');
        }
    }

    private function handleContracts($corporations, $alliances, $workspace_id)
    {
        $corporation_ids = $corporations->pluck("corporation_id");
        $alliance_ids = $alliances->pluck("alliance_id");

        $sources = InventorySource::where("source_type","contract")
            ->where("workspace_id", $workspace_id)
            ->get();
        foreach ($sources as $source){
            $source->delete();
            InventoryItem::where("source_id", $source->id)->delete();
        }

        //get the time from around when the query is triggered, so in case the db updates assets in between, we only guarantee the old state
        $time = carbon();

        $contracts = ContractDetail::where("type", "item_exchange")
            ->where("status", "outstanding")
            ->whereDate("date_expired",">",$time)
            ->where(function (Builder $query) use ($corporations, $alliance_ids) {
                $query->whereIn("assignee_id", $alliance_ids);

                foreach ($corporations as $corporation){
                    if($corporation->include_to_corporation) {
                        $query->orWhere("assignee_id", $corporation->corporation_id);
                    }
                    if($corporation->include_from_corporation) {
                        $query->orWhere(function(Builder $query) use ($corporation) {
                            $query
                                ->where("issuer_corporation_id", $corporation->corporation_id)
                                ->where("availability","public");
                        });
                    }
                }
            })
            ->get();

        foreach ($contracts as $contract) {
            $items = [];

            foreach ($contract->lines as $item) {
                $items[] = new ItemHelper($item->type_id, $item->quantity);
            }

            $station_id = $contract->end_location->station_id;
            $structure_id = $contract->end_location->structure_id;

            $location = $this->getOrCreateLocation($station_id, $structure_id);

            if ($contract->title != "") {
                $name = $contract->title;
            } else {
                $name = ContractHelper::getDescriptiveContractName($contract);
            }

            $source = new InventorySource();
            $source->location_id = $location->id;
            $source->source_name = $name;
            $source->source_type = "contract";
            $source->last_updated = $time;
            $source->workspace_id = $workspace_id;
            $source->save();

            $simplified = ItemHelper::simplifyItemList($items);

            $source_id = $source->id;
            foreach ($simplified as $item_helper) {
                $item = $item_helper->asSourceItem();
                $item->source_id = $source_id;
                $item->save();
            }
        }
    }

    private function handleAssetItem($item, &$list,$location, $handle_ship, $workspace_id)
    {
        //check for ships
        if($item->type->group->categoryID === 6 && $item->is_singleton && $handle_ship){
            //it is an assembled ship, handle it differently
            $this->handleAssembledShip($item,$location, $workspace_id);
        } else {

            $list[] = new ItemHelper($item->type_id, $item->quantity);

            //chunking bugs it out, don't optimize it
            foreach ($item->content as $content) {
                $this->handleAssetItem($content, $list, $location, true, $workspace_id);
            }
        }
    }

    private function handleAssembledShip($ship,$location, $workspace_id){
        $source = new InventorySource();
        $source->source_type = "fitted_ship";
        $source->location_id = $location;
        $ship_type_name = $ship->type->typeName;
        $source->source_name = "$ship->name($ship_type_name)";
        $source->workspace_id = $workspace_id;
        $source->save();

        $item_list = [];
        $this->handleAssetItem($ship,$item_list,$location,false, $workspace_id);
        $item_list = ItemHelper::simplifyItemList($item_list);
        $bulk = ItemHelper::prepareBulkInsertionSourceItems($item_list,$source->id);

        InventoryItem::insert($bulk);
    }

    private function createLocation($location_id): Location
    {
        $structure = UniverseStructure::find($location_id);
        $station = UniverseStation::find($location_id);

        if ($structure) {
            $location = new Location();
            $location->structure_id = $location_id;
            $location->name = $structure->name;
        } else if ($station) {
            $location = new Location();
            $location->station_id = $location_id;
            $location->name = $station->name;
        } else {
            $location = new Location();
            $location->station_id = $location_id;
            $location->structure_id = $location_id;
            $location->name = "Unknown Location";
        }

        $location->save();

        return $location;
    }

    private function getOrCreateLocation($station_id, $structure_id)
    {
        $location = null;

        //search location
        if ($station_id != null) {
            $t = Location::where("station_id", $station_id)->first();
            if ($t != null) {
                $location = $t;
            }
        }

        if ($structure_id != null) {
            $t = Location::where("structure_id", $structure_id)->first();
            if ($t != null) {
                $location = $t;
            }
        }

        //for some reason, the destination is not in the database
        if ($location == null) {
            if ($station_id) {
                $location = $this->createLocation($station_id);
            } else {
                $location = $this->createLocation($structure_id);
            }
        }

        return $location;
    }
}