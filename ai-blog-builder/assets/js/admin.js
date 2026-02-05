(function () {
    function addButton() {
        if (!window.ABB || !ABB.addPostButton || !ABB.addPostButton.enabled) return;
        var target = document.querySelector('.wrap .page-title-action');
        if (!target || document.querySelector('.cbia-add-ai')) return;

        var a = document.createElement('a');
        a.className = 'page-title-action cbia-add-ai';
        a.href = ABB.addPostButton.url;
        a.textContent = ABB.addPostButton.label || 'Anadir entrada con IA';

        target.insertAdjacentElement('afterend', a);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addButton);
    } else {
        addButton();
    }
})();