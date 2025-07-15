jQuery(document).ready(function ($) {
    let syncInProgress = false;

    // DODANO: Heartbeat funkcije
    function disableHeartbeat() {
        if (typeof wp !== 'undefined' && wp.heartbeat) {
            wp.heartbeat.suspend();
            console.log('Heartbeat disabled during sync');
        }
    }

    function enableHeartbeat() {
        if (typeof wp !== 'undefined' && wp.heartbeat) {
            wp.heartbeat.resume();
            console.log('Heartbeat enabled after sync');
        }
    }

    function showSyncNotice() {
        $('.post-lock-dialog, .autosave-info, #local-storage-notice').remove();

        if (!$('#sync-notice').length) {
            $('body').prepend(`
                <div id="sync-notice" style="
                    position: fixed; 
                    top: 32px; 
                    left: 0; 
                    right: 0; 
                    background: #0073aa; 
                    color: white; 
                    padding: 12px; 
                    text-align: center; 
                    z-index: 999999;
                    font-weight: bold;
                ">
                    🔄 Sinhronizacija u toku - molimo sačekajte...
                </div>
            `);
        }
    }

    function hideSyncNotice() {
        $('#sync-notice').fadeOut().remove();
    }

    function clearLocalStorageBackup() {
        if (typeof Storage !== 'undefined') {
            const postId = $('#post_ID').val();
            if (postId) {
                localStorage.removeItem('wp-autosave-1-' + postId);
                localStorage.removeItem('wp-autosave-' + postId);
            }
        }
        $('#local-storage-notice').remove();
    }

    function disableAutosave() {
        if (typeof wp !== 'undefined' && wp.autosave) {
            wp.autosave.server.suspend();
            console.log('Autosave disabled during sync');
        }
    }

    function enableAutosave() {
        if (typeof wp !== 'undefined' && wp.autosave) {
            wp.autosave.server.resume();
            console.log('Autosave enabled after sync');
        }
    }

    // Utility funkcije
    const showMessage = (container, message, type) => {
        const messageClass = type === 'error' ? 'error' : 'success';
        container.html(`<div class="sync-message ${messageClass}">${message}</div>`);
    };

    const updateSyncProgress = (step, status = 'active', message = '') => {
        const container = $('.sync-progress-container');
        const stepElement = container.find(`[data-step="${step}"]`);

        if (!stepElement.length) {
            console.log(`Step element "${step}" not found`);
            return;
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

    // Test konekcije (ostaje isto)
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
                html += `<h3>${status.title}</h3>`;
                html += `<div class="notice ${status.class}"><p>${status.message}</p></div>`;

                if (response.data && response.data.debug_info) {
                    html += '<div class="debug-section" style="margin-top: 15px;">';
                    html += '<h4>Tehnički detalji:</h4>';
                    html += '<dl style="margin-left: 20px;">';

                    if (response.data.debug_info.endpoint) {
                        html += `<dt style="font-weight: bold;">Test URL:</dt>`;
                        html += `<dd style="margin-bottom: 10px;">${response.data.debug_info.endpoint}</dd>`;
                    }

                    if (response.data.debug_info.auth_type) {
                        html += `<dt style="font-weight: bold;">Metoda autentifikacije:</dt>`;
                        html += `<dd style="margin-bottom: 10px;">${response.data.debug_info.auth_type}</dd>`;
                    }

                    if (response.data.debug_info.response_code) {
                        html += `<dt style="font-weight: bold;">Response Code:</dt>`;
                        html += `<dd style="margin-bottom: 10px;">`;
                        html += `<span class="${response.data.debug_info.response_code === 200 ? 'notice-success' : 'notice-error'}" 
                                 style="padding: 2px 8px; border-radius: 3px;">`;
                        html += `${response.data.debug_info.response_code}</span></dd>`;
                    }

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

    $('#test-rest-api').on('click', function () {
        testConnection('rest');
    });

    $('#test-user-auth').on('click', function () {
        testConnection('user');
    });

    // OPTIMIZOVANA sinhronizacija stanja
    $('.shopito-sync-stock').on('click', function (e) {
        e.preventDefault();

        const button = $(this);
        const productId = button.data('product-id');
        const isVariable = button.data('is-variable') === 'true';
        const statusDiv = button.siblings('.sync-status');
        const spinnerDiv = button.find('.spinner');
        const progressContainer = $('.sync-progress-container');

        // KLJUČNO: Onemogući WordPress konekcije
        disableAutosave();
        disableHeartbeat();
        showSyncNotice();

        button.prop('disabled', true);
        spinnerDiv.addClass('is-active');
        progressContainer.show();
        statusDiv.html('');

        // Reset i prikaži relevantne korake
        $('.sync-step').removeClass('active completed error').hide();
        $('.sync-step[data-step="stock"]').show();

        if (isVariable) {
            $('.sync-step[data-step="variations"]').show();
            updateSyncProgress('variations', 'active', 'Ažuriranje stanja varijacija...');
        }

        updateSyncProgress('stock', 'active', 'Ažuriranje stanja proizvoda...');

        $.ajax({
            url: shopitoSync.ajax_url,
            type: 'POST',
            timeout: 300000, // 5 minuta
            data: {
                action: 'sync_stock_to_ba',
                nonce: shopitoSync.nonce,
                product_id: productId
            },
            success: function (response) {
                if (response.success) {
                    clearLocalStorageBackup();

                    if (response.data.steps && response.data.steps.length > 0) {
                        response.data.steps.forEach(step => {
                            if (step.name === 'stock' || (isVariable && step.name === 'variations')) {
                                updateSyncProgress(step.name, step.status, step.message);
                            }
                        });
                    }

                    if (!$('.sync-step[data-step="stock"]').hasClass('completed')) {
                        updateSyncProgress('stock', 'completed', 'Stanje proizvoda ažurirano');
                    }

                    showMessage(statusDiv, response.data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
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

                enableAutosave();
                setTimeout(() => {
                    enableHeartbeat();
                    hideSyncNotice();
                }, 1000);
            }
        });
    });

    // OPTIMIZOVANA puna sinhronizacija
    $('.shopito-sync-now').on('click', function (e) {
        e.preventDefault();

        const button = $(this);
        const productId = button.data('product-id');
        const statusDiv = button.siblings('.sync-status');
        const spinnerDiv = button.find('.spinner');
        const progressContainer = $('.sync-progress-container');
        const skipImages = $('#skip-images').is(':checked');

        // KLJUČNO: Onemogući WordPress konekcije
        disableAutosave();
        disableHeartbeat();
        showSyncNotice();

        button.prop('disabled', true);
        spinnerDiv.addClass('is-active');
        progressContainer.show();
        statusDiv.html('');

        // Prikaži sve korake
        $('.sync-step').removeClass('active completed error').show();

        $.ajax({
            url: shopitoSync.ajax_url,
            type: 'POST',
            timeout: 600000, // 10 minuta
            data: {
                action: 'sync_to_ba',
                nonce: shopitoSync.nonce,
                product_id: productId,
                skip_images: skipImages
            },
            success: function (response) {
                if (response.success) {
                    clearLocalStorageBackup();

                    if (response.data.steps) {
                        response.data.steps.forEach(step => {
                            updateSyncProgress(step.name, step.status, step.message);
                        });
                    }

                    // Dodaj default steps ako nisu definisani
                    updateSyncProgress('product', 'completed', 'Proizvod kreiran/ažuriran');
                    updateSyncProgress('prices', 'completed', 'Cene konvertovane');
                    updateSyncProgress('stock', 'completed', 'Stanje ažurirano');

                    showMessage(statusDiv, response.data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
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

                enableAutosave();
                setTimeout(() => {
                    enableHeartbeat();
                    hideSyncNotice();
                }, 1000);
            }
        });
    });

    // Clear logs
    $('#clear-logs').on('click', function () {
        if (confirm('Da li ste sigurni da želite da obrišete sve logove?')) {
            const nonce = $(this).data('nonce');

            $.ajax({
                url: shopitoSync.ajax_url,
                type: 'POST',
                data: {
                    action: 'clear_shopito_logs',
                    nonce: nonce
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
                }
            });
        }
    });

    // Prevent page leave tokom sync-a
    $(window).on('beforeunload', function () {
        if (syncInProgress) {
            return 'Sinhronizacija je u toku. Da li ste sigurni da želite da napustite stranicu?';
        }
    });
});