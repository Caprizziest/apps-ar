import { Component, inject, signal, computed, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ArDataService } from '../../../core/services/ar-data.service';
import { NavigationService } from '../../../core/services/navigation.service';
import { RupiahPipe } from '../../../shared/pipes/rupiah.pipe';
import { KartuPiutangRow } from '../../../core/models/ar.models';

@Component({
  selector: 'app-kartu-piutang',
  standalone: true,
  imports: [CommonModule, FormsModule, RupiahPipe],
  template: `
    <div class="d-flex flex-column gap-3">
      <div class="dashboard-header-row">
        <div>
          <h1 class="page-title">Kartu Piutang Seimbang (Buku Pembantu)</h1>
          <p class="page-subtitle">Rincian mutasi debet, kredit, denda keterlambatan, dan saldo berjalan per pelanggan secara kronologis.</p>
        </div>
      </div>

      <div class="v3-card p-3">
        <!-- Filter Ribbon -->
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-3">
            <label for="kartu-start-date" class="form-label small fw-semibold text-secondary mb-1">Tanggal Awal</label>
            <input id="kartu-start-date" type="date" class="form-control form-control-sm v3-mono"
              [ngModel]="kartuStartDate()"
              (ngModelChange)="kartuStartDate.set($event)">
          </div>
          <div class="col-6 col-md-3">
            <label for="kartu-end-date" class="form-label small fw-semibold text-secondary mb-1">Tanggal Akhir</label>
            <input id="kartu-end-date" type="date" class="form-control form-control-sm v3-mono"
              [ngModel]="kartuEndDate()"
              (ngModelChange)="kartuEndDate.set($event)">
          </div>
          <div class="col-12 col-md-3">
            <label for="kartu-company" class="form-label small fw-semibold text-secondary mb-1">Pilih Perusahaan</label>
            <select id="kartu-company" class="form-select form-select-sm"
              [ngModel]="kartuSelectedPerusahaan()"
              (ngModelChange)="kartuSelectedPerusahaan.set($event)">
              <option value="PT. SINAR GALESONG PRATAMA">PT. SINAR GALESONG PRATAMA</option>
              <option value="PT. SINAR GALESONG AUTO">PT. SINAR GALESONG AUTO</option>
              <option value="PT. GALESONG PRATAMA HOTEL">PT. GALESONG PRATAMA HOTEL</option>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label for="kartu-customer" class="form-label small fw-semibold text-secondary mb-1">Pilih Pelanggan</label>
            <div class="d-flex gap-2">
              <select id="kartu-customer" class="form-select form-select-sm"
                [ngModel]="kartuSelectedCustomerId()"
                (ngModelChange)="kartuSelectedCustomerId.set($event)">
                <option *ngFor="let c of arService.customers()" [value]="c.id">
                  {{ c.name }}
                </option>
              </select>
            </div>
          </div>
        </div>

        <!-- Title & Subtitle -->
        <div class="border-top pt-3 mb-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div>
            <h3 class="fw-bold text-dark mb-0 fs-5 text-uppercase">
              {{ selectedKartuCustomer()?.name || 'PT. SINAR GALESONG MANDIRI' }}
            </h3>
            <div class="text-secondary small mt-0.5">
              Periode: <span class="v3-mono text-dark fw-medium">{{ kartuStartDate() }}</span> s/d <span class="v3-mono text-dark fw-medium">{{ kartuEndDate() }}</span>
            </div>
          </div>
        </div>

        <!-- Balanced T-Account Matching Table -->
        <div class="v3-table-container overflow-auto border rounded">
          <table class="table table-sm table-bordered mb-0 small" style="min-width: 1520px; font-size: 0.73rem;">
            <thead>
              <tr class="text-center text-white fw-bold">
                <th colspan="12" style="background-color: #16a34a; letter-spacing: 0.05em; font-size: 0.82rem; padding: 6px;">
                  PIUTANG
                </th>
                <th colspan="11" style="background-color: #ef4444; letter-spacing: 0.05em; font-size: 0.82rem; padding: 6px;">
                  DENDA
                </th>
              </tr>
              <tr class="text-center fw-semibold" style="background-color: #f1f5f9;">
                <th colspan="6" class="border-end" style="background-color: #ecfdf5; color: #065f46;">
                  Penagihan Piutang
                </th>
                <th colspan="6" class="border-end" style="background-color: #ecfdf5; color: #065f46;">
                  Pembayaran Piutang
                </th>
                <th colspan="5" class="border-end" style="background-color: #fef2f2; color: #991b1b;">
                  Pembayaran Denda
                </th>
                <th colspan="6" style="background-color: #fef2f2; color: #991b1b;">
                  Penghapusan Denda &amp; Aksi
                </th>
              </tr>
              <tr class="text-center text-nowrap" style="background-color: #f8fafc;">
                <!-- Penagihan Piutang -->
                <th style="width: 80px;">Tgl Terbit</th>
                <th style="width: 130px;">No Invoice</th>
                <th style="width: 100px;">No Bukti Jurnal</th>
                <th>Keterangan</th>
                <th style="width: 95px;" class="text-end">Nominal</th>
                <th style="width: 80px;">Tgl Jatuh Tempo</th>

                <!-- Pembayaran Piutang -->
                <th style="width: 80px;">Tgl Bayar</th>
                <th style="width: 100px;">No Bukti Jurnal</th>
                <th>Keterangan</th>
                <th style="width: 95px;" class="text-end">Nominal</th>
                <th style="width: 60px;" class="text-end">Diskon</th>
                <th style="width: 95px;" class="text-end">Saldo</th>

                <!-- Pembayaran Denda -->
                <th style="width: 80px;" class="text-end">Nominal Denda</th>
                <th style="width: 75px;">Tgl Bayar</th>
                <th style="width: 90px;">No Bukti Jurnal</th>
                <th>Keterangan</th>
                <th style="width: 80px;" class="text-end">Nominal</th>

                <!-- Penghapusan Denda -->
                <th style="width: 75px;">Tgl Hapus</th>
                <th style="width: 80px;">Memo</th>
                <th>Keterangan</th>
                <th style="width: 80px;" class="text-end">Nominal</th>
                <th style="width: 80px;" class="text-end">Saldo Denda</th>
                <th style="width: 110px;">Aksi Denda</th>
              </tr>
            </thead>
            <tbody>
              <tr *ngFor="let row of kartuRows()" class="align-middle text-nowrap">
                <!-- Penagihan Piutang -->
                <td class="v3-mono text-center">{{ row.tglTerbit }}</td>
                <td class="v3-mono fw-bold text-primary">{{ row.noInvoice }}</td>
                <td class="v3-mono text-secondary small">{{ row.noBuktiJurnalDebet }}</td>
                <td class="text-truncate" style="max-width: 160px;" [title]="row.keteranganDebet || ''">{{ row.keteranganDebet }}</td>
                <td class="text-end v3-mono fw-semibold">{{ (row.nominalDebet || 0) | rupiah }}</td>
                <td class="v3-mono text-center text-muted">{{ row.tglJatuhTempo || '-' }}</td>

                <!-- Pembayaran Piutang -->
                <td class="v3-mono text-center">{{ row.tglBayar }}</td>
                <td class="v3-mono text-secondary small">{{ row.noBuktiJurnalKredit }}</td>
                <td class="text-truncate" style="max-width: 180px;" [title]="row.keteranganKredit || ''">{{ row.keteranganKredit }}</td>
                <td class="text-end v3-mono fw-semibold text-success">{{ (row.nominalKredit || 0) | rupiah }}</td>
                <td class="text-end v3-mono text-muted">{{ (row.diskon || 0) | rupiah }}</td>
                <td class="text-end v3-mono fw-bold" [class.text-danger]="(row.saldoPiutang || 0) > 0">
                  {{ (row.saldoPiutang || 0) | rupiah }}
                </td>

                <!-- Pembayaran Denda -->
                <td class="text-end v3-mono text-danger">{{ (row.nominalDenda || 0) | rupiah }}</td>
                <td class="text-center v3-mono text-muted">{{ row.tglBayarDenda || '-' }}</td>
                <td class="text-center v3-mono text-secondary small">{{ row.noBuktiJurnalDenda || '-' }}</td>
                <td class="text-truncate text-muted" style="max-width: 140px;" [title]="row.keteranganDenda || ''">{{ row.keteranganDenda || '-' }}</td>
                <td class="text-end v3-mono" [class.text-success]="(row.nominalBayarDenda || 0) > 0">{{ (row.nominalBayarDenda || 0) | rupiah }}</td>

                <!-- Penghapusan Denda -->
                <td class="text-center v3-mono text-muted">{{ row.tglHapusDenda || '-' }}</td>
                <td class="text-center v3-mono text-secondary small">{{ row.memoHapusDenda || '-' }}</td>
                <td class="text-truncate text-muted" style="max-width: 140px;" [title]="row.keteranganHapusDenda || ''">{{ row.keteranganHapusDenda || '-' }}</td>
                <td class="text-end v3-mono" [class.text-warning-emphasis]="(row.nominalHapusDenda || 0) > 0">{{ (row.nominalHapusDenda || 0) | rupiah }}</td>
                <td class="text-end v3-mono fw-semibold" [class.text-danger]="(row.saldoDenda || 0) > 0" [class.text-success]="(row.nominalDenda || 0) > 0 && (row.saldoDenda || 0) === 0">
                  {{ (row.saldoDenda || 0) | rupiah }}
                </td>
                <td class="text-center">
                  <div class="d-flex justify-content-center gap-1" *ngIf="(row.saldoDenda || 0) > 0">
                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-1.5" style="font-size: 0.68rem;"
                      [disabled]="isViewer"
                      (click)="openPenaltyModal(row, 'pay')"
                      [attr.aria-label]="'Bayar denda invoice ' + row.noInvoice"
                      title="Catat Pembayaran Denda">
                      Bayar
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1.5" style="font-size: 0.68rem;"
                      [disabled]="isViewer"
                      (click)="openPenaltyModal(row, 'waive')"
                      [attr.aria-label]="'Hapus denda invoice ' + row.noInvoice"
                      title="Penghapusan Denda (Waive)">
                      Hapus
                    </button>
                  </div>
                  <span *ngIf="(row.nominalDenda || 0) > 0 && (row.saldoDenda || 0) === 0" class="badge bg-success-subtle text-success border" style="font-size: 0.65rem;">
                    Selesai
                  </span>
                  <span *ngIf="!row.nominalDenda || row.nominalDenda === 0" class="text-muted">-</span>
                </td>
              </tr>
              <tr *ngIf="kartuRows().length === 0">
                <td colspan="23" class="text-center py-4 text-muted">
                  Tidak ada transaksi kartu piutang untuk pelanggan ini pada periode terpilih.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal Pembayaran / Penghapusan Denda -->
    <div class="v3-modal-backdrop" *ngIf="showPenaltyModal() && selectedPenaltyRow() as target" (click)="onBackdropClick($event)">
      <div class="v3-modal" role="dialog" aria-modal="true" aria-labelledby="penalty-modal-title" (click)="$event.stopPropagation()">
        <div class="v3-modal-header">
          <div>
            <h4 id="penalty-modal-title" class="fw-bold fs-5 text-dark m-0">
              {{ penaltyModalMode() === 'pay' ? 'Pencatatan Pembayaran Denda' : 'Penghapusan Denda (Waiver Memo)' }}
            </h4>
            <div class="text-muted small">
              Faktur: <strong class="v3-mono text-primary">{{ target.noInvoice }}</strong> &bull; Sisa Denda: <strong class="v3-mono text-danger">{{ (target.saldoDenda || 0) | rupiah }}</strong>
            </div>
          </div>
          <button type="button" class="btn-close" (click)="showPenaltyModal.set(false)" aria-label="Tutup dialog denda"></button>
        </div>

        <div class="v3-modal-body">
          <div class="mb-3">
            <label for="penalty-amount-input" class="form-label small fw-semibold">
              {{ penaltyModalMode() === 'pay' ? 'Nominal Bayar Denda (Rp)' : 'Nominal Denda Dihapus (Rp)' }} <span class="text-danger">*</span>
            </label>
            <input id="penalty-amount-input" type="number" min="1" [max]="target.saldoDenda || 50000"
              class="form-control v3-mono fs-5 fw-bold"
              [ngModel]="penaltyFormAmount()"
              (ngModelChange)="penaltyFormAmount.set(+$event)">
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label for="penalty-date-input" class="form-label small fw-semibold">
                {{ penaltyModalMode() === 'pay' ? 'Tanggal Bayar' : 'Tanggal Hapus' }}
              </label>
              <input id="penalty-date-input" type="date" class="form-control v3-mono"
                [ngModel]="penaltyFormDate()"
                (ngModelChange)="penaltyFormDate.set($event)">
            </div>
            <div class="col-6">
              <label for="penalty-ref-input" class="form-label small fw-semibold">
                {{ penaltyModalMode() === 'pay' ? 'No. Bukti Jurnal' : 'No. Memo Penghapusan' }}
              </label>
              <input id="penalty-ref-input" type="text" class="form-control v3-mono"
                [ngModel]="penaltyFormRef()"
                (ngModelChange)="penaltyFormRef.set($event)">
            </div>
          </div>

          <div class="mb-2">
            <label for="penalty-notes-input" class="form-label small fw-semibold">Keterangan</label>
            <textarea id="penalty-notes-input" class="form-control" rows="2"
              [ngModel]="penaltyFormNotes()"
              (ngModelChange)="penaltyFormNotes.set($event)"
              placeholder="Catatan jurnal / alasan penghapusan denda..."></textarea>
          </div>
        </div>

        <div class="v3-modal-footer">
          <button type="button" class="v3-btn-secondary" (click)="showPenaltyModal.set(false)">Batal</button>
          <button type="button" class="v3-btn-primary"
            [ngClass]="penaltyModalMode() === 'pay' ? 'bg-success border-success' : 'bg-danger border-danger'"
            [disabled]="penaltyFormAmount() <= 0"
            (click)="submitPenaltyAction()">
            {{ penaltyModalMode() === 'pay' ? 'Simpan Pembayaran Denda' : 'Konfirmasi Hapus Denda' }}
          </button>
        </div>
      </div>
    </div>
  `
})
export class KartuPiutangComponent {
  readonly arService = inject(ArDataService);
  readonly navService = inject(NavigationService);

