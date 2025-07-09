@extends('web::layouts.grids.12')

@section('title', trans('inventory::settings.settings_title'))
@section('page_header', trans('inventory::settings.settings_title'))


@section('full')
    <div id="main"></div>
@stop

@push('javascript')

    <script>const CSRF_TOKEN = '{{ csrf_token() }}'</script>
    <script src="@inventoryVersionedAsset('inventory/js/utils.js')"></script>
    <script src="@inventoryVersionedAsset('inventory/js/w2.js')"></script>
    <script src="@inventoryVersionedAsset('inventory/js/select2w2.js')"></script>
    <script src="@inventoryVersionedAsset('inventory/js/bootstrapW2.js')"></script>
    <script src="@inventoryVersionedAsset('inventory/js/components.js')"></script>


    <script>

        function confirmButtonComponent(text, callback) {
            const state = {
                firstStep: true
            }
            return W2.mount(state, (container, mount, state) => {
                if (state.firstStep) {
                    container.content(
                        W2.html("button")
                            .class("btn btn-danger")
                            .content(text)
                            .event("click", () => {
                                state.firstStep = false
                                mount.update()
                            })
                    )
                } else {
                    container.content(
                        W2.html("div")
                            .class("btn-group")
                            .content(
                                W2.html("button")
                                    .class("btn btn-primary")
                                    .content({!!json_encode(trans('inventory::common.cancel_btn'))!!})
                                    .event("click", () => {
                                        state.firstStep = true
                                        mount.update()
                                    })
                            )
                            .content(
                                W2.html("button")
                                    .class("btn btn-warning")
                                    .content({!!json_encode(trans('inventory::common.confirm_btn'))!!})
                                    .event("click", () => {
                                        callback()
                                        state.firstStep = true
                                        mount.update()
                                    })
                            )
                    )
                }
            })
        }

        function editCorpTracking(corp) {
            const state = {
                include_fuel_bay: corp.include_fuel_bay > 0,
                include_to_corporation: corp.include_to_corporation > 0,
                include_from_corporation: corp.include_from_corporation > 0
            }

            BootstrapPopUp.open("Corporation Settings", (container, popup)=>{
                container.content(
                    W2.html("div")
                        .class("form-group")
                        .content(
                        W2.html("div")
                            .class("custom-control custom-switch")
                            .content(
                                W2.html("input")
                                    .attribute("type","checkbox")
                                    .id(W2.getID("editCorpTracking.fuelbay", true))
                                    .class("custom-control-input")
                                    .attributeIf(corp.include_fuel_bay,"checked","checked")
                                    .event("change",()=>{
                                        state.include_fuel_bay = !state.include_fuel_bay
                                    }),
                                W2.html("label")
                                    .attribute("for", W2.getID("editCorpTracking.fuelbay"))
                                    .class("custom-control-label")
                                    .content("Include Citadel Fuel Bay")
                            )
                        ),
                    W2.html("div")
                        .class("form-group")
                        .content(
                            W2.html("div")
                                .class("custom-control custom-switch")
                                .content(
                                    W2.html("input")
                                        .attribute("type","checkbox")
                                        .id(W2.getID("editCorpTracking.fromcorp", true))
                                        .class("custom-control-input")
                                        .attributeIf(corp.include_to_corporation,"checked","checked")
                                        .event("change",()=>{
                                            state.include_to_corporation = !state.include_to_corporation
                                        }),
                                    W2.html("label")
                                        .attribute("for", W2.getID("editCorpTracking.fromcorp"))
                                        .class("custom-control-label")
                                        .content("Include contracts assigned to corporation")
                                )
                        ),
                    W2.html("div")
                        .class("form-group")
                        .content(
                            W2.html("div")
                                .class("custom-control custom-switch")
                                .content(
                                    W2.html("input")
                                        .attribute("type","checkbox")
                                        .id(W2.getID("editCorpTracking.tocorp", true))
                                        .class("custom-control-input")
                                        .attributeIf(corp.include_from_corporation,"checked","checked")
                                        .event("change",()=>{
                                            state.include_from_corporation = !state.include_from_corporation
                                        }),
                                    W2.html("label")
                                        .attribute("for", W2.getID("editCorpTracking.tocorp"))
                                        .class("custom-control-label")
                                        .content("Include public contracts with corporation as issuer")
                                )
                        ),
                    W2.html("button")
                        .class("btn btn-success")
                        .content("Update")
                        .event("click",async ()=>{
                            const response = await jsonPostAction("{{ route("inventory.editCorporation") }}", {
                                corporation_id: corp.corporation_id,
                                workspace_id: appState.currentWorkspace.id,
                                include_fuel_bay: state.include_fuel_bay,
                                include_to_corporation: state.include_to_corporation,
                                include_from_corporation: state.include_from_corporation,
                            })
                            if(!response.ok){
                                BoostrapToast.open("Error","Failed to update corporation tracking")
                            } else {
                                popup.close()
                                await fetchData()
                                mount.update()
                            }
                        })
                )
            })
        }

        const appState = {
            corporations: [],
            alliances: [],
            corporationSelector: null,
            allianceSelector: null,
            marketSelector: null,
            markets: [],
            currentWorkspace: null,
            newWorkspaceName: null,
            newEnableNotifications: null
        }


        async function fetchData() {
            if (appState.currentWorkspace) {
                const workspaceId = appState.currentWorkspace.id
                let response = await fetch(`{{ route("inventory.listCorporations") }}?workspace=${workspaceId}`)
                appState.corporations = await response.json()
                response = await fetch(`{{ route("inventory.listAlliances") }}?workspace=${workspaceId}`)
                appState.alliances = await response.json()
                response = await fetch(`{{ route("inventory.listMarkets") }}?workspace=${workspaceId}`)
                appState.markets = await response.json()
            }
        }

        const mount = W2.mount(appState, (container, mount, state) => {
            const hasWorkspace = state.currentWorkspace !== null

            //workspace settings
            container.contentIf(hasWorkspace, W2.html("div")
                .class("card")
                .content(
                    //title header
                    W2.html("div")
                        .class("card-header")
                        .content(
                            W2.html("h3")
                                .class("cart-title")
                                .content({!!json_encode(trans('inventory::settings.workspace_settings_title'))!!})
                        ),
                    //card body
                    W2.html("div")
                        .class("card-body")
                        .content(
                            //name
                            W2.html("div")
                                .class("form-group")
                                .content(
                                    W2.html("label")
                                        .attribute("for", W2.getID("editWSName", true))
                                        .content({!!json_encode(trans('inventory::settings.workspace_name_field'))!!}),
                                    W2.html("input")
                                        .attribute("id", W2.getID("editWSName"))
                                        .class("form-control")
                                        .attribute("type", "text")
                                        .attribute("placeholder", {!!json_encode(trans('inventory::settings.workspace_name_placeholder'))!!})
                                        .attribute("value", appState.newWorkspaceName || (appState.currentWorkspace ? appState.currentWorkspace.name : ""))
                                        .event("change", (e) => {
                                            appState.newWorkspaceName = e.target.value
                                        })
                                ),
                            //notifications
                            W2.html("div")
                                .class("form-check")
                                .content(
                                    W2.html("input")
                                        .attribute("id", W2.getID("editWSNotifications", true))
                                        .class("form-check-input")
                                        .attribute("type", "checkbox")
                                        .attributeIf(appState.newEnableNotifications !== null ? appState.newEnableNotifications : (appState.currentWorkspace !== null ? appState.currentWorkspace.enable_notifications === 1 : false), "checked", "checked")
                                        .event("change", (e) => {
                                            appState.newEnableNotifications = e.target.checked === true
                                        }),
                                    W2.html("label")
                                        .attribute("for", W2.getID("editWSNotifications"))
                                        .content({!!json_encode(trans('inventory::settings.notifications_label'))!!}),
                                ),
                            //submit
                            W2.html("div")
                                .class("form-group")
                                .content(
                                    W2.html("button")
                                        .class("btn btn-primary mr-1")
                                        .content({!!json_encode(trans('inventory::common.save_btn'))!!})
                                        .event("click", async () => {
                                            const data = {
                                                workspace: appState.currentWorkspace.id,
                                                name: appState.newWorkspaceName || appState.currentWorkspace.name,
                                                enableNotifications: appState.newEnableNotifications !== null ? appState.newEnableNotifications : (appState.currentWorkspace.enable_notifications === 1)
                                            }

                                            const response = await jsonPostAction("{{route("inventory.editWorkspace")}}", data)

                                            if (response.ok) {
                                                BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, {!!json_encode(trans('inventory::settings.settings_save_success'))!!})
                                            } else {
                                                BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, {!!json_encode(trans('inventory::settings.error_save_settings'))!!})
                                            }

                                            //I'm too lazy
                                            location.reload()
                                        }),
                                    confirmButtonComponent({!!json_encode(trans('inventory::settings.delete_workspace_btn'))!!}, async function () {
                                        const data = {
                                            workspace: appState.currentWorkspace.id,
                                        }

                                        const response = await jsonPostAction("{{route("inventory.deleteWorkspace")}}", data)

                                        if (response.ok) {
                                            BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, {!!json_encode(trans('inventory::settings.workspace_delete_success'))!!})
                                        } else {
                                            BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, {!!json_encode(trans('inventory::settings.error_delete_workspace'))!!})
                                        }

                                        appState.currentWorkspace = null;

                                        //I'm too lazy
                                        location.reload()
                                    })
                                )
                        )
                )
            )

            //card for alliances
            container.contentIf(hasWorkspace, W2.html("div")
                .class("card")
                .content(
                    //title header
                    W2.html("div")
                        .class("card-header")
                        .content(
                            W2.html("h3")
                                .class("cart-title")
                                .content({!!json_encode(trans('inventory::settings.alliances_title'))!!})
                        ),
                    //card body
                    W2.html("div")
                        .class("card-body")
                        .content(
                            W2.html("div")
                                .class("form-group d-flex flex-column w-100")
                                .content(
                                    W2.html("label")
                                        .attribute("for", W2.getID("addAlliance", true))
                                        .content({!!json_encode(trans('inventory::settings.add_alliance_btn'))!!}),
                                    select2Component({
                                        select2: {
                                            placeholder: {!!json_encode(trans('inventory::settings.select_alliance_placeholder'))!!},
                                            ajax: {
                                                url: "{{ route("inventory.allianceLookup") }}"
                                            },
                                            allowClear: true,
                                        },
                                        selectionListeners: [
                                            (selection) => {
                                                state.allianceSelector = selection
                                                mount.update()
                                            }
                                        ],
                                        selection: state.allianceSelector,
                                        id: W2.getID("addAlliance")
                                    }),
                                ).contentIf(state.allianceSelector !== null,
                                W2.html("button")
                                    .class("btn btn-primary btn-block mt-2")
                                    .content({!!json_encode(trans('inventory::common.add_btn'))!!})
                                    .event("click", async () => {
                                        const response = await jsonPostAction("{{ route("inventory.addAlliance") }}", {
                                            alliance_id: state.allianceSelector.id,
                                            workspace: state.currentWorkspace.id
                                        })

                                        if (response.ok) {
                                            BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, `{{trans('inventory::settings.add_alliance_success')}} ${state.allianceSelector.text}`)
                                        } else {
                                            BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, `{{trans('inventory::settings.error_adding_alliance')}} ${state.allianceSelector.text}`)
                                        }

                                        await fetchData()
                                        mount.update()
                                    })
                            ),
                            W2.html("ul")
                                .class("list-group")
                                .content(
                                    (container) => {
                                        for (const alliance of appState.alliances) {
                                            container.content(
                                                W2.html("li")
                                                    .class("list-group-item d-flex flex-row justify-content-between align-items-baseline")
                                                    .content(
                                                        W2.html("span")
                                                            .content(alliance.alliance.name),
                                                        W2.html("div")
                                                            .contentIf(!alliance.manage_members,
                                                                tooltipComponent(
                                                                    W2.html("button")
                                                                        .class("btn btn-secondary mx-1")
                                                                        .content({!!json_encode(trans('inventory::settings.add_members_btn'))!!})
                                                                        .event("click", async () => {
                                                                            const response = await jsonPostAction("{{ route("inventory.addAllianceMembers") }}", {
                                                                                tracking_id: alliance.id
                                                                            })

                                                                            if (response.ok) {
                                                                                BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, `{{trans('inventory::settings.add_members_success')}} ${alliance.alliance.name}`)
                                                                            } else {
                                                                                BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, `{{trans('inventory::settings.error_adding_members')}} ${alliance.alliance.name}`)
                                                                            }

                                                                            await fetchData()
                                                                            mount.update()
                                                                        }),
                                                                    {!!json_encode(trans('inventory::settings.add_members_tooltip'))!!})
                                                            ).contentIf(alliance.manage_members,
                                                            tooltipComponent(
                                                                W2.html("button")
                                                                    .class("btn btn-secondary mx-1")
                                                                    .content({!!json_encode(trans('inventory::settings.remove_members_btn'))!!})
                                                                    .event("click", async () => {
                                                                        const response = await jsonPostAction("{{ route("inventory.removeAllianceMembers") }}", {
                                                                            tracking_id: alliance.id
                                                                        })

                                                                        if (response.ok) {
                                                                            BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, `{{trans('inventory::settings.remove_members_success')}} ${alliance.alliance.name}`)
                                                                        } else {
                                                                            BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, `{{trans('inventory::settings.error_remove_members')}} ${alliance.alliance.name}`)
                                                                        }

                                                                        await fetchData()
                                                                        mount.update()
                                                                    }),
                                                                {!!json_encode(trans('inventory::settings.remove_members_tooltip'))!!})
                                                        ).content(
                                                            confirmButtonComponent("Remove", async () => {
                                                                const response = await jsonPostAction("{{ route("inventory.removeAlliance") }}", {
                                                                    tracking_id: alliance.id,
                                                                })

                                                                if (response.ok) {
                                                                    BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, `{{trans('inventory::common.remove_object_success')}} ${alliance.alliance.name}`)
                                                                } else {
                                                                    BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, `{{trans('inventory::common.error_remove_object')}} ${alliance.alliance.name}`)
                                                                }

                                                                await fetchData()
                                                                mount.update()
                                                            })
                                                        )
                                                    )
                                            )
                                        }
                                    }
                                )
                        )
                )
            )

            //card for corporations
            container.contentIf(hasWorkspace, W2.html("div")
                .class("card")
                .content(
                    //title header
                    W2.html("div")
                        .class("card-header")
                        .content(
                            W2.html("h3")
                                .class("cart-title")
                                .content({!!json_encode(trans('inventory::settings.corporations_title'))!!})
                        ),
                    //card body
                    W2.html("div")
                        .class("card-body")
                        .content(
                            W2.html("div")
                                .class("form-group d-flex flex-column w-100")
                                .content(
                                    W2.html("label")
                                        .attribute("for", W2.getID("addCorporation", true))
                                        .content({!!json_encode(trans('inventory::settings.add_corporations_btn'))!!}),
                                    select2Component({
                                        select2: {
                                            placeholder: {!!json_encode(trans('inventory::settings.select_corporation_placeholder'))!!},
                                            ajax: {
                                                url: "{{ route("inventory.corporationLookup") }}"
                                            },
                                            allowClear: true,
                                        },
                                        selectionListeners: [
                                            (selection) => {
                                                state.corporationSelector = selection
                                                mount.update()
                                            }
                                        ],
                                        selection: state.corporationSelector,
                                        id: W2.getID("addCorporation")
                                    }),
                                ).contentIf(state.corporationSelector !== null,
                                W2.html("button")
                                    .class("btn btn-primary btn-block mt-2")
                                    .content({!!json_encode(trans('inventory::common.add_btn'))!!})
                                    .event("click", async () => {
                                        const response = await jsonPostAction("{{ route("inventory.addCorporation") }}", {
                                            corporation_id: state.corporationSelector.id,
                                            workspace: state.currentWorkspace.id
                                        })

                                        if (response.ok) {
                                            BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, `{{trans('inventory::settings.add_corporation_success')}} ${state.corporationSelector.text}`)
                                        } else {
                                            BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, `{{trans('inventory::settings.error_adding_corporations')}} ${state.corporationSelector.text}`)
                                        }

                                        await fetchData()
                                        mount.update()
                                    })
                            ),
                            W2.html("ul")
                                .class("list-group")
                                .content(
                                    (container) => {
                                        for (const corporation of appState.corporations) {
                                            container.content(
                                                W2.html("li")
                                                    .class("list-group-item d-flex flex-row justify-content-between align-items-baseline")
                                                    .content(
                                                        W2.html("span")
                                                            .class("mr-auto")
                                                            .content(corporation.corporation.name),
                                                        W2.html("i")
                                                            .class("fas fa-pen text-primary mx-3")
                                                            .style("cursor", "pointer")
                                                            .event("click", () => {
                                                                editCorpTracking(corporation)
                                                            }),
                                                        confirmButtonComponent({!!json_encode(trans('inventory::common.remove_btn'))!!}, async () => {
                                                            const response = await jsonPostAction("{{ route("inventory.removeCorporation") }}", {
                                                                tracking_id: corporation.id
                                                            })

                                                            if (response.ok) {
                                                                BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, `{{trans('inventory::common.remove_object_success')}} ${corporation.corporation.name}`)
                                                            } else {
                                                                BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, `{{trans('inventory::common.error_remove_object')}} ${corporation.corporation.name}`)
                                                            }

                                                            await fetchData()
                                                            mount.update()
                                                        })
                                                    )
                                            )
                                        }
                                    }
                                )
                        )
                )
            )


            //card for markets
            container.contentIf(hasWorkspace, W2.html("div")
                .class("card")
                .content(
                    //title header
                    W2.html("div")
                        .class("card-header")
                        .content(
                            W2.html("h3")
                                .class("cart-title")
                                .content({!!json_encode(trans('inventory::settings.markets_title'))!!})
                        ),
                    //card body
                    W2.html("div")
                        .class("card-body")
                        .content(
                            W2.html("div")
                                .class("form-group d-flex flex-column w-100")
                                .content(
                                    W2.html("label")
                                        .attribute("for", W2.getID("addMarket", true))
                                        .content({!!json_encode(trans('inventory::settings.add_market_btn'))!!}),
                                    select2Component({
                                        select2: {
                                            placeholder: {!!json_encode(trans('inventory::common.location_select_message'))!!},
                                            ajax: {
                                                url: "{{ route("inventory.locationLookup") }}"
                                            },
                                            allowClear: true,
                                        },
                                        selectionListeners: [
                                            (selection) => {
                                                state.marketSelector = selection
                                                mount.update()
                                            }
                                        ],
                                        selection: state.marketSelector,
                                        id: W2.getID("addMarket")
                                    }),
                                    W2.html("small")
                                        .class("text-muted")
                                        .content({!!json_encode(trans('inventory::settings.add_market_tooltip'))!!})
                                ).contentIf(state.marketSelector !== null,
                                W2.html("button")
                                    .class("btn btn-primary btn-block mt-2")
                                    .content({!!json_encode(trans('inventory::common.add_btn'))!!})
                                    .event("click", async () => {
                                        const response = await jsonPostAction("{{ route("inventory.addMarket") }}", {
                                            location_id: state.marketSelector.id,
                                            workspace: state.currentWorkspace.id
                                        })

                                        if (response.ok) {
                                            BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, `{{trans('inventory::common.add_object_success')}} ${state.marketSelector.text}`)
                                        } else {
                                            BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, `{{trans('inventory::common.error_adding_object')}} ${state.marketSelector.text}`)
                                        }

                                        await fetchData()
                                        mount.update()
                                    })
                            ),
                            W2.html("ul")
                                .class("list-group")
                                .content(
                                    (container) => {
                                        for (const market of appState.markets) {
                                            container.content(
                                                W2.html("li")
                                                    .class("list-group-item d-flex flex-row justify-content-between align-items-baseline")
                                                    .content(
                                                        W2.html("span")
                                                            .content(market.location.name),
                                                        W2.html("div")
                                                            .content(
                                                                W2.html("button")
                                                                    .class("btn btn-secondary mx-1")
                                                                    .content({!!json_encode(trans('inventory::settings.remove_market_btn'))!!})
                                                                    .event("click", async () => {
                                                                        const response = await jsonPostAction("{{ route("inventory.removeMarket") }}", {
                                                                            tracking_id: market.id
                                                                        })

                                                                        if (response.ok) {
                                                                            BoostrapToast.open({!!json_encode(trans('inventory::common.success_label'))!!}, `{{trans('inventory::settings.remove_market_success')}} ${market.location.name}`)
                                                                        } else {
                                                                            BoostrapToast.open({!!json_encode(trans('inventory::common.error_label'))!!}, `{{trans('inventory::settings.error_removing_markets')}} ${market.location.name}`)
                                                                        }

                                                                        await fetchData()
                                                                        mount.update()
                                                                    }))
                                                    )
                                            )
                                        }
                                    }
                                )
                        )
                )
            )
        })

        fetchData().then(() => {
            mount.update()
        })

        const rootMount = W2.mount((container, m) => {
            //workspace selection
            const messages = @json(\RecursiveTree\Seat\Inventory\Helpers\LocaleHelper::getWorkspaceMessages());

            container.content(workspaceSelector(messages, async (selectedWorkspace) => {
                appState.currentWorkspace = selectedWorkspace
                appState.newWorkspaceName = null
                appState.newEnableNotifications = null
                await fetchData()
                mount.update()
            }))
            container.content(mount)
        })
        rootMount.addInto("main")
    </script>
@endpush