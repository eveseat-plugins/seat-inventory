<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace'  => 'RecursiveTree\Seat\Inventory\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale'],
    'prefix' => 'inventory',
], function () {

    Route::get('/about', [
        'as'   => 'inventory.about',
        'uses' => 'InventoryController@about',
        'middleware' => 'can:inventory.view_inventory'
    ]);



    //workspaces
    //hard coded route, as it is used in a non-blade file
    Route::get('/workspaces/list', [
        'as'   => 'inventory.listWorkspaces',
        'uses' => 'TrackingController@listWorkspaces',
        'middleware' => 'can:inventory.view_inventory'
    ]);
    //hard coded route
    Route::post('/workspaces/create', [
        'as'   => 'inventory.createWorkspace',
        'uses' => 'TrackingController@createWorkspace',
        'middleware' => 'can:inventory.create_workspace'
    ]);

    Route::post('/workspaces/edit', [
        'as'   => 'inventory.editWorkspace',
        'uses' => 'TrackingController@editWorkspace',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/workspaces/delete', [
        'as'   => 'inventory.deleteWorkspace',
        'uses' => 'TrackingController@deleteWorkspace',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    //ui routes

    Route::get('/dashboard', [
        'as'   => 'inventory.dashboard',
        'uses' => 'InventoryController@dashboard',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::get('/dashboard/categories', [
        'as'   => 'inventory.getCategories',
        'uses' => 'InventoryController@getCategories',
        'middleware' => 'can:inventory.view_inventory'
    ]);


    //locations
    //this route is hard coded, due not change url
    Route::get('/location/lookup', [
        'as'   => 'inventory.locationLookup',
        'uses' => 'InventoryController@locationLookup',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    //items
    //this route is hard coded, due not change url
    Route::get('/item/lookup', [
        'as'   => 'inventory.itemLookup',
        'uses' => 'InventoryController@itemLookup',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    // alliance-industry api routes
    Route::post('/integrations/allianceindustry', [
        'as'   => 'inventory.orderItemsAllianceIndustry',
        'uses' => 'InventoryController@orderItemsAllianceIndustry',
        'middleware' => 'can:inventory.edit_inventory'
    ]);


    //category routes

    Route::post('/categories/save', [
        'as'   => 'inventory.saveCategory',
        'uses' => 'InventoryController@saveCategory',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/categories/delete', [
        'as'   => 'inventory.deleteCategory',
        'uses' => 'InventoryController@deleteCategory',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/categories/stocks/remove', [
        'as'   => 'inventory.removeStockFromCategory',
        'uses' => 'InventoryController@removeStockFromCategory',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/categories/stocks/reorder', [
        'as'   => 'inventory.changeStockOrder',
        'uses' => 'InventoryController@changeStockOrder',
        'middleware' => 'can:inventory.edit_inventory'
    ]);



    //stock routes

    Route::post('/stocks/delete', [
        'as'   => 'inventory.deleteStock',
        'uses' => 'InventoryController@deleteStock',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    //hard coded url
    Route::get('/stocks/icon/{id}', [
        'as'   => 'inventory.stockIcon',
        'uses' => 'InventoryController@stockIcon',
        'middleware' => ['can:inventory.view_inventory','\Illuminate\Http\Middleware\SetCacheHeaders:public;max_age=604800;etag']
    ]);

    Route::post('/stocks/save', [
        'as'   => 'inventory.saveStock',
        'uses' => 'InventoryController@saveStock',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::get('/stocks/lookup', [
        'as'   => 'inventory.stockSuggestion',
        'uses' => 'InventoryController@stockSuggestion',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::post('/stocks/items/', [
        'as'   => 'inventory.exportItems',
        'uses' => 'InventoryController@exportItems',
        'middleware' => 'can:inventory.view_inventory'
    ]);



    //seat-fitting related routes
    Route::get('/doctrines/lookup', [
        'as'   => 'inventory.doctrineLookup',
        'uses' => 'InventoryController@doctrineLookup',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::get('/fittings/lookup', [
        'as'   => 'inventory.fittingsLookup',
        'uses' => 'InventoryController@fittingsLookup',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    //setting routes

    Route::get('/settings', [
        'as'   => 'inventory.settings',
        'uses' => 'TrackingController@settings',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::get('/settings/corporations/list', [
        'as'   => 'inventory.listCorporations',
        'uses' => 'TrackingController@listCorporations',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::post('/settings/corporations/add', [
        'as'   => 'inventory.addCorporation',
        'uses' => 'TrackingController@addCorporation',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/settings/corporations/remove', [
        'as'   => 'inventory.removeCorporation',
        'uses' => 'TrackingController@removeCorporation',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/settings/corporations/edit', [
        'as'   => 'inventory.editCorporation',
        'uses' => 'TrackingController@editCorporationTracking',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::get('/settings/corporations/lookup', [
        'as'   => 'inventory.corporationLookup',
        'uses' => 'TrackingController@corporationLookup',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::get('/settings/alliances/lookup', [
        'as'   => 'inventory.allianceLookup',
        'uses' => 'TrackingController@allianceLookup',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::get('/settings/alliances/list', [
        'as'   => 'inventory.listAlliances',
        'uses' => 'TrackingController@listAlliances',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::post('/settings/alliances/add', [
        'as'   => 'inventory.addAlliance',
        'uses' => 'TrackingController@addAlliance',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/settings/alliances/members/add', [
        'as'   => 'inventory.addAllianceMembers',
        'uses' => 'TrackingController@addAllianceMembers',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/settings/alliances/members/remove', [
        'as'   => 'inventory.removeAllianceMembers',
        'uses' => 'TrackingController@removeAllianceMembers',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/settings/alliances/remove', [
        'as'   => 'inventory.removeAlliance',
        'uses' => 'TrackingController@removeAlliance',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    // market settings
    Route::get('/settings/markets/list', [
        'as'   => 'inventory.listMarkets',
        'uses' => 'TrackingController@listMarkets',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::post('/settings/markets/add', [
        'as'   => 'inventory.addMarket',
        'uses' => 'TrackingController@addMarket',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/settings/markets/remove', [
        'as'   => 'inventory.removeMarket',
        'uses' => 'TrackingController@removeMarket',
        'middleware' => 'can:inventory.edit_inventory'
    ]);


    //Delivery Routes
    Route::post('/deliveries/add', [
        'as'   => 'inventory.addDeliveries',
        'uses' => 'DeliveriesController@addDeliveries',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/deliveries/list', [
        'as'   => 'inventory.listDeliveries',
        'uses' => 'DeliveriesController@listDeliveries',
        'middleware' => 'can:inventory.edit_inventory'
    ]);

    Route::post('/deliveries/remove', [
        'as'   => 'inventory.deleteDeliveries',
        'uses' => 'DeliveriesController@deleteDeliveries',
        'middleware' => 'can:inventory.edit_inventory'
    ]);


    //item browser
    Route::get('/browser', [
        'as'   => 'inventory.itemBrowser',
        'uses' => 'InventoryController@itemBrowser',
        'middleware' => 'can:inventory.view_inventory'
    ]);

    Route::post('/browser', [
        'as'   => 'inventory.itemBrowserData',
        'uses' => 'InventoryController@itemBrowserData',
        'middleware' => 'can:inventory.view_inventory'
    ]);

});