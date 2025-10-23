(function($){
  function doSearch(q){
    const $grid = $('#kcfhGallery');
    const pageUrl = $grid.data('page-url');

    $.ajax({
      url: KCFH_Gallery.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: KCFH_Gallery.action,
        nonce: KCFH_Gallery.nonce,
        q: q || ''
      }
    }).done(function(resp){
      if (resp && resp.success && resp.data && typeof resp.data.html === 'string') {
        $grid.html(resp.data.html);
        // Preserve base page URL on the container for card links to use
        $grid.attr('data-page-url', pageUrl);
      }
    });
  }

  $(document).on('submit', '#kcfhSearchForm', function(e){
    e.preventDefault();
    const q = $('#kcfhSearchInput').val().trim();
    doSearch(q);
  });

  $(document).on('click', '#kcfhSearchClear', function(){
    $('#kcfhSearchInput').val('');
    doSearch('');
  });
})(jQuery);
