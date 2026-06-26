<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Phiếu mượn sách #{{ $borrow->borrow_id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            padding: 30px 40px;
            background: #fff;
        }
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
        .receipt-title {
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            margin: 14px 0 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #111;
        }
        .receipt-id { text-align: center; font-size: 10px; color: #6b7280; margin-bottom: 16px; }
        /* 2-column info grid */
        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .info-grid td { width: 50%; vertical-align: top; padding-right: 12px; border: none; background: transparent; }
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
        .info-row { display: flex; margin-bottom: 5px; }
        .info-label { width: 110px; color: #6b7280; font-size: 10px; flex-shrink: 0; }
        .info-value { flex: 1; font-size: 10px; font-weight: 600; color: #111; }
        /* Books table */
        .books-section { margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #1d4ed8;
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 6px 8px;
            text-align: left;
        }
        td { padding: 6px 8px; font-size: 10px; border-bottom: 1px solid #f3f4f6; vertical-align: top; background: transparent; }
        tr:last-child td { border-bottom: none; }
        tr:nth-child(even) td { background: #f9fafb; }
        .barcode-cell {
            font-family: monospace;
            font-size: 9px;
            background: #f3f4f6;
            padding: 2px 5px;
            border-radius: 3px;
            display: inline-block;
        }
        /* Due date box */
        .due-date-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
        }
        .due-date-label { font-size: 9px; color: #3b82f6; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; }
        .due-date-value { font-size: 15px; font-weight: bold; color: #1d4ed8; margin-top: 3px; }
        /* Footer */
        .footer { margin-top: 20px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
        .sig-table { width: 100%; border-collapse: collapse; }
        .sig-table td { width: 50%; text-align: center; border: none; background: transparent; padding: 0; }
        .signature-title { font-size: 9px; text-transform: uppercase; font-weight: bold; letter-spacing: 0.3px; color: #6b7280; margin-bottom: 28px; }
        .signature-name { font-size: 10px; font-weight: 600; border-top: 1px solid #9ca3af; padding-top: 4px; margin: 0 20px; }
        .footer-note { font-size: 9px; color: #374151; text-align: center; margin-top: 14px; font-style: italic; font-weight: 600; }
    </style>
</head>
<body>

<div class="header">
    <div class="library-name">{{ $libraryName }}</div>
    <div class="library-info">{{ $settings['address'] ?? '' }}@if(!empty($settings['contact_phone']))  ·  ĐT: {{ $settings['contact_phone'] }}@endif</div>
</div>

<div class="receipt-title">Phiếu mượn sách</div>
<div class="receipt-id">Mã phiếu: #{{ str_pad($borrow->borrow_id, 6, '0', STR_PAD_LEFT) }}&nbsp;&nbsp;·&nbsp;&nbsp;Ngày in: {{ now()->format('d/m/Y H:i') }}</div>

{{-- 2-column info --}}
<table class="info-grid">
    <tr>
        <td>
            <div class="section-title">Thông tin độc giả</div>
            <div class="info-row"><span class="info-label">Họ và tên</span><span class="info-value">{{ $reader->full_name }}</span></div>
            <div class="info-row"><span class="info-label">Mã thẻ thư viện</span><span class="info-value">{{ $reader->card_number ?? '—' }}</span></div>
            @if($reader->email)
            <div class="info-row"><span class="info-label">Email</span><span class="info-value">{{ $reader->email }}</span></div>
            @endif
        </td>
        <td>
            <div class="section-title">Thông tin phiếu</div>
            <div class="info-row"><span class="info-label">Ngày mượn</span><span class="info-value">{{ \Carbon\Carbon::parse($borrow->borrow_date)->format('d/m/Y') }}</span></div>
            <div class="info-row"><span class="info-label">Số sách mượn</span><span class="info-value">{{ count($books) }} cuốn</span></div>
            <div class="info-row"><span class="info-label">Thủ thư</span><span class="info-value">{{ $librarian }}</span></div>
        </td>
    </tr>
</table>

{{-- Books table: STT | BARCODE | TÊN SÁCH (no ISBN) --}}
<div class="books-section">
    <div class="section-title">Danh sách sách mượn</div>
    <table>
        <thead>
            <tr>
                <th style="width:30px">#</th>
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

<div class="due-date-box">
    <div class="due-date-label">Hạn trả sách</div>
    <div class="due-date-value">{{ \Carbon\Carbon::parse($borrow->due_date)->format('d/m/Y') }}</div>
</div>

<div class="footer">
    <table class="sig-table">
        <tr>
            <td>
                <div class="signature-title">Độc giả<br>(Ký, ghi rõ họ tên)</div>
                <div class="signature-name">{{ $reader->full_name }}</div>
            </td>
            <td>
                <div class="signature-title">Thủ thư xác nhận<br>(Ký, ghi rõ họ tên)</div>
                <div class="signature-name">{{ $librarian }}</div>
            </td>
        </tr>
    </table>
    <div class="footer-note">"Tôi cam kết trả sách đúng hạn."</div>
</div>

</body>
</html>
