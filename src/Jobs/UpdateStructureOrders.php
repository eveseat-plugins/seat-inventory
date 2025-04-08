<?php

namespace RecursiveTree\Seat\Inventory\Jobs;

use DateTime;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use RecursiveTree\Seat\Inventory\Models\InventoryItem;
use RecursiveTree\Seat\Inventory\Models\InventorySource;
use RecursiveTree\Seat\Inventory\Models\Location;
use RecursiveTree\Seat\Inventory\Models\Workspace;
use RecursiveTree\Seat\TreeLib\Items\EveItem;
use Seat\Eveapi\Jobs\AbstractAuthCharacterJob;
use Seat\Eveapi\Models\RefreshToken;

/**
 * Class Orders.
 *
 * @package Seat\Eveapi\Jobs\Market
 */
class UpdateStructureOrders extends AbstractAuthCharacterJob
{
    /**
     * The page to retrieve.
     *
     * Jobs that expect paged responses should have
     * this value set.
     *
     * @var int
     */
    protected $page = 1;

    public $tries = 1;

    /**
     * @var string
     */
    protected $method = 'get';

    /**
     * @var string
     */
    protected $endpoint = '/markets/structures/{structure_id}/';

    /**
     * @var string
     */
    protected $version = 'v1';

    /**
     * @var string
     */
    protected $scope = 'esi-markets.structure_markets.v1';

    /**
     * @var array
     */
    protected $tags = ['market', 'structure', 'seat-inventory', 'sources'];

    /**
     * @var Location The structure ID to which this job is related.
     */
    protected Location $location;
    protected Workspace $workspace;

    public function __construct(RefreshToken $token, Location $location, Workspace $workspace)
    {
        $this->location = $location;
        $this->workspace = $workspace;

        parent::__construct($token);
    }

    public function middleware(): array
    {
        return array_merge(
            parent::middleware(),
            [
                (new WithoutOverlapping($this->workspace->id))->releaseAfter(60),
            ]
        );
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(15);
    }

    /**
     * Execute the job.
     *
     * @throws \Throwable
     */
    public function handle()
    {
        DB::transaction(function (){
           $this->updateData();
        });
    }


    public function updateData(): void
    {
        if($this->location->structure_id === null) return;
        $item_list = [];

        //load all market data
        while (true) {
            //retrieve one page of market orders
            $response = $this->retrieve(['structure_id' => $this->location->structure_id]);
            $orders = $response->getBody();

            // map the ESI format to the database format
            // if the batch size is increased to 1000, it crashed
            collect($orders)
                ->chunk(100)
                ->each(function ($chunk) use (&$item_list) {
                    foreach ($chunk as $order){
                        if($order->is_buy_order === false){
                            $item_list[] = EveItem::fromTypeID($order->type_id,['amount'=>$order->volume_remain]);
                        }
                    }
                });

            // if there are more pages with orders, continue loading them
            if (! $this->nextPage($response->getPagesCount())) break;
        }

        $source = InventorySource::where("workspace_id", $this->workspace->id)
            ->where("location_id", $this->location->id)
            ->where("source_type","market")
            ->first();

        if($source === null){
            $source = new InventorySource();
            $source->location_id = $this->location->id;
            $source->source_name = sprintf("Market: %s",$this->location->name);
            $source->source_type = "market";
            $source->workspace_id = $this->workspace->id;
            $source->save();
        }

        $item_list = collect($item_list)->simplifyItems();
        foreach ($item_list as $item){
            $type_model = $source->items()
                ->where("type_id",$item->typeModel->typeID)
                ->first();

            if($type_model == null){
                $type_model = new InventoryItem();
                $type_model->source_id = $source->id;
                $type_model->type_id = $item->typeModel->typeID;
            }

            $type_model->amount = $item->amount;
            $type_model->save();
        }

        $type_ids = $item_list->map(function ($item){
            return $item->typeModel->typeID;
        });

        InventoryItem::where("source_id", $source->id)
            ->whereNotIn("type_id", $type_ids)
            ->delete();

        $source->last_updated = now();
        $source->save();
    }
}
