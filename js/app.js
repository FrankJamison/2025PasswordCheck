(() => {
    const form = document.getElementById('pwForm');
    const resultEl = document.getElementById('result');
    const buttonEl = document.getElementById('checkBtn');

    if (!form || !resultEl || !buttonEl) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(form);

        resultEl.classList.remove('is-ok', 'is-error', 'is-empty');
        resultEl.textContent = 'Checking…';
        buttonEl.disabled = true;

        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            const text = data && (data.result || data.error) ? (data.result || data.error) : 'Unexpected response.';
            resultEl.textContent = text;
            resultEl.classList.add(data && data.error ? 'is-error' : 'is-ok');
        } catch {
            resultEl.textContent = 'Network error. Please try again.';
            resultEl.classList.add('is-error');
        } finally {
            buttonEl.disabled = false;
        }
    });
})();