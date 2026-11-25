<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__appModalFlash = null;
if (!empty($_SESSION['app_modal']) && is_array($_SESSION['app_modal'])) {
    $__appModalFlash = $_SESSION['app_modal'];
    unset($_SESSION['app_modal']);
}
?>
<style>
    #appSystemModal {
        z-index: 2250 !important;
    }
    .app-modal-backdrop-top {
        z-index: 2240 !important;
    }
    #appSystemModal .modal-content {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #ffffff;
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.14);
    }
    #appSystemModal .app-modal-header {
        background: #ffffff;
        padding: 18px 22px 8px;
        border-bottom: 0;
    }
    #appSystemModal .app-modal-title {
        font-size: 1.02rem;
        font-weight: 600;
        color: #111827;
        margin: 0;
    }
    #appSystemModal .app-modal-body {
        padding: 6px 22px 20px;
        background: #ffffff;
    }
    #appSystemModalBody {
        font-size: 0.92rem;
        line-height: 1.55;
        color: #4b5563;
        white-space: pre-line;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    #appSystemModal .app-modal-footer {
        background: #ffffff;
        border-top: 1px solid #f1f5f9;
        padding: 12px 22px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }
    #appSystemModal .btn-app-navy {
        background: #051c3c;
        border-color: #051c3c;
        color: #ffffff;
    }
    #appSystemModal .btn-app-navy:hover {
        background: #0b2d5c;
        border-color: #0b2d5c;
        color: #ffffff;
    }
</style>

