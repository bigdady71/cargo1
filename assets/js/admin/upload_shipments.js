    (function() {
      var searchInput = document.getElementById('userSearch');
      var selectElem = document.getElementById('userSelect');
      if (searchInput && selectElem) {
        searchInput.addEventListener('input', function() {
          var query = this.value.toLowerCase();
          var options = selectElem.options;
          for (var i = 0; i < options.length; i++) {
            var opt = options[i];
            // always show the unassigned option
            if (opt.value === '') {
              opt.style.display = '';
              continue;
            }
 var text  = opt.textContent.toLowerCase();
var phone = (opt.getAttribute('data-phone') || '').toLowerCase();
var uid   = (opt.getAttribute('data-id') || '').toLowerCase();
if (!query || text.includes(query) || phone.includes(query) || uid.includes(query)) {
    opt.style.display = '';
} else {
    opt.style.display = 'none';
}

          }
        });
      }
    })();
