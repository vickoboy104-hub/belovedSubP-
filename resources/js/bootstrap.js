import axios from 'axios';

// Alpine is imported and started by app.js, after every Alpine.data() mixin is
// registered. Starting it here would initialise the page before those exist.
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
