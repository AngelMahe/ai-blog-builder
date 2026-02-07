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

    function initPromptEditor() {
        var modal = document.getElementById('cbia-prompt-modal');
        if (!modal || !window.ABB) return;

        var postSelect = document.getElementById('cbia-prompt-post');
        var textarea = document.getElementById('cbia-prompt-text');
        var status = document.getElementById('cbia-prompt-status');
        var title = document.getElementById('cbia-prompt-title');
        var btnSave = document.getElementById('cbia-prompt-save');
        var btnSaveRegen = document.getElementById('cbia-prompt-save-regen');
        var btnClose = modal.querySelector('.cbia-modal-close');

        var current = { postId: 0, type: '', idx: 0 };

        function setStatus(msg, isOk) {
            if (!status) return;
            status.textContent = msg || '';
            status.className = 'cbia-modal-status' + (isOk ? ' is-ok' : ' is-error');
        }

        function openModal(postId, type, idx) {
            current.postId = postId;
            current.type = type;
            current.idx = idx;
            if (title) {
                var label = type === 'featured' ? 'Destacada' : ('Interna ' + idx);
                title.textContent = 'Editar prompt - ' + label;
            }
            if (textarea) textarea.value = '';
            setStatus('Cargando prompt...', true);
            modal.style.display = 'flex';

            var params = new URLSearchParams();
            params.append('action', 'cbia_get_img_prompt');
            params.append('_ajax_nonce', ABB.nonce);
            params.append('post_id', postId);
            params.append('type', type);
            params.append('idx', idx);

            fetch(ABB.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success && data.data) {
                    if (textarea) textarea.value = data.data.prompt || '';
                    setStatus(data.data.has_override ? 'Override actual cargado.' : 'Prompt base cargado.', true);
                } else {
                    setStatus('No se pudo cargar el prompt.', false);
                }
            })
            .catch(function () { setStatus('Error de red al cargar el prompt.', false); });
        }

        function savePrompt(regen) {
            var prompt = textarea ? textarea.value : '';
            if (!current.postId || !current.type) return;

            setStatus('Guardando...', true);

            var params = new URLSearchParams();
            params.append('action', 'cbia_save_img_prompt_override');
            params.append('_ajax_nonce', ABB.nonce);
            params.append('post_id', current.postId);
            params.append('type', current.type);
            params.append('idx', current.idx);
            params.append('prompt', prompt);

            fetch(ABB.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    setStatus((data && data.data && data.data.message) ? data.data.message : 'No se pudo guardar el override.', false);
                    return;
                }
                setStatus('Override guardado.', true);
                if (regen) {
                    regenImage();
                }
            })
            .catch(function () { setStatus('Error de red al guardar.', false); });
        }

        function regenImage() {
            setStatus('Regenerando imagen...', true);

            var params = new URLSearchParams();
            params.append('action', 'cbia_regen_image');
            params.append('_ajax_nonce', ABB.nonce);
            params.append('post_id', current.postId);
            params.append('type', current.type);
            params.append('idx', current.idx);

            fetch(ABB.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    setStatus('Imagen regenerada correctamente.', true);
                } else {
                    setStatus((data && data.data && data.data.message) ? data.data.message : 'No se pudo regenerar la imagen.', false);
                }
            })
            .catch(function () { setStatus('Error de red al regenerar.', false); });
        }

        document.querySelectorAll('.cbia-prompt-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!postSelect || !postSelect.value) {
                    alert('Selecciona un post primero.');
                    return;
                }
                openModal(postSelect.value, btn.getAttribute('data-type'), btn.getAttribute('data-idx'));
            });
        });

        if (btnSave) btnSave.addEventListener('click', function () { savePrompt(false); });
        if (btnSaveRegen) btnSaveRegen.addEventListener('click', function () { savePrompt(true); });
        if (btnClose) btnClose.addEventListener('click', function () { modal.style.display = 'none'; });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) modal.style.display = 'none';
        });
    }

    function initProviderSelect() {
        var select = document.querySelector('.abb-provider-select-input');
        if (!select) return;

        var logo = document.querySelector('.abb-provider-logo');
        var label = document.querySelector('.abb-provider-label');
        var trigger = document.querySelector('.abb-provider-trigger');
        var menu = document.querySelector('.abb-provider-menu');
        var options = document.querySelectorAll('.abb-provider-option');
        var models = document.querySelectorAll('.abb-provider-model');
        var keys = document.querySelectorAll('.abb-provider-key');
        var openaiModel = document.getElementById('abb-openai-model');
        var openaiModelHidden = document.getElementById('abb-provider-model-openai');
        var openaiKey = document.getElementById('abb-openai-key');
        var openaiKeyHidden = document.getElementById('abb-provider-key-openai');

        function update() {
            var opt = select.options[select.selectedIndex];
            var optLogo = opt ? opt.getAttribute('data-logo') : '';
            if (logo && optLogo) {
                logo.src = optLogo;
            }
            if (label && opt) {
                label.textContent = opt.textContent;
            }
            models.forEach(function (el) {
                var provider = el.getAttribute('data-provider') || 'openai';
                el.style.display = (provider === select.value) ? '' : 'none';
            });
            keys.forEach(function (el) {
                var provider = el.getAttribute('data-provider') || 'openai';
                el.style.display = (provider === select.value) ? '' : 'none';
            });
            options.forEach(function (btn) {
                var val = btn.getAttribute('data-value');
                btn.classList.toggle('is-active', val === select.value);
            });
            if (openaiModel && openaiModelHidden) {
                openaiModelHidden.value = openaiModel.value || '';
            }
            if (openaiKey && openaiKeyHidden) {
                openaiKeyHidden.value = openaiKey.value || '';
            }
        }

        function closeMenu() {
            if (menu) menu.classList.remove('is-open');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        }

        if (trigger && menu) {
            trigger.addEventListener('click', function () {
                var isOpen = menu.classList.contains('is-open');
                if (isOpen) {
                    closeMenu();
                } else {
                    menu.classList.add('is-open');
                    trigger.setAttribute('aria-expanded', 'true');
                }
            });
        }

        options.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var val = btn.getAttribute('data-value') || 'openai';
                select.value = val;
                update();
                closeMenu();
            });
        });

        document.addEventListener('click', function (e) {
            if (!menu || !trigger) return;
            if (menu.contains(e.target) || trigger.contains(e.target)) return;
            closeMenu();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMenu();
        });

        select.addEventListener('change', update);
        if (openaiModel) {
            openaiModel.addEventListener('change', update);
        }
        if (openaiKey) {
            openaiKey.addEventListener('input', update);
        }
        update();
    }

    function initUsageModelSync() {
        var btn = document.getElementById('cbia-sync-models-btn');
        if (!btn || !window.ABB) return;

        btn.addEventListener('click', function () {
            var provider = btn.getAttribute('data-provider') || '';
            btn.disabled = true;
            var oldText = btn.textContent;
            btn.textContent = 'Sincronizando...';

            var params = new URLSearchParams();
            params.append('action', 'cbia_sync_models');
            params.append('_ajax_nonce', ABB.nonce);
            params.append('provider', provider);

            fetch(ABB.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    btn.textContent = 'Sync OK (' + (data.data.count || 0) + ')';
                    var status = document.getElementById('cbia-sync-models-status');
                    if (status && data.data.meta && data.data.meta.ts) {
                        status.textContent = 'Ultima sync: ' + data.data.meta.ts;
                    }
                } else {
                    btn.textContent = 'Sync fallo';
                    var status = document.getElementById('cbia-sync-models-status');
                    if (status && data && data.data && data.data.result && data.data.result.error) {
                        status.textContent = 'Ultima sync: error (' + data.data.result.error + ')';
                    }
                }
                setTimeout(function () {
                    btn.disabled = false;
                    btn.textContent = oldText;
                }, 2000);
            })
            .catch(function () {
                btn.disabled = false;
                btn.textContent = oldText;
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            addButton();
            initProviderSelect();
            initPromptEditor();
            initUsageModelSync();
        });
    } else {
        addButton();
        initProviderSelect();
        initPromptEditor();
        initUsageModelSync();
    }
})();


