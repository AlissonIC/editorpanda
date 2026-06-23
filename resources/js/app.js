import 'bootstrap';
import $ from 'jquery';
import axios from 'axios';

window.$ = window.jQuery = $;
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const token = document.querySelector('meta[name="csrf-token"]')?.content;
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}

import './lib/toast';
