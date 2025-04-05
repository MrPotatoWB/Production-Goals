/**
 * Production Goals - Admin JavaScript
 * Enhanced version with better UX and error handling
 * Updated with support for Spudcryption and security level dropdown
 */

(function($) {
    'use strict';

    // Declare pg_admin_data globally 
    var pg_admin_data;

    // Initialize when document is ready
    $(document).ready(function() {
        initGlobalHandlers();
        setupAjaxDefaults();
    });

    /**
     * Initialize pg_admin_data for AJAX operations
     */
    $(window).on('load', function() {
        // Initialize global variable for use in AJAX calls
        pg_admin_data = {
            nonce: typeof productionGoalsAdmin !== 'undefined' ? productionGoalsAdmin.nonce : '',
            ajaxUrl: typeof productionGoalsAdmin !== 'undefined' ? productionGoalsAdmin.ajaxUrl : ajaxurl
        };
    });
    
    /**
     * Initialize global UI handlers
     */
    function initGlobalHandlers() {
        // Copy to clipboard functionality
        initClipboard();
        
        // Toggle accordion elements
        initAccordions();
        
        // Modal dialogs
        initModals();
    }

    /**
     * Initialize clipboard copy functionality
     */
    function initClipboard() {
        $(document).on('click', '.copy-to-clipboard', function() {
            const text = $(this).data('clipboard');
            const tempTextarea = document.createElement('textarea');
            tempTextarea.value = text;
            document.body.appendChild(tempTextarea);
            tempTextarea.select();
            document.execCommand('copy');
            document.body.removeChild(tempTextarea);
            
            const originalText = $(this).text();
            $(this).text('Copied!');
            
            setTimeout(() => {
                $(this).text(originalText);
            }, 2000);
        });
    }
    
    /**
     * Initialize accordion functionality
     */
    function initAccordions() {
        $(document).on('click', '.pg-accordion-toggle', function() {
            const targetId = $(this).data('target');
            $('#' + targetId).slideToggle();
            
            // Update toggle text
            if ($(this).data('show-text') && $(this).data('hide-text')) {
                const isVisible = $('#' + targetId).is(':visible');
                $(this).text(isVisible ? $(this).data('hide-text') : $(this).data('show-text'));
            }
        });
    }
    
    /**
     * Initialize modal functionality
     */
    function initModals() {
        // Close modal when clicking the close button
        $(document).on('click', '.pg-modal-close, .pg-modal-cancel', function() {
            $(this).closest('.pg-modal').hide();
        });
        
        // Close modal when clicking outside content
        $(window).on('click', function(event) {
            if ($(event.target).hasClass('pg-modal')) {
                $('.pg-modal').hide();
            }
        });
    }
    
    /**
     * Setup global AJAX defaults
     */
    function setupAjaxDefaults() {
        // Add a global AJAX error handler
        $(document).ajaxError(function(event, jqXHR, settings, error) {
            console.error('AJAX Error:', error, settings.url);
            showNotice('An unexpected error occurred. Please try again or contact support.', 'error');
        });
    }
    
    /**
     * Display a notice in the admin area
     * @param {string} message - The message to display
     * @param {string} type - The notice type (success, error, warning, info)
     * @param {boolean} autoDismiss - Whether to auto-dismiss the notice
     */
    function showNotice(message, type = 'info', autoDismiss = true) {
        // Find the admin wrap to insert the notice
        const adminWrap = $('.pg-admin-wrap');
        if (!adminWrap.length) return;
        
        // Remove any existing notices with the same message
        $('.pg-admin-notice').each(function() {
            if ($(this).text().trim() === message) {
                $(this).remove();
            }
        });
        
        // Create the notice element
        let noticeClass = 'pg-admin-notice notice ';
        switch (type) {
            case 'success':
                noticeClass += 'notice-success';
                break;
            case 'error':
                noticeClass += 'notice-error';
                break;
            case 'warning':
                noticeClass += 'notice-warning';
                break;
            default:
                noticeClass += 'notice-info';
                break;
        }
        
        const notice = $('<div class="' + noticeClass + '"><p>' + message + '</p></div>');
        
        // Add a dismiss button
        const dismissButton = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
        notice.append(dismissButton);
        
        // Insert the notice at the beginning of the admin wrap
        adminWrap.prepend(notice);
        
        // Handle dismiss button click
        dismissButton.on('click', function() {
            notice.fadeOut(300, function() {
                $(this).remove();
            });
        });
        
        // Auto-dismiss after 5 seconds if enabled
        if (autoDismiss && type === 'success') {
            setTimeout(function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    }
    
    /**
     * Show a processing indicator
     * @param {jQuery} container - The container element to add the indicator to
     * @param {string} message - The message to display
     * @return {jQuery} The indicator element
     */
    function showProcessing(container, message = 'Processing...') {
        const indicator = $('<div class="pg-processing-indicator"><span class="pg-spinner"></span>' + message + '</div>');
        container.prepend(indicator);
        return indicator;
    }
    
    /**
     * Execute a form submission with file upload
     * @param {jQuery} form - The form element
     * @param {object} options - Configuration options
     */
    function submitFormWithFiles(form, options) {
        const defaults = {
            action: '',
            successMessage: 'Operation completed successfully!',
            errorMessage: 'An error occurred. Please try again.',
            beforeSubmit: function() {},
            success: function() {},
            error: function() {},
            complete: function() {},
            redirect: null,
            data: {}
        };
        
        const settings = $.extend({}, defaults, options);
        const formData = new FormData(form[0]);
        
        // Add action and nonce
        formData.append('action', settings.action);
        formData.append('nonce', pg_admin_data.nonce);
        
        // Add extra data
        for (const key in settings.data) {
            formData.append(key, settings.data[key]);
        }
        
        // Disable submit button
        const submitBtn = form.find('button[type="submit"]');
        const originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text('Processing...');
        
        // Show processing message
        showNotice('', 'info');
        
        // Send the AJAX request
        $.ajax({
            url: pg_admin_data.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                settings.beforeSubmit();
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message || settings.successMessage, 'success');
                    settings.success(response);
                    
                    // Redirect if specified
                    if (settings.redirect) {
                        setTimeout(function() {
                            window.location.href = settings.redirect;
                        }, 1000);
                    }
                } else {
                    showNotice(response.data.message || settings.errorMessage, 'error', false);
                    settings.error(response);
                }
            },
            error: function(xhr, status, error) {
                showNotice('Server error: ' + error, 'error', false);
                settings.error({xhr, status, error});
            },
            complete: function() {
                // Re-enable submit button
                submitBtn.prop('disabled', false).text(originalText);
                settings.complete();
            }
        });
    }
    
    /**
     * Execute a simple AJAX action
     * @param {string} action - The action name
     * @param {object} data - The data to send
     * @param {object} options - Configuration options
     */
    function executeAction(action, data, options) {
        const defaults = {
            successMessage: 'Operation completed successfully!',
            errorMessage: 'An error occurred. Please try again.',
            beforeSubmit: function() {},
            success: function() {},
            error: function() {},
            complete: function() {},
            redirect: null
        };
        
        const settings = $.extend({}, defaults, options);
        const ajaxData = $.extend({}, data, {
            action: action,
            nonce: pg_admin_data.nonce
        });
        
        // Show processing message
        showNotice('', 'info');
        
        // Send the AJAX request
        $.ajax({
            url: pg_admin_data.ajaxUrl,
            type: 'POST',
            data: ajaxData,
            beforeSend: function() {
                settings.beforeSubmit();
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message || settings.successMessage, 'success');
                    settings.success(response);
                    
                    // Redirect if specified
                    if (settings.redirect) {
                        setTimeout(function() {
                            window.location.href = settings.redirect;
                        }, 1000);
                    }
                } else {
                    showNotice(response.data.message || settings.errorMessage, 'error', false);
                    settings.error(response);
                }
            },
            error: function(xhr, status, error) {
                showNotice('Server error: ' + error, 'error', false);
                settings.error({xhr, status, error});
            },
            complete: function() {
                settings.complete();
            }
        });
    }
    
    /**
     * Confirm an action with a dialog
     * @param {string} message - The confirmation message
     * @param {Function} callback - The callback function if confirmed
     */
    function confirmAction(message, callback) {
        if (confirm(message)) {
            callback();
        }
    }
    
    /**
     * Format a number with commas
     * @param {number} number - The number to format
     * @return {string} The formatted number
     */
    function formatNumber(number) {
        return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
    
    /**
     * Format a date string to a readable format
     * @param {string} dateString - The date string to format
     * @param {boolean} includeTime - Whether to include the time
     * @return {string} The formatted date
     */
    function formatDate(dateString, includeTime = false) {
        const date = new Date(dateString);
        
        const options = {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        };
        
        if (includeTime) {
            options.hour = '2-digit';
            options.minute = '2-digit';
        }
        
        return date.toLocaleDateString(undefined, options);
    }
    
    /**
     * Calculate a percentage
     * @param {number} value - The current value
     * @param {number} total - The total value
     * @param {number} decimals - The number of decimal places
     * @return {number} The percentage
     */
    function calculatePercentage(value, total, decimals = 1) {
        if (!total) return 0;
        const percentage = (value / total) * 100;
        return parseFloat(percentage.toFixed(decimals));
    }
    
    // Expose public methods to global scope
    window.ProductionGoalsAdmin = {
        showNotice: showNotice,
        submitFormWithFiles: submitFormWithFiles,
        executeAction: executeAction,
        confirmAction: confirmAction,
        formatNumber: formatNumber,
        formatDate: formatDate,
        calculatePercentage: calculatePercentage
    };

})(jQuery);