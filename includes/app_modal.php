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
        border: 0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 18px 45px rgba(5, 28, 60, 0.28);
    }
    #appSystemModal .app-modal-header {
        background: #051c3c;
        color: #ffffff;
        padding: 16px 20px;
        border-bottom: 0;
    }
    #appSystemModal .app-modal-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    #appSystemModalBody {
        font-size: 0.96rem;
        line-height: 1.55;
        color: #1e293b;
        white-space: pre-line;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
</style>

<div class="modal fade" id="appSystemModal" tabindex="-1" aria-labelledby="appSystemModalTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 460px; width: calc(100% - 1.5rem); margin: 0.75rem auto;">
        <div class="modal-content">
            <div class="modal-header app-modal-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <i class="bi bi-shield-check text-warning fs-5 flex-shrink-0" id="appSystemModalHeaderIcon"></i>
                    <h6 class="modal-title fw-bold mb-0 text-truncate" id="appSystemModalTitle">Notice</h6>
                </div>
                <button type="button" class="btn-close btn-close-white flex-shrink-0" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-white">
                <div class="d-flex align-items-start gap-3">
                    <div id="appSystemModalIconWrap" class="app-modal-icon bg-primary-subtle text-primary">
                        <i class="bi bi-info-circle-fill" id="appSystemModalBodyIcon"></i>
                    </div>
                    <div id="appSystemModalBody" class="flex-grow-1 pt-1"></div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top py-2 px-3 d-flex justify-content-end gap-2" id="appSystemModalFooter">
                <button type="button" class="btn btn-outline-secondary btn-sm px-3 d-none" id="appSystemModalCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm px-4 fw-semibold" id="appSystemModalConfirmBtn">OK</button>
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
    const headerIconEl = document.getElementById('appSystemModalHeaderIcon');
    const iconWrapEl = document.getElementById('appSystemModalIconWrap');
    const bodyIconEl = document.getElementById('appSystemModalBodyIcon');
    const bodyEl = document.getElementById('appSystemModalBody');
    const cancelBtn = document.getElementById('appSystemModalCancelBtn');
    const confirmBtn = document.getElementById('appSystemModalConfirmBtn');

    let pendingConfirmCallback = null;
    let pendingCloseCallback = null;
    let confirmClicked = false;

    const TYPE_THEMES = {
        info: {
            title: 'Notice',
            headerIcon: 'bi-info-circle-fill text-info',
            wrapClass: 'app-modal-icon bg-primary-subtle text-primary',
            bodyIcon: 'bi-info-circle-fill',
            btnClass: 'btn btn-primary btn-sm px-4 fw-semibold'
        },
        success: {
            title: 'Success',
            headerIcon: 'bi-check-circle-fill text-success',
            wrapClass: 'app-modal-icon bg-success-subtle text-success',
            bodyIcon: 'bi-check-circle-fill',
            btnClass: 'btn btn-success btn-sm px-4 fw-semibold'
        },
        warning: {
            title: 'Notice',
            headerIcon: 'bi-exclamation-triangle-fill text-warning',
            wrapClass: 'app-modal-icon bg-warning-subtle text-warning-emphasis',
            bodyIcon: 'bi-exclamation-triangle-fill',
            btnClass: 'btn btn-warning btn-sm px-4 fw-semibold text-dark'
        },
        danger: {
            title: 'Action Blocked',
            headerIcon: 'bi-x-octagon-fill text-danger',
            wrapClass: 'app-modal-icon bg-danger-subtle text-danger',
            bodyIcon: 'bi-x-octagon-fill',
            btnClass: 'btn btn-danger btn-sm px-4 fw-semibold'
        },
        confirm: {
            title: 'Confirm Action',
            headerIcon: 'bi-question-circle-fill text-warning',
            wrapClass: 'app-modal-icon bg-primary-subtle text-primary',
            bodyIcon: 'bi-question-circle-fill',
            btnClass: 'btn btn-primary btn-sm px-4 fw-semibold'
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
        headerIconEl.className = 'bi ' + theme.headerIcon + ' fs-5 flex-shrink-0';
        iconWrapEl.className = theme.wrapClass;
        bodyIconEl.className = 'bi ' + theme.bodyIcon;
        bodyEl.textContent = String(message == null ? '' : message);

        if (mode === 'confirm') {
            cancelBtn.classList.remove('d-none');
            cancelBtn.textContent = opts.cancelText || 'Cancel';
            confirmBtn.textContent = opts.confirmText || 'Confirm';
            confirmBtn.className = opts.variant === 'danger'
                ? 'btn btn-danger btn-sm px-4 fw-semibold'
                : (opts.variant === 'success'
                    ? 'btn btn-success btn-sm px-4 fw-semibold'
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
