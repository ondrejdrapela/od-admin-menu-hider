/* OD Skrývač admin menu – administrace (2.0.0). */

/* ---------- pořadí levého menu ---------- */
jQuery(function ($) {
    var $root  = $('#od-amh-sortable');
    var $field = $('#od-amh-order');
    if (!$root.length || !$.fn.sortable) { return; }

    function collect() {
        var data = { top: [], sub: {} };
        $root.children('li').each(function () {
            var $li  = $(this);
            var slug = $li.attr('data-slug');
            data.top.push(slug);

            var subs = [];
            $li.children('ul.od-amh-subl').children('li').each(function () {
                subs.push($(this).attr('data-slug'));
            });
            if (subs.length) { data.sub[slug] = subs; }
        });
        $field.val(JSON.stringify(data));
    }

    $root.sortable({
        axis: 'y',
        items: '> li',
        handle: '> .od-amh-row',
        cancel: '.od-amh-toggle',
        placeholder: 'od-amh-placeholder',
        forcePlaceholderSize: true,
        update: collect
    });

    $root.find('ul.od-amh-subl').sortable({
        axis: 'y',
        items: '> li',
        placeholder: 'od-amh-placeholder',
        forcePlaceholderSize: true,
        update: collect
    });

    $root.on('click', '.od-amh-toggle', function () {
        var $btn = $(this);
        var $sub = $btn.closest('li').children('ul.od-amh-subl');
        var open = $btn.attr('aria-expanded') === 'true';
        $btn.attr('aria-expanded', open ? 'false' : 'true');
        $sub.prop('hidden', open);
    });

    collect();
});

jQuery(function ($) {

    /* ---------- sekce v bočním menu ---------- */
    var $tabs  = $('.od-amh-sb-nav a[data-tab]');
    var $title = $('#od-amh-title');
    var $sbToggle = $('#od-amh-sb-toggle');

    function showTab(target) {
        var $link = $tabs.filter('[href="' + target + '"]');
        if (!$link.length || !$(target).length) { return false; }
        $tabs.removeClass('is-active').removeAttr('aria-current');
        $link.addClass('is-active').attr('aria-current', 'page');
        $('.od-amh-panel').prop('hidden', true);
        $(target).prop('hidden', false);
        $title.text($link.attr('data-title') || $link.text());
        return true;
    }

    $tabs.on('click', function (e) {
        e.preventDefault();
        var target = $(this).attr('href');
        if (showTab(target)) {
            try { window.localStorage.setItem('odAmhTab', target); } catch (err) {}
        }
        $sbToggle.prop('checked', false);   // na mobilu menu zavřeme
    });

    try {
        var saved = window.localStorage.getItem('odAmhTab');
        if (saved) { showTab(saved); }
    } catch (err) {}

    /* ---------- výběr ikony ---------- */
    var $pop    = $('#od-amh-icon-pop');
    var $search = $('#od-amh-icon-search');
    var $home   = $('#od-amh-app');
    var $field  = null;

    function closePop() {
        if ($pop.prop('hidden')) { return; }
        $pop.prop('hidden', true).appendTo($home);
        $field = null;
    }

    $(document).on('click', '.od-amh-icon-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $this = $(this);
        var $new  = $this.closest('.od-amh-icon-field');

        if ($field && $field.is($new) && !$pop.prop('hidden')) { closePop(); return; }

        $field = $new;
        $pop.appendTo($field).prop('hidden', false).css({ top: $this.outerHeight() + 4, left: 0 });
        $search.val('').trigger('input').trigger('focus');
    });

    $search.on('input', function () {
        var q = $.trim($(this).val()).toLowerCase();
        $pop.find('.od-amh-icon-opt').each(function () {
            var icon = $(this).attr('data-icon') || '';
            $(this).toggle(!q || icon.indexOf(q) !== -1);
        });
    }).on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); }   // ať se neodešle formulář
        if (e.key === 'Escape') { closePop(); }
    });

    $pop.on('click', function (e) { e.stopPropagation(); });

    $pop.on('click', '.od-amh-icon-opt', function (e) {
        e.preventDefault();
        if (!$field) { return; }
        var icon = $(this).attr('data-icon') || '';
        $field.find('.od-amh-icon-input').val(icon);
        $field.find('.od-amh-icon-btn').toggleClass('has-icon', !!icon)
            .find('.dashicons').attr('class', 'dashicons ' + (icon || 'dashicons-plus-alt2'));
        $field.find('.od-amh-icon-name').text(icon ? icon.replace(/^dashicons-/, '') : 'vybrat');
        closePop();
    });

    $(document).on('click', closePop);

    /* ---------- vlastní položky lišty ---------- */
    var $items = $('#od-amh-bar-items');
    var $order = $('#od-amh-bar-order');
    var $count = $('#od-amh-custom-count');
    var $empty = $('#od-amh-bar-empty');
    if (!$items.length) { return; }

    var counter = 0;

    function collectBar() {
        var keys = [];
        $items.children('.od-amh-item-row').each(function () {
            keys.push($(this).attr('data-key'));
        });
        $order.val(JSON.stringify(keys));
        $count.text(keys.length).prop('hidden', !keys.length);
        $empty.prop('hidden', !!keys.length);
    }

    $('#od-amh-add-item').on('click', function () {
        var tpl  = $('#od-amh-item-template').html() || '';
        var html = tpl.replace(/__INDEX__/g, 'new' + (counter++));
        $items.append(html);
        $items.children('.od-amh-item-row').last().find('.od-amh-item-title').trigger('focus');
        collectBar();
    });

    $items.on('click', '.od-amh-item-remove', function () {
        closePop();   // kdyby byla nabídka ikon otevřená právě v mazaném řádku
        $(this).closest('.od-amh-item-row').remove();
        collectBar();
    });

    if ($.fn.sortable) {
        $items.sortable({
            axis: 'y',
            items: '> .od-amh-item-row',
            handle: '.od-amh-item-grip',
            placeholder: 'od-amh-placeholder',
            forcePlaceholderSize: true,
            update: collectBar
        });
    }

    collectBar();
});

/* ---------- aktualizace pluginu na pozadí (jako Finance a Systém Soulado) ---------- */
(function () {
    var a = document.getElementById('od-amh-self-update');
    if (!a || !window.fetch) { return; }
    a.addEventListener('click', function (e) {
        e.preventDefault();
        if (a.classList.contains('is-busy')) { return; }
        var lbl = a.querySelector('span'), href = a.getAttribute('href');
        a.classList.add('is-busy');
        if (lbl) { lbl.textContent = 'Aktualizuji…'; }
        var body = new URLSearchParams({
            action: 'update-plugin',
            plugin: a.getAttribute('data-plugin'),
            slug: 'od-admin-menu-hider',
            _ajax_nonce: a.getAttribute('data-nonce')
        });
        fetch(a.getAttribute('data-ajax'), { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (r && r.success) {
                    if (lbl) { lbl.textContent = 'Hotovo, načítám…'; }
                    location.href = location.href.replace(/([?&])od_amh_upd=[^&]*/, '$1').replace(/[?&]$/, '');
                } else {
                    location.href = href;
                }
            })
            .catch(function () { location.href = href; });
    });
})();
