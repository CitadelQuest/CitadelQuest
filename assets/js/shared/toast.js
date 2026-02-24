import { Toast } from 'bootstrap';

export class ToastService {
    static instance = null;
    
    constructor() {
        if (ToastService.instance) {
            return ToastService.instance;
        }
        
        this.container = document.getElementById('toast-container');
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.className = 'toast-container position-fixed top-0 start-0 p-3';
            document.body.appendChild(this.container);
        }
        
        ToastService.instance = this;
    }
    
    show(message, type = 'info', title = null) {
        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center border-0 bg-${type}`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        
        let html = '';
        if (title) {
            html += `<div class="toast-header bg-${type} text-white border-0">
                <strong class="me-auto fs-5">${title}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>`;
        }
        
        html += `
            <div class="toast-body ${title ? '' : 'd-flex'}">
                <div class="me-auto fs-6 ${type=="warning" ? "text-dark" : ""}" style="word-break: break-all; overflow-wrap: break-word;">${message}</div>
                ${title ? '' : '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>'}
            </div>`;
            
        toastEl.innerHTML = html;
        this.container.appendChild(toastEl);
        
        const toast = new Toast(toastEl, {
            animation: true,
            autohide: true,            
            delay: 5000
        });
        
        toast.show();
        
        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
        });
    }
    
    success(message, title = null) {
        this.show(message, 'success', title);
    }
    
    error(message, title = null) {
        this.show(message, 'danger', title);
    }
    
    warning(message, title = null) {
        this.show(message, 'warning', title);
    }
    
    info(message, title = null) {
        this.show(message, 'info', title);
    }
}
