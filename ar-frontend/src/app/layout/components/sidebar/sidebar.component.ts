import { Component, inject, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NavigationService, V3ActiveView } from '../../../core/services/navigation.service';
import { ArDataService } from '../../../core/services/ar-data.service';

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule],
  template: `
    <aside class="v3-sidebar"
      [class.mobile-open]="navService.sidebarMobileOpen()"
      [class.collapsed]="navService.sidebarCollapsed()">
      
      <!-- Floating Circular Collapse Toggle Button (POS Style) -->
      <button type="button" class="sidebar-collapse-toggle d-none d-lg-flex"
        (click)="navService.toggleSidebarCollapse()"
        [title]="navService.sidebarCollapsed() ? 'Perluas Sidebar' : 'Ciutkan Sidebar'"
        [attr.aria-label]="navService.sidebarCollapsed() ? 'Perluas navigasi sidebar' : 'Ciutkan navigasi sidebar'">
        <i class="bi" [ngClass]="navService.sidebarCollapsed() ? 'bi-chevron-right' : 'bi-chevron-left'"></i>
      </button>

      <div class="v3-sidebar-header">
        <div class="v3-brand" (click)="navigateTo('dashboard')" role="button" tabindex="0"
          (keydown.enter)="navigateTo('dashboard')" (keydown.space)="navigateTo('dashboard')"
          title="PT Galesong Pratama - Sistem Piutang"
          aria-label="Beranda Dashboard PT Galesong Pratama">
          <img *ngIf="!navService.sidebarCollapsed()" src="asset/image3.png" alt="PT Galesong Pratama" class="v3-sidebar-logo" />
          <div *ngIf="navService.sidebarCollapsed()" class="v3-brand-mini-icon" title="Galesong AR">
            <span>AR</span>
          </div>
        </div>
      </div>

      <nav class="v3-sidebar-nav">
        <div class="v3-nav-group-label">RINGKASAN EKSEKUTIF</div>
        <button type="button" class="v3-nav-item"
          [class.active]="navService.activeView() === 'dashboard'"
          (click)="navigateTo('dashboard')"
          title="Dashboard Eksekutif">
          <span class="v3-nav-item-content">
            <i class="bi bi-grid-1x2-fill"></i>
            <span class="v3-nav-text">Dashboard</span>
          </span>
        </button>

        <div class="v3-nav-group-label mt-3">OPERASIONAL & FOLIO AR</div>
        <button type="button" class="v3-nav-item"
          [class.active]="navService.activeView() === 'invoices' || navService.activeView() === 'invoice-detail' || navService.activeView() === 'invoice-create' || navService.activeView() === 'invoice-edit'"
          (click)="navigateTo('invoices')"
          title="Daftar Invoice">
          <span class="v3-nav-item-content">
            <i class="bi bi-receipt-cutoff"></i>
            <span class="v3-nav-text">Daftar Invoice</span>
          </span>
          <span class="v3-nav-pill-danger" *ngIf="arService.dashboardMetrics().overdueInvoicesCount > 0 && !navService.sidebarCollapsed()">
            {{ arService.dashboardMetrics().overdueInvoicesCount }}
          </span>
        </button>

        <button type="button" class="v3-nav-item"
          [class.active]="navService.activeView() === 'payments'"
          (click)="navigateTo('payments')"
          title="Riwayat Pembayaran">
          <span class="v3-nav-item-content">
            <i class="bi bi-wallet2"></i>
            <span class="v3-nav-text">Riwayat Pembayaran</span>
          </span>
        </button>

        <button type="button" class="v3-nav-item"
          [class.active]="navService.activeView() === 'income-audit'"
          (click)="navigateTo('income-audit')"
          title="Income Audit">
          <span class="v3-nav-item-content">
            <i class="bi bi-shield-check"></i>
            <span class="v3-nav-text">Income Audit</span>
          </span>
          <span class="v3-nav-pill-danger" *ngIf="pendingIncomeAuditCount() > 0 && !navService.sidebarCollapsed()">
            {{ pendingIncomeAuditCount() }}
          </span>
        </button>

        <button type="button" class="v3-nav-item"
          [class.active]="navService.activeView() === 'customers' || navService.activeView() === 'customer-detail'"
          (click)="navigateTo('customers')"
          title="Data Pelanggan">
          <span class="v3-nav-item-content">
            <i class="bi bi-people"></i>
            <span class="v3-nav-text">Data Pelanggan</span>
          </span>
        </button>

        <button type="button" class="v3-nav-item"
          [class.active]="navService.activeView() === 'dp-management'"
          (click)="navigateTo('dp-management')"
          title="Uang Muka (DP)">
          <span class="v3-nav-item-content">
            <i class="bi bi-piggy-bank"></i>
            <span class="v3-nav-text">Uang Muka (DP)</span>
          </span>
        </button>

        <div class="v3-nav-group-label mt-3">LAPORAN & AUDIT</div>
        <button type="button" class="v3-nav-item" [class.active]="navService.activeView() === 'mutasi-piutang'"
          (click)="navigateTo('mutasi-piutang')"
          title="Laporan Mutasi Piutang">
          <span class="v3-nav-item-content">
            <i class="bi bi-journal-text"></i>
            <span class="v3-nav-text">Laporan Mutasi Piutang</span>
          </span>
        </button>

        <button type="button" class="v3-nav-item" [class.active]="navService.activeView() === 'kartu-piutang'"
          (click)="navigateTo('kartu-piutang')"
          title="Kartu Piutang Seimbang">
          <span class="v3-nav-item-content">
            <i class="bi bi-layout-split"></i>
            <span class="v3-nav-text">Kartu Piutang Seimbang</span>
          </span>
        </button>

        <button type="button" class="v3-nav-item" [class.active]="navService.activeView() === 'aging-report'"
          (click)="navigateTo('aging-report')"
          title="Laporan Aging">
          <span class="v3-nav-item-content">
            <i class="bi bi-bar-chart-steps"></i>
            <span class="v3-nav-text">Laporan Aging</span>
          </span>
        </button>

        <button type="button" class="v3-nav-item" [class.active]="navService.activeView() === 'activity-logs'"
          (click)="navigateTo('activity-logs')"
          title="Log Aktivitas (Audit)">
          <span class="v3-nav-item-content">
            <i class="bi bi-clock-history"></i>
            <span class="v3-nav-text">Log Aktivitas (Audit)</span>
          </span>
        </button>

        <div class="v3-nav-group-label mt-3">MASTER & PENGATURAN</div>
        <button type="button" class="v3-nav-item" [class.active]="navService.activeView() === 'bank-accounts'"
          (click)="navigateTo('bank-accounts')"
          title="Rekening Bank Hotel">
          <span class="v3-nav-item-content">
            <i class="bi bi-bank"></i>
            <span class="v3-nav-text">Rekening Bank Hotel</span>
          </span>
        </button>
      </nav>

      <!-- Sidebar Footer (POS User Card Style) -->
      <div class="v3-sidebar-footer">
        <div class="v3-user-card">
          <div class="v3-user-avatar">
            {{ arService.currentUser().role.substring(0, 2).toUpperCase() }}
            <span class="v3-user-online-dot"></span>
          </div>
          <div class="overflow-hidden" *ngIf="!navService.sidebarCollapsed()">
            <div class="text-white fw-bold text-truncate" style="font-size: 0.8rem;">
              {{ arService.currentUser().fullName }}
            </div>
            <div class="v3-user-role-text text-truncate">
              {{ arService.currentUser().role }} &bull; Galesong AR
            </div>
          </div>
        </div>
      </div>
    </aside>
  `
})
export class SidebarComponent {
  readonly navService = inject(NavigationService);
  readonly arService = inject(ArDataService);

  readonly pendingIncomeAuditCount = computed(() => {
    return this.arService.payments().filter(p => !p.auditStatus || p.auditStatus === 'pending_fa').length;
  });

  navigateTo(view: V3ActiveView) {
    this.navService.navigateTo(view);
  }
}
