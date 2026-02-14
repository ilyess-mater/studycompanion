import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'studycompanion_theme';

export default class extends Controller {
    connect() {
        this.boundStorage = this.handleStorage.bind(this);
        window.addEventListener('storage', this.boundStorage);

        const theme = this.resolveTheme();
        this.applyTheme(theme);
    }

    disconnect() {
        window.removeEventListener('storage', this.boundStorage);
    }

    toggle() {
        const currentTheme = this.resolveTheme();
        const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
        this.persistAndApply(nextTheme);
    }

    setLight(event) {
        if (event) {
            event.preventDefault();
        }
        this.persistAndApply('light');
    }

    setDark(event) {
        if (event) {
            event.preventDefault();
        }
        this.persistAndApply('dark');
    }

    applyTheme(theme) {
        document.documentElement.dataset.theme = theme;

        document.querySelectorAll('[data-theme-option]').forEach((input) => {
            const option = input;
            if (option instanceof HTMLInputElement) {
                option.checked = option.value === theme;
            }
        });

        this.element.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    }

    persistAndApply(theme) {
        window.localStorage.setItem(STORAGE_KEY, theme);
        this.applyTheme(theme);
    }

    resolveTheme() {
        const storedTheme = window.localStorage.getItem(STORAGE_KEY);
        if (storedTheme === 'dark' || storedTheme === 'light') {
            return storedTheme;
        }

        const preferredDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

        return preferredDark ? 'dark' : 'light';
    }

    handleStorage(event) {
        if (event.key !== STORAGE_KEY) {
            return;
        }

        this.applyTheme(this.resolveTheme());
    }
}
