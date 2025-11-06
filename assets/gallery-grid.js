(function($){
  const cfg = window.KCFH_GALLERY_GRID || {};
  const $searchForm = $('#kcfh-gallery-search');
  const $gridWrap   = $('#kcfh-gallery-grid');

  function renderGridHtml(html){
    $gridWrap.replaceWith(html); // replace the grid container
  }

  function doSearch(q){
    $.post(cfg.ajaxUrl, {
      action: 'kcfh_gallery_search',
      nonce: cfg.nonce,
      q: q || '',
      columns: cfg.columns,
      includeEmpty: cfg.includeEmpty ? 1 : 0
    }).done(function(resp){
      if (resp && resp.success && resp.data && resp.data.html){
        renderGridHtml(resp.data.html);
      }
    });
  }

  function clearClientParam(){
    const url = new URL(window.location.href);
    url.searchParams.delete(cfg.queryParam);
    history.replaceState({}, '', url.toString());
  }

  // If on grid (no ?client=...), keep URL clean on first load after navigation
  if (!new URL(window.location.href).searchParams.get(cfg.queryParam)) {
    clearClientParam();
  }

  if ($searchForm.length){
    const $q = $('#kcfh_gallery_q');
    $('#kcfh_gallery_btn').on('click', () => doSearch($q.val()));
    $('#kcfh_gallery_clear').on('click', () => { $q.val(''); doSearch(''); });
    $q.on('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doSearch($q.val()); } });
  }

})(jQuery);
