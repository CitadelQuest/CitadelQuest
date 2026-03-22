/*
 * CitadelQuest Registration Form Validation
 * Real-time client-side validation for the registration form.
 */

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[name="registration"]');
    if (!form) return;

    function validateForm() {
        const button = document.getElementById('register-button');
        button.disabled = true;
        
        const username = form.querySelector('input[name="registration[username]"]');
        const email = form.querySelector('input[name="registration[email]"]');
        const password = form.querySelector('input[name="registration[password][first]"]');
        const repeatPassword = form.querySelector('input[name="registration[password][second]"]');
        const repeatPasswordWrapper = document.querySelector('.repeatPasswordWrapper');

        const usernameHelp = document.querySelector('.usernameHelp');
        usernameHelp.classList.add('d-none');

        const passwordRequirements = document.querySelector('.password-requirements');
        passwordRequirements.classList.add('d-none');
        repeatPasswordWrapper.classList.add('d-none');
        
        const passwordRequirementsMinLength = document.querySelector('.password-requirements-min-length');
        const passwordRequirementsUppercase = document.querySelector('.password-requirements-uppercase');
        const passwordRequirementsLowercase = document.querySelector('.password-requirements-lowercase');
        const passwordRequirementsNumber = document.querySelector('.password-requirements-number');
        const passwordRequirementsSpecial = document.querySelector('.password-requirements-special');
        passwordRequirementsMinLength.classList.remove('text-cyber');
        passwordRequirementsUppercase.classList.remove('text-cyber');
        passwordRequirementsLowercase.classList.remove('text-cyber');
        passwordRequirementsNumber.classList.remove('text-cyber');
        passwordRequirementsSpecial.classList.remove('text-cyber');

        const createAlsoCQAIGatewayAccountClaim = document.querySelector('.createAlsoCQAIGatewayAccountClaim');
        createAlsoCQAIGatewayAccountClaim.classList.add('d-none');
        
        // Letters, numbers, underscores and hyphens only
        const usernameRegex = /^[a-zA-Z0-9_-]+$/; 

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/;
        
        if (!username || !email || !password || !repeatPassword) {
            return false;
        }
        
        username.classList.remove('is-invalid');
        email.classList.remove('is-invalid');
        password.classList.remove('is-invalid');
        repeatPassword.classList.remove('is-invalid');

        username.classList.remove('is-valid');
        email.classList.remove('is-valid');
        password.classList.remove('is-valid');
        repeatPassword.classList.remove('is-valid');
        
        if (!usernameRegex.test(username.value)) {
            username.classList.add('is-invalid');
            usernameHelp.classList.remove('d-none');
            return false;
        } else {
            username.classList.add('is-valid');
        }
        
        if (!emailRegex.test(email.value)) {
            email.classList.add('is-invalid');
            return false;
        } else {
            email.classList.add('is-valid');
        }
    
        if (!passwordRegex.test(password.value) || (password.value.length < 8)) {
            password.classList.add('is-invalid');
            passwordRequirements.classList.remove('d-none');
            
            if (password.value.length >= 8) {
                passwordRequirementsMinLength.classList.add('text-cyber');
            }
            
            if (/^(?=.*[A-Z])/.test(password.value)) {
                passwordRequirementsUppercase.classList.add('text-cyber');
            }
            
            if (/^(?=.*[a-z])/.test(password.value)) {
                passwordRequirementsLowercase.classList.add('text-cyber');
            }
            
            if (/^(?=.*\d)/.test(password.value)) {
                passwordRequirementsNumber.classList.add('text-cyber');
            }
            
            if (/^(?=.*[@$!%*?&])/.test(password.value)) {
                passwordRequirementsSpecial.classList.add('text-cyber');
            }

            return false;
        } else {
            password.classList.add('is-valid');
            repeatPasswordWrapper.classList.remove('d-none');

            passwordRequirementsMinLength.classList.add('text-cyber');
            passwordRequirementsUppercase.classList.add('text-cyber');
            passwordRequirementsLowercase.classList.add('text-cyber');
            passwordRequirementsNumber.classList.add('text-cyber');
            passwordRequirementsSpecial.classList.add('text-cyber');
        }
        
        if (password.value !== repeatPassword.value) {
            repeatPassword.classList.add('is-invalid');
            passwordRequirements.classList.remove('d-none');
            return false;
        } else {
            repeatPassword.classList.add('is-valid');
            passwordRequirements.classList.add('d-none');
        }
        
        createAlsoCQAIGatewayAccountClaim.classList.remove('d-none');
        
        button.disabled = false;
        button.scrollIntoView({ behavior: 'smooth' });

        return true;
    }

    validateForm();

    const username = form.querySelector('input[name="registration[username]"]');
    const email = form.querySelector('input[name="registration[email]"]');
    const password = form.querySelector('input[name="registration[password][first]"]');
    const repeatPassword = form.querySelector('input[name="registration[password][second]"]');
    
    username.addEventListener('input', validateForm);
    email.addEventListener('input', validateForm);
    password.addEventListener('input', validateForm);
    repeatPassword.addEventListener('input', validateForm);
});
