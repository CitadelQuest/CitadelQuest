import { Translator } from './translations';

/**
 * CitadelQuest Cryptography Module
 * Handles client-side key generation and management
 */
class CitadelCrypto {
    constructor() {
        this.keyPair = null;
        this.PBKDF2_ITERATIONS = 100000;
        this.SALT_LENGTH = 16;
    }

    /**
     * Convert ArrayBuffer to hex string
     */
    bufferToHex(buffer) {
        return Array.from(new Uint8Array(buffer))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    /**
     * Convert hex string to ArrayBuffer
     */
    hexToBuffer(hex) {
        const bytes = new Uint8Array(hex.length / 2);
        for (let i = 0; i < hex.length; i += 2) {
            bytes[i / 2] = parseInt(hex.slice(i, i + 2), 16);
        }
        return bytes.buffer;
    }

    /**
     * Derive encryption key from password
     */
    async deriveKeyFromPassword(password, salt = null) {
        const encoder = new TextEncoder();
        const passwordData = encoder.encode(password);
        const saltBuffer = salt ? this.hexToBuffer(salt) : crypto.getRandomValues(new Uint8Array(this.SALT_LENGTH));
        
        // Generate key from password
        const keyMaterial = await crypto.subtle.importKey(
            'raw',
            passwordData,
            { name: 'PBKDF2' },
            false,
            ['deriveBits']
        );

        const derivedBits = await crypto.subtle.deriveBits(
            {
                name: 'PBKDF2',
                salt: saltBuffer,
                iterations: this.PBKDF2_ITERATIONS,
                hash: 'SHA-256'
            },
            keyMaterial,
            256
        );

        // Convert to AES key
        const aesKey = await crypto.subtle.importKey(
            'raw',
            derivedBits,
            { name: 'AES-GCM' },
            false,
            ['encrypt', 'decrypt']
        );

        return {
            key: aesKey,
            salt: this.bufferToHex(saltBuffer)
        };
    }

    /**
     * Generate RSA-OAEP key pair
     * @returns {Promise<CryptoKeyPair>}
     */
    async generateKeyPair() {
        const keyPair = await window.crypto.subtle.generateKey(
            {
                name: "RSA-OAEP",
                modulusLength: 2048,
                publicExponent: new Uint8Array([1, 0, 1]),
                hash: "SHA-256",
            },
            true, // extractable
            ["encrypt", "decrypt"]
        );
        this.keyPair = keyPair;
        return keyPair;
    }

    /**
     * Export public key in PEM format
     * @returns {Promise<string>}
     */
    async exportPublicKey() {
        if (!this.keyPair) {
            throw new Error("No key pair generated");
        }

        const exported = await window.crypto.subtle.exportKey(
            "spki",
            this.keyPair.publicKey
        );

        const exportedAsBase64 = window.btoa(
            String.fromCharCode(...new Uint8Array(exported))
        );

        return `-----BEGIN PUBLIC KEY-----\n${exportedAsBase64}\n-----END PUBLIC KEY-----`;
    }

    /**
     * Encrypt private key with password
     */
    async encryptPrivateKey(password) {
        if (!this.keyPair) {
            throw new Error("No key pair generated");
        }

        // Export private key
        const privateKeyData = await window.crypto.subtle.exportKey(
            "pkcs8",
            this.keyPair.privateKey
        );

        // Generate encryption key from password
        const { key: encryptionKey, salt } = await this.deriveKeyFromPassword(password);

        // Generate IV for AES-GCM
        const iv = window.crypto.getRandomValues(new Uint8Array(12));

        // Encrypt the private key
        const encryptedData = await window.crypto.subtle.encrypt(
            {
                name: "AES-GCM",
                iv: iv
            },
            encryptionKey,
            privateKeyData
        );

        // Combine IV and encrypted data
        const combined = new Uint8Array(iv.length + encryptedData.byteLength);
        combined.set(iv);
        combined.set(new Uint8Array(encryptedData), iv.length);

        return {
            encryptedKey: this.bufferToHex(combined),
            salt: salt
        };
    }

    /**
     * Decrypt private key with password
     */
    async decryptPrivateKey(encryptedKeyHex, salt, password) {
        // Derive the same key from password
        const { key: decryptionKey } = await this.deriveKeyFromPassword(password, salt);

        // Convert hex to buffer
        const encryptedData = this.hexToBuffer(encryptedKeyHex);

        // Extract IV and encrypted private key
        const iv = encryptedData.slice(0, 12);
        const privateKeyData = encryptedData.slice(12);

        // Decrypt the private key
        const decryptedKeyData = await window.crypto.subtle.decrypt(
            {
                name: "AES-GCM",
                iv: iv
            },
            decryptionKey,
            privateKeyData
        );

        // Import the decrypted private key
        this.keyPair = {
            privateKey: await window.crypto.subtle.importKey(
                "pkcs8",
                decryptedKeyData,
                {
                    name: "RSA-OAEP",
                    hash: "SHA-256"
                },
                true,
                ["decrypt"]
            )
        };

        return this.keyPair.privateKey;
    }

    /**
     * Store private key in IndexedDB for the session
     */
    async storePrivateKey(username) {
        if (!this.keyPair) {
            throw new Error("No key pair generated");
        }

        const db = await this.openDatabase();
        const transaction = db.transaction(["keys"], "readwrite");
        const store = transaction.objectStore("keys");

        await store.put({
            username: username,
            privateKey: this.keyPair.privateKey,
            created: new Date(),
            expires: new Date(Date.now() + 24 * 60 * 60 * 1000) // 24 hours
        });
    }

    /**
     * Initialize IndexedDB for key storage
     * @returns {Promise<IDBDatabase>}
     */
    async openDatabase() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open("CitadelQuest", 1);

            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains("keys")) {
                    db.createObjectStore("keys", { keyPath: "username" });
                }
            };
        });
    }
}