<div class="modal fade" id="appSystemModal" tabindex="-1" aria-labelledby="appSystemModalTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 410px; width: calc(100% - 1.5rem); margin: 0.75rem auto;">
        <div class="modal-content">
            <div class="modal-header app-modal-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <i class="bi bi-info-circle text-primary fs-5 flex-shrink-0" id="appSystemModalIcon"></i>
                    <h6 class="modal-title app-modal-title text-truncate" id="appSystemModalTitle">Notice</h6>
                </div>
                <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body app-modal-body">
                <div id="appSystemModalBody"></div>
            </div>
            <div class="modal-footer app-modal-footer" id="appSystemModalFooter">
                <button type="button" class="btn btn-outline-secondary btn-sm px-3 d-none" id="appSystemModalCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-app-navy btn-sm px-4 fw-medium" id="appSystemModalConfirmBtn">OK</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    if (window.__appSystemModalInitialized) return;
    window.__appSystemModalInitialized = true;

    const modalEl = document.getElementById('appSystemModal');
    if (!modalEl) return;

    const titleEl = document.getElementById('appSystemModalTitle');
    const iconEl = document.getElementById('appSystemModalIcon');
    const bodyEl = document.getElementById('appSystemModalBody');
    const cancelBtn = document.getElementById('appSystemModalCancelBtn');
    const confirmBtn = document.getElementById('appSystemModalConfirmBtn');

    let pendingConfirmCallback = null;
    let pendingCloseCallback = null;
    let confirmClicked = false;

    const TYPE_THEMES = {
        info: {
            title: 'Notice',
            icon: 'bi-info-circle text-primary',
            btnClass: 'btn btn-app-navy btn-sm px-4 fw-medium'
        },
        success: {
            title: 'Success',
            icon: 'bi-check-circle text-success',
            btnClass: 'btn btn-success btn-sm px-4 fw-medium'
        },
        warning: {
            title: 'Notice',
            icon: 'bi-exclamation-triangle text-warning',
            btnClass: 'btn btn-app-navy btn-sm px-4 fw-medium'
        },
        danger: {
            title: 'Action Blocked',
            icon: 'bi-exclamation-circle text-danger',
            btnClass: 'btn btn-danger btn-sm px-4 fw-medium'
        },
        confirm: {
            title: 'Confirm Action',
            icon: 'bi-question-circle text-secondary',
            btnClass: 'btn btn-app-navy btn-sm px-4 fw-medium'
        }
    };

    function getBsModal() {
        return bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    modalEl.addEventListener('shown.bs.modal', function () {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 0) {
            backdrops[backdrops.length - 1].classList.add('app-modal-backdrop-top');
        }
        confirmBtn.focus();
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        document.querySelectorAll('.app-modal-backdrop-top').forEach(function (bd) {
            bd.classList.remove('app-modal-backdrop-top');
        });
        if (document.querySelector('.modal.show')) {
            document.body.classList.add('modal-open');
        }
        if (!confirmClicked && typeof pendingCloseCallback === 'function') {
            const cb = pendingCloseCallback;
            pendingCloseCallback = null;
            pendingConfirmCallback = null;
            cb();
        } else {
            pendingCloseCallback = null;
            pendingConfirmCallback = null;
        }
        confirmClicked = false;
    });

    confirmBtn.addEventListener('click', function () {
        confirmClicked = true;
        const cb = pendingConfirmCallback;
        pendingConfirmCallback = null;
        pendingCloseCallback = null;
        getBsModal().hide();
        if (typeof cb === 'function') {
            cb();
        }
    });

    function configureModal(message, options) {
        const opts = options || {};
        const mode = opts.mode || 'alert';
        const typeKey = (opts.type === 'error' ? 'danger' : opts.type) || (mode === 'confirm' ? 'confirm' : 'info');
        const theme = TYPE_THEMES[typeKey] || TYPE_THEMES.info;

        titleEl.textContent = opts.title || theme.title;
        iconEl.className = 'bi ' + (opts.variant === 'danger' ? 'bi-exclamation-circle text-danger' : theme.icon) + ' fs-5 flex-shrink-0';
        bodyEl.textContent = String(message == null ? '' : message);

        if (mode === 'confirm') {
            cancelBtn.classList.remove('d-none');
            cancelBtn.textContent = opts.cancelText || 'Cancel';
            confirmBtn.textContent = opts.confirmText || 'Confirm';
            confirmBtn.className = opts.variant === 'danger'
                ? 'btn btn-danger btn-sm px-4 fw-medium'
                : (opts.variant === 'success'
                    ? 'btn btn-success btn-sm px-4 fw-medium'
                    : theme.btnClass);
        } else {
            cancelBtn.classList.add('d-none');
            confirmBtn.textContent = opts.buttonText || 'OK';
            confirmBtn.className = theme.btnClass;
        }

        confirmClicked = false;
    }

    window.showAppAlert = function (message, options) {
        const opts = options || {};
        configureModal(message, Object.assign({}, opts, { mode: 'alert' }));
        pendingConfirmCallback = typeof opts.onClose === 'function' ? opts.onClose : null;
        pendingCloseCallback = typeof opts.onClose === 'function' ? opts.onClose : null;
        getBsModal().show();
    };

    window.showAppConfirm = function (message, onConfirm, options) {
        const opts = options || {};
        configureModal(message, Object.assign({}, opts, { mode: 'confirm' }));
        pendingConfirmCallback = typeof onConfirm === 'function' ? onConfirm : null;
        pendingCloseCallback = typeof opts.onCancel === 'function' ? opts.onCancel : null;
        getBsModal().show();
    };

    window.appConfirmLink = function (event, linkEl, message, options) {
        if (event) event.preventDefault();
        const href = linkEl && linkEl.getAttribute ? linkEl.getAttribute('href') : null;
        window.showAppConfirm(message, function () {
            if (href) {
                window.location.href = href;
            }
        }, options);
        return false;
    };

    window.appConfirmForm = function (event, formEl, message, options) {
        if (formEl && formEl.dataset && formEl.dataset.appConfirmed === '1') {
            delete formEl.dataset.appConfirmed;
            return true;
        }
        if (event) event.preventDefault();
        const submitter = event && event.submitter ? event.submitter : null;
        window.showAppConfirm(message, function () {
            if (!formEl) return;
            formEl.dataset.appConfirmed = '1';
            if (typeof formEl.requestSubmit === 'function' && submitter) {
                formEl.requestSubmit(submitter);
            } else {
                formEl.submit();
            }
        }, options);
        return false;
    };

    window.appConfirmButtonSubmit = function (event, btnEl, message, options) {
        if (event) event.preventDefault();
        const formEl = btnEl ? btnEl.closest('form') : null;
        window.showAppConfirm(message, function () {
            if (!formEl) return;
            if (btnEl && btnEl.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = btnEl.name;
                hidden.value = btnEl.value || '';
                formEl.appendChild(hidden);
            }
            formEl.submit();
        }, options);
        return false;
    };

    <?php if ($__appModalFlash !== null): ?>
    document.addEventListener('DOMContentLoaded', function () {
        window.showAppAlert(
            <?php echo json_encode((string) ($__appModalFlash['message'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
            {
                title: <?php echo json_encode((string) ($__appModalFlash['title'] ?? 'Notice'), JSON_UNESCAPED_UNICODE); ?>,
                type: <?php echo json_encode((string) ($__appModalFlash['type'] ?? 'info'), JSON_UNESCAPED_UNICODE); ?>
            }
        );
    });
    <?php endif; ?>
})();
</script>
