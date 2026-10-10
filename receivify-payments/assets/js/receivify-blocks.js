/**
 * Registers Receivify with the WooCommerce block checkout. No build step: plain
 * ES5 against the globals WooCommerce and WordPress already load.
 */
(function () {
  var wc = window.wc || {};
  var registry = wc.wcBlocksRegistry;
  var wcSettings = wc.wcSettings;
  var element = window.wp && window.wp.element;
  if (!registry || !wcSettings || !element) return;

  var settings = wcSettings.getPaymentMethodData('receivify', {}) || {};
  var decode = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };
  var title = decode(settings.title || 'Pay with crypto');
  var description = decode(settings.description || '');

  var Content = function () {
    return element.createElement('div', null, description);
  };

  registry.registerPaymentMethod({
    name: 'receivify',
    label: element.createElement('span', null, title),
    ariaLabel: title,
    content: element.createElement(Content, null),
    edit: element.createElement(Content, null),
    canMakePayment: function () { return true; },
    supports: { features: settings.supports || ['products'] },
  });
})();
