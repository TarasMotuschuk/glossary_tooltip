(function ($, Drupal) {
  Drupal.behaviors.glossaryTooltip = {
    attach: function (context) {
      $('.glossary-tooltip', context).once('glossary-tooltip').each(function () {
        var $element = $(this);

        $element.on('mouseenter', function () {
          $element.addClass('is-active');
        });

        $element.on('mouseleave', function () {
          $element.removeClass('is-active');
        });

        $element.on('focusin', function () {
          $element.addClass('is-active');
        });

        $element.on('focusout', function () {
          $element.removeClass('is-active');
        });
      });
    }
  };
})(jQuery, Drupal);
