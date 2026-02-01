/**
 * FEIDE WordPress Auth - Admin JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize admin functionality
        initTestAuth();
        initRoleMappings();
        initFormValidation();
        initEndpointTesting();
    });

    /**
     * Initialize test authentication functionality
     */
    function initTestAuth() {
        // Test auth button is now a direct link, no AJAX needed
        // But we can add loading state if needed
        $('.feide-test-auth-btn').on('click', function(e) {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Omdirigerer til FEIDE...');
        });
    }

    /**
     * Initialize role mapping functionality
     */
    function initRoleMappings() {
        var mappingIndex = $('.role-mapping-item').length;

        // Add new role mapping
        $('#add-role-mapping').on('click', function() {
            var newIndex = mappingIndex++;
            var newMapping = createRoleMappingHTML(newIndex);
            $('#role-mappings-container').append(newMapping);
        });

        // Remove role mapping
        $(document).on('click', '.remove-role-mapping', function() {
            if ($('.role-mapping-item').length > 1) {
                if (confirm('Er du sikker på at du vil fjerne denne rolleregelen?')) {
                    $(this).closest('.role-mapping-item').remove();
                    updateMappingNumbers();
                }
            } else {
                alert('Du må ha minst én rolleregel.');
            }
        });

        // Add criterion
        $(document).on('click', '.add-criterion', function() {
            var mappingIdx = $(this).data('mapping-index');
            var container = $('.criteria-container[data-mapping-index="' + mappingIdx + '"]');
            var criterionCount = container.find('.criterion-item').length;
            var newCriterion = createCriterionHTML(mappingIdx, criterionCount);
            container.append(newCriterion);
        });

        // Remove criterion
        $(document).on('click', '.remove-criterion', function() {
            var container = $(this).closest('.criteria-container');
            if (container.find('.criterion-item').length > 1) {
                $(this).closest('.criterion-item').remove();
            } else {
                alert('Du må ha minst ett kriterium per rolleregel.');
            }
        });

        // Update heading when rule name changes
        $(document).on('input', '.rule-name-input', function() {
            var $input = $(this);
            var $mappingItem = $input.closest('.role-mapping-item');
            var $heading = $mappingItem.find('h3').first();
            var index = $mappingItem.data('index');
            var name = $input.val().trim();

            // Update heading text (preserve the button)
            var buttonHTML = $heading.find('.remove-role-mapping')[0].outerHTML;
            if (name) {
                $heading.html(name + ' ' + buttonHTML);
            } else {
                $heading.html('Rolleregel #' + (index + 1) + ' ' + buttonHTML);
            }
        });

        // Keyboard navigation: Enter in criterion value field adds new criterion
        $(document).on('keypress', '.criterion-item input[name*="[value]"]', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                var $addBtn = $(this).closest('.criteria-container').find('.add-criterion');
                $addBtn.click();

                // Focus the new criterion's attribute field after a brief delay
                setTimeout(function() {
                    var $container = $(e.target).closest('.criteria-container');
                    var $lastCriterion = $container.find('.criterion-item').last();
                    $lastCriterion.find('input[name*="[attribute]"]').focus();
                }, 50);
            }
        });

        // Focus management: Focus first input in new mapping
        $(document).on('click', '#add-role-mapping', function() {
            setTimeout(function() {
                var $lastMapping = $('.role-mapping-item').last();
                $lastMapping.find('.rule-name-input').focus();
            }, 50);
        });

        // Announce dynamic content changes for screen readers
        $(document).on('click', '.add-criterion, .remove-criterion, #add-role-mapping, .remove-role-mapping', function() {
            announceToScreenReader('Skjema oppdatert');
        });
    }

    /**
     * Announce message to screen readers via aria-live region
     */
    function announceToScreenReader(message) {
        var $announcer = $('#feide-sr-announcer');
        if (!$announcer.length) {
            $announcer = $('<div id="feide-sr-announcer" role="status" aria-live="polite" aria-atomic="true" class="screen-reader-text"></div>');
            $('body').append($announcer);
        }
        $announcer.text(message);
    }

    /**
     * Create HTML for new role mapping
     */
    function createRoleMappingHTML(index) {
        var roles = window.wpRoles || {};
        var rolesHTML = '';

        // Build roles dropdown
        for (var roleKey in roles) {
            if (roles.hasOwnProperty(roleKey)) {
                rolesHTML += '<option value="' + roleKey + '">' + roles[roleKey] + '</option>';
            }
        }

        // Fallback if roles not available
        if (!rolesHTML) {
            rolesHTML = '<option value="subscriber">Abonnent</option>' +
                       '<option value="contributor">Bidragsyter</option>' +
                       '<option value="author">Forfatter</option>' +
                       '<option value="editor">Redaktør</option>' +
                       '<option value="administrator">Administrator</option>';
        }

        return `
            <div class="role-mapping-item" data-index="${index}" role="region" aria-label="Rolleregel ${index + 1}">
                <h3>Rolleregel #${index + 1}
                    <button type="button" class="button remove-role-mapping" aria-label="Fjern denne rolleregelen">Fjern</button>
                </h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Navn på regel</th>
                        <td>
                            <input type="text" name="feide_wp_auth_settings[role_mappings][${index}][name]"
                                   value=""
                                   placeholder="F.eks. 'Studenter' eller 'Ansatte'"
                                   class="regular-text rule-name-input">
                            <p class="description">Gi regelen et beskrivende navn (valgfritt)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WordPress-rolle</th>
                        <td>
                            <select name="feide_wp_auth_settings[role_mappings][${index}][role]" class="regular-text">
                                ${rolesHTML}
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Kriterier-operator</th>
                        <td>
                            <label>
                                <input type="radio" name="feide_wp_auth_settings[role_mappings][${index}][operator]" value="AND" checked>
                                AND (alle kriterier må være oppfylt)
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="feide_wp_auth_settings[role_mappings][${index}][operator]" value="OR">
                                OR (minst ett kriterium må være oppfylt)
                            </label>
                        </td>
                    </tr>
                </table>
                <h4 id="criteria-heading-${index}">Kriterier</h4>
                <div class="criteria-container" data-mapping-index="${index}" role="group" aria-labelledby="criteria-heading-${index}">
                    ${createCriterionHTML(index, 0)}
                </div>
                <p>
                    <button type="button" class="button add-criterion" data-mapping-index="${index}" aria-label="Legg til nytt kriterium">
                        Legg til kriterium
                    </button>
                </p>
            </div>
        `;
    }

    /**
     * Create HTML for new criterion
     */
    function createCriterionHTML(mappingIndex, criterionIndex) {
        return `
            <div class="criterion-item" role="group" aria-label="Kriterium ${criterionIndex + 1}">
                <input type="text"
                       name="feide_wp_auth_settings[role_mappings][${mappingIndex}][criteria][${criterionIndex}][attribute]"
                       placeholder="Attributt (f.eks. groups:*:id eller user:email)"
                       title="Bruk * som wildcard for å matche alle elementer i et array"
                       aria-label="Attributt-sti"
                       class="regular-text">
                <select name="feide_wp_auth_settings[role_mappings][${mappingIndex}][criteria][${criterionIndex}][comparison]"
                        aria-label="Sammenligningsoperator">
                    <option value="equals">Er lik</option>
                    <option value="contains">Inneholder</option>
                    <option value="starts_with">Starter med</option>
                    <option value="ends_with">Slutter med</option>
                    <option value="not_equals">Er ikke lik</option>
                </select>
                <input type="text"
                       name="feide_wp_auth_settings[role_mappings][${mappingIndex}][criteria][${criterionIndex}][value]"
                       placeholder="Verdi"
                       aria-label="Forventet verdi"
                       class="regular-text">
                <button type="button" class="button remove-criterion" aria-label="Fjern dette kriteriet">Fjern</button>
            </div>
        `;
    }

    /**
     * Update mapping numbers after removal
     */
    function updateMappingNumbers() {
        $('.role-mapping-item').each(function(index) {
            $(this).attr('data-index', index);
            var $heading = $(this).find('h3').first();
            var $nameInput = $(this).find('.rule-name-input');
            var name = $nameInput.val().trim();
            var buttonHTML = $heading.find('.remove-role-mapping')[0].outerHTML;

            // Use custom name if exists, otherwise use number
            if (name) {
                $heading.html(name + ' ' + buttonHTML);
            } else {
                $heading.html('Rolleregel #' + (index + 1) + ' ' + buttonHTML);
            }
        });
    }

    /**
     * Validate a single field and show inline error
     */
    function validateField($field, errorMsg) {
        var $container = $field.closest('td');
        if ($container.length === 0) {
            $container = $field.closest('.criterion-item');
        }

        // Remove existing error
        $container.find('.field-error').remove();
        $field.removeClass('error-field');

        // Add error if message provided
        if (errorMsg) {
            $field.addClass('error-field');
            $container.append('<p class="field-error">' + errorMsg + '</p>');
            return false;
        }
        return true;
    }

    /**
     * Clear all validation errors
     */
    function clearValidationErrors() {
        $('.field-error').remove();
        $('.error-field').removeClass('error-field');
    }

    /**
     * Initialize form validation
     */
    function initFormValidation() {
        // Real-time validation on blur for required fields
        $('#client_id').on('blur', function() {
            var value = $(this).val().trim();
            if (value === '') {
                validateField($(this), 'Client ID er påkrevd');
            } else {
                validateField($(this), '');
            }
        });

        $('#client_secret').on('blur', function() {
            var value = $(this).val().trim();
            if (value === '') {
                validateField($(this), 'Client Secret er påkrevd');
            } else {
                validateField($(this), '');
            }
        });

        // Validate criterion fields on blur
        $(document).on('blur', 'input[name*="[attribute]"], input[name*="[value]"]', function() {
            var value = $(this).val().trim();
            if (value === '') {
                var fieldName = $(this).attr('name').includes('[attribute]') ? 'Attributt' : 'Verdi';
                validateField($(this), fieldName + ' er påkrevd');
            } else {
                validateField($(this), '');
            }
        });

        // Form submission validation
        $('form').on('submit', function(e) {
            clearValidationErrors();
            var valid = true;

            // Validate on settings tab
            if ($('#client_id').length) {
                var clientId = $('#client_id').val().trim();
                var clientSecret = $('#client_secret').val().trim();

                if (clientId === '') {
                    validateField($('#client_id'), 'Client ID er påkrevd');
                    valid = false;
                }

                if (clientSecret === '') {
                    validateField($('#client_secret'), 'Client Secret er påkrevd');
                    valid = false;
                }
            }

            // Validate role mappings
            $('.role-mapping-item').each(function() {
                $(this).find('.criterion-item').each(function() {
                    var $attrInput = $(this).find('input[name*="[attribute]"]');
                    var $valueInput = $(this).find('input[name*="[value]"]');
                    var attr = $attrInput.val().trim();
                    var value = $valueInput.val().trim();

                    if (attr === '') {
                        validateField($attrInput, 'Attributt er påkrevd');
                        valid = false;
                    }

                    if (value === '') {
                        validateField($valueInput, 'Verdi er påkrevd');
                        valid = false;
                    }
                });
            });

            if (!valid) {
                e.preventDefault();
                // Scroll to first error
                var $firstError = $('.error-field').first();
                if ($firstError.length) {
                    $('html, body').animate({
                        scrollTop: $firstError.offset().top - 100
                    }, 300);
                }
            }
        });
    }

    /**
     * Show notification
     */
    function showNotification(message, type) {
        var noticeClass = 'notice-' + type;
        var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

        $('.wrap h1').after(notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    /**
     * Copy to clipboard helper (uses modern Clipboard API with fallback)
     */
    function copyToClipboard(text) {
        // Try modern Clipboard API first
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                // Success - silent
            }).catch(function(err) {
                // Fallback to old method
                copyToClipboardFallback(text);
            });
        } else {
            // Browser doesn't support Clipboard API, use fallback
            copyToClipboardFallback(text);
        }
    }

    /**
     * Fallback clipboard copy for older browsers
     */
    function copyToClipboardFallback(text) {
        var $temp = $('<input>');
        $('body').append($temp);
        $temp.val(text).select();
        try {
            document.execCommand('copy');
        } catch (err) {
            console.error('Failed to copy text: ', err);
        }
        $temp.remove();
    }

    /**
     * Initialize endpoint connectivity testing
     */
    function initEndpointTesting() {
        $('.test-endpoint-btn').on('click', function() {
            var $btn = $(this);
            var endpointId = $btn.data('endpoint');
            var $input = $('#' + endpointId);
            var $status = $('#' + endpointId + '_status');
            var endpointUrl = $input.val().trim();

            if (!endpointUrl) {
                $status.html('<span style="color: #dc3232;">Ingen URL oppgitt</span>');
                return;
            }

            // Show loading state
            $btn.prop('disabled', true);
            $status.html('<span class="spinner is-active" style="float: none;"></span>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'feide_test_endpoint',
                    nonce: feideAdmin.nonce,
                    endpoint_url: endpointUrl
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var color = data.reachable ? '#28a745' : '#dc3232';
                        var icon = data.reachable ? '✓' : '✗';
                        $status.html('<span style="color: ' + color + ';">' + icon + ' ' + data.status_text + ' (HTTP ' + data.status_code + ')</span>');
                    } else {
                        $status.html('<span style="color: #dc3232;">✗ ' + response.data + '</span>');
                    }
                },
                error: function() {
                    $status.html('<span style="color: #dc3232;">✗ Kommunikasjonsfeil</span>');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    // Add copy buttons to code elements in test results
    $('.feide-test-results code').each(function() {
        var $code = $(this);
        var $copyBtn = $('<button class="button button-small" style="margin-left: 10px;">Kopier</button>');

        $copyBtn.on('click', function(e) {
            e.preventDefault();
            copyToClipboard($code.text());
            showNotification('Kopiert til utklippstavle', 'success');
        });

        $code.after($copyBtn);
    });

})(jQuery);
