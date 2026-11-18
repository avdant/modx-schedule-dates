/* global Ext, MODx, ScheduleDatesConfig */
(() => {
    'use strict';
    Ext.namespace('ScheduleDates');
    const cfg = ScheduleDatesConfig;
    const params = (operation, extra) => Ext.apply({action: 'events', operation, HTTP_MODAUTH: cfg.auth}, extra || {});
    const request = (operation, extra, success, scope) => MODx.Ajax.request({
        url: cfg.connector, auth: cfg.auth, params: params(operation, extra),
        listeners: {success: {fn: success || Ext.emptyFn, scope}, failure: {fn: Ext.emptyFn}}
    });
    const refreshGrids = () => {
        ['sd-grid-events', 'sd-grid-types'].forEach(id => {
            const grid = Ext.getCmp(id);
            if (grid) grid.getStore().reload();
        });
    };
    const windowDefaults = {
        url: cfg.connector, modal: true, closeAction: 'close', autoHeight: true,
        allowDrop: false, collapsible: false, maximizable: false,
        labelAlign: 'top', formDefaults: {anchor: '100%', msgTarget: 'under'}
    };

    ScheduleDates.EventWindow = function(config) {
        config = config || {};
        const record = Ext.apply({}, config.record || {});
        const date = record.event_date ? Date.parseDate(record.event_date, 'Y-m-d') : (config.defaultDate || new Date());
        record.event_date = date;
        record.active = record.id ? Number(record.active) === 1 : true;
        Ext.applyIf(config, Ext.apply({
            title: record.id ? 'Редактировать событие' : 'Добавить событие',
            width: Math.min(600, Ext.getBody().getViewSize().width - 32),
            record, baseParams: params('save'),
            fields: [
                {xtype: 'hidden', name: 'id', value: record.id || 0},
                {xtype: 'hidden', name: 'date_iso'},
                {xtype: 'datefield', name: 'event_date', fieldLabel: 'Дата события', allowBlank: false,
                    format: 'd.m.Y', altFormats: 'Y-m-d|d.m.Y', startDay: 1,
                    minValue: new Date(1900, 0, 1), maxValue: new Date(2100, 11, 31)},
                {xtype: 'combo', name: 'type', hiddenName: 'type', fieldLabel: 'Тип события',
                    allowBlank: false, editable: false, forceSelection: true, mode: 'local', triggerAction: 'all',
                    store: new Ext.data.JsonStore({fields: ['code', 'name'], data: config.types}),
                    valueField: 'code', displayField: 'name', value: record.type || config.types[0].code,
                    tpl: new Ext.XTemplate('<tpl for="."><div class="x-combo-list-item">{name:htmlEncode}</div></tpl>')},
                {xtype: 'textfield', name: 'title', fieldLabel: 'Название', allowBlank: false, maxLength: 190},
                {xtype: 'textarea', name: 'description', fieldLabel: 'Описание', height: 100, maxLength: 10000},
                {xtype: 'checkbox', name: 'active', boxLabel: 'Опубликовано', inputValue: '1', checked: record.active}
            ],
            listeners: {success: {fn: refreshGrids}}
        }, windowDefaults));
        ScheduleDates.EventWindow.superclass.constructor.call(this, config);
    };
    Ext.extend(ScheduleDates.EventWindow, MODx.Window, {
        submit: function() {
            const form = this.fp.getForm();
            if (!form.isValid()) return;
            form.findField('date_iso').setValue(form.findField('event_date').getValue().format('Y-m-d'));
            ScheduleDates.EventWindow.superclass.submit.call(this);
        }
    });
    Ext.reg('sd-window-event', ScheduleDates.EventWindow);

    ScheduleDates.TypeWindow = function(config) {
        config = config || {};
        const record = config.record || {};
        Ext.applyIf(config, Ext.apply({
            title: record.id ? 'Редактировать тип события' : 'Добавить тип события',
            width: Math.min(500, Ext.getBody().getViewSize().width - 32),
            record, baseParams: params('saveType'),
            fields: [
                {xtype: 'hidden', name: 'id', value: record.id || 0},
                {xtype: 'textfield', name: 'name', fieldLabel: 'Название', allowBlank: false, maxLength: 190},
                {xtype: 'textfield', name: 'code', fieldLabel: 'Код для фильтра', allowBlank: false, maxLength: 20,
                    regex: /^[a-z][a-z0-9_-]{0,19}$/,
                    regexText: 'Строчные латинские буквы, цифры, дефис или подчёркивание. Первый символ — буква.'},
                {xtype: 'displayfield', value: 'При изменении кода обновите параметр &amp;type в вызовах сниппета.'}
            ],
            listeners: {success: {fn: refreshGrids}}
        }, windowDefaults));
        ScheduleDates.TypeWindow.superclass.constructor.call(this, config);
    };
    Ext.extend(ScheduleDates.TypeWindow, MODx.Window);
    Ext.reg('sd-window-type', ScheduleDates.TypeWindow);

    ScheduleDates.Grid = function(config) {
        config = config || {};
        const isEvent = config.kind === 'events';
        this.kind = config.kind;
        this.year = new Date().getFullYear();
        const toolbar = [
            {text: isEvent ? 'Добавить событие' : 'Добавить тип', cls: 'primary-button', handler: this.createRow, scope: this},
            {text: 'Редактировать', handler: this.editRow, scope: this},
            {text: 'Удалить', handler: this.removeRow, scope: this}
        ];
        toolbar.push('->');
        if (isEvent) toolbar.push({
            xtype: 'container', layout: 'form', cls: 'grid-filter', width: 110,
            itemId: 'sd-year-filter-wrap', defaults: {anchor: '100%'},
            items: [{xtype: 'label', html: 'Год', forId: 'sd-year-filter-input'}, {
            xtype: 'combo', id: 'sd-year-filter-input', hideLabel: true, value: this.year, itemId: 'sd-year-filter',
            emptyText: 'Год', mode: 'local', editable: false, forceSelection: true,
            triggerAction: 'all', valueField: 'year', displayField: 'year',
            store: new Ext.data.ArrayStore({fields: [{name: 'year', type: 'int'}],
                data: Array.from({length: 201}, (_, index) => [2100 - index])}),
            listeners: {select: {fn: function(field, record) {
                const year = record.get('year');
                if (year === this.year) return;
                this.year = year;
                this.getStore().setBaseParam('year', year);
                this.loadFirstPage();
            }, scope: this}}
        }]
        });
        const queryId = isEvent ? 'sd-events-query' : 'sd-types-query';
        toolbar.push({
            xtype: 'container', layout: 'form', cls: 'grid-filter', width: 180,
            defaults: {anchor: '100%'},
            items: [{xtype: 'label', html: 'Поиск', forId: queryId}, {
            xtype: 'textfield', id: queryId, hideLabel: true, emptyText: 'Поиск…', enableKeyEvents: true,
            listeners: {keyup: {fn: function(field) {
                this.getStore().setBaseParam('query', field.getValue());
                this.loadFirstPage();
            }, buffer: 350, scope: this}}}]
        });
        Ext.applyIf(config, {
            id: isEvent ? 'sd-grid-events' : 'sd-grid-types',
            url: cfg.connector, baseParams: params(isEvent ? 'gridEvents' : 'gridTypes', isEvent ? {year: this.year} : {}),
            fields: isEvent ? ['id', 'event_date', 'title', 'description', 'type', 'type_name', 'active'] : ['id', 'name', 'code'],
            columns: isEvent ? [
                {header: 'Дата', dataIndex: 'event_date', width: 110, sortable: true, renderer: value => value ? value.split('-').reverse().join('.') : ''},
                {header: 'Название', dataIndex: 'title', width: 300, sortable: true, renderer: Ext.util.Format.htmlEncode},
                {header: 'Тип события', dataIndex: 'type_name', width: 230, sortable: true, renderer: Ext.util.Format.htmlEncode},
                {header: 'Опубликовано', dataIndex: 'active', width: 110, sortable: true, renderer: value => Number(value) === 1 ? 'Да' : 'Нет'}
            ] : [
                {header: 'Название', dataIndex: 'name', width: 350, sortable: true, renderer: Ext.util.Format.htmlEncode},
                {header: 'Код', dataIndex: 'code', width: 250, sortable: true, renderer: Ext.util.Format.htmlEncode}
            ],
            paging: true, pageSize: 20, remoteSort: true, sortBy: isEvent ? 'event_date' : 'name', sortDir: 'ASC',
            autoHeight: true, anchor: '100%', collapsible: false, showActionsColumn: false,
            sm: new Ext.grid.RowSelectionModel({singleSelect: true}),
            emptyText: isEvent ? 'Событий за выбранный год нет.' : 'Типов событий пока нет.',
            tbar: {cls: 'has-nested-filters', items: toolbar},
            listeners: {rowdblclick: {fn: this.editRow, scope: this}}
        });
        ScheduleDates.Grid.superclass.constructor.call(this, config);
    };
    Ext.extend(ScheduleDates.Grid, MODx.grid.Grid, {
        loadFirstPage: function() { this.getBottomToolbar().changePage(1); },
        createRow: function() { this.openRow({}); },
        editRow: function() {
            const record = this.getSelectionModel().getSelected();
            if (record) this.openRow(Ext.apply({}, record.data));
            else MODx.msg.alert('Редактирование', 'Сначала выберите строку.');
        },
        openRow: function(record) {
            if (this.kind === 'types') {
                MODx.load({xtype: 'sd-window-type', record}).show();
                return;
            }
            request('gridTypes', {limit: 0}, function(response) {
                if (!response.results.length) {
                    MODx.msg.alert('Типы событий', 'Сначала добавьте тип события на вкладке «Типы событий».');
                    return;
                }
                MODx.load({xtype: 'sd-window-event', record, types: response.results,
                    defaultDate: this.year === new Date().getFullYear() ? new Date() : new Date(this.year, 0, 1)}).show();
            }, this);
        },
        removeRow: function() {
            const record = this.getSelectionModel().getSelected();
            if (!record) { MODx.msg.alert('Удаление', 'Сначала выберите строку.'); return; }
            const isEvent = this.kind === 'events';
            const message = isEvent ? 'Удалить выбранное событие?' : 'Удалить выбранный тип? Тип, используемый в событиях, удалить нельзя.';
            Ext.Msg.confirm('Подтверждение удаления', message, function(button) {
                if (button !== 'yes') return;
                request(isEvent ? 'remove' : 'removeType', {id: record.get('id')}, refreshGrids);
            }, this);
        },
        getMenu: function() {
            return [{text: 'Редактировать', handler: this.editRow, scope: this},
                {text: 'Удалить', handler: this.removeRow, scope: this}];
        }
    });
    Ext.reg('sd-grid', ScheduleDates.Grid);

    Ext.onReady(() => MODx.add({
        xtype: 'panel', id: 'sd-panel', cls: 'container', border: false, autoHeight: true,
        items: [{xtype: 'modx-header', html: 'ScheduleDates'}, {
            xtype: 'modx-tabs', id: 'sd-tabs', activeTab: 0,
            items: [
                {title: 'Даты событий', layout: 'anchor', items: [{xtype: 'sd-grid', kind: 'events', cls: 'main-wrapper'}]},
                {title: 'Типы событий', layout: 'anchor', items: [{xtype: 'sd-grid', kind: 'types', cls: 'main-wrapper'}]}
            ]
        }]
    }));
})();
