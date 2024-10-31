jQuery(function ($) {
  function initHostedForm(data) {
    const css = `
      .pb-iframe-container { width: 100%; height: 100%; }
      .pb-iframe-container-overlay { background: rgba(0, 0, 0, .3); position: fixed; z-index: 999999; top: 0; left: 0; }
      .pb-iframe { width: 100%; height: 100%; z-index: 999999; border: none; position: fixed; top: 0; left: 0; }
      .pb-iframe-half-size { background: white; margin: auto; right: 0; bottom: 0; box-shadow: 0 0 20px #000; border-radius: 10px; width: 60% !important; height: 90% !important; }
    `;
    const head = document.head || document.getElementsByTagName("head")[0];
    const style = document.createElement("style");

    head.appendChild(style);

    style.type = "text/css";
    style.appendChild(document.createTextNode(css));

    const configs = btoa(JSON.stringify(data));
    const container = document.createElement("DIV");
    container.classList.add("pb-iframe-container");

    const iframe = document.createElement("IFRAME");
    iframe.classList.add("pb-iframe");
    iframe.id = "pb-hf-ifrm";

    if (paybyrd_params.size === "half") {
      container.classList.add("pb-iframe-container-overlay");
      iframe.classList.add("pb-iframe-half-size");
    }

    iframe.src = `https://checkout.paybyrd.com/#/payment?checkoutKey=${data.checkoutKey}&orderId=${data.orderId}&configs=${configs}`;
    iframe.onload = function () {
      document.body.style.overflow = "hidden";
    };

    container.append(iframe);
    document.body.append(container);
  }

  initHostedForm({
    redirectUrl: paybyrd_params.redirectUrl,
    locale: paybyrd_params.locale,
    orderId: paybyrd_params.orderId,
    checkoutKey: paybyrd_params.checkoutKey,
    theme: {
      backgroundColor: paybyrd_params.hfBackgroundColor,
      formBackgroundColor: paybyrd_params.hfFormBackgroundColor,
      primaryColor: paybyrd_params.hfPrimaryColor,
      textColor: paybyrd_params.hfTextColor,
      effectsBackgroundColor: paybyrd_params.hfEffectsBackgroundColor,
    },
    autoRedirect: true,
    showCancelButton: false,
    skipATMSuccessPage: true,
  });
});
