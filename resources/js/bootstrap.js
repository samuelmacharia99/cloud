import axios from 'axios'

window.axios = axios

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

const csrfToken = () => document.head.querySelector('meta[name="csrf-token"]')?.content

const syncAxiosCsrf = () => {
    const token = csrfToken()
    if (token) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token
    }
}

syncAxiosCsrf()

// Mobile Safari (and others) restore POST forms from bfcache with a stale CSRF token → 419 Page Expired.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        window.location.reload()
    }
})

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        syncAxiosCsrf()
    }
})
