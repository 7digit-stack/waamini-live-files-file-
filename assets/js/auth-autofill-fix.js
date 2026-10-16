/**
 * WAAMINI Auth Autofill Fix
 * Bypasses aggressive browser autofill using the readonly toggle trick.
 * Compliant with CSP (no inline scripts).
 */

document.addEventListener('DOMContentLoaded', function() {
    const authInputs = document.querySelectorAll('.auth-input[readonly]');
    
    authInputs.forEach(input => {
        // Remove readonly on focus, click, or mouseover to allow typing
        const unlock = function() {
            input.removeAttribute('readonly');
        };

        input.addEventListener('focus', unlock);
        input.addEventListener('mousedown', unlock);
        input.addEventListener('touchstart', unlock);
        
        // Optional: re-enable readonly on blur if the value is empty 
        // (to prevent autofill from triggering again if they click away)
        input.addEventListener('blur', function() {
            if (this.value === '') {
                this.setAttribute('readonly', 'readonly');
            }
        });
    });
});
