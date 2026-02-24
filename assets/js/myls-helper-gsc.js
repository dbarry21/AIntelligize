(function($){
  function paint($dot, cls){
    $dot.removeClass('is-checking is-ok is-bad is-unknown').addClass(cls);
  }

  function checkIndex($scope){
    const $dot = $scope.find('.myls-status-dot');
    paint($dot, 'is-checking');
    $.post(MYLS_GSC.ajax, {
      action : 'myls_gsc_check_index',
      nonce  : MYLS_GSC.nonce,
      url    : MYLS_GSC.url,
      siteUrl: MYLS_GSC.siteUrl
    }).done(function(res){
      if (res && res.success) {
        paint($dot, res.data.indexed ? 'is-ok' : 'is-bad');
      } else {
        paint($dot, 'is-unknown');
      }
    }).fail(function(){
      paint($dot, 'is-unknown');
    });
  }

  $(document).on('click', '.myls-gsc-submit', function(e){
    e.preventDefault();
    const $wrap = $(this).closest('.myls-gsc-block, .myls-helper-item, .myls-helper-block');
    if (!MYLS_GSC.hasToken) {
      alert('Connect Google in AIntelligize → API Integration → Search Console.');
      return;
    }
    // 1) Check status
    checkIndex($wrap);
    // 2) Kick a re-inspect (fetch-now style). We don’t block on it.
    $.post(MYLS_GSC.ajax, {
      action : 'myls_gsc_request_inspect',
      nonce  : MYLS_GSC.nonce,
      url    : MYLS_GSC.url,
      siteUrl: MYLS_GSC.siteUrl
    });
  });

  $(function(){
    if (!MYLS_GSC.hasToken) return;
    const $wrap = $('.myls-gsc-block').first();
    if ($wrap.length) checkIndex($wrap);
  });
})(jQuery);
