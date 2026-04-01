import './bootstrap'

// Dark mode toggle
document.addEventListener('DOMContentLoaded', function () {
    const darkModeToggle = document.getElementById('darkModeToggle')

    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', function () {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark')
                localStorage.theme = 'light'
            } else {
                document.documentElement.classList.add('dark')
                localStorage.theme = 'dark'
            }
        })
    }
})
