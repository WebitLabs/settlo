import axios from 'axios';

/**
 * The bootstrap module is also evaluated by the Inertia SSR runtime, where no
 * browser globals exist — only wire axios up when running in a browser.
 */
if (typeof window !== 'undefined') {
    window.axios = axios;
    window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
}
