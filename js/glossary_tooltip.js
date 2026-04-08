(function ($, Drupal) {
  Drupal.behaviors.glossaryTooltip = {
    attach: function (context) {
      $('.glossary-tooltip', context).once('glossary-tooltip').each(function () {
        var $tooltip_element = $(this);

        $tooltip_element.on('mouseenter', function () {
          $tooltip_element.addClass('is-active');
        });

        $tooltip_element.on('mouseleave', function () {
          $tooltip_element.removeClass('is-active');
        });

        $tooltip_element.on('focusin', function () {
          $tooltip_element.addClass('is-active');
        });

        $tooltip_element.on('focusout', function () {
          $tooltip_element.removeClass('is-active');
        });
      });
    }
  };
})(jQuery, Drupal);