  kartuStartDate = signal<string>('2026-06-01');
  kartuEndDate = signal<string>('2026-09-30');
  kartuSelectedPerusahaan = signal<string>('PT. SINAR GALESONG PRATAMA');
  kartuSelectedCustomerId = signal<string>('cust-sgm');

  showPenaltyModal = signal<boolean>(false);
  penaltyModalMode = signal<'pay' | 'waive'>('pay');
  selectedPenaltyRow = signal<KartuPiutangRow | null>(null);

  penaltyFormAmount = signal<number>(50000);
  penaltyFormDate = signal<string>(this.arService.today);
  penaltyFormRef = signal<string>('');
  penaltyFormNotes = signal<string>('');

  @HostListener('document:keydown.escape')
  onEscape() {
    if (this.showPenaltyModal()) {
      this.showPenaltyModal.set(false);
    }
  }

  onBackdropClick(event: MouseEvent) {
    if ((event.target as HTMLElement).classList.contains('v3-modal-backdrop')) {
      this.showPenaltyModal.set(false);
    }
  }

  get isViewer(): boolean {
    return this.arService.currentUser().role === 'Viewer';
  }

  selectedKartuCustomer = computed(() => {
    return this.arService.customers().find(c => c.id === this.kartuSelectedCustomerId()) || this.arService.customers()[0] || null;
  });

