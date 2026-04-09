((Drupal, once) => {
  const ACTIVE_CLASS = 'is-active';
  const MEASURING_CLASS = 'is-measuring';
  const VIEWPORT_PADDING = 16;
  const TOOLTIP_OFFSET = 8;
  const MIN_ARROW_OFFSET = 18;
  const TOUCH_MEDIA_QUERY = '(hover: none), (pointer: coarse)';

  const isTouchLikeDevice = () => {
    if (typeof window.matchMedia !== 'function') {
      return false;
    }

    return window.matchMedia(TOUCH_MEDIA_QUERY).matches;
  };

  const setExpandedState = (tooltipElement, bubbleElement, expanded) => {
    tooltipElement.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    bubbleElement.setAttribute('aria-hidden', expanded ? 'false' : 'true');
  };

  const closeTooltip = tooltipElement => {
    const bubbleElement = tooltipElement.querySelector('.glossary-tooltip__bubble');
    tooltipElement.classList.remove(ACTIVE_CLASS, MEASURING_CLASS);

    if (bubbleElement) {
      setExpandedState(tooltipElement, bubbleElement, false);
    }
  };

  const closeOtherTooltips = currentTooltipElement => {
    document.querySelectorAll(`.glossary-tooltip.${ACTIVE_CLASS}`)
      .forEach(tooltipElement => {
      if (tooltipElement !== currentTooltipElement) {
        closeTooltip(tooltipElement);
      }
    });
  };

  const updatePosition = (tooltipElement, triggerElement, bubbleElement) => {
    tooltipElement.classList.add(MEASURING_CLASS);

    const triggerRect = triggerElement.getBoundingClientRect();
    const bubbleRect = bubbleElement.getBoundingClientRect();
    const bubbleWidth = bubbleRect.width;
    const bubbleHeight = bubbleRect.height;
    const preferredLeft = triggerRect.left + (triggerRect.width / 2) - (bubbleWidth / 2);
    const clampedLeft = Math.min(
      Math.max(preferredLeft, VIEWPORT_PADDING),
      window.innerWidth - bubbleWidth - VIEWPORT_PADDING,
    );
    const openAbove = triggerRect.top >= bubbleHeight + TOOLTIP_OFFSET + VIEWPORT_PADDING;
    const top = openAbove
      ? triggerRect.top - bubbleHeight - TOOLTIP_OFFSET
      : triggerRect.bottom + TOOLTIP_OFFSET;
    const arrowLeft = Math.min(
      Math.max((triggerRect.left + (triggerRect.width / 2)) - clampedLeft, MIN_ARROW_OFFSET),
      bubbleWidth - MIN_ARROW_OFFSET,
    );

    bubbleElement.style.setProperty('--glossary-tooltip-left', `${clampedLeft}px`);
    bubbleElement.style.setProperty('--glossary-tooltip-top', `${top}px`);
    bubbleElement.style.setProperty('--glossary-tooltip-arrow-left', `${arrowLeft}px`);
    tooltipElement.setAttribute('data-placement', openAbove ? 'top' : 'bottom');
    tooltipElement.classList.remove(MEASURING_CLASS);
  };

  const attachTooltip = tooltipElement => {
    const triggerElement = tooltipElement.querySelector('.glossary-tooltip__term');
    const bubbleElement = tooltipElement.querySelector('.glossary-tooltip__bubble');

    if (!triggerElement || !bubbleElement) {
      return;
    }

    const isFocusedWithin = () => tooltipElement.contains(document.activeElement);
    const isOpen = () =>
      tooltipElement.classList.contains(ACTIVE_CLASS)
      || tooltipElement.matches(':hover')
      || isFocusedWithin();

    const openTooltip = ({ persistent = false } = {}) => {
      if (!persistent) {
        closeOtherTooltips(tooltipElement);
      }

      updatePosition(tooltipElement, triggerElement, bubbleElement);

      if (persistent) {
        tooltipElement.classList.add(ACTIVE_CLASS);
      }

      setExpandedState(tooltipElement, bubbleElement, true);
    };

    tooltipElement.addEventListener('mouseenter', () => {
      if (!isTouchLikeDevice()) {
        openTooltip();
      }
    });

    tooltipElement.addEventListener('mouseleave', () => {
      if (!tooltipElement.classList.contains(ACTIVE_CLASS) && !isFocusedWithin()) {
        setExpandedState(tooltipElement, bubbleElement, false);
      }
    });

    tooltipElement.addEventListener('focusin', () => {
      if (!isTouchLikeDevice()) {
        openTooltip();
      }
    });

    tooltipElement.addEventListener('focusout', () => {
      window.requestAnimationFrame(() => {
        if (!tooltipElement.classList.contains(ACTIVE_CLASS) && !isFocusedWithin()) {
          setExpandedState(tooltipElement, bubbleElement, false);
        }
      });
    });

    triggerElement.addEventListener('click', event => {
      if (!isTouchLikeDevice()) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      if (tooltipElement.classList.contains(ACTIVE_CLASS)) {
        closeTooltip(tooltipElement);
        return;
      }

      closeOtherTooltips(tooltipElement);
      openTooltip({ persistent: true });
    });

    tooltipElement.addEventListener('keydown', event => {
      if (event.key === 'Escape') {
        closeTooltip(tooltipElement);
        triggerElement.blur();
      }
    });

    document.addEventListener('click', event => {
      if (tooltipElement.classList.contains(ACTIVE_CLASS) && !tooltipElement.contains(event.target)) {
        closeTooltip(tooltipElement);
      }
    });

    const repositionIfOpen = () => {
      if (!isTouchLikeDevice() && tooltipElement.classList.contains(ACTIVE_CLASS)) {
        closeTooltip(tooltipElement);
      }

      if (isOpen()) {
        updatePosition(tooltipElement, triggerElement, bubbleElement);
      }
    };

    window.addEventListener('resize', repositionIfOpen);
    window.addEventListener('scroll', repositionIfOpen, true);
  };

  Drupal.behaviors.glossaryTooltip = {
    attach: context => {
      once('glossary-tooltip', '.glossary-tooltip', context).forEach(attachTooltip);
    },
  };
})(Drupal, once);
