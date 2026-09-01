/**
 * DZ Carrier Manager — Carrier Account JS
 * Handles connection testing and copy webhook URL actions.
 */
document.addEventListener('DOMContentLoaded', function () {
    // ── Copy Webhook URL ─────────────────────────────────────────
    const copyBtn = document.getElementById('dzcm-copy-webhook-btn');
    const webhookInput = document.getElementById('dzcm-webhook-url');

    if (copyBtn && webhookInput) {
        copyBtn.addEventListener('click', function () {
            webhookInput.select();
            webhookInput.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(webhookInput.value).then(function () {
                const originalHtml = copyBtn.innerHTML;
                copyBtn.classList.remove('btn-outline-secondary');
                copyBtn.classList.add('btn-success');
                copyBtn.innerHTML = '<i class="material-icons" style="font-size:16px">check</i> Copied!';
                setTimeout(function () {
                    copyBtn.classList.remove('btn-success');
                    copyBtn.classList.add('btn-outline-secondary');
                    copyBtn.innerHTML = originalHtml;
                }, 2500);
            }).catch(function () {
                document.execCommand('copy');
            });
        });
    }

    // ── Test API Connection ──────────────────────────────────────
    const testBtn = document.getElementById('dzcm-btn-test-connection');
    const resultBox = document.getElementById('dzcm-test-result');

    if (testBtn && resultBox) {
        testBtn.addEventListener('click', function () {
            const testUrl = testBtn.getAttribute('data-test-url');
            const apiId = document.getElementById('api_id') ? document.getElementById('api_id').value : '';
            const apiToken = document.getElementById('api_token') ? document.getElementById('api_token').value : '';
            const baseUrl = document.getElementById('base_url') ? document.getElementById('base_url').value : '';

            if (!apiId || !apiToken) {
                resultBox.className = 'alert alert-warning mt-3';
                resultBox.textContent = 'Please enter both API ID and API Token before testing.';
                resultBox.classList.remove('d-none');
                return;
            }

            testBtn.disabled = true;
            const originalBtnHtml = testBtn.innerHTML;
            testBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Testing...';
            resultBox.classList.add('d-none');

            const formData = new FormData();
            formData.append('api_id', apiId);
            formData.append('api_token', apiToken);
            if (baseUrl) {
                formData.append('base_url', baseUrl);
            }

            fetch(testUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                testBtn.disabled = false;
                testBtn.innerHTML = originalBtnHtml;

                if (data.success) {
                    resultBox.className = 'alert alert-success mt-3';
                    resultBox.innerHTML = '<i class="material-icons" style="vertical-align:middle;font-size:18px">check_circle</i> ' + data.message;
                } else {
                    resultBox.className = 'alert alert-danger mt-3';
                    resultBox.innerHTML = '<i class="material-icons" style="vertical-align:middle;font-size:18px">error</i> ' + data.message;
                }
                resultBox.classList.remove('d-none');
            })
            .catch(function (error) {
                testBtn.disabled = false;
                testBtn.innerHTML = originalBtnHtml;
                resultBox.className = 'alert alert-danger mt-3';
                resultBox.innerHTML = '<i class="material-icons" style="vertical-align:middle;font-size:18px">error</i> An unexpected error occurred: ' + error.message;
                resultBox.classList.remove('d-none');
            });
        });
    }
});
