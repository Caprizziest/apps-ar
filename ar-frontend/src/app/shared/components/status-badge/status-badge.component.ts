import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { InvoiceStatus } from '../../../core/models/ar.models';

@Component({
  selector: 'app-status-badge',
  standalone: true,
  imports: [CommonModule],
  template: `
    <span class="v3-badge" [ngClass]="badgeClass">
      <i *ngIf="status === 'Overdue'" class="bi bi-exclamation-circle-fill me-1"></i>
      <i *ngIf="status === 'Paid'" class="bi bi-check-circle-fill me-1"></i>
      {{ status }}
    </span>
  `,
  styles: [`
    .v3-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.2rem 0.55rem;
      border-radius: 9999px;
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: -0.01em;
      white-space: nowrap;
    }
    .v3-badge-draft { background: var(--badge-draft-bg, #f1f5f9); color: var(--badge-draft-color, #475569); border: 1px solid var(--badge-draft-border, #cbd5e1); }
    .v3-badge-issued { background: var(--badge-issued-bg, #eff6ff); color: var(--badge-issued-color, #1d4ed8); border: 1px solid var(--badge-issued-border, #bfdbfe); }
    .v3-badge-partial { background: var(--badge-partial-bg, #fffbeb); color: var(--badge-partial-color, #b45309); border: 1px solid var(--badge-partial-border, #fde68a); }
    .v3-badge-paid { background: var(--badge-paid-bg, #ecfdf5); color: var(--badge-paid-color, #047857); border: 1px solid var(--badge-paid-border, #a7f3d0); }
    .v3-badge-overdue { background: var(--badge-overdue-bg, #fef2f2); color: var(--badge-overdue-color, #b91c1c); border: 1px solid var(--badge-overdue-border, #fecaca); }
    .v3-badge-cancelled { background: var(--badge-cancelled-bg, #f8fafc); color: var(--badge-cancelled-color, #475569); border: 1px solid var(--badge-cancelled-border, #cbd5e1); }
  `]
})
export class StatusBadgeComponent {
  @Input() status: InvoiceStatus | string = 'Draft';

  get badgeClass(): string {
    switch (this.status) {
      case 'Draft': return 'v3-badge-draft';
      case 'Issued': return 'v3-badge-issued';
      case 'Partially Paid': return 'v3-badge-partial';
      case 'Paid': return 'v3-badge-paid';
      case 'Overdue': return 'v3-badge-overdue';
      case 'Cancelled': return 'v3-badge-cancelled';
      default: return 'v3-badge-draft';
    }
  }
}
