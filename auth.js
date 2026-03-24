/**
 * auth.js — Client-side auth module for music.jjjp.ca (GitHub Pages)
 *
 * Handles:
 *   1. Capturing ?token= from the URL after redirect from jjjp.ca/auth
 *   2. Storing the token in localStorage
 *   3. Providing the login redirect URL
 *   4. Checking auth state
 *   5. Fetching user profile via the whoami endpoint
 *
 * Usage:
 *   import { auth } from './auth.js';
 *
 *   // On page load — capture token if returning from auth
 *   auth.handleCallback();
 *
 *   // Check if authenticated
 *   if (auth.isAuthenticated()) {
 *       const user = await auth.whoami();
 *       console.log('Hello', user.given_name);
 *   }
 *
 *   // Trigger login
 *   document.getElementById('login-btn').onclick = () => auth.login();
 *
 *   // Get token for API calls
 *   fetch(API_URL + '?action=library', {
 *       headers: { 'X-API-Key': auth.getToken() }
 *   });
 *
 *   // Logout (clears local token only)
 *   auth.logout();
 */

const AUTH_CONFIG = {
    authUrl:    'https://jjjp.ca/auth/app_token.php',
    apiUrl:     'https://jjjp.ca/music/api.php',
    app:        'music',
    storageKey: 'jjjp_music_token',
    userKey:    'jjjp_music_user',
};

const auth = {

    /**
     * Call on every page load. If ?token= is in the URL, store it
     * and clean up the URL so the token isn't sitting in the address bar.
     * Returns true if a token was captured.
     */
    handleCallback() {
        const params = new URLSearchParams(window.location.search);
        const token = params.get('token');

        if (token) {
            localStorage.setItem(AUTH_CONFIG.storageKey, token);

            // Remove token from URL without a page reload
            params.delete('token');
            const clean = params.toString();
            const newUrl = window.location.pathname + (clean ? '?' + clean : '') + window.location.hash;
            window.history.replaceState({}, '', newUrl);

            return true;
        }
        return false;
    },

    /**
     * Redirect user to jjjp.ca/auth to authenticate.
     * After login they'll be redirected back here with ?token=.
     */
    login() {
        const redirectUrl = window.location.origin + window.location.pathname;
        const authUrl = AUTH_CONFIG.authUrl
            + '?app=' + encodeURIComponent(AUTH_CONFIG.app)
            + '&redirect=' + encodeURIComponent(redirectUrl);
        window.location.href = authUrl;
    },

    /**
     * Check if a token is stored locally.
     */
    isAuthenticated() {
        return !!localStorage.getItem(AUTH_CONFIG.storageKey);
    },

    /**
     * Get the stored token for use in API requests.
     */
    getToken() {
        return localStorage.getItem(AUTH_CONFIG.storageKey) || '';
    },

    /**
     * Fetch the current user's profile from the API.
     * Returns { authenticated, user_id, name, given_name, picture } or null.
     */
    async whoami() {
        const token = this.getToken();
        if (!token) return null;

        // Check local cache first
        const cached = localStorage.getItem(AUTH_CONFIG.userKey);
        if (cached) {
            try {
                const parsed = JSON.parse(cached);
                // Cache for 1 hour
                if (parsed._ts && Date.now() - parsed._ts < 3600000) {
                    return parsed;
                }
            } catch (e) { /* ignore bad cache */ }
        }

        try {
            const res = await fetch(AUTH_CONFIG.apiUrl + '?action=whoami', {
                headers: { 'X-API-Key': token },
            });

            if (!res.ok) {
                // Token might be invalid/revoked
                if (res.status === 401) {
                    this.logout();
                }
                return null;
            }

            const data = await res.json();
            // Cache it
            data._ts = Date.now();
            localStorage.setItem(AUTH_CONFIG.userKey, JSON.stringify(data));
            return data;
        } catch (e) {
            console.error('whoami failed:', e);
            return null;
        }
    },

    /**
     * Clear the local token and cached user info.
     * Does NOT revoke the token server-side.
     */
    logout() {
        localStorage.removeItem(AUTH_CONFIG.storageKey);
        localStorage.removeItem(AUTH_CONFIG.userKey);
    },
};
