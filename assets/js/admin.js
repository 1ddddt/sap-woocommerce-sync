/**
 * SAP WooCommerce Sync v2.0 - Admin JavaScript
 */
(function ($) {
    'use strict';

    var SAPWCSync = {
        init: function () {
            this.bindEvents();
        },

        bindEvents: function () {
            $('#sap-wc-test-connection').on('click', this.testConnection);
            $('#sap-wc-sync-inventory').on('click', this.syncInventory);
            $('#sap-wc-sync-products').on('click', this.syncProducts);
            $(document).on('click', '.sap-wc-sync-single', this.syncSingleProduct);
            $(document).on('click', '.sap-wc-manual-map', this.openManualMapModal);
            $(document).on('click', '.sap-wc-retry-order', this.retryOrder);
            $(document).on('click', '.sap-wc-retry-dead-letter', this.retryDeadLetter);
        },

        showResult: function (message, type) {
            var $result = $('#sap-wc-action-result');
            $result.removeClass('success error loading').addClass(type).html(message).show();
        },

        testConnection: function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text(sapWcSync.strings.testing);
            SAPWCSync.showResult(sapWcSync.strings.testing, 'loading');

            $.ajax({
                url: sapWcSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sap_wc_test_connection',
                    nonce: sapWcSync.nonce,
                    base_url: $('#sap_wc_base_url').val(),
                    company_db: $('#sap_wc_company_db').val(),
                    username: $('#sap_wc_username').val(),
                    password: $('#sap_wc_password').val() || '**SAVED**'
                },
                success: function (res) {
                    if (res.success) {
                        SAPWCSync.showResult(res.data.message, 'success');
                    } else {
                        SAPWCSync.showResult(sapWcSync.strings.error + ' ' + res.data.message, 'error');
                    }
                },
                error: function () {
                    SAPWCSync.showResult('Connection failed. Check server settings.', 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Test Connection');
                }
            });
        },

        syncInventory: function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text(sapWcSync.strings.syncing);
            SAPWCSync.showResult('Starting inventory sync...', 'loading');

            $.ajax({
                url: sapWcSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sap_wc_manual_sync',
                    nonce: sapWcSync.nonce
                },
                success: function (res) {
                    if (res.success) {
                        var status = res.data.status || 'completed';
                        if (status === 'started' || status === 'running') {
                            SAPWCSync.showResult(res.data.message, 'loading');
                            SAPWCSync.pollSyncStatus($btn);
                        } else {
                            SAPWCSync.showResult(res.data.message, 'success');
                            $btn.prop('disabled', false).text('Sync Inventory Now');
                        }
                    } else {
                        SAPWCSync.showResult(sapWcSync.strings.error + ' ' + res.data.message, 'error');
                        $btn.prop('disabled', false).text('Sync Inventory Now');
                    }
                },
                error: function () {
                    SAPWCSync.showResult('Failed to start sync.', 'error');
                    $btn.prop('disabled', false).text('Sync Inventory Now');
                }
            });
        },

        pollSyncStatus: function ($btn) {
            var pollCount = 0;
            var maxPolls = 100; // 100 * 3s = 5 minutes max
            var errorCount = 0;

            var pollInterval = setInterval(function () {
                pollCount++;
                if (pollCount > maxPolls) {
                    clearInterval(pollInterval);
                    SAPWCSync.showResult('Sync is still running in the background. Refresh the page later to see results.', 'loading');
                    $btn.prop('disabled', false).text('Sync Inventory Now');
                    return;
                }

                $.ajax({
                    url: sapWcSync.ajaxUrl,
                    type: 'POST',
                    timeout: 10000,
                    data: {
                        action: 'sap_wc_sync_status',
                        nonce: sapWcSync.nonce
                    },
                    success: function (res) {
                        errorCount = 0; // reset on success
                        if (res.success) {
                            if (res.data.status === 'completed') {
                                clearInterval(pollInterval);
                                var hasErrors = res.data.result && res.data.result.errors && res.data.result.errors.length > 0;
                                SAPWCSync.showResult(res.data.message, hasErrors ? 'error' : 'success');
                                $btn.prop('disabled', false).text('Sync Inventory Now');
                            } else if (res.data.status === 'running') {
                                SAPWCSync.showResult(res.data.message, 'loading');
                            } else if (res.data.status === 'idle' && pollCount > 5) {
                                clearInterval(pollInterval);
                                SAPWCSync.showResult('Sync completed.', 'success');
                                $btn.prop('disabled', false).text('Sync Inventory Now');
                            }
                        }
                    },
                    error: function () {
                        errorCount++;
                        if (errorCount >= 5) {
                            clearInterval(pollInterval);
                            SAPWCSync.showResult('Lost connection to server. Sync may still be running in the background.', 'error');
                            $btn.prop('disabled', false).text('Sync Inventory Now');
                        }
                    }
                });
            }, 3000);
        },

        syncProducts: function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text(sapWcSync.strings.syncing);
            SAPWCSync.showResult('Starting product mapping...', 'loading');

            // Chunked sync with progress
            var offset = 0;
            var limit = 10;
            var stats = { matched: 0, skipped: 0, errors: 0 };

            function processChunk() {
                $.ajax({
                    url: sapWcSync.ajaxUrl,
                    type: 'POST',
                    timeout: 30000,
                    data: {
                        action: 'sap_wc_sync_products_chunk',
                        nonce: sapWcSync.nonce,
                        offset: offset,
                        limit: limit
                    },
                    success: function (res) {
                        if (res.success) {
                            var d = res.data;
                            stats.matched += d.matched || 0;
                            stats.skipped += d.skipped || 0;
                            stats.errors += (d.errors && d.errors.length) || 0;

                            var progress = d.total > 0 ? Math.round((d.processed / d.total) * 100) : 100;
                            SAPWCSync.showResult(
                                'Progress: ' + progress + '% (' + d.processed + '/' + d.total + ')<br>' +
                                'Matched: ' + stats.matched + ' | Skipped: ' + stats.skipped + ' | Errors: ' + stats.errors,
                                'loading'
                            );

                            if (d.has_more) {
                                offset += limit;
                                processChunk();
                            } else {
                                SAPWCSync.showResult(
                                    'Product mapping complete!<br>' +
                                    'Matched: ' + stats.matched + ' | Skipped: ' + stats.skipped + ' | Errors: ' + stats.errors,
                                    stats.errors > 0 ? 'error' : 'success'
                                );
                                $btn.prop('disabled', false).text('Map Products from SAP');
                            }
                        } else {
                            SAPWCSync.showResult(sapWcSync.strings.error + ' ' + res.data.message, 'error');
                            $btn.prop('disabled', false).text('Map Products from SAP');
                        }
                    },
                    error: function () {
                        SAPWCSync.showResult('Chunk processing failed. Try again.', 'error');
                        $btn.prop('disabled', false).text('Map Products from SAP');
                    }
                });
            }

            processChunk();
        },

        syncSingleProduct: function () {
            var $btn = $(this);
            var productId = $btn.data('product-id');

            $btn.prop('disabled', true).text('...');

            $.ajax({
                url: sapWcSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sap_wc_sync_single',
                    nonce: sapWcSync.nonce,
                    product_id: productId
                },
                success: function (res) {
                    if (res.success) {
                        $btn.text('Done').css('color', '#46b450');
                        setTimeout(function () { location.reload(); }, 1500);
                    } else {
                        $btn.text('Fail').css('color', '#dc3232');
                        setTimeout(function () {
                            $btn.prop('disabled', false).text('Sync').css('color', '');
                        }, 2000);
                    }
                },
                error: function () {
                    $btn.text('Err').css('color', '#dc3232');
                    setTimeout(function () {
                        $btn.prop('disabled', false).text('Sync').css('color', '');
                    }, 2000);
                }
            });
        },

        openManualMapModal: function () {
            var $btn = $(this);
            var productId = $btn.data('product-id');
            var productName = $btn.data('product-name');

            var html = '<div class="sap-wc-modal-overlay">' +
                '<div class="sap-wc-modal">' +
                '<h3>Map Product to SAP Item</h3>' +
                '<p><strong>' + $('<span>').text(productName).html() + '</strong></p>' +
                '<p><label>SAP ItemCode:<br>' +
                '<input type="text" id="sap-wc-map-itemcode" class="regular-text" placeholder="Enter SAP ItemCode"></label></p>' +
                '<p>' +
                '<button type="button" id="sap-wc-map-submit" class="button button-primary">Map Product</button> ' +
                '<button type="button" id="sap-wc-map-cancel" class="button">Cancel</button>' +
                '</p>' +
                '<div id="sap-wc-map-result"></div>' +
                '</div></div>';

            $('body').append(html);

            $('#sap-wc-map-cancel').on('click', function () {
                $('.sap-wc-modal-overlay').remove();
            });

            $('#sap-wc-map-submit').on('click', function () {
                var itemCode = $.trim($('#sap-wc-map-itemcode').val());
                if (!itemCode) {
                    $('#sap-wc-map-result').html('<p style="color:#dc3232;">Please enter an ItemCode</p>');
                    return;
                }

                var $submit = $(this);
                $submit.prop('disabled', true).text('Mapping...');

                $.ajax({
                    url: sapWcSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'sap_wc_manual_map',
                        nonce: sapWcSync.nonce,
                        product_id: productId,
                        item_code: itemCode
                    },
                    success: function (res) {
                        if (res.success) {
                            $('#sap-wc-map-result').html('<p style="color:#46b450;">' + res.data.message + '</p>');
                            setTimeout(function () { location.reload(); }, 1500);
                        } else {
                            $('#sap-wc-map-result').html('<p style="color:#dc3232;">' + res.data.message + '</p>');
                            $submit.prop('disabled', false).text('Map Product');
                        }
                    },
                    error: function () {
                        $('#sap-wc-map-result').html('<p style="color:#dc3232;">Request failed</p>');
                        $submit.prop('disabled', false).text('Map Product');
                    }
                });
            });
        },

        retryOrder: function () {
            var $btn = $(this);
            var orderId = $btn.data('order-id');

            if (!confirm('Retry syncing order #' + orderId + '?')) return;

            $btn.prop('disabled', true).text('Retrying...');

            $.ajax({
                url: sapWcSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sap_wc_retry_order',
                    nonce: sapWcSync.nonce,
                    order_id: orderId
                },
                success: function (res) {
                    alert(res.success ? res.data.message : ('Error: ' + res.data.message));
                    if (res.success) setTimeout(function () { location.reload(); }, 1000);
                    else $btn.prop('disabled', false).text('Retry');
                },
                error: function () {
                    alert('Request failed. Please try again.');
                    $btn.prop('disabled', false).text('Retry');
                }
            });
        },

        retryDeadLetter: function () {
            var $btn = $(this);
            var dlId = $btn.data('dead-letter-id');

            if (!confirm('Re-enqueue dead letter #' + dlId + '?')) return;

            $btn.prop('disabled', true).text('Processing...');

            $.ajax({
                url: sapWcSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sap_wc_retry_dead_letter',
                    nonce: sapWcSync.nonce,
                    dead_letter_id: dlId
                },
                success: function (res) {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + res.data.message);
                        $btn.prop('disabled', false).text('Re-enqueue');
                    }
                },
                error: function () {
                    alert('Request failed.');
                    $btn.prop('disabled', false).text('Re-enqueue');
                }
            });
        }
    };

    $(document).ready(function () {
        SAPWCSync.init();
    });
})(jQuery);
