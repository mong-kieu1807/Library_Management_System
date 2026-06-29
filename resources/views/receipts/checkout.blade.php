<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Phiếu mượn sách #{{ $borrow->borrow_id }}</title>
    <style>
        /*
         * QUAN TRỌNG: DomPDF yêu cầu:
         *  1. Tên font nhiều từ phải có DẤU NHÁY: 'DejaVu Sans'
         *  2. KHÔNG dùng display:flex (không được hỗ trợ)
         *  3. KHÔNG dùng calc() hay CSS variable
         *  4. Dùng <table> cho mọi layout 2 cột
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
            border-bottom: 2px solid #1d4ed8;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .library-name {
            font-size: 16px;
            font-weight: bold;
            color: #1d4ed8;
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

        /* ── Bảng sách ───────────────────────────── */
        .books-section { margin-bottom: 14px; }
        .books-table { width: 100%; border-collapse: collapse; }
        .books-table th {
            background: #1d4ed8;
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
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

        /* ── Hạn trả ──────────────────────────────── */
        .due-date-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 10px 14px;
            margin-bottom: 14px;
        }
        .due-date-label {
            font-size: 9px;
            color: #3b82f6;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: bold;
        }
        .due-date-value {
            font-size: 15px;
            font-weight: bold;
            color: #1d4ed8;
            margin-top: 3px;
        }

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

<div class="receipt-title">Phiếu mượn sách</div>
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
                @if($reader->email)
                <tr>
                    <td class="info-label-cell">Email</td>
                    <td class="info-value-cell">{{ $reader->email }}</td>
                </tr>
                @endif
            </table>
        </td>

        {{-- Cột phải: Thông tin phiếu --}}
        <td>
            <div class="section-title">Thông tin phiếu</div>
            <table class="info-table">
                <tr>
                    <td class="info-label-cell">Ngày mượn</td>
                    <td class="info-value-cell">{{ \Carbon\Carbon::parse($borrow->borrow_date)->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td class="info-label-cell">Số sách mượn</td>
                    <td class="info-value-cell">{{ count($books) }} cuốn</td>
                </tr>
                <tr>
                    <td class="info-label-cell">Thủ thư</td>
                    <td class="info-value-cell">{{ $librarian }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- Bảng sách --}}
<div class="books-section">
    <div class="section-title">Danh sách sách mượn</div>
    <table class="books-table">
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th style="width:110px">Barcode</th>
                <th>Tên sách</th>
            </tr>
        </thead>
        <tbody>
            @foreach($books as $i => $book)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td><span class="barcode-cell">{{ $book->barcode }}</span></td>
                <td>{{ $book->title }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Hạn trả --}}
<div class="due-date-box">
    <div class="due-date-label">Hạn trả sách</div>
    <div class="due-date-value">{{ \Carbon\Carbon::parse($borrow->due_date)->format('d/m/Y') }}</div>
</div>

{{-- Footer / Chữ ký --}}
<div class="footer">
    <table class="sig-table">
        <tr>
            <td>
                <div class="signature-title">Độc giả<br/>(Ký, ghi rõ họ tên)</div>
                <div class="signature-name">{{ $reader->full_name }}</div>
            </td>
            <td>
                <div class="signature-title">Thủ thư xác nhận<br/>(Ký, ghi rõ họ tên)</div>
                <div class="signature-name">{{ $librarian }}</div>
            </td>
        </tr>
    </table>
    <div class="footer-note">"Tôi cam kết trả sách đúng hạn."</div>
</div>

</body>
</html>
