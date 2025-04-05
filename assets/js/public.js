/**
 * Production Goals - Public JavaScript
 * Enhanced to prevent duplicate submissions while allowing rapid sequential submissions
 */

(function($) {
    'use strict';

    // Submission tracking system
    const submissionTracker = {
        // Track IDs of submissions already processed
        processedIds: new Set(),
        
        // Track active submission parts to prevent duplicates
        activeParts: {},
        
        // Countdown timer intervals
        countdownIntervals: {},
        
        // Load processed IDs from localStorage on initialization
        init: function() {
            try {
                const storedIds = localStorage.getItem('pg_processed_ids');
                if (storedIds) {
                    const idArray = JSON.parse(storedIds);
                    idArray.forEach(id => this.processedIds.add(id));
                }
                
                // Clean up old submissions
                this.pruneOldSubmissions();
            } catch (e) {
                console.error('Error initializing submission tracker:', e);
                localStorage.removeItem('pg_processed_ids');
            }
            
            // Clear any active parts on page load
            this.activeParts = {};
        },
        
        // Check if a submission has already been processed
        isProcessed: function(submissionId) {
            return this.processedIds.has(submissionId);
        },
        
        // Mark a submission as processed
        markProcessed: function(submissionId) {
            // Add to in-memory set
            this.processedIds.add(submissionId);
            
            try {
                // Store submission with timestamp
                const submission = {
                    id: submissionId,
                    timestamp: Date.now()
                };
                
                // Add to local storage
                let storedSubmissions = [];
                const storedIds = localStorage.getItem('pg_processed_submissions');
                
                if (storedIds) {
                    storedSubmissions = JSON.parse(storedIds);
                }
                
                // Add new submission
                storedSubmissions.push(submission);
                
                // Limit to 200 submissions
                if (storedSubmissions.length > 200) {
                    storedSubmissions = storedSubmissions.slice(-200);
                }
                
                localStorage.setItem('pg_processed_submissions', JSON.stringify(storedSubmissions));
                
                // Update the quick lookup array
                const idArray = Array.from(this.processedIds);
                localStorage.setItem('pg_processed_ids', JSON.stringify(idArray));
            } catch (e) {
                console.error('Error storing processed submission:', e);
            }
        },
        
        // Record a submission by user, part, and quantity for exact duplicate detection
        recordSubmission: function(userId, partId, quantity) {
            try {
                const key = `pg_sub_${userId}_${partId}_${quantity}`;
                const data = {
                    timestamp: Date.now()
                };
                localStorage.setItem(key, JSON.stringify(data));
                
                // Add to list of all keys
                let allKeys = [];
                const storedKeys = localStorage.getItem('pg_all_submission_keys');
                
                if (storedKeys) {
                    allKeys = JSON.parse(storedKeys);
                }
                
                if (!allKeys.includes(key)) {
                    allKeys.push(key);
                    localStorage.setItem('pg_all_submission_keys', JSON.stringify(allKeys));
                }
            } catch (e) {
                console.error('Error recording submission:', e);
            }
        },
        
        // Check if exact submission was made recently
        checkExactDuplicate: function(userId, partId, quantity) {
            try {
                const key = `pg_sub_${userId}_${partId}_${quantity}`;
                const storedData = localStorage.getItem(key);
                
                if (storedData) {
                    const data = JSON.parse(storedData);
                    const timeElapsed = Date.now() - data.timestamp;
                    
                    // Only consider exact same quantity as duplicate for 3 minutes
                    return timeElapsed < 3 * 60 * 1000;
                }
                return false;
            } catch (e) {
                console.error('Error checking recent submissions:', e);
                return false;
            }
        },
        
        // Lock a part for submission (with countdown)
        lockPart: function(userId, partId, countdownElement) {
            // If the part is already locked, do nothing
            if (this.isPartLocked(userId, partId)) {
                return false;
            }
            
            // Lock the part
            const key = `${userId}_${partId}`;
            this.activeParts[key] = Date.now();
            
            // Create countdown timer if element provided
            if (countdownElement) {
                this.startCountdown(userId, partId, countdownElement);
            }
            
            // Auto-release after short period
            setTimeout(() => {
                this.releasePart(userId, partId);
            }, 3000); // Release after 3 seconds
            
            return true;
        },
        
        // Check if a part is currently locked
        isPartLocked: function(userId, partId) {
            const key = `${userId}_${partId}`;
            
            // Check if locked and the lock is recent (within 3 seconds)
            if (this.activeParts[key]) {
                const elapsed = Date.now() - this.activeParts[key]; 
                return elapsed < 3000; // Locks expire after 3 seconds
            }
            
            return false;
        },
        
        // Release a part lock
        releasePart: function(userId, partId) {
            const key = `${userId}_${partId}`;
            delete this.activeParts[key];
            
            // Clear any countdown timer
            if (this.countdownIntervals[key]) {
                clearInterval(this.countdownIntervals[key]);
                delete this.countdownIntervals[key];
            }
            
            // Find and update any countdown element
            $(`.pg-countdown[data-part-id="${partId}"]`).text('').hide();
        },
        
        // Start a countdown timer
        startCountdown: function(userId, partId, element) {
            const key = `${userId}_${partId}`;
            let secondsLeft = 3; // 3 second cooldown
            
            // Clear any existing interval
            if (this.countdownIntervals[key]) {
                clearInterval(this.countdownIntervals[key]);
            }
            
            // Update the display
            element.text(`Wait ${secondsLeft}s`).show();
            
            // Start the countdown
            this.countdownIntervals[key] = setInterval(() => {
                secondsLeft--;
                
                if (secondsLeft <= 0) {
                    // Time's up
                    clearInterval(this.countdownIntervals[key]);
                    delete this.countdownIntervals[key];
                    element.text('Ready!').fadeOut(1000);
                } else {
                    // Update display
                    element.text(`Wait ${secondsLeft}s`);
                }
            }, 1000);
        },
        
        // Prune old submissions (older than 24 hours)
        pruneOldSubmissions: function() {
            try {
                const storedSubmissions = localStorage.getItem('pg_processed_submissions');
                if (storedSubmissions) {
                    let submissions = JSON.parse(storedSubmissions);
                    const oneDayAgo = Date.now() - (24 * 60 * 60 * 1000);
                    
                    // Filter out old submissions
                    const freshSubmissions = submissions.filter(sub => sub.timestamp >= oneDayAgo);
                    
                    // If we removed any, update storage
                    if (freshSubmissions.length < submissions.length) {
                        localStorage.setItem('pg_processed_submissions', JSON.stringify(freshSubmissions));
                        
                        // Rebuild the processedIds set
                        this.processedIds.clear();
                        freshSubmissions.forEach(sub => this.processedIds.add(sub.id));
                        
                        // Update the quick lookup array
                        const idArray = Array.from(this.processedIds);
                        localStorage.setItem('pg_processed_ids', JSON.stringify(idArray));
                    }
                }
                
                // Clean up old individual submission records
                const allKeys = localStorage.getItem('pg_all_submission_keys');
                if (allKeys) {
                    const keys = JSON.parse(allKeys);
                    const now = Date.now();
                    const threeHoursAgo = now - (3 * 60 * 60 * 1000);
                    const validKeys = [];
                    
                    keys.forEach(key => {
                        const data = localStorage.getItem(key);
                        if (data) {
                            try {
                                const parsed = JSON.parse(data);
                                if (parsed.timestamp && parsed.timestamp >= threeHoursAgo) {
                                    validKeys.push(key);
                                } else {
                                    localStorage.removeItem(key);
                                }
                            } catch (e) {
                                localStorage.removeItem(key);
                            }
                        }
                    });
                    
                    localStorage.setItem('pg_all_submission_keys', JSON.stringify(validKeys));
                }
            } catch (e) {
                console.error('Error pruning old submissions:', e);
            }
        }
    };
    
    // Generate a unique page session ID
    const pageSessionId = Math.random().toString(36).substring(2, 15);

    // Initialize when document is ready
    $(document).ready(function() {
        // Initialize the submission tracker
        submissionTracker.init();
        
        // Try to get user ID
        const userId = typeof pgUserData !== 'undefined' ? pgUserData.userId : 0;
        
        initTabs();
        initSubmissionForms(userId);
        initEditForms(userId);
        initDeleteButtons(userId);
        initProductionGoalForm(userId);
        
        // Add refresh detection
        $(window).on('beforeunload', function() {
            try {
                localStorage.setItem('pg_page_unloading', Date.now().toString());
                setTimeout(function() {
                    localStorage.removeItem('pg_page_unloading');
                }, 5000);
            } catch (e) {
                console.error('Error handling page unload:', e);
            }
        });
        
        // Check if this is a page refresh
        try {
            const unloadTime = localStorage.getItem('pg_page_unloading');
            if (unloadTime) {
                const refreshTimestamp = parseInt(unloadTime);
                const currentTime = Date.now();
                
                if (currentTime - refreshTimestamp < 5000) {
                    localStorage.setItem('pg_page_refreshed', 'true');
                }
                localStorage.removeItem('pg_page_unloading');
            } else {
                localStorage.removeItem('pg_page_refreshed');
            }
        } catch (e) {
            console.error('Error checking page refresh:', e);
        }
    });

    /**
     * Initialize tab functionality
     */
    function initTabs() {
        $('.pg-tabs-nav').on('click', '.pg-tab-button', function() {
            const tabId = $(this).data('tab');
            
            // Update active tab button
            $(this).siblings().removeClass('pg-active');
            $(this).addClass('pg-active');
            
            // Show selected tab content
            const tabContainer = $(this).closest('.pg-tabs').find('.pg-tab');
            tabContainer.removeClass('pg-active');
            $('#' + tabId).addClass('pg-active');
        });
    }

    /**
     * Initialize submission forms for my_projects page with countdown timer
     */
    function initSubmissionForms(userId) {
        // Process all submission forms and add countdown elements
        $('.pg-contribution-form:not(#production-goal-form)').each(function() {
            const form = $(this);
            const partId = form.data('part-id') || form.find('input[name="part_id"]').val();
            
            // Add countdown element if not present
            if (partId && !form.find('.pg-countdown').length) {
                $('<div class="pg-countdown" data-part-id="' + partId + '"></div>')
                    .css({
                        'display': 'none',
                        'color': '#E91E63',
                        'font-weight': 'bold',
                        'margin-top': '5px',
                        'text-align': 'center'
                    })
                    .appendTo(form);
            }
        });
        
        // Handle form submissions
        $('.pg-contribution-form:not(#production-goal-form)').on('submit', function(e) {
            e.preventDefault();
            
            const form = $(this);
            
            // Get form data
            const projectId = form.find('input[name="project_id"]').val();
            const partId = form.find('input[name="part_id"]').val();
            const quantity = parseInt(form.find('input[name="quantity"]').val());
            const submitButton = form.find('button[type="submit"]');
            
            // Get or create countdown element
            let countdownElement = form.find('.pg-countdown');
            if (!countdownElement.length) {
                countdownElement = $('<div class="pg-countdown" data-part-id="' + partId + '"></div>')
                    .css({
                        'display': 'none',
                        'color': '#E91E63',
                        'font-weight': 'bold',
                        'margin-top': '5px',
                        'text-align': 'center'
                    })
                    .appendTo(form);
            }
            
            // Generate a unique submission ID
            const submissionId = `${userId}_${partId}_${quantity}_${Date.now()}_${pageSessionId}_${Math.random().toString(36).substring(2, 10)}`;
            
            // Validate inputs
            if (!partId || !quantity || quantity <= 0) {
                showMessage(form, 'Please enter a valid quantity.', 'error');
                return false;
            }
            
            // Check if user ID is available
            if (!userId) {
                showMessage(form, 'User authentication issue. Please refresh the page and try again.', 'error');
                return false;
            }
            
            // Check if we've already processed this exact submission
            if (submissionTracker.isProcessed(submissionId)) {
                showMessage(form, 'This exact submission has already been processed.', 'error');
                return false;
            }
            
            // Check if this is an exact duplicate (same quantity)
            if (submissionTracker.checkExactDuplicate(userId, partId, quantity)) {
                showMessage(form, 'This exact quantity was just submitted. Please use a different quantity or wait a moment.', 'error');
                return false;
            }
            
            // Lock this part with countdown
            if (!submissionTracker.lockPart(userId, partId, countdownElement)) {
                showMessage(form, 'Another submission for this part is in progress. Please wait a moment.', 'error');
                return false;
            }
            
            // Disable form during submission
            submitButton.prop('disabled', true).text('Submitting...');
            
            // Submit via AJAX
            $.ajax({
                url: productionGoals.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'production_goals_submit',
                    nonce: productionGoals.nonce,
                    project_id: projectId,
                    part_id: partId,
                    quantity: quantity,
                    submission_id: submissionId
                },
                success: function(response) {
                    if (response.success) {
                        // Mark submission as processed
                        submissionTracker.markProcessed(submissionId);
                        
                        // Record the submission
                        submissionTracker.recordSubmission(userId, partId, quantity);
                        
                        // Show success message
                        showMessage(form, response.data.message, 'success');
                        
                        // Reset form
                        form.find('input[name="quantity"]').val('1');
                        
                        // Update progress displays
                        if (response.data.partId && response.data.newProgress) {
                            updatePartProgress(response.data.partId, response.data.newProgress, response.data.goal);
                        }
                        
                        // Update user's contribution display
                        updateUserContribution(partId, response.data.userContribution);
                    } else {
                        // Handle duplicate detected by server
                        if (response.data.duplicate) {
                            submissionTracker.markProcessed(submissionId);
                            submissionTracker.recordSubmission(userId, partId, quantity);
                        }
                        
                        showMessage(form, response.data.message || 'Error submitting contribution.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    showMessage(form, 'Server error: ' + error + '. Please try again.', 'error');
                },
                complete: function() {
                    submitButton.prop('disabled', false).text('Submit');
                    
                    // Part lock will auto-release after the countdown
                }
            });
        });
    }

    /**
     * Initialize the main production goal form on project pages with countdown
     */
function initProductionGoalForm(userId) {
    // Check if the form exists
    const form = $('#production-goal-form');
    if (!form.length) return;
    
    // Check if the form is already initialized to prevent duplicate handlers
    if (form.data('initialized') === 'true') {
        console.log('Production goal form already initialized, skipping duplicate handler');
        return;
    }
    
    // Mark the form as initialized
    form.attr('data-initialized', 'true');
    
    // Add the submit handler
    form.on('submit', function(e) {
        e.preventDefault();
        
        const formId = 'production-goal-' + pageSessionId + '-' + Date.now();
        
        const projectId = form.find('input[name="project_id"]').val();
        const resultContainer = $('#submission-result');
        const submitButton = form.find('.submit-button');
        const spinner = $('#submission-spinner');
        
        // Check for refresh
        const isPageRefresh = localStorage.getItem('pg_page_refreshed') === 'true';
        
        // Gather all inputs with quantities
        const submissions = [];
        let hasValidSubmission = false;
        let lockedPartId = null; // Track if any part is locked
        
        form.find('tr').each(function() {
            const row = $(this);
            const quantityInput = row.find('input[name="quantity"]');
            const partIdInput = row.find('input[name="part_id"]');
            
            if (quantityInput.length && partIdInput.length) {
                // Make sure to trim input value and handle non-numeric values
                const quantityVal = quantityInput.val().trim();
                const quantity = quantityVal === '' ? 0 : parseInt(quantityVal, 10) || 0;
                const partId = partIdInput.val();
                
                // Debug logging to see what values we're working with
                console.log('Input value:', quantityVal, 'Parsed quantity:', quantity, 'for part:', partId);
                
                // Get or create countdown element for this row
                let countdownElement = row.find('.pg-countdown');
                if (!countdownElement.length) {
                    countdownElement = $('<div class="pg-countdown" data-part-id="' + partId + '"></div>')
                        .css({
                            'display': 'none',
                            'color': '#E91E63',
                            'font-weight': 'bold',
                            'margin-top': '5px',
                            'text-align': 'center'
                        })
                        .appendTo(row.find('td:last'));
                }
                
                if (quantity > 0) {  // Explicitly check if greater than 0
                    // Generate unique submission ID
                    const submissionId = `${userId}_${partId}_${quantity}_${Date.now()}_${pageSessionId}_${Math.random().toString(36).substring(2, 10)}`;
                    
                    // Check for duplicates
                    const isLocked = submissionTracker.isPartLocked(userId, partId);
                    const isProcessed = submissionTracker.isProcessed(submissionId);
                    
                    if (isProcessed || isLocked) {
                        // Skip this one but don't block the whole form
                        console.log('Skipping part', partId, {isProcessed, isLocked});
                        
                        // If part is locked, show countdown
                        if (isLocked) {
                            lockedPartId = partId;
                        }
                    } else {
                        console.log('Adding valid submission for part', partId, 'quantity', quantity);
                        hasValidSubmission = true;
                        
                        // Lock the part with countdown
                        submissionTracker.lockPart(userId, partId, countdownElement);
                        
                        submissions.push({
                            partId: partId,
                            quantity: quantity,
                            submissionId: submissionId,
                            countdownElement: countdownElement
                        });
                    }
                } else {
                    console.log('Zero or invalid quantity for part', partId, 'value:', quantityVal);
                }
            }
        });
        
        console.log('Has valid submission:', hasValidSubmission, 'Submissions:', submissions.length);
        
        // Check if any valid submissions
        if (!hasValidSubmission) {
            if (lockedPartId) {
                resultContainer.html('<div class="error-message">Please wait before submitting again. The countdown shows remaining time.</div>');
            } else {
                resultContainer.html('<div class="error-message">Please enter at least one quantity.</div>');
            }
            return false;
        }
        
        // Disable form during submission
        submitButton.prop('disabled', true).text('Submitting...');
        spinner.show();
        
        // Process each submission sequentially
        processSubmissions(submissions, 0, projectId, formId, form, resultContainer, submitButton, spinner, userId);
    });
    
    // Make quantity inputs more visible after reset
    form.on('reset', function() {
        setTimeout(function() {
            form.find('input[name="quantity"]').css('background-color', '#f0f8ff').attr('placeholder', 'Enter quantity');
        }, 10);
    });
}
    /**
     * Process submissions recursively with countdown support
     */
    function processSubmissions(submissions, index, projectId, formId, form, resultContainer, submitButton, spinner, userId) {
        if (index >= submissions.length) {
            // All submissions processed
            submitButton.prop('disabled', false).text('Submit');
            spinner.hide();
            
            // Show final success message
            resultContainer.html('<div class="success-message">All contributions submitted successfully!</div>');
            
            // Reset inputs
            form.find('input[name="quantity"]').val('');
            
            return;
        }
        
        const submission = submissions[index];
        
        $.ajax({
            url: productionGoals.ajaxUrl,
            type: 'POST',
            data: {
                action: 'production_goals_submit',
                nonce: productionGoals.nonce,
                project_id: projectId,
                part_id: submission.partId,
                quantity: submission.quantity,
                submission_id: submission.submissionId
            },
            success: function(response) {
                if (response.success) {
                    // Mark submission as processed
                    submissionTracker.markProcessed(submission.submissionId);
                    
                    // Record the submission
                    submissionTracker.recordSubmission(userId, submission.partId, submission.quantity);
                    
                    // Update the progress display
                    const row = form.find('input[name="part_id"][value="' + submission.partId + '"]').closest('tr');
                    
                    if (response.data.newProgress !== undefined) {
                        // Update progress text
                        const progressElem = row.find('.part-progress');
                        const goal = parseInt(response.data.goal || progressElem.text().split('/')[1].trim());
                        
                        progressElem.text(response.data.newProgress + ' / ' + goal);
                        
                        // Update progress bar
                        const percentage = goal > 0 ? Math.min(100, (response.data.newProgress / goal) * 100) : 0;
                        row.find('.progress-bar').css('width', percentage + '%');
                        
                        // Update user contribution
                        if (response.data.userContribution !== undefined) {
                            row.find('.user-contribution').text(response.data.userContribution);
                        }
                    }
                    
                    // Process next submission
                    processSubmissions(submissions, index + 1, projectId, formId, form, resultContainer, submitButton, spinner, userId);
                } else {
                    // Handle duplicate
                    if (response.data.duplicate) {
                        submissionTracker.markProcessed(submission.submissionId);
                        submissionTracker.recordSubmission(userId, submission.partId, submission.quantity);
                        
                        // Try the next one
                        processSubmissions(submissions, index + 1, projectId, formId, form, resultContainer, submitButton, spinner, userId);
                    } else {
                        // Show error and stop
                        resultContainer.html('<div class="error-message">' + (response.data.message || 'Error submitting contribution.') + '</div>');
                        submitButton.prop('disabled', false).text('Submit');
                        spinner.hide();
                    }
                }
            },
            error: function(xhr, status, error) {
                if (xhr.status === 409) { // Conflict (duplicate)
                    submissionTracker.markProcessed(submission.submissionId);
                    submissionTracker.recordSubmission(userId, submission.partId, submission.quantity);
                    
                    // Continue with next submission
                    processSubmissions(submissions, index + 1, projectId, formId, form, resultContainer, submitButton, spinner, userId);
                } else {
                    resultContainer.html('<div class="error-message">Server error: ' + error + '. Please try again.</div>');
                    submitButton.prop('disabled', false).text('Submit');
                    spinner.hide();
                }
            }
        });
    }

    /**
     * Initialize edit forms with countdown support
     */
    function initEditForms(userId) {
        $('.pg-edit-form').on('submit', function(e) {
            e.preventDefault();
            
            const form = $(this);
            const submissionId = form.data('submission-id');
            const formId = 'edit-' + submissionId + '-' + pageSessionId + '-' + Date.now();
            
            const quantity = parseInt(form.find('input[name="quantity"]').val());
            const submitButton = form.find('button[type="submit"]');
            
            // Create a unique edit ID
            const editSubmissionId = `edit_${userId}_${submissionId}_${quantity}_${Date.now()}`;
            
            // Check if already processed
            if (submissionTracker.isProcessed(editSubmissionId)) {
                const message = $('<div class="pg-error-message">This edit has already been processed.</div>');
                form.prepend(message);
                setTimeout(() => message.fadeOut(() => message.remove()), 3000);
                return false;
            }
            
            // Validate inputs
            if (!submissionId || !quantity || quantity <= 0) {
                alert('Please enter a valid quantity greater than 0');
                return false;
            }
            
            // Disable form during submission
            submitButton.prop('disabled', true).text('Updating...');
            
            // Submit via AJAX
            $.ajax({
                url: productionGoals.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'production_goals_edit_submission',
                    nonce: productionGoals.nonce,
                    submission_id: submissionId,
                    quantity: quantity,
                    edit_id: editSubmissionId
                },
                success: function(response) {
                    if (response.success) {
                        // Mark edit as processed
                        submissionTracker.markProcessed(editSubmissionId);
                        
                        // Show success message
                        const message = $('<div class="pg-success-message">' + response.data.message + '</div>');
                        form.prepend(message);
                        
                        // Update the displayed quantity
                        form.closest('.pg-submission-item').find('.pg-submission-quantity').text(quantity + ' parts submitted');
                        
                        // Update progress
                        if (response.data.partId && response.data.newProgress) {
                            updatePartProgress(response.data.partId, response.data.newProgress, response.data.goal);
                        }
                        
                        // Hide message and form after a delay
                        setTimeout(function() {
                            message.fadeOut(function() { $(this).remove(); });
                            form.slideUp();
                        }, 1500);
                    } else {
                        if (response.data.duplicate) {
                            submissionTracker.markProcessed(editSubmissionId);
                        }
                        
                        const message = $('<div class="pg-error-message">' + (response.data.message || 'Error updating submission') + '</div>');
                        form.prepend(message);
                        
                        setTimeout(function() {
                            message.fadeOut(function() { $(this).remove(); });
                        }, 3000);
                    }
                },
                error: function(xhr, status, error) {
                    const message = $('<div class="pg-error-message">Server error: ' + error + '. Please try again.</div>');
                    form.prepend(message);
                    
                    setTimeout(function() {
                        message.fadeOut(function() { $(this).remove(); });
                    }, 3000);
                },
                complete: function() {
                    submitButton.prop('disabled', false).text('Update');
                }
            });
        });
        
        // Toggle edit form visibility
        $('.pg-edit-button').on('click', function() {
            $(this).closest('.pg-submission-item').find('.pg-edit-form').slideToggle();
        });
        
        // Cancel button for edit forms
        $('.edit-cancel-button').on('click', function() {
            $(this).closest('.pg-edit-form').slideUp();
        });
    }

    /**
     * Initialize delete buttons
     */
    function initDeleteButtons(userId) {
        $('.pg-delete-button').on('click', function() {
            if (!confirm('Are you sure you want to delete this submission? This cannot be undone.')) {
                return;
            }
            
            const button = $(this);
            const submissionId = button.data('submission-id');
            const deleteId = 'delete-' + submissionId + '-' + pageSessionId + '-' + Date.now();
            
            // Check if already processed
            if (submissionTracker.isProcessed(deleteId)) {
                alert('This deletion has already been processed.');
                return false;
            }
            
            const submissionItem = button.closest('.pg-submission-item');
            
            // Disable button during operation
            button.prop('disabled', true).text('Deleting...');
            
            // Submit via AJAX
            $.ajax({
                url: productionGoals.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'production_goals_delete_submission',
                    nonce: productionGoals.nonce,
                    submission_id: submissionId,
                    delete_id: deleteId
                },
                success: function(response) {
                    if (response.success) {
                        // Mark deletion as processed
                        submissionTracker.markProcessed(deleteId);
                        
                        // Remove the submission item with animation
                        submissionItem.slideUp(function() {
                            $(this).remove();
                        });
                        
                        // Update progress
                        if (response.data.partId && response.data.newProgress !== undefined) {
                            updatePartProgress(response.data.partId, response.data.newProgress, response.data.goal);
                        }
                    } else {
                        if (response.data.duplicate) {
                            submissionTracker.markProcessed(deleteId);
                            alert('This submission has already been deleted.');
                        } else {
                            alert(response.data.message || 'Error deleting submission');
                        }
                        button.prop('disabled', false).text('Delete');
                    }
                },
                error: function(xhr, status, error) {
                    alert('Server error: ' + error + '. Please try again.');
                    button.prop('disabled', false).text('Delete');
                }
            });
        });
    }

    /**
     * Update progress display for a part
     */
    function updatePartProgress(partId, newProgress, goal) {
        // Convert to numbers for calculation
        newProgress = parseInt(newProgress);
        goal = parseInt(goal || 0);
        
        // Calculate percentage
        const percentage = goal > 0 ? Math.min(100, (newProgress / goal) * 100) : 0;
        
        // Update all instances on the page
        
        // 1. Update in my_projects page
        $('.pg-part-item').each(function() {
            const item = $(this);
            if (item.data('part-id') == partId) {
                // Update progress text
                item.find('.pg-progress-value').text(newProgress);
                item.find('.pg-percentage-value').text(percentage.toFixed(1) + '%');
                
                // Update progress bar
                item.find('.pg-progress-bar').css('width', percentage + '%');
            }
        });
        
        // 2. Update in production goal page
        $('.progress-table tr').each(function() {
            const row = $(this);
            const rowPartId = row.find('input[name="part_id"]').val();
            
            if (rowPartId == partId) {
                // Update progress text
                row.find('.part-progress').text(newProgress + ' / ' + goal);
                
                // Update progress bar
                row.find('.progress-bar').css('width', percentage + '%');
            }
        });
        
        // 3. Update in other locations
        $('[data-part-id="' + partId + '"]').each(function() {
            const elem = $(this);
            
            // Update progress values
            elem.find('.pg-progress-current, .part-progress-current').text(newProgress);
            
            // Update percentage
            const percentElem = elem.find('.pg-progress-percentage');
            if (percentElem.length) {
                percentElem.text('(' + percentage.toFixed(2) + '%)');
            }
            
            // Update progress bar
            elem.find('.pg-progress-bar').css('width', percentage + '%');
        });
    }

    /**
     * Update user contribution display
     */
    function updateUserContribution(partId, userContribution) {
        if (!userContribution) return;
        
        // Find all user contribution displays for this part
        $('.pg-user-contribution').each(function() {
            const contributionElem = $(this);
            const container = contributionElem.closest('[data-part-id="' + partId + '"]');
            
            if (container.length) {
                contributionElem.text(userContribution);
            }
        });
    }

    /**
     * Show a message in a container
     */
    function showMessage(container, message, type) {
        // Create message element
        const messageClass = (type === 'error') ? 'pg-error-message' : 'pg-success-message';
        const messageElement = $('<div class="' + messageClass + '">' + message + '</div>');
        
        // Find a suitable container for the message
        let messageContainer;
        
        if (container.find('.pg-submission-message').length) {
            messageContainer = container.find('.pg-submission-message');
        } else if (container.parent().find('.pg-submission-message').length) {
            messageContainer = container.parent().find('.pg-submission-message');
        } else {
            // Create a new container if needed
            messageContainer = $('<div class="pg-submission-message"></div>');
            container.append(messageContainer);
        }
        
        // Clear existing messages and add new one
        messageContainer.empty().append(messageElement);
        
        // Auto-hide success messages after delay
        if (type === 'success') {
            setTimeout(function() {
                messageElement.fadeOut('slow', function() {
                    $(this).remove();
                });
            }, 3000);
        }
    }

})(jQuery);