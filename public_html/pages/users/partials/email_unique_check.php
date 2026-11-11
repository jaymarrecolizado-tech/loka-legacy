<?php
/** Live uniqueness check for individual (login) email only. */
$excludeId = (int) ($emailCheckExcludeId ?? 0);
?>
<script>
(function () {
    var input = document.getElementById('individualEmail');
    var hint = document.getElementById('individualEmailHint');
    if (!input || !hint) return;
    var timer = null;
    var exclude = <?= $excludeId ?>;
    var urlBase = <?= json_encode(rtrim((string) APP_URL, '/') . '/?page=api&action=check_email') ?>;

    function check() {
        var email = (input.value || '').trim();
        hint.textContent = '';
        hint.className = 'form-text';
        input.classList.remove('is-invalid', 'is-valid');
        if (!email) return;
        var url = urlBase + '&email=' + encodeURIComponent(email);
        if (exclude) url += '&exclude_id=' + exclude;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (body) {
                if (!body || !body.success) return;
                if (body.taken) {
                    hint.textContent = body.message || 'This individual email is already registered.';
                    hint.classList.add('text-danger');
                    input.classList.add('is-invalid');
                } else if (body.valid) {
                    hint.textContent = 'Individual email is available.';
                    hint.classList.add('text-success');
                    input.classList.add('is-valid');
                }
            })
            .catch(function () {});
    }

    input.addEventListener('blur', check);
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(check, 400);
    });
})();
</script>
