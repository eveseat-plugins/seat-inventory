<?php

namespace RecursiveTree\Seat\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RecursiveTree\Seat\Inventory\Helpers\LocationHelper;
use RecursiveTree\Seat\Inventory\Helpers\StockHelper;
use RecursiveTree\Seat\Inventory\Jobs\UpdateInventory;
use RecursiveTree\Seat\Inventory\Models\Location;
use RecursiveTree\Seat\Inventory\Models\TrackedAlliance;
use RecursiveTree\Seat\Inventory\Models\TrackedCorporation;
use RecursiveTree\Seat\Inventory\Models\TrackedMarket;
use RecursiveTree\Seat\Inventory\Models\Workspace;
use Seat\Eveapi\Models\Alliances\Alliance;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Market\CharacterOrder;
use Seat\Web\Http\Controllers\Controller;

class TrackingController extends Controller
{
    public function settings(){
        return view("inventory::settings");
    }

    public function listCorporations(Request $request){
        $request->validate([
            "workspace"=>"required|integer"
        ]);

        $corporations = TrackedCorporation::with(["corporation","alliance"])->where("workspace_id",$request->workspace)->get();

        return response()->json($corporations);
    }

    public function addCorporation(Request $request){
        $request->validate([
            "corporation_id"=>"required|integer",
            "workspace"=>"required|integer"
        ]);

        if(!Workspace::where("id",$request->workspace)->exists()){
            return response()->json(["message"=>"workspace doesn't exist"],400);
        }

        if(!CorporationInfo::where("corporation_id",$request->corporation_id)->exists()){
            return response()->json(["message"=>"corporation doesn't exist"],400);
        }

        if(TrackedCorporation::where("corporation_id",$request->corporation_id)
            ->where("workspace_id",$request->workspace_id)
            ->exists()
        ){
            $corp = TrackedCorporation::where("corporation_id",$request->corporation_id)
                ->where("workspace_id",$request->workspace_id)
                ->first();

            //special case: add corporation permanently
            if($corp->managed_by !== null){
                $corp->managed_by = null;
                $corp->workspace_id = $request->workspace;
                $corp->save();

                //config changed -> update items
                UpdateInventory::dispatch()->onQueue('default');

                return response()->json();
            } else {
                return response()->json(["message" => "corporation is already tracked"], 400);
            }
        }

        //save it to the db
        $db_entry = new TrackedCorporation();
        $db_entry->corporation_id = $request->corporation_id;
        $db_entry->workspace_id = $request->workspace;
        $db_entry->include_to_corporation = true;
        $db_entry->include_from_corporation = true;
        $db_entry->include_fuel_bay = true;
        $db_entry->save();

        //new corporations, new assets -> we need to update
        UpdateInventory::dispatch()->onQueue('default');

        return response()->json();
    }

    public function removeCorporation(Request $request){
        $request->validate([
            "tracking_id"=>"required|integer"
        ]);

        TrackedCorporation::destroy($request->tracking_id);

        //-1 corporation, less assets -> we need to update
        UpdateInventory::dispatch()->onQueue('default');

        return response()->json();
    }

    public function corporationLookup(Request $request){
        $request->validate([
            "term"=>"nullable|string",
            "id"=>"nullable|integer"
        ]);

        $query = CorporationInfo::query();

        if($request->term){
            $query = $query->where("name","like","%$request->term%");
        }

        if($request->id){
            $query = $query->where("id",$request->id);
        }

        $suggestions = $query->get();

        $suggestions = $suggestions
            ->map(function ($corporation){
                return [
                    'id' => $corporation->corporation_id,
                    'text' => "$corporation->name"
                ];
            });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function allianceLookup(Request $request){
        $request->validate([
            "term"=>"nullable|string",
            "id"=>"nullable|integer"
        ]);

        $query = Alliance::query();

        if($request->term){
            $query = $query->where("name","like","%$request->term%");
        }

        if($request->id){
            $query = $query->where("id",$request->id);
        }

        $suggestions = $query->get();

        $suggestions = $suggestions
            ->map(function ($alliance){
                return [
                    'id' => $alliance->alliance_id,
                    'text' => "$alliance->name"
                ];
            });

        return response()->json([
            'results'=>$suggestions
        ]);
    }

    public function addAlliance(Request $request){
        $request->validate([
            "alliance_id"=>"required|integer",
            "workspace"=>"required|integer"
        ]);

        if(!Workspace::where("id",$request->workspace)->exists()){
            return response()->json(["message"=>"workspace doesn't exist"],400);
        }

        if(!Alliance::where("alliance_id",$request->alliance_id)->exists()){
            return response()->json(["message"=>"alliance doesn't exist"],400);
        }

        if(TrackedAlliance::where("alliance_id",$request->alliance_id)
            ->where("workspace_id",$request->workspace)
            ->exists()
        ){
            return response()->json(["message"=>"alliance already trackes"],40);
        }

        $db_entry = new TrackedAlliance();
        $db_entry->alliance_id = $request->alliance_id;
        $db_entry->manage_members = false;
        $db_entry->workspace_id = $request->workspace;
        $db_entry->save();

        //config changed -> update items
        UpdateInventory::dispatch()->onQueue('default');

        return response()->json([]);
    }

    public function removeAlliance(Request $request){
        $request->validate([
            "tracking_id"=>"required|integer"
        ]);

        $alliance = TrackedAlliance::find($request->tracking_id);

        if($alliance === null){
            return response()->json(["message"=>"alliance doesn't exist"],400);
        }

        if($alliance->manange_members){
            $corporations = TrackedCorporation::where("managed_by",$request->alliance_id)->get();
            foreach ($corporations as $corporation){
                $corporation->delete();
            }
        }
        $alliance->delete();

        //config changed -> update items
        UpdateInventory::dispatch()->onQueue('default');

        return response()->json([]);
    }

    public function listAlliances(Request $request){
        $request->validate([
            "workspace"=>"required|integer"
        ]);

        $corporations = TrackedAlliance::with("alliance")->where("workspace_id",$request->workspace)->get();

        return response()->json($corporations);
    }

    public function listMarkets(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            "workspace"=>"required|integer"
        ]);

