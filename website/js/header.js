(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var header = document.querySelector('[data-testid="header"]');
    if (!header) return;

    header.style.backgroundColor = 'hsl(215, 70%, 28%)';
    header.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.1)';
    header.classList.remove('bg-transparent');
  });
})();
