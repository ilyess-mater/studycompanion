import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    press(event) {
        const control = event.target.closest('.liquid-control, button, .btn');
        if (!(control instanceof HTMLElement)) {
            return;
        }

        control.classList.add('liquid-control');

        const rect = control.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;

        control.style.setProperty('--press-x', `${x}px`);
        control.style.setProperty('--press-y', `${y}px`);
        control.classList.remove('is-pressed');

        // Trigger reflow so repeated clicks replay the animation.
        void control.offsetWidth;
        control.classList.add('is-pressed');

        window.setTimeout(() => {
            control.classList.remove('is-pressed');
        }, 320);
    }
}
