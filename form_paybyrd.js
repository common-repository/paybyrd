jQuery(function ($) {
    const webhookGeneratorButton = document.querySelector("input.pb-generator-field");

    if (webhookGeneratorButton) {
        webhookGeneratorButton.value = webhookGeneratorButton.getAttribute("data-label");
    }
  });
  