import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    connect() {
        this.boundOutsideClick = this.handleOutsideClick.bind(this);
        document.addEventListener("click", this.boundOutsideClick);
    }

    disconnect() {
        document.removeEventListener("click", this.boundOutsideClick);
    }

    toggleMenu(event) {
        event.preventDefault();
        event.stopPropagation();

        const trigger = event.currentTarget;
        if (!(trigger instanceof HTMLElement)) {
            return;
        }

        const commentId = trigger.dataset.commentId ?? "";
        if (commentId === "") {
            return;
        }

        const menu = this.findMenu(commentId);
        if (!(menu instanceof HTMLElement)) {
            return;
        }

        const shouldOpen = menu.hidden;
        this.closeAllMenus();
        menu.hidden = !shouldOpen;
    }

    toggleEdit(event) {
        event.preventDefault();
        const trigger = event.currentTarget;
        if (!(trigger instanceof HTMLElement)) {
            return;
        }

        const commentId = trigger.dataset.commentId ?? "";
        if (commentId === "") {
            return;
        }

        const form = this.findEditForm(commentId);
        if (!(form instanceof HTMLElement)) {
            return;
        }

        form.hidden = !form.hidden;
        this.closeAllMenus();
    }

    cancelEdit(event) {
        event.preventDefault();
        const trigger = event.currentTarget;
        if (!(trigger instanceof HTMLElement)) {
            return;
        }

        const commentId = trigger.dataset.commentId ?? "";
        if (commentId === "") {
            return;
        }

        const form = this.findEditForm(commentId);
        if (!(form instanceof HTMLElement)) {
            return;
        }

        form.hidden = true;
    }

    handleOutsideClick(event) {
        const target = event.target;
        if (!(target instanceof Node)) {
            return;
        }

        if (!this.element.contains(target)) {
            this.closeAllMenus();
            return;
        }

        const withinMenu =
            target instanceof Element &&
            target.closest("[data-chat-actions-menu]");
        const withinToggle =
            target instanceof Element && target.closest(".chat-actions-btn");
        if (!withinMenu && !withinToggle) {
            this.closeAllMenus();
        }
    }

    closeAllMenus() {
        this.element
            .querySelectorAll("[data-chat-actions-menu]")
            .forEach((menu) => {
                if (menu instanceof HTMLElement) {
                    menu.hidden = true;
                }
            });
    }

    findMenu(commentId) {
        return this.element.querySelector(
            `[data-chat-actions-menu][data-comment-id="${commentId}"]`,
        );
    }

    findEditForm(commentId) {
        return this.element.querySelector(
            `[data-chat-actions-edit-form][data-comment-id="${commentId}"]`,
        );
    }
}
