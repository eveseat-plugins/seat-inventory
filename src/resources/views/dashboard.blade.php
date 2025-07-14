@extends('web::layouts.app')

@section('title', trans('inventory::inv.inventory_dashboard_title'))
@section('page_header', trans('inventory::inv.inventory_dashboard_title'))


@section('content')
    <div id="content-target"></div>
@stop

@push("javascript")
    <script>const CSRF_TOKEN = '{{ csrf_token() }}'</script>
    <script src="@inventoryVersionedAsset('inventory/js/utils.js')"></script>
    <script src="@inventoryVersionedAsset('inventory/js/w2.js')"></script>
    <script src="@inventoryVersionedAsset('inventory/js/select2w2.js')"></script>
    <script src="@inventoryVersionedAsset('inventory/js/bootstrapW2.js')"></script>
    <script src="@inventoryVersionedAsset('inventory/js/components.js')"></script>


    <script>
        //TODO: load them from the server
        let stockPriorities = null

        async function getStockPriorities() {
            if (!stockPriorities) {
                try {
                    const response = await fetch("{{ route("treelib.prioritiesList") }}")
                    const data = await response.json()
                    stockPriorities = []
                    for (const [priority, pdata] of data.entries()) {
                        stockPriorities.push({
                            priority,
                            name: pdata.name
                        })
                    }

                } catch (e) {
                    BoostrapToast.open("Error", "Failed to load stock priority data")
                    stockPriorities = [{
                        priority: 0,
                        name: "Error"
                    }]
                }
            }
            return stockPriorities
        }

        function getStockPriorityName(priority) {
            if (!stockPriorities) {
                return priority.toString()
            }
            for (const pdata of stockPriorities) {
                if (pdata.priority === priority) {
                    return pdata.name
                }
            }
            return priority.toString()
        }

        //load stock priorities
        getStockPriorities()

        const HAS_ALLIANCE_INDUSTRY_PLUGIN = {!! \RecursiveTree\Seat\TreeLib\Helpers\AllianceIndustryPluginHelper::pluginIsAvailable() ? "true":"false" !!}

        //stores data related to different source types
        class SourceTypeHelper {
            static #source_types = {!! json_encode(config("inventory.sources")) !!}
            //comments after blade things prevents syntax highlighting errors

            static getFullName(type_name) {
                return this.getData(type_name).name
            }

            static getData(type_name) {
                return this.#source_types[type_name] || {name: type_name, virtual: true, pooled: true}
            }
        }

        function generateMultiBuy(items) {
            return items.map(item => `${item.name} ${item.amount}`).join("\n")
        }

        async function jsonGetAction(url, data) {
            url = new URL(url)
            //stackoverflow many thanks
            Object.keys(data).forEach(key => url.searchParams.append(key, data[key]))
            return await fetch(url, {
                method: "GET",
            })
        }

        // Helper function to abbreviate numbers to their SI suffix
        var SI_SYMBOL = ["", "k", "M", "B", "T", "P", "E"];
        function abbreviateNumberEVE(number){

            // what tier? (determines SI symbol)
            var tier = Math.log10(number) / 3 | 0;

            // if zero, we don't need a suffix
            if(tier == 0) return number.toFixed(1);

            // get suffix and determine scale
            var suffix = SI_SYMBOL[tier];
            var scale = Math.pow(10, tier * 3);

            // scale the number
            var scaled = number / scale;

            // format number and add suffix
            return scaled.toFixed(1) + suffix;
        }

        class LocationFilterComponent extends W2.W2Component {
            locationListeners
            id

            constructor(options) {
                super();

                this.id = W2.getID("locationFilterSelect", true)

                this.locationListeners = options.locationListeners || []
            }

            locationSelected(selection) {
                let locationID = null
                if (selection) {
                    locationID = selection.id
                }

                for (const locationListener of this.locationListeners) {
                    locationListener(locationID)
                }
            }

            render(container) {
                const card = W2
                    .html("div")
                    .class("card")
                    .content(
                        W2.html("div")
                            .class("card-body")
                            .content(
                                W2.html("label")
                                    .attribute("for", this.id)
                                    .content("Location")
                            )
                            .content(
                                select2Component({
                                    select2: {
                                        placeholder: {!!json_encode(trans('inventory::inv.inventory_all_locations'))!!},
                                        ajax: {
                                            url: "{{ route("inventory.locationLookup") }}"
                                        },
                                        allowClear: true,
                                    },
                                    id: this.id,
                                    selectionListeners: [
                                        (...args) => this.locationSelected(...args)
                                    ]
                                })
                            )
                            .content(
                                W2.html("small")
                                    .class("text-muted")
                                    .content({!!json_encode(trans('inventory::inv.inventory_only_show_categories_with_stock'))!!})
                            )
                    )

                container.content(card)
            }
        }

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

        async function lookupName(id, url) {
            const response = await jsonGetAction(url, {
                id
            })

            if (!response.ok) {
                throw new Error({!!json_encode(trans('inventory::common.error_server_response_error'))!!})
            }

            const data = await response.json()

            if (data.results.length < 1) {
                throw new Error({!!json_encode(trans('inventory::common.error_doctrine_not_found'))!!})
            }

            return data.results[0].text
        }

        function editCategoryPopUp(app, category) {
            BootstrapPopUp.open(category.id ? {!!json_encode(trans('inventory::inv.inventory_edit_group'))!!} : {!!json_encode(trans('inventory::inv.inventory_create_group'))!!}, (container, popup) => {
                let filters = category.filters || ""
                try {
                    filters = JSON.parse(filters)
                } catch (e) {
                    filters = []
                    BoostrapToast.open("Category", {!!json_encode(trans('inventory::common.error_failed_to_parse_group_data'))!!})
                }
                const filterLocations = []
                const filterDoctrines = []
                for (const filter of filters) {
                    if (filter.type === "location") {
                        const data = {
                            id: filter.id,
                            text: {!!json_encode(trans('inventory::inv.inventory_loading_name'))!!}
                        }

                        filterLocations.push(data)

                        lookupName(filter.id, "{{ route("inventory.locationLookup") }}").then((name) => {
                            data.text = name
                        }).catch((e) => {
                            data.text = {!!json_encode(trans('inventory::common.error_inventory_loading_failed'))!!}
                        })
                    } else if (filter.type === "doctrine") {
                        const data = {
                            id: filter.id,
                            text: {!!json_encode(trans('inventory::inv.inventory_loading_name'))!!}
                        }

                        filterDoctrines.push(data)

                        lookupName(filter.id, "{{ route("inventory.doctrineLookup") }}").then((name) => {
                            data.text = name
                        }).catch((e) => {
                            data.text = {!!json_encode(trans('inventory::common.error_inventory_loading_failed'))!!}
                        })
                    }
                }

                let stocks = []
                if (category.stocks) {
                    stocks = category.stocks.map((stock) => {
                        stock.manually_added = stock.pivot.manually_added
                        stock.category_eligible = stock.pivot.category_eligible
                        return stock
                    })
                }


                const state = {
                    name: category.name || "",
                    message: null,
                    stocks: stocks,
                    stocksExpanded: false,
                    filtersExpanded: false,
                    generalExpanded: true,
                    filterLocations: filterLocations,
                    filterDoctrines: filterDoctrines,
                }

                const mount = W2.mount(state, (container, mount, state) => {
                    container
                        .content(
                            //general settings
                            W2.html("div")
                                .class("card")
                                .content(
                                    W2.html("div")
                                        .class("card-body")
                                        .content(
                                            //head with expand/collapse
                                            W2.html("div")
                                                .class("d-flex align-items-baseline")
                                                .content(
                                                    W2.html("h6")
                                                        .content({!!json_encode(trans('inventory::common.general_header'))!!}),
                                                    W2.html("button")
                                                        .class("btn btn-primary ml-auto")
                                                        .contentIf(state.generalExpanded, {!!json_encode(trans('inventory::common.collapse_btn'))!!})
                                                        .contentIf(!state.generalExpanded, {!!json_encode(trans('inventory::common.expand_btn'))!!})
                                                        .event("click", () => {
                                                            state.generalExpanded = !state.generalExpanded
                                                            mount.update()
                                                        })
                                                )
                                        )
                                        //actual general settings
                                        .contentIf(state.generalExpanded,
                                            W2.html("label")
                                                .attribute("for", W2.getID("editCategoryNameLabel", true))
                                                .content({!!json_encode(trans('inventory::inv.inventory_group_name_label'))!!}),
                                            W2.html("input")
                                                .class("form-control")
                                                .attribute("type", "text")
                                                .id(W2.getID("editCategoryNameLabel"))
                                                .attribute("placeholder", {!!json_encode(trans('inventory::inv.inventory_group_name_hint'))!!})
                                                .attribute("value", state.name)
                                                .event("change", (e) => {
                                                    state.name = e.target.value
                                                }),
                                            (container) => {
                                                if (state.message) {
                                                    container.content(
                                                        W2.html("small").class("text-danger").content(state.message)
                                                    )
                                                }
                                            }
                                        )
                                )
                        )

                    //stock list+manual addition
                    container.content(
                        W2.html("div")
                            .class("card")
                            .content(
                                W2.html("div")
                                    .class("card-body")
                                    .content(
                                        W2.html("div")
                                            .class("d-flex align-items-baseline")
                                            .content(
                                                W2.html("h6")
                                                    .content({!!json_encode(trans('inventory::inv.inventory_stocks_label'))!!}),
                                                W2.html("button")
                                                    .class("btn btn-primary ml-auto")
                                                    .contentIf(state.stocksExpanded, {!!json_encode(trans('inventory::common.collapse_btn'))!!})
                                                    .contentIf(!state.stocksExpanded, {!!json_encode(trans('inventory::common.expand_btn'))!!})
                                                    .event("click", () => {
                                                        state.stocksExpanded = !state.stocksExpanded
                                                        mount.update()
                                                    })
                                            ),
                                    )
                                    //stocks when expanded
                                    .contentIf(state.stocksExpanded,
                                        //only add margin if expanded, use a dummy for this
                                        W2.html("div").class("mt-2"),
                                        //manual addition select2
                                        select2Component({
                                            select2: {
                                                placeholder: {!!json_encode(trans('inventory::inv.inventory_manually_add_stock_btn'))!!},
                                                ajax: {
                                                    url: "{{ route("inventory.stockSuggestion") }}",
                                                    data: function (params) {
                                                        return {
                                                            term: params.term,
                                                            workspace: app.workspace.id
                                                        }
                                                    },
                                                    processResults: (data) => {
                                                        return {
                                                            results: data.results.filter((data) => {

                                                                const includedIDs = state.stocks
                                                                    //remove automatically added stock so that they still can be added
                                                                    .filter((entry) => entry.manually_added)
                                                                    //only get the id
                                                                    .map((entry) => entry.id)

                                                                //not in manually added stocks
                                                                return !includedIDs.includes(data.id.id)
                                                            })
                                                        }
                                                    }
                                                },
                                                allowClear: true,
                                            },
                                            selectionListeners: [
                                                (data) => {
                                                    const stock = data.id

                                                    //if it is an automated stock that being added, we have to change instead of add it
                                                    const existingStocks = state.stocks.filter((entry) => entry.id === stock.id)
                                                    if (existingStocks.length > 0) {
                                                        //it's a automated stock, switch it to a manual one
                                                        for (const existingStock of existingStocks) {
                                                            existingStock.manually_added = true
                                                        }
                                                    } else {
                                                        //its a new stock

                                                        //the api doesn't include the data from the pivots, add them
                                                        stock.manually_added = true
                                                        stock.category_eligible = false

                                                        state.stocks.push(stock)
                                                    }

                                                    mount.update()
                                                }
                                            ]
                                        }),
                                        //stock list
                                        (container) => {
                                            if (state.stocks.length > 0) {
                                                container.content(
                                                    W2.html("ul")
                                                        .class("list-group list-group-flush mt-2")
                                                        .content((container) => {
                                                            for (const stock of state.stocks) {
                                                                container.content(
                                                                    W2.html("li")
                                                                        .class("list-group-item d-flex align-items-baseline justify-content-between")
                                                                        .style("padding-right", "0")
                                                                        .content(stock.name)

                                                                        //remove button
                                                                        .contentIf(stock.manually_added,
                                                                            W2.html("button")
                                                                                .class("btn btn-outline-danger")
                                                                                .content({!!json_encode(trans('inventory::common.remove_btn'))!!})
                                                                                .event("click", () => {
                                                                                    //TODO reset to automtic
                                                                                    state.stocks = state.stocks.filter((e) => {
                                                                                        //find the current stock
                                                                                        if (e.id === stock.id) {
                                                                                            //if it was originally automatic, set it back to automatic
                                                                                            if (e.category_eligible) {
                                                                                                e.manually_added = false
                                                                                            } else {
                                                                                                //originally manual, remove it form the list
                                                                                                return false
                                                                                            }
                                                                                        }
                                                                                        return true
                                                                                    })
                                                                                    mount.update()
                                                                                })
                                                                        )

                                                                        //automated message
                                                                        .contentIf(!stock.manually_added,
                                                                            W2.html("button")
                                                                                .class("btn btn-outline-secondary")
                                                                                .content({!!json_encode(trans('inventory::common.make_permanent_btn'))!!})
                                                                                .event("click", () => {
                                                                                    stock.manually_added = true
                                                                                    mount.update()
                                                                                })
                                                                        )
                                                                )
                                                            }
                                                        })
                                                )
                                            } else {
                                                container.content(
                                                    W2.html("p")
                                                        .class("mt-3")
                                                        .content({!!json_encode(trans('inventory::inv.inventory_empty_stock_or_filters_not_applied'))!!})
                                                )
                                            }
                                        }
                                    )
                            )
                    )

                    //filters
                    container.content(
                        W2.html("div")
                            .class("card")
                            .content(
                                W2.html("div")
                                    .class("card-body")
                                    .content(
                                        W2.html("div")
                                            .class("d-flex align-items-baseline")
                                            .content(
                                                W2.html("h6")
                                                    .content({!!json_encode(trans('inventory::common.filters_btn'))!!}),
                                                W2.html("button")
                                                    .class("btn btn-primary ml-auto")
                                                    .contentIf(state.filtersExpanded, {!!json_encode(trans('inventory::common.collapse_btn'))!!})
                                                    .contentIf(!state.filtersExpanded, {!!json_encode(trans('inventory::common.expand_btn'))!!})
                                                    .event("click", () => {
                                                        state.filtersExpanded = !state.filtersExpanded
                                                        mount.update()
                                                    })
                                            )
                                    )
                                    .contentIf(state.filtersExpanded,
                                        //location filter
                                        W2.html("label")
                                            .content({!!json_encode(trans('inventory::common.locations_header'))!!}),
                                        select2Component({
                                            select2: {
                                                placeholder: {!!json_encode(trans('inventory::common.locations_select_btn'))!!},
                                                ajax: {
                                                    url: "{{ route("inventory.locationLookup") }}"
                                                },
                                                dropdownParent: popup.jQuery,
                                                multiple: true,
                                                allowClear: true,
                                            },
                                            selectionListeners: [
                                                (selection) => {
                                                    if (selection) {
                                                        const data = {
                                                            id: selection.id,
                                                            text: selection.text
                                                        }
                                                        state.filterLocations.push(data)
                                                    } else {
                                                        state.filterLocations = []
                                                    }

                                                    mount.update()
                                                }
                                            ],
                                            unselectListeners: [
                                                (selection) => {
                                                    const id = parseInt(selection.id)
                                                    state.filterLocations = state.filterLocations.filter((e) => e.id !== id)
                                                    mount.update()
                                                }
                                            ],
                                            selection: state.filterLocations
                                        }),
                                        //doctrine filter
                                        W2.html("label")
                                            .content({!!json_encode(trans('inventory::common.doctrines_label'))!!})
                                            .class("mt-2"),
                                        select2Component({
                                            select2: {
                                                placeholder: {!!json_encode(trans('inventory::common.doctrines_select_input_label'))!!},
                                                ajax: {
                                                    url: "{{ route("inventory.doctrineLookup") }}"
                                                },
                                                dropdownParent: popup.jQuery,
                                                multiple: true,
                                                allowClear: true,
                                            },
                                            selectionListeners: [
                                                (selection) => {
                                                    if (selection) {
                                                        const data = {
                                                            id: selection.id,
                                                            text: selection.text
                                                        }
                                                        state.filterDoctrines.push(data)
                                                    } else {
                                                        state.filterDoctrines = []
                                                    }

                                                    mount.update()
                                                }
                                            ],
                                            unselectListeners: [
                                                (selection) => {
                                                    const id = parseInt(selection.id)
                                                    state.filterDoctrines = state.filterDoctrines.filter((e) => e.id !== id)
                                                    mount.update()
                                                }
                                            ],
                                            selection: state.filterDoctrines
                                        })
                                    )
                            )
                    )


                    //button bar at the bottom
                    container.content(
                        W2.html("div")
                            .class("d-flex flex-row")
                            .content(
                                (container) => {
                                    //delete button
                                    if (category.id) {
                                        container.content(
                                            confirmButtonComponent({!!json_encode(trans('inventory::common.delete_btn'))!!}, async () => {
                                                popup.close()

                                                const response = await jsonPostAction("{{ route("inventory.deleteCategory") }}", {
                                                    id: category.id
                                                })

                                                if (!response.ok) {
                                                    BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_group_label'))!!}, {!!json_encode(trans('inventory::common.error_failed_to_delete_group'))!!})
                                                } else {
                                                    BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_group_label'))!!}, {!!json_encode(trans('inventory::common.delete_group_success'))!!})
                                                }

                                                app.categoryList.state.loadData()
                                            })
                                        )
                                    }
                                }
                            )
                            .content(
                                //close button
                                W2.html("button")
                                    .class("btn btn-secondary mr-1 ml-auto")
                                    .content({!!json_encode(trans('inventory::common.close_btn'))!!})
                                    .event("click", () => popup.close())
                            )
                            .content(
                                //save button
                                W2.html("button")
                                    .class("btn btn-primary")
                                    .content({!!json_encode(trans('inventory::common.save_btn'))!!})
                                    .event("click", async () => {
                                        if (state.name && state.name.length > 0) {
                                            //name field is not empty, save the category

                                            //filters
                                            const filters = []
                                            //location filter
                                            for (const location of state.filterLocations) {
                                                filters.push({
                                                    type: "location",
                                                    id: location.id
                                                })
                                            }
                                            //doctrine filter
                                            for (const doctrine of state.filterDoctrines) {
                                                filters.push({
                                                    type: "doctrine",
                                                    id: doctrine.id
                                                })
                                            }

                                            const data = {
                                                id: category.id,
                                                name: state.name,
                                                stocks: state.stocks.map((e) => {
                                                    return {
                                                        id: e.id,
                                                        manually_added: e.manually_added
                                                    }
                                                }),
                                                filters: filters,
                                                workspace: app.workspace.id
                                            }

                                            const response = await jsonPostAction("{{ route("inventory.saveCategory") }}", data)

                                            if (!response.ok) {
                                                BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_group_label'))!!}, {!!json_encode(trans('inventory::common.error_failed_to_save_group'))!!})
                                            } else {
                                                BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_group_label'))!!}, {!!json_encode(trans('inventory::common.save_group_success'))!!})
                                                popup.close()
                                            }

                                            app.categoryList.state.loadData()

                                        } else {
                                            //name field is empty
                                            state.message = {!!json_encode(trans('inventory::common.error_invalid_name_input'))!!}
                                            mount.update()
                                        }
                                    })
                            )
                    )
                })

                container.content(mount)
            })
        }

        function stockCardPropertyEntry(name, value, style = null) {
            let effectiveText = value
            let addToolTip = false
            if (effectiveText.length >= 20) {
                effectiveText = effectiveText.substring(0, 20)
                addToolTip = true
            }

            return W2.html("li")
                .class("list-group-item")
                .classIf(style, `list-group-item-${style}`)
                .content(name)
                .content(
                    tooltipComponent(
                        W2.html("b")
                            .class("float-right")
                            .content(effectiveText),
                        addToolTip ? value : ""
                    )
                )
        }

        function stockCardComponent(app, stock, location) {
            const available = stock.available

            let availabilityColor = null
            if (available === 0) {
                availabilityColor = "danger"
            } else if (available < stock.warning_threshold) {
                availabilityColor = "warning"
            }

            return W2.html("div")
                .class("card m-1")
                .style("width", "16rem")
                .styleIf(location !== null && location !== stock.location_id, "opacity", "0.5")
                .content(
                    //card header
                    W2.html("div")
                        .class("card-header d-flex align-items-baseline")
                        .style("padding-right", "0.75rem")
                        .content(
                            W2.html("h5")
                                .class("card-title mr-auto")
                                .content(
                                    W2.html("span")
                                        .class("text-primary")
                                        .attribute("href", `/inventory/stocks/view/${stock.id}`)
                                        .content(stock.name)
                                )
                        )
                        .content(
                            W2.html("i")
                                .class("fas fa-pen text-primary")
                                .style("cursor", "pointer")
                                .event("click", () => {
                                    editStockPopUp(app, stock)
                                })
                        )
                        .content(
                            W2.html("i")
                                .class("fas fa-info text-primary ml-2")
                                .style("cursor", "pointer")
                                .event("click", () => {
                                    stockInfoPopUp(app, stock)
                                })
                        )
                )
                //card body
                .content(
                    W2.html("img")
                        .attribute("src", `/inventory/stocks/icon/${stock.id}`)
                        .attribute("loading", "lazy")
                        .attribute("alt", `Icons of the most important items in ${stock.name}`)
                        .style("width", "100%")
                )
                .content(
                    W2.html("ul")
                        .class("list-group list-group-flush")
                        .content(stockCardPropertyEntry({!!json_encode(trans('inventory::common.location_field'))!!}, stock.location.name))
                        .content(stockCardPropertyEntry({!!json_encode(trans('inventory::common.priority_field'))!!}, getStockPriorityName(stock.priority)))
                        .content(stockCardPropertyEntry({!!json_encode(trans('inventory::common.planned_field'))!!}, stock.amount))
                        .content(stockCardPropertyEntry({!!json_encode(trans('inventory::common.warning_threshold_field'))!!}, stock.warning_threshold))
                        .content(stockCardPropertyEntry({!!json_encode(trans('inventory::common.missing_price'))!!}, `${abbreviateNumberEVE(stock.missing_price)} ISK`))
                        .content(stockCardPropertyEntry({!!json_encode(trans('inventory::common.available_field'))!!}, available, availabilityColor))
                        .content((container) => {
                            const sorted = stock.levels.sort((a, b) => b.amount - a.amount)

                            const addEntry = (index) => {
                                const level = sorted[index]
                                if (level) {
                                    container.content(stockCardPropertyEntry(SourceTypeHelper.getFullName(level.source_type), level.amount))
                                } else {
                                    container.content(stockCardPropertyEntry("\u200b", "\u200b"))
                                }
                            }

                            if (sorted.length > 3) {
                                addEntry(0)
                                addEntry(1)
                                container.content(stockCardPropertyEntry({!!json_encode(trans('inventory::common.locations_other_header'))!!}, sorted.slice(2).reduce((p, c) => p + c.amount, 0)))
                            } else {
                                addEntry(0)
                                addEntry(1)
                                addEntry(2)
                            }
                        })
                )
        }

        function categoryComponent(app, category, collapsed, toggleCollapse, location) {
            return W2.html("div")
                .class("card")
                .content(
                    W2.html("div")
                        .class("card-body")
                        .content(
                            //header
                            W2.html("div")
                                .class("d-flex align-items-baseline")
                                .content(
                                    W2.html("h5")
                                        .class("card-title flex-grow-1")
                                        .content(category.name)
                                )
                                .content(
                                    W2.html("button")
                                        .class("btn btn-secondary mx-1")
                                        .content(
                                            W2.html("i").class("fas fa-info")
                                        )
                                        .event("click", () => categoryInfoPopup(category))
                                )
                                .content(
                                    W2.html("button")
                                        .class("btn btn-secondary mx-1")
                                        .content(
                                            W2.html("i").class("fas fa-pen")
                                        )
                                        .event("click", () => editCategoryPopUp(app, category))
                                )
                                .contentIf(
                                    toggleCollapse,//only show expand button if expanding is supported
                                    W2.html("button")
                                        .class("btn btn-primary")
                                        .content(collapsed ? {!!json_encode(trans('inventory::common.expand_btn'))!!} : {!!json_encode(trans('inventory::common.collapse_btn'))!!})
                                        .event("click", (e) => {
                                            e.preventDefault()
                                            toggleCollapse(category.id)
                                        })
                                )
                        )
                        .contentIf(
                            //stock cards
                            !collapsed,
                            W2.html("div")
                                .class("d-flex flex-wrap")
                                .content((container) => {
                                    if (category.stocks.length < 1) {
                                        container.content(W2.html("span").content({!!json_encode(trans('inventory::inv.inventory_empty_stock'))!!}))
                                    }
                                    for (const stock of category.stocks) {
                                        container.content(stockCardComponent(app, stock, location))
                                    }
                                })
                        )
                )
        }

        function categoryListComponent(app) {
            class CategoryListState extends W2.W2MountState {
                categories
                location
                collapsed
                defaultCollapseState

                constructor() {
                    super();
                    this.categories = null
                    this.location = null
                    this.collapsed = {}
                    this.defaultCollapseState = true
                    this.loadData()
                }

                setLocation(location) {
                    this.location = location
                    this.stateChanged()
                }

                toggleCollapse(id) {
                    this.collapsed[id] = !this.collapsed[id]
                    this.stateChanged()
                }

                collapseAll() {
                    this.collapsed = {}
                    this.defaultCollapseState = true
                    this.stateChanged()
                }

                expandAll() {
                    this.defaultCollapseState = false
                    for (const category of this.categories) {
                        this.collapsed[category.id] = false
                    }
                    this.stateChanged()
                }

                isCollapsed(id) {
                    const state = this.collapsed[id]

                    if (state === undefined) {
                        this.collapsed[id] = this.defaultCollapseState
                        return this.defaultCollapseState
                    }

                    return state
                }

                async loadData() {
                    let url = `{{ route("inventory.getCategories") }}?workspace=${app.workspace.id}`

                    const response = await fetch(url)
                    if (!response.ok) {
                        BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_groups_label'))!!}, {!!json_encode(trans('inventory::common.error_failed_to_load_group_data'))!!})
                        this.categoryData = null
                    }

                    this.categories = await response.json()

                    this.stateChanged()
                }
            }

            const state = new CategoryListState()

            return W2.mount(state, (container, mount, state) => {
                if (state.categories) {
                    for (const category of state.categories) {
                        container.content(
                            categoryComponent(
                                app,
                                category,
                                state.isCollapsed(category.id),
                                (id) => state.toggleCollapse(id),
                                state.location
                            )
                        )
                    }
                } else {
                    container.content({!!json_encode(trans('inventory::inv.inventory_loading'))!!})
                }
            })
        }

        const StockCreationDefaults = {
            type: null,
            amount: null,
            warning_threshold: null,
            location: null,
            priority: null
        }

        //stock creation and edit button
        async function editStockPopUp(app, stock) {
            const priorities = await getStockPriorities()

            const multibuy_placeholder = "Co - Processor II 2\nDrone Damage Amplifier II 1\nTristan 3"
            const fit_placeholder = "[Pacifier, 2022 Scanner]\n\nCo-Processor II\nCo-Processor II\nType-D Restrained Inertial Stabilizers\nInertial Stabilizers II"

            //create popup
            BootstrapPopUp.open(stock.name || {!!json_encode(trans('inventory::inv.inventory_new_stock_title'))!!}, (container, popup) => {

                let location = null
                //convert the location to a select2 compatible object
                if (stock.location) {
                    location = {
                        id: stock.location.id || null,
                        text: stock.location.name || null
                    }
                } else if (StockCreationDefaults.location) {
                    location = StockCreationDefaults.location
                }

                //ui state
                const state = {
                    type: stock.fitting_plugin_fitting_id ? "plugin" : StockCreationDefaults.type || "multibuy",
                    amount: stock.amount || StockCreationDefaults.amount || 1,
                    warning_threshold: stock.warning_threshold || StockCreationDefaults.warning_threshold || 1,
                    location, //conversion from json see above
                    priority: stock.priority || StockCreationDefaults.priority || 1,
                    multibuy: "", //for existing stocks, the data is loaded after the ui code, as it needs access to the mount
                    fit: "",
                    name: stock.name || "",
                    pluginFit: stock.fitting_plugin_fitting_id ? {
                        id: stock.fitting_plugin_fitting_id,
                        text: stock.name // should be synchrnoized with the fitting name
                    } : null,

                    invalidLocation: false,
                    invalidFit: false,
                    invalidName: false,
                    invalidPluginFit: false
                }

                //render stock creation popup content in a mount
                const mount = W2.mount(state, (container, mount, state) => {

                    //type selection
                    container.content(
                        W2.html("div")
                            .class("form-group")
                            .content(
                                W2.html("label")
                                    .attribute("for", W2.getID("editStockSelectType", true))
                                    .content({!!json_encode(trans('inventory::inv.inventory_stock_type_label'))!!}),
                                W2.html("select")
                                    .class("form-control")
                                    .content(
                                        //add type options
                                        W2.html("option")
                                            .content({!!json_encode(trans('inventory::inv.inventory_type_multibuy_label'))!!})
                                            .attribute("value", "multibuy")
                                            .attributeIf(state.type === "multibuy", "selected", true),
                                        W2.html("option")
                                            .content({!!json_encode(trans('inventory::inv.inventory_type_fit_label'))!!})
                                            .attribute("value", "fit")
                                            .attributeIf(state.type === "fit", "selected", true),
                                        W2.html("option")
                                            .content({!!json_encode(trans('inventory::inv.inventory_type_fitting_plugin_label'))!!})
                                            .attribute("value", "plugin")
                                            .attributeIf(state.type === "plugin", "selected", true)
                                    )
                                    .event("change", (e) => {
                                        //update the state and rerender
                                        state.type = e.target.value
                                        StockCreationDefaults.type = state.type
                                        mount.update()
                                    })
                            )
                    )

                    //we have a multibuy
                    if (state.type === "multibuy") {
                        container.content(
                            //textarea
                            W2.html("div")
                                .class("form-group")
                                .content(
                                    W2.html("label")
                                        .attribute("for", W2.getID("editStockMultibuy", true))
                                        .content({!!json_encode(trans('inventory::inv.inventory_type_multibuy_label'))!!}),
                                    W2.html(
                                        W2.html("textarea")
                                            .class("form-control")
                                            .id(W2.getID("editStockMultibuy"))
                                            .attribute("placeholder", multibuy_placeholder)
                                            .attribute("rows", 8)
                                            .content(state.multibuy)
                                            .event("change", (e) => {
                                                state.multibuy = e.target.value
                                                //no need to update the ui
                                            })
                                    )
                                ),
                            //name
                            W2.html("div")
                                .class("form-group")
                                .content(
                                    W2.html("label")
                                        .attribute("for", W2.getID("editStockName", true))
                                        .content({!!json_encode(trans('inventory::inv.inventory_stock_name_label'))!!}),
                                    W2.html(
                                        W2.html("input")
                                            .class("form-control")
                                            .classIf(state.invalidName, "is-invalid")
                                            .id(W2.getID("editStockName"))
                                            .attribute("type", "text")
                                            .attribute("placeholder", {!!json_encode(trans('inventory::inv.inventory_stock_name_placeholder'))!!})
                                            .attribute("value", state.name)
                                            .event("change", (e) => {
                                                state.name = e.target.value
                                                //update UI if it is valid now
                                                if (state.name.length > 0) {
                                                    state.invalidname = false
                                                    mount.update()
                                                }
                                            })
                                    )
                                )
                        )
                    }
                    //it is a fit
                    else if (state.type === "fit") {
                        container.content(
                            W2.html("div")
                                .class("form-group")
                                .content(
                                    W2.html("label")
                                        .attribute("for", W2.getID("editStockFit", true))
                                        .content({!!json_encode(trans('inventory::inv.inventory_type_fit_label'))!!}),
                                    W2.html(
                                        W2.html("textarea")
                                            .class("form-control")
                                            .classIf(state.invalidFit, "is-invalid")
                                            .id(W2.getID("editStockFit"))
                                            .attribute("placeholder", fit_placeholder)
                                            .attribute("rows", 8)
                                            .content(state.fit)
                                            .event("change", (e) => {
                                                state.fit = e.target.value

                                                if (state.fit.length > 0) {
                                                    state.invalidFit = false
                                                }

                                                mount.update()
                                            })
                                    )
                                )
                        )
                    }
                    //it is a fit from the fitting plugin
                    else if (state.type === "plugin") {
                        container.content(
                            W2.html("div")
                                .class("form-group")
                                .content(
                                    W2.html("label")
                                        .attribute("for", W2.getID("editStockPlugin", true))
                                        .content({!!json_encode(trans('inventory::inv.inventory_type_fitting_plugin_label_short'))!!}),
                                    select2Component({
                                        select2: {
                                            placeholder: "Select a fit",
                                            ajax: {
                                                url: "{{ route("inventory.fittingsLookup") }}"
                                            },
                                            allowClear: true,
                                            dropdownParent: popup.jQuery
                                        },
                                        selectionListeners: [
                                            (selection) => {
                                                state.pluginFit = selection
                                                state.invalidPluginFit = false
                                                mount.update()
                                            }
                                        ],
                                        id: W2.getID("editStockPlugin"),
                                        selection: state.pluginFit
                                    }),
                                )
                                .contentIf(state.invalidPluginFit,
                                    W2.html("small")
                                        .class("text-danger")
                                        .content({!!json_encode(trans('inventory::inv.inventory_stock_select_fit_label'))!!})
                                )
                        )
                    }

                    //data required for any kind of stock
                    //amount
                    container.content(
                        W2.html("div")
                            .class("form-group")
                            .content(
                                W2.html("label")
                                    .attribute("for", W2.getID("editStockAmount", true))
                                    .content({!!json_encode(trans('inventory::inv.inventory_stock_amount_label'))!!}),
                                W2.html("input")
                                    .class("form-control")
                                    .id(W2.getID("editStockAmount"))
                                    .attribute("type", "number")
                                    .attribute("value", state.amount)
                                    .event("change", (e) => {
                                        //update the state and rerender
                                        state.amount = e.currentTarget.value
                                        StockCreationDefaults.amount = state.amount
                                        //no need to update the ui
                                    })
                            )
                    )
                    //warning threshold
                    container.content(
                        W2.html("div")
                            .class("form-group")
                            .content(
                                W2.html("label")
                                    .attribute("for", W2.getID("editStockWarningThreshold", true))
                                    .content({!!json_encode(trans('inventory::common.warning_threshold_field'))!!}),
                                W2.html("input")
                                    .class("form-control")
                                    .id(W2.getID("editStockWarningThreshold"))
                                    .attribute("type", "number")
                                    .attribute("value", state.warning_threshold)
                                    .event("change", (e) => {
                                        //update the state and rerender
                                        state.warning_threshold = e.currentTarget.value
                                        StockCreationDefaults.warning_threshold = state.warning_threshold
                                        //no need to update the ui
                                    })
                            )
                    )
                    //location
                    container.content(
                        W2.html("div")
                            .class("form-group")
                            .content(
                                //label
                                W2.html("label")
                                    .attribute("for", W2.getID("editStockLocation", true))
                                    .content({!!json_encode(trans('inventory::common.location_field'))!!}),
                            )
                            .content(
                                select2Component({
                                    select2: {
                                        placeholder: {!!json_encode(trans('inventory::common.locations_all_field'))!!},
                                        ajax: {
                                            url: "{{ route("inventory.locationLookup") }}"
                                        },
                                        allowClear: true,
                                        dropdownParent: popup.jQuery
                                    },
                                    selectionListeners: [
                                        (selection) => {
                                            if (selection) {
                                                //set location
                                                state.location = selection
                                                StockCreationDefaults.location = state.location
                                            }
                                            state.invalidLocation = false
                                            //update ui to switch location selection stage
                                            mount.update()
                                        }
                                    ],
                                    id: W2.getID("editStockLocation"),
                                    selection: state.location
                                })
                            )
                            .contentIf(state.invalidLocation,
                                W2.html("small")
                                    .class("text-danger")
                                    .content({!!json_encode(trans('inventory::common.location_select_message'))!!})
                            )
                    )
                    //priority
                    container.content(
                        W2.html("div")
                            .class("form-group")
                            .content(
                                W2.html("label")
                                    .attribute("for", W2.getID("editStockPriority", true))
                                    .content({!!json_encode(trans('inventory::common.priority_field'))!!}),
                                W2.html("select")
                                    .class("form-control")
                                    .id(W2.getID("editStockPriority"))
                                    //add options
                                    .content((container) => {
                                        //add one entry for each option
                                        for (const priority of priorities) {
                                            container.content(
                                                W2.html("option")
                                                    .content(priority.name)
                                                    .attribute("value", priority.priority)
                                                    .attributeIf(state.priority === priority.priority, "selected", true)
                                            )
                                        }
                                    }),
                            )
                            .event("change", (e) => {
                                //update the state and rerender
                                state.priority = parseInt(e.target.value)
                                StockCreationDefaults.priority = state.priority
                                //no need to update the ui
                            })
                    )

                    //add bottom button bar
                    container.content(
                        //flexbox container for buttons
                        W2.html("div")
                            .class("d-flex")

                            //delete button
                            //only show the stock delete button if we edit one
                            .contentIf(stock.id,
                                confirmButtonComponent({!!json_encode(trans('inventory::common.delete_btn'))!!}, async () => {

                                    //make deletion request
                                    const response = await jsonPostAction("{{ route("inventory.deleteStock") }}", {
                                        id: stock.id
                                    })

                                    //check response status
                                    if (response.ok) {
                                        BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_stock_label'))!!}, {!!json_encode(trans('inventory::inv.inventory_stock_delete_success'))!!})
                                        popup.close()
                                    } else {
                                        BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_stock_label'))!!}, {!!json_encode(trans('inventory::common.error_failed_to_delete_stock'))!!})
                                    }

                                    //reload categories
                                    app.categoryList.state.loadData()
                                })
                            )

                            //close button
                            .content(
                                W2.html("button")
                                    .class("btn btn-secondary ml-auto")
                                    .content({!!json_encode(trans('inventory::common.close_btn'))!!})
                                    .event("click", () => {
                                        //close popup when close button is pressed
                                        popup.close()
                                    })
                            )

                            //save button
                            .content(
                                W2.html("button")
                                    .class("btn btn-primary ml-1")
                                    .content({!!json_encode(trans('inventory::common.save_btn'))!!})
                                    .event("click", async () => {
                                        //save the stock

                                        let invalidData = false

                                        if (state.location === null) {
                                            invalidData = true
                                            state.invalidLocation = true
                                        } else {
                                            state.invalidLocation = false
                                        }

                                        if (state.type === "fit" && state.fit.length === 0) {
                                            state.invalidFit = true
                                            invalidData = true
                                        } else {
                                            state.invalidFit = false
                                        }

                                        if (state.type === "multibuy" && state.name.length === 0) {
                                            state.invalidName = true
                                            invalidData = true
                                        } else {
                                            state.invalidName = false
                                        }

                                        if (state.type === "plugin" && !state.pluginFit) {
                                            state.invalidPluginFit = true
                                            invalidData = true
                                        } else {
                                            state.invalidPluginFit = false
                                        }

                                        //update for validation
                                        mount.update()

                                        if (invalidData) {
                                            return
                                        }

                                        const data = {
                                            id: stock.id,
                                            location: state.location.id,
                                            amount: state.amount,
                                            warning_threshold: state.warning_threshold,
                                            priority: state.priority,
                                            workspace: app.workspace.id
                                        }
                                        if (state.type === "fit") {
                                            data.fit = state.fit
                                        } else if (state.type === "multibuy") {
                                            data.multibuy = state.multibuy
                                            data.name = state.name
                                        } else if (state.type === "plugin") {
                                            data.plugin_fitting_id = state.pluginFit.id
                                        }

                                        const response = await jsonPostAction("{{ route("inventory.saveStock") }}", data)

                                        //check response status
                                        if (response.ok) {
                                            BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_stock_label'))!!}, {!!json_encode(trans('inventory::inv.inventory_save_success'))!!})
                                        } else {
                                            const msg = (await response.json()).message || "No reason provided"
                                            BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_stock_label'))!!}, {!!json_encode(trans('inventory::common.error_failed_to_save_stock'))!!}+": "+msg)
                                        }

                                        //reload categories
                                        app.categoryList.state.loadData()

                                        //if it is saved, close the popup
                                        if (response.ok) {
                                            popup.close()
                                        } else {
                                            mount.update()
                                        }
                                    })
                            )
                    )
                })

                async function loadMultibuy(id) {
                    const response = await jsonPostAction("{{ route("inventory.exportItems") }}", {
                        stocks: [id]
                    })

                    if (!response.ok) {
                        BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_stock_label'))!!}, {!!json_encode(trans('inventory::common.error_failed_to_load_items'))!!})
                        return
                    }

                    const data = await response.json()
                    state.multibuy = generateMultiBuy(data.items)
                    mount.update()
                }

                //load items as multibuy if it is an existing stock
                if (stock.id) {
                    loadMultibuy(stock.id)
                }

                container.content(mount)
            })
        }

        function stockItemsComponent(stockIds, onlyMissing = false, location = null) {
            const state = {
                items: [],
                showMultibuy: false,
                showTypeTriState: 0,
                missing_items: [],
                all_items: []
            }

            const getItems = (state) => {
                if (state.showTypeTriState === 0) {
                    return state.missing_items
                } else if (state.showTypeTriState === 1) {
                    return state.items
                } else {
                    return state.all_items
                }
            }

            const mount = W2.mount(state, (container, mount, state) => {
                container.content(
                    W2.html("div")
                        .class("d-flex flex-row justify-content-between")
                        .content(
                            W2.html("ul")
                                .class("nav nav-pills m-2")
                                .content(
                                    W2.html("li")
                                        .class("nav-item nav-link")
                                        .classIf(!state.showMultibuy, "active")
                                        .content(
                                            W2.html("span")
                                                .content({!!json_encode(trans('inventory::inv.inventory_stock_list'))!!})
                                        ).event("click", () => {
                                        state.showMultibuy = !state.showMultibuy
                                        mount.update()
                                    }),
                                    W2.html("li")
                                        .class("nav-item nav-link")
                                        .classIf(state.showMultibuy, "active")
                                        .content(
                                            W2.html("span")
                                                .content({!!json_encode(trans('inventory::inv.inventory_type_multibuy_label'))!!})
                                        ).event("click", () => {
                                        state.showMultibuy = !state.showMultibuy
                                        mount.update()
                                    }),
                                ),

                            W2.html("ul")
                                .class("nav nav-pills m-2")
                                .content(
                                    W2.html("li")
                                        .class("nav-item nav-link")
                                        .classIf(state.showTypeTriState === 0, "active")
                                        .content(
                                            W2.html("span")
                                                .content({!!json_encode(trans('inventory::inv.inventory_stock_missing'))!!})
                                        ).event("click", () => {
                                        state.showTypeTriState = 0
                                        mount.update()
                                    })
                                )
                                .contentIf(!onlyMissing,
                                    W2.html("li")
                                        .class("nav-item nav-link")
                                        .classIf(state.showTypeTriState === 1, "active")
                                        .content(
                                            W2.html("span")
                                                .content({!!json_encode(trans('inventory::inv.inventory_stock_one'))!!})
                                        ).event("click", () => {
                                        state.showTypeTriState = 1
                                        mount.update()
                                    }),
                                    W2.html("li")
                                        .class("nav-item nav-link")
                                        .classIf(state.showTypeTriState === 2, "active")
                                        .content(
                                            W2.html("span")
                                                .content({!!json_encode(trans('inventory::inv.inventory_stock_all'))!!})
                                        ).event("click", () => {
                                        state.showTypeTriState = 2
                                        mount.update()
                                    }),
                                ),
                        )
                ).contentIf(state.showMultibuy,
                    W2.html("textarea")
                        .class("form-control w-100 flex-grow-1")
                        .style("resize", "none")
                        .attribute("readonly", "readonly")
                        .attribute("rows", 10)
                        .content(
                            generateMultiBuy(getItems(state))
                        )
                ).contentIf(!state.showMultibuy,
                    W2.html("div")
                        .content(
                            W2.html("table")
                                .class("table table-borderless table-striped")
                                .content(
                                    W2.html("tbody")
                                        .content((container) => {
                                            for (const item of getItems(state)) {
                                                container.content(
                                                    W2.html("tr")
                                                        .content(
                                                            W2.html("td")
                                                                .content(
                                                                    W2.html("img")
                                                                        .attribute("src", `https://images.evetech.net/types/${item.type_id}/icon?size=32`)
                                                                        .style("min-width", "32px")
                                                                ),
                                                            W2.html("td")
                                                                .content(item.name),
                                                            W2.html("td")
                                                                .content(item.amount),
                                                        )
                                                )
                                            }
                                        })
                                )
                        )
                ).contentIf(HAS_ALLIANCE_INDUSTRY_PLUGIN,
                    W2.html("button")
                        .class("btn btn-secondary btn-block mt-1")
                        .content({!!json_encode(trans('inventory::common.create_alliance_industry_order'))!!})
                        .event("click", () => {
                            const data = {
                                items: getItems(state).map((e) => {
                                    return {
                                        type_id: e.type_id,
                                        amount: e.amount
                                    }
                                }),
                                location: location ? location.structure_id || location.station_id : null
                            }

                            //because we want to change the page displayed too, we have to use a form
                            const form = document.createElement("form")
                            form.method = "POST"
                            form.action = "{{ route("inventory.orderItemsAllianceIndustry") }}"
                            form.target = "_blank"

                            const csrf = document.createElement("input")
                            csrf.type = "hidden"
                            csrf.name = "_token"
                            csrf.value = "{{ csrf_token() }}"
                            form.appendChild(csrf)

                            const items = document.createElement("input")
                            items.type = "hidden"
                            items.name = "items"
                            items.value = JSON.stringify(data)
                            form.appendChild(items)

                            document.body.appendChild(form)
                            form.submit()

                            console.log(data)
                        })
                )
            })

            async function loadItems(stockIds) {
                const request = await jsonPostAction("{{ route("inventory.exportItems") }}", {
                    stocks: stockIds
                })
                if (!request.ok) {
                    BoostrapToast.open({!!json_encode(trans('inventory::inv.inventory_stock_items'))!!}, {!!json_encode(trans('inventory::common.error_failed_to_load_items'))!!})
                    return
                }
                const response = await request.json()
                mount.state.items = response.items
                mount.state.missing_items = response.missing_items
                mount.state.all_items = response.all
                mount.update()
            }

            loadItems(stockIds)

            return mount
        }

        async function stockInfoPopUp(app, stock) {
            function dataEntry(name, value) {
                return W2.html("tr")
                    .content(
                        W2.html("td").content(name),
                        W2.html("td").content(value)
                    )
            }

            function booleanIcon(bool) {
                if (bool) {
                    return W2.html("i").class("fas fa-check text-success")
                } else {
                    return W2.html("i").class("fas fa-times text-danger")
                }
            }

            const available = stock.available

            BootstrapPopUp.open(stock.name, (container) => {
                container.content(
                    W2.html("div")
                        .class("d-flex flex-row")
                        .content(
                            W2.html("div")
                                .class("card")
                                .content(
                                    W2.html("div")
                                        .class("card-body")
                                        .content(
                                            W2.html("h5")
                                                .content({!!json_encode(trans('inventory::common.attributes_header'))!!}),
                                            W2.html("table")
                                                .class("table table-striped")
                                                .content(
                                                    W2.html("thead")
                                                        .content(
                                                            W2.html("tr")
                                                                .content(
                                                                    W2.html("th")
                                                                        .content({!!json_encode(trans('inventory::common.attribute_header'))!!}),
                                                                    W2.html("th")
                                                                        .content({!!json_encode(trans('inventory::common.value_header'))!!}),
                                                                )
                                                        ),
                                                    W2.html("tbody")
                                                        .content(
                                                            dataEntry({!!json_encode(trans('inventory::common.name_field'))!!}, stock.name),
                                                            dataEntry({!!json_encode(trans('inventory::common.location_field'))!!}, stock.location.name),
                                                            dataEntry({!!json_encode(trans('inventory::common.last_updated_field'))!!}, stock.last_updated || {!!json_encode(trans('inventory::common.never_value'))!!}),
                                                            dataEntry({!!json_encode(trans('inventory::inv.inventory_stock_amount_label'))!!}, stock.amount),
                                                            dataEntry({!!json_encode(trans('inventory::common.warning_threshold_field'))!!}, stock.warning_threshold),
                                                            dataEntry({!!json_encode(trans('inventory::common.priority_field'))!!}, getStockPriorityName(stock.priority)),
                                                            dataEntry({!!json_encode(trans('inventory::common.available_field'))!!}, available),
                                                            (container) => {
                                                                for (const level of stock.levels) {
                                                                    container.content(dataEntry(SourceTypeHelper.getFullName(level.source_type), level.amount))
                                                                }
                                                            },
                                                            dataEntry({!!json_encode(trans('inventory::common.minimal_amount_fullfilled_field'))!!}, booleanIcon(available < stock.warning_threshold)),
                                                            dataEntry({!!json_encode(trans('inventory::common.fitting_link_field'))!!}, booleanIcon(stock.fitting_plugin_fitting_id)),
                                                            dataEntry({!!json_encode(trans('inventory::inv.inventory_groups_label'))!!}, W2.emptyHtml().content((container) => {
                                                                for (const category of stock.categories) {
                                                                    container.content(
                                                                        W2.html("span")
                                                                            .class("badge badge-primary mr-1")
                                                                            .content(category.name)
                                                                    )
                                                                }
                                                            })),
                                                        )
                                                )
                                        ),
                                )
                        )
                        .content(
                            W2.html("div")
                                .class("card ml-2 flex-grow-1")
                                .content(
                                    W2.html("div")
                                        .class("card-body d-flex flex-column")
                                        .content(
                                            W2.html("h5")
                                                .content({!!json_encode(trans('inventory::inv.inventory_stock_items'))!!}),
                                            stockItemsComponent([stock.id], false, stock.location)
                                        )
                                )
                        )
                )
            })
        }

        //deliveries popup
        function deliveriesPopup(workspace) {
            BootstrapPopUp.open({!!json_encode(trans('inventory::common.deliveries_popup_title'))!!}, (container, popup) => {
                const state = {
                    addPanel: true,
                    deliveryPreview: null,
                    location: null,
                    items: "",
                    message: "",
                    deliveries: [],
                }

                const mount = W2.mount(state, (container, mount, state) => {
                    container.content(
                        W2.html("div")
                            .class("d-flex flex-row w-100 h-100")
                            .content(
                                //sidebar panel
                                W2.html("div")
                                    .class("d-flex flex-column mr-3")
                                    .content(
                                        //button to add a new delivery
                                        W2.html("button")
                                            .class("btn btn-primary btn-block")
                                            .content({!!json_encode(trans('inventory::common.add_delivery_btn'))!!})
                                            .event("click", (e) => {
                                                e.target.blur()
                                                state.addPanel = true
                                                mount.update()
                                            }),

                                        //different deliveries
                                        (container) => {
                                            for (const delivery of state.deliveries) {
                                                container.content(
                                                    //different deliveries
                                                    W2.html("button")
                                                        .class("btn btn-secondary btn-block")
                                                        .content(delivery.location.name)
                                                        .event("click", (e) => {
                                                            e.target.blur()
                                                            state.deliveryPreview = delivery
                                                            state.addPanel = false
                                                            mount.update()
                                                        }),
                                                )
                                            }
                                        }
                                    ),
                                // main panel wrapper
                                W2.html("div")
                                    .class("d-flex flex-column flex-fill")
                                    .content(
                                        (container) => {
                                            if (state.addPanel) {
                                                //panel to add a new stock
                                                container.content(
                                                    //textarea+label group
                                                    W2.html("div")
                                                        .class("form-group w-100")
                                                        .content(
                                                            W2.html("label")
                                                                .content({!!json_encode(trans('inventory::inv.inventory_stock_items'))!!}),
                                                            W2.html("textarea")
                                                                .class("form-control w-100")
                                                                .style("resize", "none")
                                                                .attribute("placeholder", "Co - Processor II 2\nDrone Damage Amplifier II 1\nTristan 3")
                                                                .attribute("rows", 10)
                                                                .content(state.items)
                                                                .event("change", (e) => {
                                                                    state.items = e.target.value

                                                                    mount.update()
                                                                }),
                                                        ),

                                                    //Location selection
                                                    W2.html("div")
                                                        .class("form-group")
                                                        .content(
                                                            //location selection label
                                                            W2.html("label")
                                                                .attribute("for", W2.getID("deliveriesLocation", true))
                                                                .content({!!json_encode(trans('inventory::common.location_field'))!!}),
                                                        )
                                                        .content(
                                                            select2Component({
                                                                select2: {
                                                                    placeholder: {!!json_encode(trans('inventory::common.location_select_btn'))!!},
                                                                    ajax: {
                                                                        url: "{{ route("inventory.locationLookup") }}"
                                                                    },
                                                                    dropdownParent: popup.jQuery
                                                                },
                                                                selectionListeners: [
                                                                    (selection) => {
                                                                        if (selection) {
                                                                            //set location
                                                                            state.location = selection
                                                                        }
                                                                        mount.update()
                                                                    }
                                                                ],
                                                                id: W2.getID("deliveriesLocation"),
                                                                selection: state.location
                                                            })
                                                        ),

                                                    W2.html("p")
                                                        .content(state.message)
                                                        .class("text-danger"),

                                                    //submit button
                                                    W2.html("button")
                                                        .class("btn btn-primary btn-block")
                                                        .content({!!json_encode(trans('inventory::common.save_btn'))!!})
                                                        .event("click", async () => {
                                                            if (state.location == null) {
                                                                state.message = {!!json_encode(trans('inventory::common.location_select_message'))!!}
                                                                mount.update()
                                                                return

                                                            }
                                                            if (state.items.length < 1) {
                                                                state.message = {!!json_encode(trans('inventory::inv.inventory_add_items_message'))!!}
                                                                mount.update()
                                                                return
                                                            }

                                                            //reset message if ok
                                                            state.message = "";

                                                            mount.update()

                                                            const data = {
                                                                items: state.items,
                                                                location: state.location.id,
                                                                workspace: workspace.id
                                                            }

                                                            const response = await jsonPostAction("{{ route("inventory.addDeliveries") }}", data)
                                                            if (!response.ok) {
                                                                state.message = {!!json_encode(trans('inventory::common.error_failed_to_save_delivery'))!!}
                                                                mount.update()
                                                            } else {
                                                                BoostrapToast.open({!!json_encode(trans('inventory::common.delivery_title'))!!}, {!!json_encode(trans('inventory::common.added_delivery_message'))!!})
                                                                await loadDeliveriesData()
                                                            }

                                                        })
                                                )
                                            } else if (state.deliveryPreview === null) {
                                                container.content({!!json_encode(trans('inventory::common.select_delivery_message'))!!})
                                            } else {

                                                //preview for a delivery
                                                container.content(
                                                    W2.html("h5")
                                                        .content(state.deliveryPreview.location.name),
                                                    W2.html("textarea")
                                                        .class("form-control w-100 flex-grow-1 mb-3")
                                                        .style("resize", "none")
                                                        .attribute("readonly", "readonly")
                                                        .attribute("rows", 10)
                                                        .content(
                                                            generateMultiBuy(state.deliveryPreview.items.map((item) => {
                                                                return {
                                                                    amount: item.amount,
                                                                    name: item.type.typeName
                                                                }
                                                            }))
                                                        ),
                                                    confirmButtonComponent({!!json_encode(trans('inventory::common.delete_btn'))!!}, async () => {
                                                        const response = await jsonPostAction("{{ route("inventory.deleteDeliveries") }}", {
                                                            id: state.deliveryPreview.id
                                                        })

                                                        if (!response.ok) {
                                                            BoostrapToast.open({!!json_encode(trans('inventory::common.deliveries_popup_title'))!!}, {!!json_encode(trans('inventory::common.error_failed_to_delete_delivery'))!!})
                                                        } else {
                                                            BoostrapToast.open({!!json_encode(trans('inventory::common.deliveries_popup_title'))!!}, {!!json_encode(trans('inventory::common.delete_delivery_success'))!!})
                                                        }

                                                        await loadDeliveriesData()
                                                        state.deliveryPreview = null
                                                        state.addPanel = true
                                                        mount.update()
                                                    })
                                                )
                                            }
                                        }
                                    ),
                            )
                    )
                })

                container.content(mount)

                async function loadDeliveriesData() {
                    const response = await jsonPostAction("{{ route("inventory.listDeliveries") }}", {
                        workspace: workspace.id
                    })
                    if (!response.ok) {
                        BoostrapToast.open({!!json_encode(trans('inventory::common.deliveries_popup_title'))!!}, {!!json_encode(trans('inventory::common.error_failed_to_load_deliveries'))!!})
                        return
                    }

                    const data = await response.json()
                    state.deliveries = data
                    mount.update()
                }

                loadDeliveriesData()
            })
        }

        //popup to show missing items for a category
        function categoryInfoPopup(category) {
            //open a popup
            BootstrapPopUp.open(category.name, (container, popup) => {
                container.content(
                    W2.html("h5")
                        .content({!!json_encode(trans('inventory::inv.inventory_missing_items'))!!})
                )

                container.content(
                    stockItemsComponent(category.stocks.map((stock) => stock.id), true)
                )
            })
        }

        function toolButtonPanelComponent(app) {
            return W2.html("div")
                .class("d-flex flex-row align-items-center mb-1")
                .content(
                    W2.html("button")
                        .class("btn btn-success ml-auto")
                        .content(W2.html("i").class("fas fa-sync"), " {{trans('inventory::common.update_btn')}}")
                        .event("click", (e) => {
                            e.target.blur()
                            app.categoryList.state.loadData()
                        })
                )
                .content(
                    W2.html("button")
                        .class("btn btn-secondary ml-1")
                        .content(W2.html("i").class("fas fa-truck"), " {{trans('inventory::common.deliveries_popup_title')}}")
                        .event("click", (e) => {
                            e.target.blur()
                            deliveriesPopup(app.workspace)
                        })
                )
                .content(
                    W2.html("a")
                        .class("btn btn-secondary ml-1")
                        .content(
                            W2.html("i").class("fas fa-book"),
                            " {{trans('inventory::common.documentation_label')}}"
                        )
                        .attribute("href", "https://github.com/recursivetree/seat-inventory/blob/master/documentation/seat-inventory.md")
                        .attribute("target", "_blank")
                )
                .content(
                    W2.html("button")
                        .class("btn btn-secondary ml-1")
                        .content({!!json_encode(trans('inventory::common.collapse_all_btn'))!!})
                        .event("click", () => {
                            app.categoryList.state.collapseAll()
                        })
                )
                .content(
                    W2.html("button")
                        .class("btn btn-secondary ml-1")
                        .content({!!json_encode(trans('inventory::common.expand_all_btn'))!!})
                        .event("click", () => {
                            app.categoryList.state.expandAll()
                        })
                )
                .content(
                    W2.html("button")
                        .class("btn btn-primary ml-1")
                        .content(
                            W2.html("i").class("fas fa-plus"),
                            " Stock"
                        )
                        .event("click", () => {
                            editStockPopUp(app, {})
                        })
                )
                .content(
                    W2.html("button")
                        .class("btn btn-primary ml-1")
                        .content(
                            W2.html("i").class("fas fa-plus"),
                            " View"
                        )
                        .event("click", () => {
                            editCategoryPopUp(app, {})
                        })
                )
        }

        class App {
            categoryList
            locationFilter
            workspace
            mount

            constructor() {
                this.workspace = null

                this.mount = W2.mount((container, mount) => {
                    if (this.workspace) {
                        this.categoryList = categoryListComponent(this)
                        this.locationFilter = new LocationFilterComponent({
                            locationListeners: [(location) => {
                                this.categoryList.state.setLocation(location)
                            }]
                        })
                    }

                    container
                        .contentIf(this.workspace, (container) => container.content(this.locationFilter.mount()))
                        .contentIf(this.workspace, toolButtonPanelComponent(this))
                        .contentIf(this.workspace,(c)=>c.content(
                            W2.html("div")
                                .class("d-flex justify-content-end mb-1")
                                .content(
                                    W2.html("span")
                                        .contentIf(this.workspace.data_availability!==null,`Data Availability: ${this.workspace.data_availability} EVE time or later`)
                                        .contentIf(this.workspace.data_availability===null, "Data Availability: Will appear as soon as the first stocks are ready")
                                )
                        ))
                        .contentIf(this.workspace, this.categoryList)
                })
            }

            render() {
                return W2.emptyHtml()
                    .content(this.mount)
            }
        }

        const app = new App();

        const messages = @json(\RecursiveTree\Seat\Inventory\Helpers\LocaleHelper::getWorkspaceMessages());

        W2.emptyHtml()
            .content(workspaceSelector(messages, (workspace) => {
                app.workspace = workspace
                app.mount.update()
            }))
            .content(app.render())
            .addInto("content-target")

    </script>
@endpush



@push("head")
    <style>
        .stock-list-entry:hover {
            background-color: #eee;
        }

        .select2-container {
            width: 100% !important;
        }

        .toast {
            background-color: white;
        }
    </style>
@endpush