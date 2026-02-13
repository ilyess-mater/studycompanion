import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['timer', 'form'];
    static values = {
        duration: Number,
        focusSessionId: Number,
        violationUrl: String,
    };

    connect() {
        this.remainingSeconds = this.durationValue || 600;
        this.startedAt = Date.now();
        this.boundVisibility = this.handleVisibility.bind(this);
        this.boundBlur = this.handleBlur.bind(this);
        this.boundKeydown = this.handleKeydown.bind(this);
        this.boundSubmit = this.handleSubmit.bind(this);

        document.addEventListener('visibilitychange', this.boundVisibility);
        window.addEventListener('blur', this.boundBlur);
        document.addEventListener('keydown', this.boundKeydown);

        if (this.hasFormTarget) {
            this.formTarget.addEventListener('submit', this.boundSubmit);
        }

        this.tryEnterFullscreen();
        this.interval = window.setInterval(() => this.tick(), 1000);
        this.tick();
    }

    disconnect() {
        if (this.interval) {
            window.clearInterval(this.interval);
        }

        document.removeEventListener('visibilitychange', this.boundVisibility);
        window.removeEventListener('blur', this.boundBlur);
        document.removeEventListener('keydown', this.boundKeydown);

        if (this.hasFormTarget) {
            this.formTarget.removeEventListener('submit', this.boundSubmit);
        }
    }

    tick() {
        this.remainingSeconds -= 1;
        if (this.hasTimerTarget) {
            this.timerTarget.textContent = this.formatDuration(this.remainingSeconds);
        }

        if (this.remainingSeconds <= 0) {
            this.recordViolation('VISIBILITY_CHANGE', 'Timer expired; quiz auto-submitted.', 2);
            if (this.hasFormTarget) {
                this.fillResponseTimes();
                this.formTarget.submit();
            }
            this.disconnect();
        }
    }

    handleVisibility() {
        if (document.visibilityState !== 'visible') {
            this.recordViolation('VISIBILITY_CHANGE', 'Tab or window left visible state.', 3);
        }
    }

    handleBlur() {
        this.recordViolation('WINDOW_BLUR', 'Window lost focus.', 2);
    }

    handleKeydown(event) {
        const blocked = (event.ctrlKey && ['t', 'w', 'n', 'r', 'l'].includes(event.key.toLowerCase())) ||
            event.key === 'F12' ||
            (event.altKey && event.key === 'Tab');

        if (blocked) {
            event.preventDefault();
            this.recordViolation('BLOCKED_SHORTCUT', `Blocked key combo: ${event.key}`, 4);
        }
    }

    handleSubmit() {
        this.fillResponseTimes();
    }

    fillResponseTimes() {
        const elapsedMs = Date.now() - this.startedAt;
        this.element.querySelectorAll('input[data-response-time]').forEach((input) => {
            if (!input.value) {
                input.value = String(elapsedMs);
            }
        });
    }

    async tryEnterFullscreen() {
        if (document.fullscreenElement) {
            return;
        }

        const element = document.documentElement;
        if (element.requestFullscreen) {
            try {
                await element.requestFullscreen();
            } catch (_e) {
                this.recordViolation('FULLSCREEN_EXIT', 'Fullscreen request denied.', 2);
            }
        }
    }

    recordViolation(type, details, severity) {
        if (!this.violationUrlValue) {
            return;
        }

        fetch(this.violationUrlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                type,
                details,
                severity,
                focusSessionId: this.focusSessionIdValue,
            }),
            credentials: 'same-origin',
        }).catch(() => {
            // Ignore network errors; the quiz must continue.
        });
    }

    formatDuration(seconds) {
        const safeSeconds = Math.max(0, seconds);
        const minutes = String(Math.floor(safeSeconds / 60)).padStart(2, '0');
        const remaining = String(safeSeconds % 60).padStart(2, '0');

        return `${minutes}:${remaining}`;
    }
}
