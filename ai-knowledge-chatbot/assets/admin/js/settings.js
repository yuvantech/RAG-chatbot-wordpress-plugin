/**
 * Repopulates the model <select> for a given role ("chat" or "embedding")
 * whenever its paired provider <select> changes, using the models map the
 * server localized via wp_add_inline_script (window.aikcProviderModels).
 *
 * No admin-ajax round trip is needed for this because the full model
 * catalogue for every provider is small and known up front; providers with
 * a genuinely dynamic catalogue (e.g. OpenRouter) will refresh this map via
 * ajax in a later phase without changing this wiring.
 */
(function () {
    'use strict';

    function populateModelSelect(select, models) {
        if (!select) {
            return;
        }

        select.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '— Select a model —';
        select.appendChild(placeholder);

        (models || []).forEach(function (model) {
            var option = document.createElement('option');
            option.value = model.value;
            option.textContent = model.label;
            select.appendChild(option);
        });
    }

    function wireProviderSelect(role) {
        var providerSelect = document.querySelector('select[data-role="' + role + '"]');
        var modelSelect = document.querySelector('select[data-role="' + role + '-model"]');

        if (!providerSelect || !modelSelect) {
            return;
        }

        providerSelect.addEventListener('change', function () {
            var map = (window.aikcProviderModels && window.aikcProviderModels[role]) || {};
            populateModelSelect(modelSelect, map[providerSelect.value]);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        wireProviderSelect('chat');
        wireProviderSelect('embedding');
    });
})();
