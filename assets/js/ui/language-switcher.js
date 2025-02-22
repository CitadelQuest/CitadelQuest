// Language switcher functionality
export function initLanguageSwitcher() {
    const languageLinks = document.querySelectorAll('.language-switch');
    
    languageLinks.forEach(link => {
        link.addEventListener('click', async (e) => {
            e.preventDefault();
            
            // Remove active class from all links
            languageLinks.forEach(l => l.classList.remove('active'));
            // Add active class to clicked link
            link.classList.add('active');
            
            const locale = link.dataset.locale;
            
            try {
                // Update server-side session and get cookie in response
                const response = await fetch(`/language/${locale}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                    },
                    credentials: 'same-origin' // Important for cookie handling
                });
                
                if (response.ok) {
                    // Reload the page to apply new language
                    window.location.reload();
                } else {
                    console.error('Failed to switch language: Server returned error');
                    // Revert active state on error
                    link.classList.remove('active');
                }
            } catch (error) {
                console.error('Failed to switch language:', error);
                // Revert active state on error
                link.classList.remove('active');
            }
        });
    });
}
