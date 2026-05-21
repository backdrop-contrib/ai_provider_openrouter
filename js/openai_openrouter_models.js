/**
 * @file
 * JavaScript for OpenRouter models configuration form.
 */

(function ($) {
  'use strict';

  Backdrop.behaviors.openrouterModelsForm = {
    attach: function (context, settings) {
      // Select all models for a provider
      $('.select-all-provider', context).once('openrouter-select-all').on('click', function (e) {
        e.preventDefault();
        var fieldset = $(this).closest('fieldset');
        fieldset.find('input[type="checkbox"]').prop('checked', true);
      });

      // Deselect all models for a provider
      $('.deselect-all-provider', context).once('openrouter-deselect-all').on('click', function (e) {
        e.preventDefault();
        var fieldset = $(this).closest('fieldset');
        fieldset.find('input[type="checkbox"]').prop('checked', false);
      });
    }
  };

})(jQuery);
