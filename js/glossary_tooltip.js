(function (Drupal, once) {
  Drupal.behaviors.glossaryTooltip = {
    attach: function (context) {
      once('glossary-tooltip', '.glossary-tooltip', context).forEach(function (element) {
        var tooltip_element = element;

        tooltip_element.addEventListener('mouseenter', function () {
          tooltip_element.classList.add('is-active');
        });

        tooltip_element.addEventListener('mouseleave', function () {
          tooltip_element.classList.remove('is-active');
        });

        tooltip_element.addEventListener('focusin', function () {
          tooltip_element.classList.add('is-active');
        });

        tooltip_element.addEventListener('focusout', function () {
          tooltip_element.classList.remove('is-active');
        });
      });
    }
  };
})(Drupal, once);
