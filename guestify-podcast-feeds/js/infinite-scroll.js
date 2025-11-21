;(function($){
  $(function(){
    $(document).on('click', '#load-more-button', function(e){
      e.preventDefault();

      var btn   = $(this),
          page  = btn.data('page')           || 1,
          feed  = btn.data('feed-url')       || '',
          init  = btn.data('initial-posts')  || 10,
          perpg = btn.data('posts-per-page') || 10;

      $.post( gpfAjax.ajax_url, {
        action          : 'load_more_podcast_episodes',
        nonce           : gpfAjax.nonce,
        page            : page + 1,
        feed            : feed,
        initial_posts   : init,
        posts_per_page  : perpg
      })
      .done(function(resp){
        if ( ! resp.success ) {
          console.error('Load more error:', resp.data);
          btn.prop('disabled', true).text('Error');
          return;
        }

        var html = resp.data || '';
        if ( html.trim().length ) {
          $('#load-more-container').before( html );
          btn.data('page', page + 1);
        } else {
          btn.prop('disabled', true).text('No more episodes');
        }
      })
      .fail(function(xhr, status, err){
        console.error('AJAX failed:', status, err);
        btn.prop('disabled', true).text('Request failed');
      });
    });
  });
})(jQuery);
