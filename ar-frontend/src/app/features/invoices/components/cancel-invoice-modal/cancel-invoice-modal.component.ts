import { Component, Input, Output, EventEmitter, signal, HostListener, ElementRef, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Invoice } from '../../../../core/models/ar.models';

@Component({
  selector: 'app-cancel-invoice-modal',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="v3-modal-backdrop" *ngIf="isOpen && invoice" (click)="onBackdropClick($event)">
      <div class="v3-modal" role="dialog" aria-modal="true" aria-labelledby="cancel-invoice-modal-title">
        <div class="v3-modal-header">
          <h4 id="cancel-invoice-modal-title" class="fw-bold mb-0 fs-5 text-danger">Batalkan Invoice {{ invoice.invoiceNumber }}</h4>
          <button type="button" class="btn-close" (click)="close.emit()" aria-label="Tutup dialog pembatalan"></button>
        </div>
        <div class="v3-modal-body">
          <div class="alert alert-warning small mb-3">
            <strong>Perhatian:</strong> Invoice yang dibatalkan bersifat final. Pastikan invoice belum memiliki pembayaran teralokasi.
          </div>
          <label for="cancel-invoice-reason" class="form-label small fw-semibold">Alasan Pembatalan <span class="text-danger">*</span></label>
          <textarea id="cancel-invoice-reason" class="form-control" rows="3"
            placeholder="Tuliskan alasan pembatalan..."
            [ngModel]="reason()" (ngModelChange)="reason.set($event)">
          </textarea>
        </div>
        <div class="v3-modal-footer">
          <button type="button" class="v3-btn-secondary" (click)="close.emit()">Batal</button>
          <button type="button" class="btn btn-danger btn-sm fw-semibold" [disabled]="!reason().trim()"
            (click)="onConfirm()">
            Batalkan Invoice
          </button>
        </div>
      </div>
    </div>
  `
})
export class CancelInvoiceModalComponent {
  private readonly elementRef = inject(ElementRef);

  @Input() isOpen = false;
  @Input() invoice: Invoice | null = null;

  @Output() close = new EventEmitter<void>();
  @Output() confirm = new EventEmitter<{ invoiceId: string; reason: string }>();

  reason = signal<string>('');

  @HostListener('document:keydown.escape')
  onEscape() {
    if (this.isOpen) {
      this.close.emit();
    }
  }

  @HostListener('keydown', ['$event'])
  onKeydown(event: KeyboardEvent) {
    if (!this.isOpen || event.key !== 'Tab') return;
    const focusable = this.elementRef.nativeElement.querySelectorAll(
      'button:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    if (focusable.length === 0) return;
    const first = focusable[0] as HTMLElement;
    const last = focusable[focusable.length - 1] as HTMLElement;

    if (event.shiftKey && document.activeElement === first) {
      last.focus();
      event.preventDefault();
    } else if (!event.shiftKey && document.activeElement === last) {
      first.focus();
      event.preventDefault();
    }
  }

  onBackdropClick(event: MouseEvent) {
    if ((event.target as HTMLElement).classList.contains('v3-modal-backdrop')) {
      this.close.emit();
    }
  }

  onConfirm() {
    if (this.invoice && this.reason().trim()) {
      this.confirm.emit({ invoiceId: this.invoice.id, reason: this.reason().trim() });
      this.reason.set('');
    }
  }
}
