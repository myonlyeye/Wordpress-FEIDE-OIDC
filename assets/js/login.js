/**
 * FEIDE WordPress Auth - Login Page JavaScript
 */

(function() {
    'use strict';

    /**
     * Move FEIDE button to top of login form when DOM is ready
     */
    function moveFeideButton() {
        var feideWrapper = document.getElementById('feide-login-wrapper');
        var loginForm = document.getElementById('loginform');

        if (feideWrapper && loginForm) {
            // Move FEIDE button to top of form
            loginForm.insertBefore(feideWrapper, loginForm.firstChild);
        }
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', moveFeideButton);
    } else {
        moveFeideButton();
    }
})();
