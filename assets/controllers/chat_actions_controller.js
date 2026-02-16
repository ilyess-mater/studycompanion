import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    connect() {
        this.boundOutsideClick = this.handleOutsideClick.bind(this);
        this.boundEscapeKey = this.handleEscapeKey.bind(this);
        this.closeAllMenus();
        this.closeAllEditForms();
        document.addEventListener("click", this.boundOutsideClick);
        document.addEventListener("keydown", this.boundEscapeKey);
    }

    disconnect() {
        document.removeEventListener("click", this.boundOutsideClick);
        document.removeEventListener("keydown", this.boundEscapeKey);
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

        this.closeAllEditForms();
        this.closeAllMenus();

        if (shouldOpen) {
            menu.hidden = false;
        }
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

        this.closeAllEditForms();
        if (form.hidden) {
            form.hidden = false;
        }
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
            this.closeAllEditForms();
            return;
        }

        const withinMenu =
            target instanceof Element &&
            target.closest("[data-chat-actions-menu]");
        const withinToggle =
            target instanceof Element && target.closest(".chat-actions-btn");
        const withinEditForm =
            target instanceof Element &&
            target.closest("[data-chat-actions-edit-form]");
        if (!withinMenu && !withinToggle) {
            this.closeAllMenus();
            if (!withinEditForm) {
                this.closeAllEditForms();
            }
        }
    }

    handleEscapeKey(event) {
        if (event.key !== "Escape") {
            return;
        }

        this.closeAllMenus();
        this.closeAllEditForms();
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

    closeAllEditForms() {
        this.element
            .querySelectorAll("[data-chat-actions-edit-form]")
            .forEach((form) => {
                if (form instanceof HTMLElement) {
                    form.hidden = true;
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
