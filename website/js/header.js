(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var header = document.querySelector('[data-testid="header"]');
    if (!header) return;

    var links = header.querySelectorAll('a, button, span, nav a');
    var logo = header.querySelector('img');

    function applyScrolled() {
      header.style.backgroundColor = 'hsl(215, 70%, 28%)';
      header.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.1)';
      header.style.borderBottom = '1px solid rgba(26, 54, 93, 0.8)';
      header.classList.remove('bg-transparent');
    }

    function applyTop() {
      header.style.backgroundColor = 'transparent';
      header.style.boxShadow = 'none';
      header.style.borderBottom = 'none';
      header.classList.add('bg-transparent');
    }

    if (logo) {
      logo.style.filter = 'brightness(0) invert(1)';
    }

    function handleScroll() {
      if (window.scrollY > 20) {
        applyScrolled();
      } else {
        applyTop();
      }
    }

    window.addEventListener('scroll', handleScroll);
    handleScroll();
  });
})();
