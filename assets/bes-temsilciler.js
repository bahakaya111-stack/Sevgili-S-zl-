(function($){
  const state = {
    l0: 0,
    l1: 0,
    l2: 0,
    l3: 0,
    ilName: '',
    page: 1,
    perPage: 24,
    q: '',
    pages: 0,
    total: 0,
    loading: false,
  };

  const $status = $('#bes-status');
  const $bc = $('#bes-bc');
  const $results = $('#bes-results');
  const $skeleton = $('#bes-skeleton');
  const $loadRow = $('#bes-load-row');
  const $loadMore = $('#bes-load-more');

  function setStatus(text, type){
    $status.removeClass('warn').text(text);
    if(type === 'warn') $status.addClass('warn');
  }

  function renderBreadcrumb(){
    const parts = [];
    if(state.ilName){
      parts.push('İl: ' + state.ilName);
    } else {
      const $l0 = $('#bes-level-0 option:selected').text();
      const $l1 = $('#bes-level-1 option:selected').text();
      const $l2 = $('#bes-level-2 option:selected').text();
      const $l3 = $('#bes-level-3 option:selected').text();
      if(state.l0) parts.push('Şube: ' + $l0);
      if(state.l1) parts.push('İl: ' + $l1);
      if(state.l2) parts.push('İlçe: ' + $l2);
      if(state.l3) parts.push('İşyeri: ' + $l3);
    }
    if(state.q) parts.push('Arama: "' + state.q + '"');
    $bc.text(parts.join(' / '));
  }

  function renderCard(item){
    const title = item.name || ((item.ad || '') + ' ' + (item.soyad || '')).trim();
    const photo = item.photo ? `<img class="bes-avatar" src="${item.photo}" alt="${title}">` : `<div class="bes-avatar"></div>`;
    const unvan = item.unvan ? `<div class="bes-card-line"><strong>Ünvan:</strong><span>${item.unvan}</span></div>` : '';
    const kurum = item.kurum ? `<div class="bes-card-line"><strong>İşyeri:</strong><span>${item.kurum}</span></div>` : '';
    const yetkiler = item.yetkiler ? `<div class="bes-card-line"><strong>Görev:</strong><span>${item.yetkiler}</span></div>` : '';
    const levelTag = item.level ? `<span class="bes-tag">${item.level}</span>` : '';
    const telAction = item.telefon_link ? `<a class="bes-primary" href="${item.telefon_link}">Ara</a>` : '';
    const waAction = item.whatsapp ? `<a href="${item.whatsapp}">WhatsApp</a>` : '';

    return `
      <div class="bes-card">
        <div class="bes-card-top">
          ${photo}
          <div>
            <div class="bes-card-title">${title || '-'}</div>
            <div class="bes-card-sub">${item.telefon || ''}</div>
          </div>
        </div>
        <div class="bes-card-body">
          ${unvan}
          ${kurum}
          ${yetkiler}
        </div>
        <div class="bes-tags">${levelTag}</div>
        <div class="bes-card-actions">${telAction}${waAction}</div>
      </div>
    `;
  }

  function renderResults(items, append){
    const html = items.map(renderCard).join('');
    if(append){
      $results.append(html);
    } else {
      $results.html(html);
    }
  }

  function fetchResults(reset){
    if(state.loading) return;
    state.loading = true;
    if(reset){
      state.page = 1;
      $results.empty();
    }
    $skeleton.show();
    setStatus('Yükleniyor...', '');

    $.post(BES_T.ajaxurl, {
      action: 'bes_get_temsilciler',
      nonce: BES_T.nonce,
      l0: state.l0,
      l1: state.l1,
      l2: state.l2,
      l3: state.l3,
      il_name: state.ilName,
      page: state.page,
      per_page: state.perPage,
      q: state.q
    }).done(function(res){
      if(!res || !res.success){
        setStatus('Sonuç alınamadı.', 'warn');
        return;
      }
      const data = res.data || {};
      state.pages = data.pages || 0;
      state.total = data.total || 0;
      renderResults(data.items || [], !reset && state.page > 1);
      const countText = state.total ? `${state.total} temsilci bulundu.` : 'Sonuç bulunamadı.';
      setStatus(countText, state.total ? '' : 'warn');
      $loadRow.toggle(state.page < state.pages);
      renderBreadcrumb();
    }).fail(function(){
      setStatus('Sunucu hatası oluştu.', 'warn');
    }).always(function(){
      state.loading = false;
      $skeleton.hide();
    });
  }

  function populateChildren(level, parentId){
    const $select = $('#bes-level-' + level);
    const $next = $('#bes-level-' + (level + 1));
    if(!$select.length) return;
    $select.prop('disabled', true).html('<option value="">Yükleniyor...</option>');
    $.post(BES_T.ajaxurl, {
      action: 'bes_get_children',
      nonce: BES_T.nonce,
      parent_id: parentId
    }).done(function(res){
      if(!res || !res.success){
        $select.html('<option value="">Birim yok</option>');
        return;
      }
      const items = res.data.items || [];
      let options = '<option value="">Seç</option>';
      items.forEach(function(item){
        options += `<option value="${item.id}">${item.name}</option>`;
      });
      $select.html(options).prop('disabled', false);
      if($next.length){
        $next.prop('disabled', true).html('<option value="">Seç</option>');
      }
    });
  }

  function resetFilters(){
    state.l0 = state.l1 = state.l2 = state.l3 = 0;
    state.ilName = '';
    state.q = '';
    $('#bes-q').val('');
    $('#bes-level-0').val('');
    $('#bes-level-1').prop('disabled', true).html('<option value="">İl seç</option>');
    $('#bes-level-2').prop('disabled', true).html('<option value="">İlçe seç</option>');
    $('#bes-level-3').prop('disabled', true).html('<option value="">İşyeri seç</option>');
    $('.bes-map-btn').removeClass('is-active');
    setStatus('Lütfen bir Şube seçin.', '');
    renderBreadcrumb();
    $results.empty();
    $loadRow.hide();
  }

  function renderMapList(){
    const $map = $('#bes-tr-map');
    if(!$map.length || !window.BES_T_MAP) return;
    const counts = {};
    (BES_T_MAP.data || []).forEach(function(item){
      counts[item[0]] = item[1];
    });
    const names = BES_T_MAP.names || {};
    const entries = Object.keys(names).map(function(code){
      return { code: code, name: names[code], count: counts[code] || 0 };
    }).sort(function(a,b){
      return a.name.localeCompare(b.name, 'tr');
    });

    const buttons = entries.map(function(item){
      const title = item.name + ' (' + item.count + ')';
      return `
        <button class="bes-map-btn" data-il="${item.name}" title="${title}">
          <span>${item.name}</span>
          <span class="bes-map-count">${item.count}</span>
        </button>
      `;
    }).join('');

    $map.html('<div class="bes-map-list">' + buttons + '</div>');
  }

  function bindEvents(){
    $('#bes-level-0').on('change', function(){
      state.l0 = parseInt($(this).val() || 0, 10);
      state.l1 = state.l2 = state.l3 = 0;
      state.ilName = '';
      $('.bes-map-btn').removeClass('is-active');
      if(state.l0){
        populateChildren(1, state.l0);
        fetchResults(true);
      } else {
        resetFilters();
      }
    });

    $('#bes-level-1').on('change', function(){
      state.l1 = parseInt($(this).val() || 0, 10);
      state.l2 = state.l3 = 0;
      state.ilName = '';
      $('.bes-map-btn').removeClass('is-active');
      if(state.l1){
        populateChildren(2, state.l1);
      }
      fetchResults(true);
    });

    $('#bes-level-2').on('change', function(){
      state.l2 = parseInt($(this).val() || 0, 10);
      state.l3 = 0;
      state.ilName = '';
      $('.bes-map-btn').removeClass('is-active');
      if(state.l2){
        populateChildren(3, state.l2);
      }
      fetchResults(true);
    });

    $('#bes-level-3').on('change', function(){
      state.l3 = parseInt($(this).val() || 0, 10);
      state.ilName = '';
      $('.bes-map-btn').removeClass('is-active');
      fetchResults(true);
    });

    $('#bes-reset').on('click', function(){
      resetFilters();
    });

    let searchTimer;
    $('#bes-q').on('input', function(){
      clearTimeout(searchTimer);
      state.q = $(this).val().trim();
      searchTimer = setTimeout(function(){
        if(state.l0 || state.ilName){
          fetchResults(true);
        }
      }, 350);
    });

    $loadMore.on('click', function(){
      if(state.page < state.pages){
        state.page += 1;
        fetchResults(false);
      }
    });

    $(document).on('click', '.bes-map-btn', function(){
      const name = $(this).data('il');
      if(!name) return;
      state.ilName = name;
      state.l0 = state.l1 = state.l2 = state.l3 = 0;
      $('#bes-level-0').val('');
      $('#bes-level-1').prop('disabled', true).html('<option value="">İl seç</option>');
      $('#bes-level-2').prop('disabled', true).html('<option value="">İlçe seç</option>');
      $('#bes-level-3').prop('disabled', true).html('<option value="">İşyeri seç</option>');
      $('.bes-map-btn').removeClass('is-active');
      $(this).addClass('is-active');
      fetchResults(true);
    });
  }

  $(function(){
    if(!$('#bes-results').length) return;
    renderMapList();
    bindEvents();
  });
})(jQuery);
