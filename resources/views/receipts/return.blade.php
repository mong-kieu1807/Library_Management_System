<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Biên lai trả sách #{{ $borrow->borrow_id }}</title>
    <style>
        /*
         * QUAN TRỌNG: DomPDF yêu cầu:
         *  1. Tên font nhiều từ phải có DẤU NHÁY: 'DejaVu Sans'
         *  2. KHÔNG dùng display:flex (không được hỗ trợ)
         *  3. KHÔNG dùng calc() hay CSS variable
         *  4. Dùng <table> cho mọi layout 2 cột và hàng label-value
         */

        * { margin: 0; padding: 0; }

        body {
            font-family: 'DejaVu Sans', sans-serif;   /* dấu nháy BẮT BUỘC */
            font-size: 11px;
            color: #1a1a1a;
            padding: 30px 40px;
            background: #fff;
        }

        /* ── Header ──────────────────────────────── */
        .header {
            text-align: center;
            border-bottom: 2px solid #dc2626;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .library-name {
            font-size: 16px;
            font-weight: bold;
            color: #dc2626;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .library-info { font-size: 9px; color: #6b7280; margin-top: 3px; }

        /* ── Tiêu đề phiếu ──────────────────────── */
        .receipt-title {
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            margin: 14px 0 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #111;
        }
        .receipt-id {
            text-align: center;
            font-size: 10px;
            color: #6b7280;
            margin-bottom: 16px;
        }

        /* ── Grid 2 cột thông tin ────────────────── */
        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .info-grid td { width: 50%; vertical-align: top; padding-right: 12px; }
        .info-grid td:last-child { padding-right: 0; padding-left: 12px; }

        .section-title {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 6px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
        }

        /*
         * FIX: dùng <table> thay display:flex cho hàng nhãn-giá trị
         * DomPDF không hỗ trợ flexbox
         */
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table tr td { border: none; padding: 0 0 5px 0; vertical-align: top; }
        .info-label-cell {
            width: 115px;
            color: #6b7280;
            font-size: 10px;
        }
        .info-value-cell {
            font-size: 10px;
            font-weight: 600;
            color: #111;
        }
        .info-value-red { color: #dc2626; font-weight: bold; }

        /* ── Bảng sách ───────────────────────────── */
        .books-section { margin-bottom: 14px; }
        .books-table { width: 100%; border-collapse: collapse; }
        .books-table th {
            background: #dc2626;
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 6px 8px;
            text-align: left;
        }
        .books-table td {
            padding: 6px 8px;
            font-size: 10px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }
        .books-table tr:last-child td { border-bottom: none; }
        .books-table tr td { background: #ffffff; }

        .barcode-cell {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 9px;
            background: #f3f4f6;
            padding: 2px 5px;
        }
        .overdue-text { color: #dc2626; font-weight: bold; }
        .ok-text      { color: #9ca3af; }

        /* ── Fee box ─────────────────────────────── */
        .fee-box {
            border: 1.5px solid #fdba74;
            padding: 12px 14px;
            margin-bottom: 14px;
            background: #fff7ed;
        }
        .fee-box-title {
            font-size: 9px;
            color: #ea580c;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        /*
         * FIX: dùng <table> thay display:flex; justify-content:space-between
         * cho các hàng phí phạt
         */
        .fee-table { width: 100%; border-collapse: collapse; }
        .fee-table td { border: none; padding: 0 0 5px 0; font-size: 10px; vertical-align: top; }
        .fee-label-cell { color: #374151; }
        .fee-value-cell { text-align: right; font-weight: 700; }

        .fee-total-row td { padding-bottom: 6px; }
        .fee-total-label { font-size: 10px; font-weight: bold; color: #374151; }
        .fee-total-value { font-size: 10px; font-weight: bold; color: #dc2626; }
        .fee-divider { border-bottom: 1px solid #fdba74; height: 0; }

        .fee-paid-value   { color: #16a34a; }
        .fee-unpaid-value { color: #dc2626; }
        .fee-zero-value   { color: #9ca3af; }
        .fee-none-box     { font-size: 13px; font-weight: bold; color: #16a34a; }

        /* ── Footer / Chữ ký ─────────────────────── */
        .footer { margin-top: 20px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
        .sig-table { width: 100%; border-collapse: collapse; }
        .sig-table td { width: 50%; text-align: center; vertical-align: top; padding: 0; }
        .signature-title {
            font-size: 9px;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 0.3px;
            color: #6b7280;
            margin-bottom: 28px;
        }
        .signature-name {
            font-size: 10px;
            font-weight: 600;
            border-top: 1px solid #9ca3af;
            padding-top: 4px;
            margin: 0 20px;
        }
        .footer-note {
            font-size: 9px;
            color: #374151;
            text-align: center;
            margin-top: 14px;
            font-style: italic;
            font-weight: 600;
        }
    </style>
</head>
<body>

{{-- Header --}}
<div class="header">
    <div class="library-name">{{ $libraryName }}</div>
    <div class="library-info">
        {{ $settings['address'] ?? '' }}@if(!empty($settings['contact_phone']))&nbsp;&nbsp;·&nbsp;&nbsp;ĐT: {{ $settings['contact_phone'] }}@endif
    </div>
</div>

<div class="receipt-title">Biên lai trả sách</div>
<div class="receipt-id">
    Mã phiếu: #{{ str_pad($borrow->borrow_id, 6, '0', STR_PAD_LEFT) }}
    &nbsp;&nbsp;·&nbsp;&nbsp;
    Ngày in: {{ now()->format('d/m/Y H:i') }}
</div>

{{-- 2 cột thông tin: dùng <table> + <tr><td> thay flex --}}
<table class="info-grid">
    <tr>
        {{-- Cột trái: Thông tin độc giả --}}
        <td>
            <div class="section-title">Thông tin độc giả</div>
            <table class="info-table">
                <tr>
                    <td class="info-label-cell">Họ và tên</td>
                    <td class="info-value-cell">{{ $reader->full_name }}</td>
                </tr>
                <tr>
                    <td class="info-label-cell">Mã thẻ thư viện</td>
                    <td class="info-value-cell">{{ $reader->card_number ?? '—' }}</td>
                </tr>
            </table>
        </td>

        {{-- Cột phải: Thông tin trả sách --}}
        <td>
            <div class="section-title">Thông tin trả sách</div>
            <table class="info-table">
                <tr>
                    <td class="info-label-cell">Mã phiếu mượn</td>
                    <td class="info-value-cell">#{{ str_pad($borrow->borrow_id, 6, '0', STR_PAD_LEFT) }}</td>
                </tr>
                <tr>
                    <td class="info-label-cell">Ngày mượn</td>
                    <td class="info-value-cell">{{ \Carbon\Carbon::parse($borrow->borrow_date)->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td class="info-label-cell">Hạn trả gốc</td>
                    <td class="info-value-cell">{{ \Carbon\Carbon::parse($borrow->due_date)->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td class="info-label-cell">Ngày trả thực tế</td>
                    <td class="info-value-cell info-value-red">{{ $returnDate }}</td>
                </tr>
                <tr>
                    <td class="info-label-cell">Thủ thư xử lý</td>
                    <td class="info-value-cell">{{ $librarian }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- Bảng sách: BARCODE | TÊN SÁCH | SỐ NGÀY TRỄ --}}
<div class="books-section">
    <div class="section-title">Sách đã trả ({{ count($returnedBooks) }} cuốn)</div>
    <table class="books-table">
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th style="width:110px">Barcode</th>
                <th>Tên sách</th>
                <th style="width:80px; text-align:right">Số ngày trễ</th>
            </tr>
        </thead>
        <tbody>
            @foreach($returnedBooks as $i => $book)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td><span class="barcode-cell">{{ $book->barcode }}</span></td>
                <td>{{ $book->title }}</td>
                <td style="text-align:right" class="{{ $book->overdue_days > 0 ? 'overdue-text' : 'ok-text' }}">
                    {{ $book->overdue_days > 0 ? $book->overdue_days . ' ngày' : '—' }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Fee box: Tổng phí | Đã thu | Chưa thu --}}
<div class="fee-box">
    <div class="fee-box-title">Thông tin phí phạt</div>
    @if($totalFine > 0)
        {{-- Dùng <table> thay display:flex để hiển thị label bên trái, value bên phải --}}
        <table class="fee-table">
            <tr class="fee-total-row">
                <td class="fee-label-cell fee-total-label">Tổng phí phạt</td>
                <td class="fee-value-cell fee-total-value">{{ number_format($totalFine, 0, ',', '.') }} VNĐ</td>
            </tr>
        </table>
        <hr class="fee-divider" style="border:none; border-bottom:1px solid #fdba74; margin-bottom:6px;"/>
        <table class="fee-table">
            <tr>
                <td class="fee-label-cell">Đã thu</td>
                <td class="fee-value-cell {{ $paidAmount > 0 ? 'fee-paid-value' : 'fee-zero-value' }}">
                    {{ $paidAmount > 0 ? number_format($paidAmount, 0, ',', '.') . ' VNĐ' : '0 VNĐ' }}
                </td>
            </tr>
            <tr>
                <td class="fee-label-cell">Chưa thu</td>
                <td class="fee-value-cell {{ $unpaidAmount > 0 ? 'fee-unpaid-value' : 'fee-zero-value' }}">
                    {{ $unpaidAmount > 0 ? number_format($unpaidAmount, 0, ',', '.') . ' VNĐ' : '0 VNĐ' }}
                </td>
            </tr>
        </table>
    @else
        <div class="fee-none-box">Không có phí phạt</div>
    @endif
</div>

{{-- Footer / Chữ ký --}}
<div class="footer">
    <table class="sig-table">
        <tr>
            <td>
                <div class="signature-title">Độc giả xác nhận<br/>(Ký, ghi rõ họ tên)</div>
                <div class="signature-name">{{ $reader->full_name }}</div>
            </td>
            <td>
                <div class="signature-title">Thủ thư xác nhận<br/>(Ký, ghi rõ họ tên)</div>
                <div class="signature-name">{{ $librarian }}</div>
            </td>
        </tr>
    </table>
    <div class="footer-note">"Xác nhận đã nhận lại sách."</div>
</div>

</body>
</html>
