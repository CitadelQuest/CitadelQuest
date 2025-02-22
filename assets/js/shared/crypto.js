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
     * Store encrypted private key in IndexedDB
     * @param {string} username
     * @returns {Promise<void>}
     */
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
            spinner.classList.remove('d-none');
            statusText.classList.remove('d-none');
            buttonText.textContent = Translator.trans('auth.key_generation.generating_keys');

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
    }
});