// Create a singleton instance
const citadelCrypto = new CitadelCrypto();

// Handle registration form submission
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[name="registration"]');
    if (form) {
        const button = document.getElementById('register-button');
        const spinner = button.querySelector('.spinner-border');
        const buttonText = button.querySelector('.button-text');
        const statusText = document.querySelector('.key-generation-status');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Show loading state
            button.disabled = true;
            //spinner.classList.remove('d-none');
            //statusText.classList.remove('d-none');
            //buttonText.textContent = Translator.trans('auth.key_generation.generating_keys');

            try {
                // Get form data
                const username = form.querySelector('input[name="registration[username]"]').value;
                const password = form.querySelector('input[name="registration[password][first]"]').value;

                // Generate key pair
                await citadelCrypto.generateKeyPair();
                const publicKey = await citadelCrypto.exportPublicKey();

                // Encrypt private key with password
                const { encryptedKey, salt } = await citadelCrypto.encryptPrivateKey(password);

                // Store private key for this session
                await citadelCrypto.storePrivateKey(username);

                // Set keys in form
                const publicKeyInput = form.querySelector('input[name="registration[publicKey]"]');
                const encryptedKeyInput = form.querySelector('input[name="registration[encryptedPrivateKey]"]');
                const saltInput = form.querySelector('input[name="registration[keySalt]"]');

                publicKeyInput.value = publicKey;
                encryptedKeyInput.value = encryptedKey;
                saltInput.value = salt;

                // Submit the form
                buttonText.textContent = Translator.trans('auth.key_generation.creating_citadel');
                form.submit();
            } catch (error) {
                console.error('Error during key generation:', error);
                alert(Translator.trans('auth.key_generation.error'));
                
                // Reset loading state
                button.disabled = false;
                spinner.classList.add('d-none');
                statusText.classList.add('d-none');
                buttonText.textContent = Translator.trans('auth.key_generation.button_text');
            }
        });
        
        function validateForm() {
            const button = document.getElementById('register-button');
            button.disabled = true;
            
            const form = document.querySelector('form[name="registration"]');
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
            
            // fix << Letters, numbers and underscores only
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
                } else {
                    //passwordRequirementsMinLength.classList.add('text-warning');
                }
                
                if (/^(?=.*[A-Z])/.test(password.value)) {
                    passwordRequirementsUppercase.classList.add('text-cyber');
                } else {
                    //passwordRequirementsUppercase.classList.add('text-warning');
                }
                
                if (/^(?=.*[a-z])/.test(password.value)) {
                    passwordRequirementsLowercase.classList.add('text-cyber');
                } else {
                    //passwordRequirementsLowercase.classList.add('text-warning');
                }
                
                if (/^(?=.*\d)/.test(password.value)) {
                    passwordRequirementsNumber.classList.add('text-cyber');
                } else {
                    //passwordRequirementsNumber.classList.add('text-warning');
                }
                
                if (/^(?=.*[@$!%*?&])/.test(password.value)) {
                    passwordRequirementsSpecial.classList.add('text-cyber');
                } else {
                    //passwordRequirementsSpecial.classList.add('text-warning');
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
    }
});
