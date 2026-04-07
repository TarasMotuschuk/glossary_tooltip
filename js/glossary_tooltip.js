(function (Drupal, once) {
  Drupal.behaviors.glossaryTooltip = {
    attach: function (context) {
      once('glossary-tooltip', '.glossary-tooltip', context).forEach(function (element) {
        element.addEventListener('mouseenter', function () {
          element.classList.add('is-active');
        });

        element.addEventListener('mouseleave', function () {
          element.classList.remove('is-active');
        });

        element.addEventListener('focusin', function () {
          element.classList.add('is-active');
        });

        element.addEventListener('focusout', function () {
          element.classList.remove('is-active');
        });
      });
    }
  };
})(Drupal, once);
