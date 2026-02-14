import { Controller } from '@hotwired/stimulus';

const SIDEBAR_MODE_KEY = 'studycompanion_sidebar_mode';
const SIDEBAR_MODE_FIXED = 'fixed';
const SIDEBAR_MODE_HOVER = 'hover';
const MOBILE_BREAKPOINT = 980;

export default class extends Controller {
    connect() {
        this.mode = this.resolveMode();
        this.hideTimer = null;

        this.boundResize = this.handleResize.bind(this);
        this.boundStorage = this.handleStorage.bind(this);
        this.boundKeyup = this.handleKeyup.bind(this);

        window.addEventListener('resize', this.boundResize);
        window.addEventListener('storage', this.boundStorage);
        document.addEventListener('keyup', this.boundKeyup);

        this.applyMode(this.mode, false);
    }

    disconnect() {
        window.removeEventListener('resize', this.boundResize);
        window.removeEventListener('storage', this.boundStorage);
        document.removeEventListener('keyup', this.boundKeyup);
        this.clearHideTimer();
    }

    openSidebar(event) {
        if (event) {
            event.preventDefault();
        }

        this.clearHideTimer();
        this.setSidebarState(true);
    }

    closeSidebar(event) {
        if (event) {
            event.preventDefault();
        }

        if (this.mode === SIDEBAR_MODE_FIXED && !this.isMobile()) {
            return;
        }

        this.clearHideTimer();
        this.setSidebarState(false);
    }

    setHoverMode(event) {
        if (event && event.target instanceof HTMLInputElement && !event.target.checked) {
            return;
        }

        this.applyMode(SIDEBAR_MODE_HOVER);
    }

    setFixedMode(event) {
        if (event && event.target instanceof HTMLInputElement && !event.target.checked) {
            return;
        }

        this.applyMode(SIDEBAR_MODE_FIXED);
    }

    edgeEnter() {
        if (this.mode !== SIDEBAR_MODE_HOVER || this.isMobile()) {
            return;
        }

        this.openSidebar();
    }

    sidebarEnter() {
        this.clearHideTimer();
    }

    sidebarLeave() {
        if (this.mode !== SIDEBAR_MODE_HOVER || this.isMobile()) {
            return;
        }

        this.clearHideTimer();
        this.hideTimer = window.setTimeout(() => {
            this.setSidebarState(false);
        }, 160);
    }

    handleResize() {
        this.applyMode(this.mode, false);
    }

    handleStorage(event) {
        if (event.key !== SIDEBAR_MODE_KEY) {
            return;
        }

        this.applyMode(this.resolveMode(), false);
    }

    handleKeyup(event) {
        if (event.key === 'Escape' && this.isOpen()) {
            this.closeSidebar();
        }
    }

    applyMode(mode, persist = true) {
        this.mode = mode === SIDEBAR_MODE_HOVER ? SIDEBAR_MODE_HOVER : SIDEBAR_MODE_FIXED;

        if (persist) {
            window.localStorage.setItem(SIDEBAR_MODE_KEY, this.mode);
        }

        this.element.classList.toggle('sidebar-mode-hover', this.mode === SIDEBAR_MODE_HOVER);
        this.element.classList.toggle('sidebar-mode-fixed', this.mode === SIDEBAR_MODE_FIXED);

        if (this.isMobile()) {
            this.setSidebarState(false);
        } else {
            this.setSidebarState(this.mode === SIDEBAR_MODE_FIXED);
        }

        this.syncModeInputs();
    }

    setSidebarState(open) {
        this.element.classList.toggle('is-sidebar-open', open);
        this.element.classList.toggle('is-sidebar-closed', !open);
        this.element.setAttribute('data-sidebar-state', open ? 'open' : 'closed');
    }

    isOpen() {
        return this.element.classList.contains('is-sidebar-open');
    }

    resolveMode() {
        const stored = window.localStorage.getItem(SIDEBAR_MODE_KEY);

        return stored === SIDEBAR_MODE_HOVER ? SIDEBAR_MODE_HOVER : SIDEBAR_MODE_FIXED;
    }

    syncModeInputs() {
        document.querySelectorAll('[data-sidebar-mode-option]').forEach((input) => {
            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            input.checked = input.value === this.mode;
        });
    }

    isMobile() {
        return window.innerWidth <= MOBILE_BREAKPOINT;
    }

    clearHideTimer() {
        if (this.hideTimer !== null) {
            window.clearTimeout(this.hideTimer);
            this.hideTimer = null;
        }
    }
}
