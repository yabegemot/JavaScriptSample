require([
        'jquery',
        'kendo.all.min',
        'bundles/smartmain/js/storage',
        'bundles/smartmain/js/common/grid',
        'bundles/smartmain/js/report/renders',
        'bundles/smartmain/js/common/window',
        'bundles/smartmain/js/smart.dates',
        'bundles/smartmain/js/string',
        'kendo.columnmenuexternal'
    ],
    function ($, kendo, storageLib, commonGrid, renders, windowLib) {

        var $grid = $('#report-grid'),
            $gridSettingsKey = $grid.data('settings-id'),
            defaultOptionsGlobal = [],
            autoComplete = null,
            storage = storageLib.loadGridState($gridSettingsKey),
            kendoGrid = null,
            pageSize = false,
            menu = null,
            counting = {
                'report-kendo-grid-reservation': 'reservations',
                'report-kendo-grid-referral-fees': 'reservations',
                'report-kendo-grid-budget-variance': 'requests',
                'report-kendo-grid-average-stay': 'reservations',
                'report-kendo-grid-average-rate': 'reservations',
                'report-kendo-grid-supplier-requests': smart.user.isGranted(smart.roles.ROLE_REQUESTOR) ? 'requests suppliers notified' : 'requests',
                'report-kendo-grid-supplier-company-requests': smart.user.isGranted(smart.roles.ROLE_REQUESTOR) ? 'requests suppliers notified' : 'requests',
                'report-kendo-grid-requests-lost': 'options',
                'report-kendo-grid-supplier-scorecard': 'suppliers'
            },
            countingMessage = typeof counting[$gridSettingsKey] !== 'undefined' ? counting[$gridSettingsKey] : 'items';

        $('#report-configuration-button').click(function (e) {
            e.preventDefault();
            $('#report-configuration').toggle();
            $(this).text($(this).text() === "show filters" ? "hide filters" : "show filters");
        });

        $('#go_back').click(function () {
            parent.history.back();
            return false;
        });

        var saving = false;

        function saveState(options) {
            storageLib.ajaxQueue(function (next) {
                saving = true;

                var saveData = storageLib.createGridStorageSettings(kendoGrid, options);

                if (kendo.stringify(storage.data) !== kendo.stringify(saveData)) {
                    storage.data = saveData;
                    storageLib.saveState(storage, function (jqXHR) {
                        windowLib.popupNotification().show("Filter and column configuration saved.", "success");
                        if (storage.id === null) {
                            storage.id = jqXHR.getResponseHeader('Location').split('/').slice(-1).pop();
                            smart.user.settings.push(storage);
                        }
                        if ($(document).queue("ajax").length >= 1 && !saving) {
                            $(document).dequeue("ajax");
                        }

                        saving = false;
                    });
                } else {
                    saving = false;
                }
            });

            if ($(document).queue("ajax").length >= 1 && !saving) {
                $(document).dequeue("ajax");
            }
        }

        function initMenu() {

            var $menu = $("#menu");

            $menu.kendoMenu({
                openOnClick: true
            });

            initColumnsMenu();

            $('#reset-state').on('click', function () {
                $.when(windowLib.showConfirmationWindow('Reset filter and column configuration?')).then(function (confirmed) {
                    if (confirmed) {
                        storageLib.resetState(storage, $gridSettingsKey, function (newStorage) {
                            storage = newStorage;
                            loadGrid(defaultOptionsGlobal);
                            windowLib.popupNotification().show("Filter and column configuration reset.", "success");
                        });
                    }
                });
            });

            $('#export-csv').click(function () {
                $('<input>').attr('type','hidden').attr('name','form[settingskey]').attr('value',$gridSettingsKey).appendTo('#report-form');
                $('#report-form').submit();
            });

            $("#export-excel").click(function (e) {
                require(['jszip'], function (JSZip) {
                    window.JSZip = JSZip;
                    kendoGrid.saveAsExcel();
                });
            });

            menu = $menu.data('kendoColumnMenuExternal');

        }

        function initAutoControls() {
            autoComplete = $("#search_box").kendoAutoComplete({
                delay: 600,
                width: '150px',
                minLength: 3,
                enforceMinLength: true,
                autoBind: true,
                dataSource: null,
                value: storage.data.filter.search,
                noDataTemplate: null,
                filtering: function (e) {
                    searchBox(this.value());
                    saveState();
                },
                change: function () {
                    searchBox(this.value());
                    saveState();
                }
            });

            autoComplete.focus();

            initMenu();

        }

        function buildOptions(ajaxUrl, defaultOptions) {

            var dataSource = (function () {

                var dataSourceOption = {
                    type: "odata",
                    transport: {
                        read: {
                            url: ajaxUrl,
                            type: "POST",
                            dataType: "json",
                            data: $.String.deparam($('#report-form').serialize())
                        }
                    },
                    page: 1,
                    pageSize: 25,
                    filter: undefined,
                    serverPaging: false,
                    serverSorting: false,
                    serverFiltering: false
                };

                return $.extend(true, dataSourceOption, defaultOptions.dataSource);

            })();

            if (storage.data.dataSource !== null) {
                dataSource = $.extend(true, dataSource, storage.data.dataSource);
            } else {
                dataSource = $.extend(true, {}, dataSource);
            }

            function tableWidth() {
                $('.k-grid-header-wrap table', $grid).width($('.k-grid-header-wrap', $grid).width());
                $('.k-grid-content table', $grid).width($('.k-grid-header-wrap', $grid).width());
            }

            var saveStateHandler = function () {
                saveState();
            };

            var options = $.extend(true, {}, {
                columnHide: function (e) {
                    tableWidth();
                    saveState();
                    if( $gridSettingsKey === 'report-kendo-grid-supplier-scorecard' ) {
                        loadSupplierScorecardSubHeaders(e.sender.columns);
                    }
                },
                columnMenu: false,
                columnReorder: function (e) {

                    var allowReorder = true;
                    // disable cross colgroup reordering for supplier scorecard report
                    if( $gridSettingsKey === 'report-kendo-grid-supplier-scorecard'
                        && typeof e.sender.columns[e.newIndex].attributes.colgroup !== 'undefined'
                        && typeof e.sender.columns[e.oldIndex].attributes.colgroup !== 'undefined'
                        && e.sender.columns[e.newIndex].attributes.colgroup !== e.sender.columns[e.oldIndex].attributes.colgroup
                    ) {
                        allowReorder = false;
                    }

                    if( allowReorder ) {
                        var options = $.extend(true, {}, {
                            movedColumn: e.column,
                            oldIndex: e.oldIndex,
                            newIndex: e.newIndex
                        });
                        initColumnsMenu();
                        saveState(options);
                    } else {
                        var _this = this;
                        setTimeout(function () {
                            _this.reorderColumn(e.oldIndex, e.column);
                        });
                    }

                },
                columnResize: function (e) {
                    if( $gridSettingsKey === 'report-kendo-grid-supplier-scorecard' ) {
                        loadSupplierScorecardSubHeaders(e.sender.columns);
                    }
                    saveState();
                },
                columnShow: function (e) {
                    tableWidth();
                    if( $gridSettingsKey === 'report-kendo-grid-supplier-scorecard' ) {
                        loadSupplierScorecardSubHeaders(e.sender.columns);
                    }
                    saveState();
                },
                sort: function (e) {
                    saveState({dataSource: {sort: [e.sort]}});
                },
                excel: {
                    fileName: "export.xlsx",
                    proxyURL: "//demos.telerik.com/kendo-ui/service/export",
                    filterable: true
                },
                excelExport: function (e) {
                    var sheet = e.workbook.sheets[0];
                    for (var i = 0; i < sheet.rows.length; i++) {
                        for (var ci = 0; ci < sheet.rows[i].cells.length; ci++) {
                            if (sheet.rows[i].cells[ci].value !== undefined &&
                                typeof sheet.rows[i].cells[ci].value === 'string' &&
                                sheet.rows[i].cells[ci].value.charAt(0) === '<') {
                                sheet.rows[i].cells[ci].value = $(sheet.rows[i].cells[ci].value).text();
                            }
                        }
                    }
                },
                filterable: false,
                height: 'auto',
                change: function () {
                },
                dataBound: function (e) {
                    var _height = 0, visible_rows = 10, row_count = 0;
                    $grid.find('.k-grid-content table tr').each(function (index, el) {
                        if (row_count > visible_rows) {
                            return false;
                        }
                        row_count++;
                        _height += rowHeight(el);
                    });
                    var rr = visible_rows - row_count;
                    while (rr > 0) {
                        rr--;
                        _height += rowHeight();
                    }
                    $grid.find('.k-grid-content').height(_height);

                    if( $gridSettingsKey === 'report-kendo-grid-supplier-scorecard' ) {
                        //var columns = e.sender.columns;
                        var dataItems = e.sender.dataSource.view();
                        for (var j = 0; j < dataItems.length; j++) {
                            var row = e.sender.tbody.find("[data-uid='" + dataItems[j].uid + "']");
                            row.find('td[colgroup="option"]').addClass( j % 2 === 0 ? 'alterHighlight1' : 'alterHighlight2' );
                        }
                    }
                },
                dataBinding: function (e) {
                    if( false === pageSize ) {
                        pageSize = e.sender.dataSource.pageSize();
                    } else if( pageSize !== e.sender.dataSource.pageSize() ) {
                        saveState();
                    }
                },
                noRecords: true,
                pageable: {
                    buttonCount: 6,
                    refresh: true,
                    pageSizes: [10, 25, 50, "all"],
                    messages: {
                        display: "Showing {0}-{1} from {2} " + countingMessage,
                        empty: "No "+countingMessage + " to display",
                        itemsPerPage: countingMessage + " per page",
                    }
                },
                reorderable: true,
                resizable: true,
                scrollable: true,
                sortable: {
                    mode: "single",
                    allowUnsort: false
                }
            });

            var toolbar = kendo.template($("#template").html());

            return function () {
                return $.extend(true, {}, $.extend({},
                    options), {
                    columns: commonGrid.buildColumns(defaultOptions.columns, storage.data.columns, renders),
                    dataSource: $.extend(true, {}, dataSource),
                    toolbar: toolbar
                });
            }();
        }

        function rowHeight(el) {
            var h = $(el).outerHeight();
            return h && h > 0 ? h : 39;
        }

        function initColumnsMenu() {

            if (menu) {
                menu.destroy();
            }

            var COLUMNMENUINIT = 'columnMenuExternalInit';
            var grep = $.grep;
            var that = kendoGrid, menu, columns = commonGrid.leafColumns(that.columns), column, options = that.options, columnMenu = options.columnMenu, menuOptions, hasMultiColumnHeaders = grep(that.columns, function (item) {
                    return item.columns !== undefined;
                }).length > 0, isMobile = this._isMobile, initCallback = function (e) {
                that.trigger(COLUMNMENUINIT, {
                    field: e.field,
                    container: e.container
                });
            }, closeCallback = function (element) {
                // focusTable(element.closest('table'), true);
            }, $angular = options.$angular;

            menuOptions = {
                dataSource: kendoGrid.dataSource,
                columns: columnMenu.columns,
                messages: columnMenu.messages,
                owner: kendoGrid,
                closeCallback: closeCallback,
                init: initCallback,
                pane: that.pane,
                filter: isMobile ? ':not(.k-column-active)' : '',
                commands: [
                    {
                        title: 'Reset',
                        icon: 'glyphicon glyphicon-trash',
                        handler: function () {
                            $.when(windowLib.showConfirmationWindow('Reset filter and column configuration?')).then(function (confirmed) {
                                if (confirmed) {
                                    storageLib.resetState(storage, $gridSettingsKey, function (newStorage) {
                                        storage = newStorage;
                                        loadGrid(defaultOptionsGlobal);
                                        windowLib.popupNotification().show("Filter and column configuration reset.", "success");
                                    });
                                }
                            });
                        }
                    }
                ]
            };


            $("#menu").kendoColumnMenuExternal(menuOptions);
        }

        function initGrid(options) {


            $grid.kendoGrid(options);

            kendoGrid = $grid.data("kendoGrid");

            kendoGrid.bind("dataBound", function (e) {
                if (storage.id === null) {
                    kendoGrid.unbind('columnResize', saveState);
                    kendoGrid.autoFitColumn('deadline');
                    kendoGrid.bind('columnResize', saveState);
                }
            });

            if( $gridSettingsKey === 'report-kendo-grid-supplier-scorecard' ) {
                loadSupplierScorecardSubHeaders(options.columns);
            }
        }

        var resetScorecardColumnOrderTimes = 0;

        function loadSupplierScorecardSubHeaders(optionColumns) {

            $('#report-grid[data-settings-id="report-kendo-grid-supplier-scorecard"]')
                .find(".k-grid-header")
                .find(".k-grid-header-wrap")
                .find(".k-sub-header")
                .remove();

            var _isConsistent = true, _colCount = 0, _colgroupChangeCount = 0,
                _colgroup = false, _requestColSpan = 0, _optionColSpan = 0,
                _html = '<table role="grid" class="k-sub-header"><colgroup>';

            for (var c = 0; c < optionColumns.length; c++) {

                var _hidden = typeof optionColumns[c].hidden !== 'undefined' && optionColumns[c].hidden,
                    _width = typeof optionColumns[c].width !== 'undefined' ? optionColumns[c].width : 100,
                    _columnColgroup = typeof optionColumns[c].attributes.colgroup !== 'undefined' ? optionColumns[c].attributes.colgroup : 'request';

                if( _hidden ) {
                    continue;
                }

                _colCount++;

                _html += '<col style="width: '+_width+'px;">';

                if( ! _colgroup ) {
                    _colgroup = _columnColgroup;
                }

                if( _colgroup !== _columnColgroup ) {
                    _colgroupChangeCount++;
                }

                if( _columnColgroup === 'request' ) {
                    _requestColSpan++;
                } else if( _columnColgroup === 'option' ) {
                    _optionColSpan++;
                }

                _colgroup = _columnColgroup;
            }

            _isConsistent = _colgroupChangeCount === 1 && _colCount === (_requestColSpan+_optionColSpan);

            if( _isConsistent ) {

                for (var c = 0; c < optionColumns.length; c++) {
                    if( typeof optionColumns[c].attributes.colgroup !== 'undefined' && optionColumns[c].attributes.colgroup === 'request' ) {
                        $('#report-grid[data-settings-id="report-kendo-grid-supplier-scorecard"]')
                            .find(".k-grid-header")
                            .find(".k-grid-header-wrap")
                            .find("th[data-field='" + optionColumns[c].field + "']").addClass('alterGreen');
                    }
                }

                _html += '</colgroup><thead role="rowgroup" class="k-grid-subheader"><tr role="row">';
                _html += '<th scope="col" colspan="'+_requestColSpan+'" class="k-colspan">Based on Requests</th>';
                _html += '<th scope="col" colspan="'+_optionColSpan+'" class="k-colspan">Based on Options</th>';
                _html += '</tr></thead></table>'
                        //+'</div>'
                        ;
                $('#report-grid[data-settings-id="report-kendo-grid-supplier-scorecard"]')
                    .find(".k-grid-header")
                    .find(".k-grid-header-wrap")
                    .prepend(_html);
            } else if( resetScorecardColumnOrderTimes === 0 ) {
                
                storageLib.resetState(storage, $gridSettingsKey, function (newStorage) {
                    storage = newStorage;
                    loadGrid(defaultOptionsGlobal);
                    loadSupplierScorecardSubHeaders(defaultOptionsGlobal.columns);
                });
                resetScorecardColumnOrderTimes++;
            }
        }

        function loadGrid(defaultOptions) {

            defaultOptionsGlobal = defaultOptions;

            if (kendoGrid !== null) {
                $grid.html('');
                kendoGrid.destroy();
            }

            var options = buildOptions(ajax_route, defaultOptions);

            console.log(options);

            initGrid(options);

            initAutoControls();

        }

        function searchBox(value) {

            var selectedItem = value.toUpperCase();
            var selectedArray = selectedItem.split(" ");
            if (selectedItem) {
                var orFilter = {logic: "or", filters: []};
                var andFilter = {logic: "and", filters: []};
                $.each(selectedArray, function (i, v) {
                    if (v.trim() === "") {
                    }
                    else {
                        $.each(selectedArray, function (i, v1) {
                            if (v1.trim() === "") {
                            } else {
                                var fields = defaultOptionsGlobal.dataSource.schema.model.fields;
                                for (var i in fields) {
                                    if (fields[i].type === 'string') {
                                        orFilter.filters.push({field: fields[i].field, operator: "contains", value: v1});
                                    }
                                }

                                andFilter.filters.push(orFilter);
                                orFilter = {logic: "or", filters: []};
                            }

                        });
                    }
                });

                kendoGrid.dataSource.filter(andFilter);
            } else {
                kendoGrid.dataSource.filter({});
            }
        }

        window.loadGrid = loadGrid;

        $.ajax({
            url: header_route,
            dataType: "script",
            type: 'POST',
            data: $('#report-form').serialize(),
            jsonpCallback: "loadGrid"
            }
        });

    });
