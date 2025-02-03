/**
 * CitadelQuest Cryptography Module
 * Handles client-side key generation and management
 */
class CitadelCrypto {
    constructor() {
        this.keyPair = null;
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
     * Store encrypted private key in IndexedDB
     * @param {string} username
     * @returns {Promise<void>}
     */
    async storePrivateKey(username) {
        if (!this.keyPair) {
            throw new Error("No key pair generated");
        }

        const exported = await window.crypto.subtle.exportKey(
            "pkcs8",
            this.keyPair.privateKey
        );

        const db = await this.openDatabase();
        const transaction = db.transaction(["keys"], "readwrite");
        const store = transaction.objectStore("keys");

        await store.put({
            username: username,
            privateKey: exported,
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

// Initialize crypto module
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
            spinner.classList.remove('d-none');
            statusText.classList.remove('d-none');
            buttonText.textContent = 'Generating Keys...';

            try {
                // Generate key pair
                await citadelCrypto.generateKeyPair();
                const publicKey = await citadelCrypto.exportPublicKey();

                // Add public key to form
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'registration[publicKey]';
                input.value = publicKey;
                form.appendChild(input);

                // Store private key in IndexedDB
                const usernameInput = form.querySelector('input[name="registration[username]"]');
                if (usernameInput) {
                    await citadelCrypto.storePrivateKey(usernameInput.value);
                }

                // Submit the form
                buttonText.textContent = 'Creating Your Citadel...';
                form.submit();
            } catch (error) {
                console.error('Error during key generation:', error);
                alert('Error during key generation. Please try again.');
                
                // Reset loading state
                button.disabled = false;
                spinner.classList.add('d-none');
                statusText.classList.add('d-none');
                buttonText.textContent = 'Create My Citadel';
            }
        });
    }
});
