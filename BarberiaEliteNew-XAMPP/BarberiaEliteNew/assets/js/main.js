const modalTriggers = new WeakMap();

window.openModal = function (id) {
    const overlay = document.getElementById(id);
    if (!overlay || overlay.classList.contains('open')) return;
    const panel = overlay.querySelector('.modal-box');
    if (!panel) return;

    modalTriggers.set(overlay, document.activeElement);
    overlay.classList.add('open');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    const heading = panel.querySelector('h2, h3');
    if (heading) {
        heading.id ||= id + '-title';
        overlay.setAttribute('aria-labelledby', heading.id);
    }
    panel.tabIndex = -1;
    panel.focus();
};

window.closeModal = function (id) {
    const overlay = document.getElementById(id);
    if (!overlay || !overlay.classList.contains('open')) return;
    overlay.classList.remove('open');
    modalTriggers.get(overlay)?.focus();
};

document.addEventListener('click', (event) => {
    if (event.target.matches('.modal-overlay.open')) {
        window.closeModal(event.target.id);
    }
});

document.addEventListener('keydown', (event) => {
    const overlay = document.querySelector('.modal-overlay.open');
    if (!overlay) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        window.closeModal(overlay.id);
    }
    if (event.key === 'Tab') {
        const controls = [...overlay.querySelectorAll('button, a[href], input, select, textarea, [tabindex="0"]')]
            .filter((element) => !element.disabled && element.tabIndex >= 0 && element.getClientRects().length);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (!first) {
            event.preventDefault();
        } else if (event.shiftKey && (document.activeElement === first || !controls.includes(document.activeElement))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !controls.includes(document.activeElement))) {
            event.preventDefault();
            first.focus();
        }
    }
});

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Nascondi' : 'Mostra';
        button.setAttribute('aria-pressed', String(show));
    });
});

document.querySelector('[data-focus-alert]')?.focus();