        $corporations = TrackedMarket::with("location")->where("workspace_id",$request->workspace)->get();

        return response()->json($corporations);
    }

    public function addMarket(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            "location_id"=>"required|integer",
            "workspace"=>"required|integer"
        ]);

        if(!Workspace::where("id",$request->workspace)->exists()){
            return response()->json(["message"=>"workspace doesn't exist"],400);
        }

        if(!Location::where("id",$request->location_id)->exists()){
            return response()->json(["message"=>"market doesn't exist"],400);
        }

        if(TrackedMarket::where("location_id",$request->location_id)
            ->where("workspace_id",$request->workspace)
            ->exists()
        ){
            return response()->json(["message"=>"locations already tracked"],400);
        }

        $character_id =auth()->user()->main_character_id ?? null;
        if($character_id === 0 || $character_id === null){
            return response()->json(["message"=>"User has no main character"],400);
        }

        $market = new TrackedMarket();
        $market->location_id = $request->location_id;
        $market->workspace_id = $request->workspace;
        $market->character_id = $character_id;
        $market->save();

        //config changed -> update items
        UpdateInventory::dispatch()->onQueue('default');

        return response()->json([]);
    }

    public function removeMarket(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            "tracking_id"=>"required|integer"
        ]);

        $market = TrackedMarket::find($request->tracking_id);

        if($market === null){
            return response()->json(["message"=>"market doesn't exist"],400);
        }

        $market->delete();

        //config changed -> update items
        UpdateInventory::dispatch()->onQueue('default');

        return response()->json([]);
    }

    public function addAllianceMembers(Request $request){
        $request->validate([
            "tracking_id"=>"required|integer"
        ]);

        $tracking = TrackedAlliance::find($request->tracking_id);

        if($tracking === null){
            return response()->json(["message"=>"alliance isn't tracked"],400);
        }

        $tracking->manage_members = true;
        $tracking->save();

        foreach ($tracking->alliance->members as $member){
            $corp = TrackedCorporation::where("corporation_id",$member->corporation_id)->first();
            //skip manually added corporations
            if($corp!==null && $corp->managed_by === null) continue;
            // update corporation
            if($corp === null){
                $corp = new TrackedCorporation();
                $corp->corporation_id = $member->corporation_id;
            }
            $corp->managed_by = $tracking->alliance_id;
            $corp->workspace_id = $tracking->workspace_id;
            $corp->save();
        }

        //config changed -> update items
        UpdateInventory::dispatch()->onQueue('default');

        return response()->json([]);
    }

    public function removeAllianceMembers(Request $request)
    {
        $request->validate([
            "tracking_id" => "required|integer"
        ]);

        $tracking = TrackedAlliance::find($request->tracking_id);

        if ($tracking === null) {
            return response()->json(["message" => "alliance isn't tracked"], 400);
        }

        $tracking->manage_members = false;
        $tracking->save();

        TrackedCorporation::where("managed_by",$tracking->alliance_id)->delete();

        //config changed -> update items
        UpdateInventory::dispatch()->onQueue('default');

        return response()->json([]);
    }

    public function listWorkspaces(){
        $workspaces = Workspace::all();

        return response()->json($workspaces,200);
    }

    public function createWorkspace(Request $request){
        $request->validate([
           "name"=>"required|string"
        ]);

        $workspace = new Workspace();
        $workspace->name = $request->name;
        $workspace->save();
    }

    public function deleteWorkspace(Request $request){
        $request->validate([
           'workspace'=>'required|integer'
        ]);
        Workspace::destroy($request->workspace);
        return response()->json();
    }

    public function editWorkspace(Request $request){

        $request->validate([
            "workspace"=>"required|integer",
            "name"=>"required|string",
            "enableNotifications"=>"required|boolean"
        ]);

        $workspace = Workspace::find($request->workspace);

        if(!$workspace){
            return response()->json([],400);
        }

        $workspace->name = $request->name;
        $workspace->enable_notifications = $request->enableNotifications;

        $workspace->save();

        return response()->json([99]);
    }

    public function editCorporationTracking(Request $request)
    {
        $request->validate([
            'corporation_id'=>'required|integer',
            "workspace_id"=>"required|integer",
            "include_fuel_bay"=>"required|boolean",
            "include_to_corporation"=>"required|boolean",
            "include_from_corporation"=>"required|boolean"
        ]);
        $corporation = TrackedCorporation::where("corporation_id",$request->corporation_id)
            ->where("workspace_id",$request->workspace_id)
            ->first();

        $corporation->include_fuel_bay = $request->include_fuel_bay;
        $corporation->include_to_corporation = $request->include_to_corporation;
        $corporation->include_from_corporation = $request->include_from_corporation;

        $corporation->save();

        UpdateInventory::dispatch();

        return response()->json([]);
    }
}