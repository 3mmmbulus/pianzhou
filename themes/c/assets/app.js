(function () {
  var imgs = document.querySelectorAll('main img');
  for (var i = 0; i < imgs.length; i++) {
    var img = imgs[i];
    if (!img.getAttribute('loading')) img.setAttribute('loading', 'lazy');
    if (!img.getAttribute('decoding')) img.setAttribute('decoding', 'async');
  }
})();
