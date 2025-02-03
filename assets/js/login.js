/**
 * CitadelQuest Login Handler
 * Handles decryption and restoration of private key during login
 */

document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login_form');
    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            try {
                const username = document.getElementById('username').value;
                const password = document.getElementById('password').value;

                // Get user's encrypted private key and salt from server
                const response = await fetch('/api/keys', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ username })
                });

                if (!response.ok) {
                    throw new Error('Failed to fetch keys');
                }

                const { encryptedPrivateKey, keySalt } = await response.json();

                // Decrypt private key
                await citadelCrypto.decryptPrivateKey(encryptedPrivateKey, keySalt, password);

                // Store decrypted private key for the session
                await citadelCrypto.storePrivateKey(username);

                // Continue with login
                loginForm.submit();
            } catch (error) {
                console.error('Error during login:', error);
                alert('Login failed. Please check your credentials and try again.');
            }
        });
    }
});
