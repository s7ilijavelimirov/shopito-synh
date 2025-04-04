jQuery(document).ready(function ($) {
    // Utility funkcije
    const showMessage = (container, message, type) => {
        const messageClass = type === 'error' ? 'error' : 'success';
        container.html(`<div class="sync-message ${messageClass}">${message}</div>`);
    };

    function getStatusMessage(response, type) {
        if (response.success) {
            if (type === 'rest') {
                return {
                    title: 'WooCommerce REST API Test',
                    message: 'REST API (Consumer Keys) konekcija je uspešna! Sinhronizacija proizvoda će raditi.',
                    class: 'notice-success'
                };
            } else {
                return {
                    title: 'WordPress Basic Auth Test',
                    message: 'Basic Auth konekcija je uspešna! Upload slika će raditi.',
                    class: 'notice-success'
                };
            }
        } else {
            if (type === 'rest') {
                return {
                    title: 'WooCommerce REST API Test',
                    message: 'REST API konekcija nije uspela. Proverite Consumer Key i Secret.',
                    class: 'notice-error'
                };
            } else {
                return {
                    title: 'WordPress Basic Auth Test',
                    message: 'Basic Auth konekcija nije uspela. Proverite Application Password.',
                    class: 'notice-error'
                };
            }
        }
    }

    const updateSyncProgress = (step, status = 'active', message = '') => {
        const container = $('.sync-progress-container');
        const stepElement = container.find(`[data-step="${step}"]`);

        if (!stepElement.length) {
            console.log(`Step element "${step}" not found`);
            return;
        }

        if (!stepElement.find('.step-text').data('original-text')) {
            stepElement.find('.step-text').data('original-text',
                stepElement.find('.step-text').text());
        }

        stepElement.removeClass('active completed error');
        stepElement.find('.step-status').removeClass('success error');

        switch (status) {
            case 'active':
                stepElement.addClass('active');
                if (message) stepElement.find('.step-text').text(message);
                break;
            case 'completed':
                stepElement.addClass('completed');
                stepElement.find('.step-status').addClass('success');
                if (message) stepElement.find('.step-text').text(message);
                break;
            case 'error':
                stepElement.addClass('error');
                stepElement.find('.step-status').addClass('error');
                if (message) stepElement.find('.step-text').text(message);
                break;
        }
    };

    const handleAjaxError = (error, statusDiv) => {
        console.error('Ajax Error:', error);
        let errorMessage = 'Greška pri sinhronizaciji';

        if (error.responseJSON && error.responseJSON.data) {
            errorMessage += ': ' + error.responseJSON.data;
        } else if (error.statusText) {
            errorMessage += ': ' + error.statusText;
        } else if (typeof error === 'string') {
            errorMessage += ': ' + error;
        }

        showMessage(statusDiv, errorMessage, 'error');
    };

    // Funkcija za testiranje konekcije
    function testConnection(type) {
        const resultDiv = $('#connection-result');
        const spinner = $('.spinner');
        const buttons = $('#test-rest-api, #test-user-auth');

        buttons.prop('disabled', true);
        spinner.addClass('is-active');
        resultDiv.html('');

        $.ajax({
            url: shopitoSync.ajax_url,
            type: 'POST',
            data: {
                action: 'test_shopito_connection',
                nonce: shopitoSync.test_nonce,
                test_type: type
            },
            success: function (response) {
                const status = getStatusMessage(response, type);
                let html = '<div style="margin-top: 20px; padding: 15px; background: #f8f8f8; border: 1px solid #ddd;">';

                // Naslov i glavni status
                html += `<h3>${status.title}</h3>`;
                html += `<div class="notice ${status.class}"><p>${status.message}</p></div>`;

                // Detalji
                if (response.data && response.data.debug_info) {
                    html += '<div class="debug-section" style="margin-top: 15px;">';
                    html += '<h4>Tehnički detalji:</h4>';
                    html += '<dl style="margin-left: 20px;">';

                    // Endpoint - proveravamo da li postoji
                    if (response.data.debug_info.endpoint) {
                        html += `<dt style="font-weight: bold;">Test URL:</dt>`;
                        html += `<dd style="margin-bottom: 10px;">${response.data.debug_info.endpoint}</dd>`;
                    }

                    // Auth Type - proveravamo da li postoji
                    if (response.data.debug_info.auth_type) {
                        html += `<dt style="font-weight: bold;">Metoda autentifikacije:</dt>`;
                        html += `<dd style="margin-bottom: 10px;">${response.data.debug_info.auth_type}</dd>`;
                    }

                    // Response Code - proveravamo da li postoji
                    if (response.data.debug_info.response_code) {
                        html += `<dt style="font-weight: bold;">Response Code:</dt>`;
                        html += `<dd style="margin-bottom: 10px;">`;
                        html += `<span class="${response.data.debug_info.response_code === 200 ? 'notice-success' : 'notice-error'}" 
                                 style="padding: 2px 8px; border-radius: 3px;">`;
                        html += `${response.data.debug_info.response_code}</span></dd>`;
                    }

                    // Response Body - proveravamo da li postoji
                    if (response.data.debug_info.response_body) {
                        html += `<dt style="font-weight: bold;">Server Response:</dt>`;
                        html += `<dd><pre style="background: #fff; padding: 10px; margin: 10px 0; max-height: 200px; overflow: auto;">`;
                        html += `${JSON.stringify(response.data.debug_info.response_body, null, 2)}</pre></dd>`;
                    }

                    html += '</dl>';
                    html += '</div>';
                }

                html += '</div>';
                resultDiv.html(html);

                // Dodajemo console.log za debugiranje
                console.log('Response from server:', response);
            },
            error: function (xhr, status, error) {
                resultDiv.html(
                    '<div class="notice notice-error">' +
                    '<p>Greška pri testiranju konekcije: ' + error + '</p>' +
                    '<pre style="background: #fff; padding: 10px; margin-top: 10px;">' + xhr.responseText + '</pre>' +
                    '</div>'
                );
            },
            complete: function () {
                buttons.prop('disabled', false);
                spinner.removeClass('is-active');
            }
        });
    }

    // Event handleri za test dugmad
    $('#test-rest-api').on('click', function () {
        testConnection('rest');
    });

    $('#test-user-auth').on('click', function () {
        testConnection('user');
    });

    // Sinhronizacija stanja proizvoda
    $('.shopito-sync-stock').on('click', function (e) {
        e.preventDefault();
        const button = $(this);
        const productId = button.data('product-id');
        const isVariable = button.data('is-variable') === 'true';
        const statusDiv = button.siblings('.sync-status');
        const spinnerDiv = button.find('.spinner');
        const progressContainer = $('.sync-progress-container');

        // Reset UI
        button.prop('disabled', true);
        spinnerDiv.addClass('is-active');
        progressContainer.show();
        statusDiv.html('');

        // Reset steps and hide all
        $('.sync-step').removeClass('active completed error').hide();
        $('.step-status').removeClass('success error');

        // Prikazujemo samo korake relevantne za sinhronizaciju stanja
        $('.sync-step[data-step="stock"]').show();

        // Za varijabilne proizvode, prikazujemo poseban korak za varijacije
        if (isVariable) {
            $('.sync-step[data-step="variations"]').show();
            updateSyncProgress('variations', 'active', 'Ažuriranje stanja varijacija...');
        }

        // Inicijalno stanje - koristimo stock korak
        updateSyncProgress('stock', 'active', 'Ažuriranje stanja proizvoda...');

        $.ajax({
            url: shopitoSync.ajax_url,
            type: 'POST',
            data: {
                action: 'sync_stock_to_ba',
                nonce: shopitoSync.nonce,
                product_id: productId
            },
            success: function (response) {
                if (response.success) {
                    // Procesuiramo korake iz odgovora
                    if (response.data.steps && response.data.steps.length > 0) {
                        response.data.steps.forEach(step => {
                            // Prikažemo samo korake za stock i variations (za varijabilne proizvode)
                            if (step.name === 'stock' || (isVariable && step.name === 'variations')) {
                                const stepElement = $(`.sync-step[data-step="${step.name}"]`);
                                if (stepElement.length) {
                                    stepElement.show();
                                    updateSyncProgress(step.name, step.status, step.message);
                                }
                            }
                        });
                    }

                    // Uvek ažuriramo stock korak na kraju ako nije već ažuriran
                    if (!$('.sync-step[data-step="stock"]').hasClass('completed')) {
                        updateSyncProgress('stock', 'completed', 'Stanje proizvoda ažurirano');
                    }

                    showMessage(statusDiv, response.data.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    handleAjaxError(response.data || 'Nepoznata greška', statusDiv);
                }
            },
            error: function (xhr, status, error) {
                handleAjaxError(xhr, statusDiv);
            },
            complete: function () {
                button.prop('disabled', false);
                spinnerDiv.removeClass('is-active');
            }
        });
    });

    // Puna sinhronizacija proizvoda
    $('.shopito-sync-now').on('click', function (e) {
        e.preventDefault();
        const button = $(this);
        const productId = button.data('product-id');
        const statusDiv = button.siblings('.sync-status');
        const spinnerDiv = button.find('.spinner');
        const progressContainer = $('.sync-progress-container');

        // Reset UI
        button.prop('disabled', true);
        spinnerDiv.addClass('is-active');
        progressContainer.show();
        statusDiv.html('');

        // Reset steps
        $('.sync-step').removeClass('active completed error');
        $('.step-status').removeClass('success error');

        // Prikazujemo sve korake za punu sinhronizaciju
        $('.sync-step').show();

        $.ajax({
            url: shopitoSync.ajax_url,
            type: 'POST',
            data: {
                action: 'sync_to_ba',
                nonce: shopitoSync.nonce,
                product_id: productId
            },
            success: function (response) {
                if (response.success) {
                    // Update progress steps
                    if (response.data.steps) {
                        response.data.steps.forEach(step => {
                            updateSyncProgress(step.name, step.status, step.message);
                        });
                    }

                    // Osiguravamo da je korak za stanje vidljiv i označen kao završen
                    if (!$('.sync-step[data-step="stock"]').hasClass('completed')) {
                        updateSyncProgress('stock', 'completed', 'Stanje proizvoda ažurirano');
                    }

                    showMessage(statusDiv, response.data.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    handleAjaxError(response.data || 'Nepoznata greška', statusDiv);
                }
            },
            error: function (xhr, status, error) {
                handleAjaxError(xhr, statusDiv);
            },
            complete: function () {
                button.prop('disabled', false);
                spinnerDiv.removeClass('is-active');
            }
        });
    });

    // Brisanje logova na stranici logova
    $('#clear-logs').on('click', function () {
        if (confirm('Da li ste sigurni da želite da obrišete sve logove?')) {
            var nonce = $(this).data('nonce');

            $.ajax({
                url: shopitoSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'clear_shopito_logs',
                    nonce: nonce
                },
                beforeSend: function () {
                    $('#clear-logs').prop('disabled', true);
                },
                success: function (response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Greška: ' + response.data);
                    }
                },
                error: function () {
                    alert('Došlo je do greške prilikom brisanja logova.');
                },
                complete: function () {
                    $('#clear-logs').prop('disabled', false);
                }
            });
        }
    });
});