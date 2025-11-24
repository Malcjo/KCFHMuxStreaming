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



  function normalise(str){
    return (str || '').toString().toLowerCase().trim();
  }

  function filterCards(term){
    var needle = normalise(term);

    $('#kcfhGallery .kcfh-card').each(function(){
      var $card = $(this);
      var name = normalise($card.data('name'));

      if(!needle || name.indexOf(needle) !== -1){
        $card.show();
      } else {
        $card.hide();
      }
    });
  }

  $(function(){
    var $form = $('#kcfhSearchForm');
    var $input = $('#kcfhSearchInput');
    var $clear = $('#kcfhSearchClear');

    if(!$form.length) return; //no search bar on the page

    //Prevent the form submission from reloading the page
    $form.on('submit', function(e){
      e.preventDefault();
      filterCards($input.val());
    });

    // Live filtering as you type
    $input.on('input', function(){
      filterCards($input.val());
    });

    //clear button
    $clear.on('click', function(){
      $input.val('');
      filterCards(');')
    });
  });
})(jQuery);
