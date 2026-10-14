document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));
            if (!input) {
                return;
            }

            var willShow = input.type === 'password';
            input.type = willShow ? 'text' : 'password';
            button.setAttribute('aria-pressed', willShow ? 'true' : 'false');
            button.setAttribute('aria-label', willShow ? 'Hide password' : 'Show password');

            var icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !willShow);
                icon.classList.toggle('bi-eye-slash', willShow);
            }
        });
    });
});