  kartuRows = computed(() => {
    const cust = this.selectedKartuCustomer();
    if (!cust) return [];
    return this.arService.getKartuPiutang(cust.id, this.kartuStartDate(), this.kartuEndDate());
  });

  openPenaltyModal(row: KartuPiutangRow, mode: 'pay' | 'waive') {
    this.selectedPenaltyRow.set(row);
    this.penaltyModalMode.set(mode);
    this.penaltyFormAmount.set(row.saldoDenda || 50000);
    this.penaltyFormDate.set(this.arService.today);

    const dateCode = this.arService.today.replace(/-/g, '').substring(2);
    if (mode === 'pay') {
      this.penaltyFormRef.set(`BD${dateCode}${Math.floor(100 + Math.random() * 900)}`);
      this.penaltyFormNotes.set(`Pelunasan denda faktur ${row.noInvoice}`);
    } else {
      this.penaltyFormRef.set(`MEMO/FNC/${dateCode}/${Math.floor(10 + Math.random() * 89)}`);
      this.penaltyFormNotes.set(`Penghapusan denda keterlambatan ${row.noInvoice}`);
    }

    this.showPenaltyModal.set(true);
  }

  submitPenaltyAction() {
    const row = this.selectedPenaltyRow();
    if (!row || !row.invoiceId) return;

    if (this.penaltyModalMode() === 'pay') {
      const res = this.arService.payInvoicePenalty({
        invoiceId: row.invoiceId,
        paidAmount: this.penaltyFormAmount(),
        paidDate: this.penaltyFormDate(),
        paymentJournalNumber: this.penaltyFormRef(),
        paymentNotes: this.penaltyFormNotes()
      });
      this.navService.showToast(res.message, res.success ? 'success' : 'danger');
    } else {
      const res = this.arService.waiveInvoicePenalty({
        invoiceId: row.invoiceId,
        waivedAmount: this.penaltyFormAmount(),
        waivedDate: this.penaltyFormDate(),
        waiverMemoNumber: this.penaltyFormRef(),
        waiverNotes: this.penaltyFormNotes()
      });
      this.navService.showToast(res.message, res.success ? 'success' : 'danger');
    }

    this.showPenaltyModal.set(false);
  }
}
